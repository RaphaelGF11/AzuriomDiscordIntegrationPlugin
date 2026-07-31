<?php

namespace Azuriom\Plugin\DiscordIntegration\Support\Gateway;

use Azuriom\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use WebSocket\Client;

/**
 * One Discord Gateway (WebSocket) session: Hello/Identify (or Resume),
 * heartbeats, presence updates, and dispatch events fed to
 * GatewayEventHandler. run() blocks until the session ends and returns an
 * outcome for GatewayCommand's supervision loop, which owns reconnection -
 * session state (session id, resume URL, last sequence) lives on this
 * object across run() calls so a drop can be resumed instead of paying a
 * full re-identify (Discord caps identifies at 1000/day).
 *
 * Settings are re-read from the database directly (never through the
 * setting() helper): Azuriom loads settings once at boot into an in-memory
 * repository that is never refreshed in console mode, so setting() would
 * return week-old values in a long-lived daemon. Reading through the
 * Setting *model* keeps its accessor (decryption) behavior.
 */
class GatewayConnection
{
    protected const GATEWAY_URL = 'wss://gateway.discord.gg/?v=10&encoding=json';

    // GUILDS (1 << 0) | GUILD_MEMBERS (1 << 1) - members is a privileged
    // intent, but the plugin's REST member features already require it.
    protected const INTENTS = 3;

    public const OUTCOME_DISABLED = 'disabled';
    public const OUTCOME_RETRY = 'retry';
    public const OUTCOME_FATAL = 'fatal';

    /**
     * Close codes after which Discord forbids simply reconnecting (bad
     * token, bad intents, ...) - retrying fast would just loop, so the
     * supervisor backs off for minutes and the admin page shows the error.
     */
    protected const FATAL_CLOSE_CODES = [4004, 4010, 4011, 4012, 4013, 4014];

    /**
     * Close codes that invalidate the session (a resume would be rejected)
     * but allow a fresh identify right away.
     */
    protected const REIDENTIFY_CLOSE_CODES = [4007, 4009];

    protected ?Client $client = null;

    protected ?string $token = null;

    protected ?float $heartbeatInterval = null;

    protected float $nextHeartbeatAt = 0.0;

    protected bool $awaitingAck = false;

    protected ?int $sequence = null;

    protected ?string $sessionId = null;

    protected ?string $resumeUrl = null;

    /** Whether READY/RESUMED has been reached in the current run. */
    protected bool $live = false;

    protected string $outcome = self::OUTCOME_RETRY;

    protected bool $stopRequested = false;

    protected string $presenceHash = '';

    protected float $nextSettingsPollAt = 0.0;

    protected float $nextDbPingAt = 0.0;

    protected float $nextGcAt = 0.0;

    public function __construct(protected GatewayEventHandler $events)
    {
        $events->setChunkRequester(fn (string $guildId) => $this->requestMemberChunks($guildId));
    }

    /**
     * The gateway settings (and resolved bot token), read fresh from the
     * database - see the class docblock for why setting() can't be used.
     *
     * @return array{enabled: bool, token: ?string, status: string, activity_type: string, activity_text: ?string}
     */
    public static function freshSettings(): array
    {
        $values = Setting::whereIn('name', [
            'discord-integration.gateway_enabled',
            'discord-integration.gateway_presence_status',
            'discord-integration.gateway_activity_type',
            'discord-integration.gateway_activity_text',
            'discord-integration.use_custom_credentials',
            'discord-integration.bot_token',
            'discord.token',
        ])->get()->pluck('value', 'name');

        // Same resolution as DiscordCredentials::botToken(), re-implemented
        // on fresh reads so a token change applies without restarting the
        // daemon (a stale token would identify-loop against close 4004).
        $token = $values['discord-integration.use_custom_credentials'] ?? false
            ? ($values['discord-integration.bot_token'] ?? null)
            : ($values['discord.token'] ?? null);

        return [
            'enabled' => (bool) ($values['discord-integration.gateway_enabled'] ?? false),
            'token' => $token !== null && $token !== '' ? $token : null,
            'status' => $values['discord-integration.gateway_presence_status'] ?? 'online',
            'activity_type' => $values['discord-integration.gateway_activity_type'] ?? 'none',
            'activity_text' => $values['discord-integration.gateway_activity_text'] ?? null,
        ];
    }

    /**
     * Run one gateway session to completion. Returns an OUTCOME_* constant
     * telling the supervisor how to proceed (normal backoff, long fatal
     * backoff, or back to the disabled idle loop).
     */
    public function run(): string
    {
        $this->outcome = self::OUTCOME_RETRY;
        $this->live = false;
        $this->heartbeatInterval = null;
        $this->awaitingAck = false;

        $settings = static::freshSettings();
        $this->token = $settings['token'];
        $this->presenceHash = $this->hashPresence($settings);

        if (! $settings['enabled'] || $this->token === null) {
            return self::OUTCOME_DISABLED;
        }

        $resuming = $this->sessionId !== null && $this->resumeUrl !== null;

        if (! $resuming) {
            // Fresh identify: Discord is about to re-send the full state
            // (every GUILD_CREATE), so drop whatever the old session cached.
            $this->sequence = null;
            $this->events->reset();
        }

        $url = $resuming ? $this->withGatewayQuery($this->resumeUrl) : self::GATEWAY_URL;

        $client = new Client($url);
        $this->client = $client;

        // 5s to establish the connection; once connected, drop to 1s so the
        // tick loop (heartbeats, settings poll) runs at least once a second.
        $client->setTimeout(5);
        $client->onConnect(function (Client $client) {
            $client->setTimeout(1);
        });
        $client->onText(function ($client, $connection, $message) {
            $this->handleFrame($message->getContent());
        });
        $client->onClose(function ($client, $connection, $close) {
            $this->handleClose($close->getCloseStatus());
        });
        $client->onError(function ($client, $connection, $exception) {
            GatewayCache::markDisconnected($exception->getMessage());
        });
        $client->onTick(function () {
            $this->tick();
        });

        try {
            $client->start();
        } catch (Throwable $e) {
            GatewayCache::markDisconnected($e->getMessage());
            Log::warning('discord-integration: gateway connection failed', ['error' => $e->getMessage()]);
        } finally {
            try {
                $client->disconnect();
            } catch (Throwable) {
                // Already gone - nothing left to close.
            }

            $this->client = null;
        }

        if ($this->outcome === self::OUTCOME_DISABLED) {
            // Clean shutdown: no session to resume, and the cached data
            // shouldn't outlive the feature being turned off.
            $this->sessionId = null;
            $this->resumeUrl = null;
            $this->events->reset();
            GatewayCache::markDisconnected();
        }

        return $this->outcome;
    }

    /**
     * Ask the current session to stop (signal handler / shutdown path) - a
     * clean close without keeping a resumable session.
     */
    public function requestStop(): void
    {
        $this->stopRequested = true;
        $this->sessionId = null;
        $this->resumeUrl = null;
        $this->closeSocket(1000);
    }

    protected function handleFrame(string $json): void
    {
        $frame = json_decode($json, true);

        if (! is_array($frame)) {
            return;
        }

        if (isset($frame['s'])) {
            $this->sequence = $frame['s'];
        }

        $data = is_array($frame['d'] ?? null) ? $frame['d'] : [];

        switch ($frame['op'] ?? null) {
            case 10: // Hello
                $this->heartbeatInterval = ($data['heartbeat_interval'] ?? 41250) / 1000;
                // First heartbeat after interval * jitter (per the docs, so
                // a mass reconnect doesn't thundering-herd Discord).
                $this->nextHeartbeatAt = microtime(true) + $this->heartbeatInterval * (mt_rand(0, 1000) / 1000);
                $this->awaitingAck = false;

                $this->sessionId !== null ? $this->sendResume() : $this->sendIdentify();
                break;

            case 11: // Heartbeat ACK
                $this->awaitingAck = false;
                break;

            case 1: // Discord requests an immediate heartbeat
                $this->sendHeartbeat();
                break;

            case 7: // Reconnect: close and resume on a fresh socket
                $this->closeSocket(4000);
                break;

            case 9: // Invalid session ("d" says whether it's resumable)
                if (! ($frame['d'] ?? false)) {
                    $this->sessionId = null;
                    $this->resumeUrl = null;
                    // The docs mandate a short random wait before the fresh
                    // identify that follows.
                    usleep(mt_rand(2000, 5000) * 1000);
                }

                $this->closeSocket(4000);
                break;

            case 0: // Dispatch
                $this->handleDispatch($frame['t'] ?? '', $data);
                break;
        }
    }

    protected function handleDispatch(string $event, array $data): void
    {
        if ($event === 'READY') {
            $this->sessionId = $data['session_id'] ?? null;
            $this->resumeUrl = $data['resume_gateway_url'] ?? null;
            $this->live = true;
            GatewayCache::markConnected($this->sessionId);

            return;
        }

        if ($event === 'RESUMED') {
            $this->live = true;
            GatewayCache::markConnected($this->sessionId);

            return;
        }

        $this->events->handle($event, $data);
    }

    protected function handleClose(?int $code): void
    {
        if (in_array($code, self::FATAL_CLOSE_CODES, true)) {
            $this->outcome = self::OUTCOME_FATAL;
            $this->sessionId = null;
            $this->resumeUrl = null;
            GatewayCache::markDisconnected($this->fatalCloseMessage($code));
        } elseif (in_array($code, self::REIDENTIFY_CLOSE_CODES, true)) {
            $this->sessionId = null;
            $this->resumeUrl = null;
            GatewayCache::markDisconnected("Discord a fermé la connexion (code {$code})");
        } else {
            // Resumable close - keep the session, the supervisor's next
            // run() will resume it.
            GatewayCache::markDisconnected($code !== null ? "Discord a fermé la connexion (code {$code})" : null);
        }

        try {
            $this->client?->stop();
        } catch (Throwable) {
            // The loop is exiting anyway.
        }
    }

    protected function fatalCloseMessage(?int $code): string
    {
        return match ($code) {
            4004 => trans('discord-integration::admin.gateway.error_bad_token'),
            4013, 4014 => trans('discord-integration::admin.gateway.error_bad_intents'),
            default => "Discord a refusé la connexion (code {$code})",
        };
    }

    /**
     * The once-a-second housekeeping loop, driven by the client's tick
     * event: heartbeats, settings polling (live toggle/presence), status
     * heartbeat, debounced role syncs, DB keep-alive and GC.
     */
    protected function tick(): void
    {
        $now = microtime(true);

        if ($this->stopRequested) {
            $this->closeSocket(1000);

            return;
        }

        if ($this->heartbeatInterval !== null && $now >= $this->nextHeartbeatAt) {
            if ($this->awaitingAck) {
                // Zombie connection: the last heartbeat was never ACKed.
                // Close with a non-1000 code and let the supervisor resume.
                GatewayCache::markDisconnected('heartbeat sans réponse');
                $this->closeSocket(4000);

                return;
            }

            $this->sendHeartbeat();
            $this->nextHeartbeatAt = $now + $this->heartbeatInterval;
        }

        if ($now >= $this->nextSettingsPollAt) {
            $this->nextSettingsPollAt = $now + 5;

            if ($this->live) {
                GatewayCache::heartbeat();
            }

            try {
                $settings = static::freshSettings();
            } catch (Throwable $e) {
                // A dropped DB connection here shouldn't kill the gateway
                // session - the periodic ping below will restore it.
                Log::warning('discord-integration: gateway settings poll failed', ['error' => $e->getMessage()]);

                return;
            }

            if (! $settings['enabled'] || $settings['token'] === null) {
                $this->outcome = self::OUTCOME_DISABLED;
                $this->closeSocket(1000);

                return;
            }

            $hash = $this->hashPresence($settings);

            if ($hash !== $this->presenceHash && $this->live) {
                $this->presenceHash = $hash;
                $this->send(['op' => 3, 'd' => $this->presencePayload($settings)]);
            }

            $this->events->flushPendingReconciles();
        }

        if ($now >= $this->nextDbPingAt) {
            $this->nextDbPingAt = $now + 60;

            try {
                DB::select('select 1');
            } catch (Throwable) {
                try {
                    DB::reconnect();
                } catch (Throwable $e) {
                    Log::warning('discord-integration: gateway DB reconnect failed', ['error' => $e->getMessage()]);
                }
            }
        }

        if ($now >= $this->nextGcAt) {
            $this->nextGcAt = $now + 300;
            gc_collect_cycles();
        }
    }

    protected function sendIdentify(): void
    {
        $this->send(['op' => 2, 'd' => [
            'token' => $this->token,
            'intents' => self::INTENTS,
            'properties' => [
                'os' => PHP_OS_FAMILY,
                'browser' => 'azuriom-discord-integration',
                'device' => 'azuriom-discord-integration',
            ],
            'presence' => $this->presencePayload(static::freshSettings()),
        ]]);
    }

    protected function sendResume(): void
    {
        $this->send(['op' => 6, 'd' => [
            'token' => $this->token,
            'session_id' => $this->sessionId,
            'seq' => $this->sequence,
        ]]);
    }

    protected function sendHeartbeat(): void
    {
        $this->awaitingAck = true;
        $this->send(['op' => 1, 'd' => $this->sequence]);
    }

    protected function requestMemberChunks(string $guildId): void
    {
        $this->send(['op' => 8, 'd' => [
            'guild_id' => $guildId,
            'query' => '',
            'limit' => 0,
        ]]);
    }

    /**
     * @see https://discord.com/developers/docs/events/gateway-events#update-presence
     */
    protected function presencePayload(array $settings): array
    {
        $text = trim((string) $settings['activity_text']);
        $type = $settings['activity_type'];
        $activities = [];

        if ($text !== '' && $type !== 'none') {
            // A custom status is activity type 4 with the text in "state"
            // ("name" is required by the API but never displayed for that
            // type); every other type shows "name" after its verb.
            $activities[] = $type === 'custom'
                ? ['name' => 'Custom Status', 'state' => $text, 'type' => 4]
                : ['name' => $text, 'type' => match ($type) {
                    'listening' => 2,
                    'watching' => 3,
                    'competing' => 5,
                    default => 0, // playing
                }];
        }

        return [
            'since' => null,
            'activities' => $activities,
            'status' => $settings['status'],
            'afk' => false,
        ];
    }

    protected function hashPresence(array $settings): string
    {
        return $settings['status'].'|'.$settings['activity_type'].'|'.($settings['activity_text'] ?? '');
    }

    protected function send(array $frame): void
    {
        try {
            $this->client?->text(json_encode($frame));
        } catch (Throwable $e) {
            Log::warning('discord-integration: gateway send failed', ['error' => $e->getMessage()]);
        }
    }

    protected function closeSocket(int $code): void
    {
        try {
            $this->client?->close($code);
        } catch (Throwable) {
            // Socket may already be gone.
        }

        try {
            $this->client?->stop();
        } catch (Throwable) {
            // Loop may already be stopping.
        }
    }

    protected function withGatewayQuery(string $url): string
    {
        return str_contains($url, '?') ? $url : $url.'/?v=10&encoding=json';
    }
}
