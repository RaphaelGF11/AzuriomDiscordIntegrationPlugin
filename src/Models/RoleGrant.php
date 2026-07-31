<?php

namespace Azuriom\Plugin\DiscordIntegration\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Marks a Discord guild role as having been granted by the plugin's own role
 * sync to a given Discord user - see RoleSyncEvaluator, which is the only
 * thing that creates/deletes these. Used to tell apart a role the plugin gave
 * from one the member already held independently, so revoking eligibility
 * (or unlinking) only retracts the former by default.
 *
 * @property string $discord_user_id
 * @property string $discord_guild_id
 * @property string $discord_role_id
 */
class RoleGrant extends Model
{
    protected $table = 'discord_integration_role_grants';

    protected $fillable = [
        'discord_user_id', 'discord_guild_id', 'discord_role_id',
    ];
}
