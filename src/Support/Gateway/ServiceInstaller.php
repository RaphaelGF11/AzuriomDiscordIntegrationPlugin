<?php

namespace Azuriom\Plugin\DiscordIntegration\Support\Gateway;

use Symfony\Component\Process\Process;
use Throwable;

/**
 * Everything around installing the gateway daemon's systemd unit. The web
 * side only DISPLAYS things (the unit file, the service's installed/active
 * state, and the one-line "sudo php .../artisan
 * discord-integration:install-gateway-service" command) - the actual
 * privileged install only ever happens in the admin's own SSH session via
 * that artisan command (see InstallGatewayServiceCommand), deliberately:
 * a web process that can escalate to root is exactly what a careful
 * sysadmin doesn't want to find on their machine.
 */
class ServiceInstaller
{
    public const UNIT_PATH = '/etc/systemd/system/azuriom-discord-gateway.service';

    public const SERVICE_NAME = 'azuriom-discord-gateway';

    /**
     * The unit's install state, for the tutorial section to show either
     * "already installed (+ running or not)" or the install instructions.
     * Both checks work unprivileged (the unit file is world-readable,
     * "systemctl is-active" needs no rights).
     *
     * @return array{installed: bool, active: bool}
     */
    public static function detect(): array
    {
        $installed = file_exists(self::UNIT_PATH);

        return [
            'installed' => $installed,
            'active' => $installed && static::serviceActive(),
        ];
    }

    /**
     * Install and start the unit - the caller (InstallGatewayServiceCommand)
     * guarantees this already runs as root, so this is just the plain
     * steps, no privilege escalation of any kind.
     *
     * @return array{success: bool, output: string}
     */
    public static function installAsRoot(): array
    {
        $unitFile = storage_path('app/azuriom-discord-gateway.service.tmp');

        try {
            file_put_contents($unitFile, static::unitFileContent());

            $script = 'set -e'."\n"
                .'install -m 644 '.escapeshellarg($unitFile).' '.escapeshellarg(self::UNIT_PATH)."\n"
                .'systemctl daemon-reload'."\n"
                .'systemctl enable --now '.self::SERVICE_NAME.'.service'."\n";

            $process = new Process(['bash', '-c', $script]);
            $process->setTimeout(60);
            $process->run();

            return [
                'success' => $process->isSuccessful(),
                'output' => trim($process->getOutput()."\n".$process->getErrorOutput()),
            ];
        } catch (Throwable $e) {
            return ['success' => false, 'output' => $e->getMessage()];
        } finally {
            @unlink($unitFile);
        }
    }

    /**
     * The unit file, shared between the tutorial's copy-paste block and the
     * install-gateway-service artisan command so they can never drift
     * apart. Kept generic (multi-user.target, no distribution-specific web
     * server unit) since it has to work on any systemd machine Azuriom
     * runs on.
     */
    public static function unitFileContent(): string
    {
        $php = static::phpBinary();
        $basePath = base_path();

        return <<<UNIT
            [Unit]
            Description=Azuriom Discord Gateway
            After=network-online.target mysql.service
            Wants=network-online.target

            [Service]
            Type=simple
            User={$php['user']}
            WorkingDirectory={$basePath}
            ExecStart={$php['binary']} artisan discord-integration:gateway
            Restart=always
            RestartSec=5

            [Install]
            WantedBy=multi-user.target
            UNIT."\n";
    }

    /**
     * The one-line SSH install command shown on the Gateway page: the
     * install-gateway-service artisan command run with the SSH user's own
     * privileges ("sudo" prefixed only when sudo exists on this machine).
     */
    public static function cliInstallCommand(): string
    {
        $sudo = static::binaryExists('sudo') ? 'sudo ' : '';

        return $sudo.static::phpBinary()['binary'].' '.base_path('artisan').' discord-integration:install-gateway-service';
    }

    /**
     * The PHP CLI binary for the ExecStart line. Under PHP-FPM, PHP_BINARY
     * is the php-fpm binary itself - useless for a CLI daemon - so fall
     * back to plain "php" (the tutorial hints at verifying with "which php").
     */
    public static function phpBinary(): array
    {
        $binary = PHP_BINARY !== '' && ! str_contains(basename(PHP_BINARY), 'fpm') ? PHP_BINARY : 'php';

        return ['binary' => $binary, 'user' => static::serviceUser()];
    }

    /**
     * The user the daemon should run as. Normally the web server process's
     * own user - but when this code runs as ROOT (the install-gateway-service
     * artisan command via sudo), "current user" would wrongly put root on
     * the unit's User= line, so the owner of storage/ (the user the site
     * actually runs/writes as) is used instead.
     */
    protected static function serviceUser(): string
    {
        $current = static::currentUser();

        if ($current !== 'root') {
            return $current;
        }

        $owner = @fileowner(storage_path());

        if ($owner !== false && function_exists('posix_getpwuid')) {
            return posix_getpwuid($owner)['name'] ?? 'www-data';
        }

        return 'www-data';
    }

    protected static function currentUser(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            return posix_getpwuid(posix_geteuid())['name'] ?? 'www-data';
        }

        return get_current_user() ?: 'www-data';
    }

    protected static function serviceActive(): bool
    {
        try {
            $process = new Process(['systemctl', 'is-active', '--quiet', self::SERVICE_NAME]);
            $process->setTimeout(10);
            $process->run();

            return $process->isSuccessful();
        } catch (Throwable) {
            return false;
        }
    }

    protected static function binaryExists(string $binary): bool
    {
        foreach (['/usr/bin/', '/bin/', '/usr/sbin/', '/sbin/', '/usr/local/bin/'] as $dir) {
            if (is_executable($dir.$binary)) {
                return true;
            }
        }

        return false;
    }
}
