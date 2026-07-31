<?php

namespace Azuriom\Plugin\DiscordIntegration\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A rule granting a Discord guild role to any user matching its conditions
 * (all set conditions are ANDed within a rule - see RoleSyncEvaluator).
 *
 * @property int $id
 * @property string $discord_guild_id
 * @property string $discord_role_id
 * @property bool $overwrite
 * @property int[]|null $site_role_ids
 * @property int|null $shop_package_id
 * @property float|null $balance_min
 * @property float|null $balance_max
 */
class RoleSync extends Model
{
    protected $table = 'discord_integration_role_syncs';

    protected $fillable = [
        'discord_guild_id', 'discord_role_id', 'overwrite', 'site_role_ids',
        'shop_package_id', 'balance_min', 'balance_max',
    ];

    protected $casts = [
        'overwrite' => 'boolean',
        'site_role_ids' => 'array',
        'shop_package_id' => 'integer',
        'balance_min' => 'float',
        'balance_max' => 'float',
    ];
}
