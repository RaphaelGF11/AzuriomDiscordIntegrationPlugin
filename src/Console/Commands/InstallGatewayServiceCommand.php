<?php

namespace Azuriom\Plugin\DiscordIntegration\Console\Commands;

use Azuriom\Plugin\DiscordIntegration\Support\Gateway\ServiceInstaller;
use Illuminate\Console\Command;

/**
 * The one-line SSH fallback of the Gateway page's "install automatically"
 * button, for when the web server process can't reach root itself: the
 * admin runs this with their own privileges instead (sudo php artisan
 * discord-integration:install-gateway-service - the exact line, with real
 * paths, is shown on the admin Gateway page). Installs the same systemd
 * unit the page's tutorial shows, reloads systemd, and enables + starts
 * the service.
 */
class InstallGatewayServiceCommand extends Command
{
    protected $signature = 'discord-integration:install-gateway-service';

    protected $description = 'Install and enable the azuriom-discord-gateway systemd service (must be run as root, e.g. through sudo)';

    public function handle(): int
    {
        $isRoot = function_exists('posix_geteuid') ? posix_geteuid() === 0 : get_current_user() === 'root';

        if (! $isRoot) {
            $this->error('This command must be run as root - e.g.: sudo '.PHP_BINARY.' '.base_path('artisan').' discord-integration:install-gateway-service');

            return self::FAILURE;
        }

        $result = ServiceInstaller::installAsRoot();

        if (! $result['success']) {
            $this->error('Installation failed:');
            $this->line($result['output']);

            return self::FAILURE;
        }

        $this->info('Service '.ServiceInstaller::SERVICE_NAME.' installed, enabled and started.');
        $this->line('Check it with: systemctl status '.ServiceInstaller::SERVICE_NAME);

        return self::SUCCESS;
    }
}
