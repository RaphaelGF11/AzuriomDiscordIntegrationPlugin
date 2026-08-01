<?php

namespace Azuriom\Plugin\DiscordIntegration\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

/**
 * One entry in the admin "Journal" page - see Support\PluginLog, the only
 * thing that creates these. Immutable once written (no updated_at), and
 * self-pruning after 30 days via the Prunable trait, scheduled alongside
 * this plugin's other periodic jobs in DiscordIntegrationServiceProvider.
 *
 * @property string $level "info"|"warning"|"error"
 * @property string $message
 * @property int|null $status the HTTP status Discord responded with, when
 *                             this entry came from a failed API call
 * @property string|null $discord_guild_id
 * @property string|null $discord_role_id
 * @property array|null $context free-form extra data (request path, response
 *                                body, exception class...) shown on the Journal page
 */
class LogEntry extends Model
{
    use Prunable;

    public const UPDATED_AT = null;

    protected $table = 'discord_integration_logs';

    protected $fillable = [
        'level', 'message', 'status', 'discord_guild_id', 'discord_role_id', 'context', 'created_at',
    ];

    protected $casts = [
        'context' => 'array',
        'created_at' => 'datetime',
    ];

    public function prunable()
    {
        return static::where('created_at', '<', now()->subDays(30));
    }

    /**
     * Whether there's a recent Discord 403 tied to this exact (guild, role)
     * pair - a 403 on a role-scoped call is Discord's "you don't have
     * permission to manage this" response, almost always meaning the bot's
     * own role is missing "Manage Roles" or sits below the target role in
     * the guild's hierarchy. Used by the Roles admin page to surface that
     * directly on the rule, instead of an admin having to notice a role
     * with a matching rule and zero members and go digging through logs.
     *
     * A 6h window: long enough to survive a few retries of the 15-minute
     * scheduled sweep without needing every single run to have just failed,
     * short enough that fixing the permission on Discord's side clears the
     * warning within one sweep or two rather than it lingering for a day.
     */
    public static function recentPermissionError(string $guildId, string $roleId): ?self
    {
        return static::where('discord_guild_id', $guildId)
            ->where('discord_role_id', $roleId)
            ->where('status', 403)
            ->where('created_at', '>=', now()->subHours(6))
            ->latest('created_at')
            ->first();
    }
}
