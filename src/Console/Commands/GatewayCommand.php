<?php

namespace Azuriom\Plugin\DiscordIntegration\Console\Commands;

use Azuriom\Plugin\DiscordIntegration\Support\Gateway\GatewayCache;
use Azuriom\Plugin\DiscordIntegration\Support\Gateway\GatewayConnection;
use Azuriom\Plugin\DiscordIntegration\Support\Gateway\GatewayEventHandler;
use Illuminate\Console\Command;

/**
 * The endless gateway daemon - meant to run under a systemd unit (the
 * admin Gateway page shows the setup tutorial), NOT from the scheduler.
 * This is only a supervision loop: the actual protocol lives in
 * GatewayConnection, which returns here every time a session ends so this
 * loop can decide how to continue (idle while the feature is disabled,
 * quick backoff on drops, minutes-long backoff on fatal rejections).
 * Never exits on its own - even "disabled" just idles, so flipping the
 * admin toggle works live without ever touching systemd.
 */
class GatewayCommand extends Command
{
    protected $signature = 'discord-integration:gateway';

    protected $description = 'Maintain a persistent Discord Gateway connection (bot presence, instant role sync, event-fed cache)';

    /**
     * Reconnection backoff after a dropped session, doubling up to a cap -
     * reset once a session proves stable (see STABLE_SECONDS below).
     */
    protected const BACKOFF_START_SECONDS = 1;

    protected const BACKOFF_CAP_SECONDS = 60;

    /**
     * A session that lived at least this long counts as "stable": the next
     * drop starts the backoff over from the bottom instead of compounding.
     */
    protected const STABLE_SECONDS = 60;

    /**
     * Backoff after a fatal rejection (bad token, missing intents) - fast
     * retries would just loop against the same refusal, and identifies are
     * a capped daily budget on Discord's side.
     */
    protected const FATAL_BACKOFF_SECONDS = 300;

    protected bool $shouldExit = false;

    public function handle(): int
    {
        $connection = new GatewayConnection(new GatewayEventHandler());

        $this->registerSignalHandlers($connection);

        $this->info('Discord gateway daemon started.');

        $backoff = self::BACKOFF_START_SECONDS;

        while (! $this->shouldExit) {
            $settings = GatewayConnection::freshSettings();

            if (! $settings['enabled'] || $settings['token'] === null) {
                // Disabled (or no usable token): idle and keep polling so
                // the admin toggle brings the connection up without a
                // service restart.
                GatewayCache::markDisconnected();
                $this->interruptibleSleep(5);

                continue;
            }

            $startedAt = time();
            $outcome = $connection->run();

            if ($this->shouldExit) {
                break;
            }

            if ($outcome === GatewayConnection::OUTCOME_FATAL) {
                $this->warn('Discord rejected the connection - retrying in '.self::FATAL_BACKOFF_SECONDS.'s (see the admin Gateway page for the reason).');
                $this->interruptibleSleep(self::FATAL_BACKOFF_SECONDS);
                $backoff = self::BACKOFF_START_SECONDS;

                continue;
            }

            if ($outcome === GatewayConnection::OUTCOME_DISABLED) {
                // Back to the idle loop above - no backoff, it wasn't a failure.
                continue;
            }

            if (time() - $startedAt >= self::STABLE_SECONDS) {
                $backoff = self::BACKOFF_START_SECONDS;
            }

            $this->interruptibleSleep($backoff);
            $backoff = min(self::BACKOFF_CAP_SECONDS, $backoff * 2);
        }

        GatewayCache::markDisconnected();
        $this->info('Discord gateway daemon stopped.');

        return self::SUCCESS;
    }

    /**
     * Let systemctl stop/restart end the process cleanly (close frame sent,
     * status marked disconnected) - pcntl is standard on the CLI SAPI, but
     * degrade to "killed mid-loop, freshness gate cleans up within 90s"
     * rather than failing if it's somehow unavailable.
     */
    protected function registerSignalHandlers(GatewayConnection $connection): void
    {
        if (! function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);

        $handler = function () use ($connection) {
            $this->shouldExit = true;
            $connection->requestStop();
        };

        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);
    }

    /**
     * sleep() in 1s slices so a stop signal (or systemd restart) never
     * waits out a minutes-long backoff before being honored.
     */
    protected function interruptibleSleep(int $seconds): void
    {
        for ($i = 0; $i < $seconds && ! $this->shouldExit; $i++) {
            sleep(1);
        }
    }
}
