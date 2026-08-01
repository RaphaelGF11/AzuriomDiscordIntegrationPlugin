<?php

namespace Azuriom\Plugin\DiscordIntegration\Support;

use Azuriom\Plugin\DiscordIntegration\Models\LogEntry;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes to both the standard Laravel log (for sysadmins tailing files or
 * shipping them to an external aggregator - unchanged from before this
 * existed) and the admin-visible "Journal" page's own table (LogEntry),
 * from the same call. A single write site rather than duplicating the
 * message text at every failure point that wants both.
 *
 * guild/role id and the HTTP status are pulled out of $context here (once)
 * rather than at every call site, from the two shapes call sites already
 * pass: an explicit 'guild_id'/'role_id' key (script actions, role sync),
 * or a Discord API request's 'path' (DiscordBotClient's request logging) -
 * a role-scoped path always ends in "/roles/{role id}", which is exactly
 * (and only) the shape Models\LogEntry::recentPermissionError() needs to
 * surface a permission problem directly on the Roles admin page.
 */
class PluginLog
{
    public static function info(string $message, array $context = []): void
    {
        static::write('info', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        static::write('warning', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        static::write('error', $message, $context);
    }

    protected static function write(string $level, string $message, array $context): void
    {
        Log::log($level, 'discord-integration: '.$message, $context);

        try {
            [$guildId, $roleId] = static::extractGuildAndRole($context);

            LogEntry::create([
                'level' => $level,
                'message' => $message,
                'status' => $context['status'] ?? null,
                'discord_guild_id' => $guildId,
                'discord_role_id' => $roleId,
                'context' => $context !== [] ? $context : null,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            // The journal is a convenience, never a dependency - a DB
            // hiccup writing it must not throw away (or replace) the
            // Log::log() call above, which already got through.
            report($e);
        }
    }

    /**
     * @return array{0: ?string, 1: ?string} [guild id, role id]
     */
    protected static function extractGuildAndRole(array $context): array
    {
        $guildId = $context['guild_id'] ?? null;
        $roleId = $context['role_id'] ?? null;

        if (($guildId === null || $roleId === null) && isset($context['path']) && is_string($context['path'])) {
            if ($guildId === null && preg_match('#/guilds/(\d+)#', $context['path'], $match)) {
                $guildId = $match[1];
            }

            // Deliberately only matches a *trailing* role id (assign/remove
            // a specific role), not "/guilds/{id}/roles" (list all roles) -
            // a failure listing roles isn't about any one role, so it
            // shouldn't attribute itself to one on the Roles page.
            if ($roleId === null && preg_match('#/roles/(\d+)$#', $context['path'], $match)) {
                $roleId = $match[1];
            }
        }

        return [$guildId, $roleId];
    }
}
