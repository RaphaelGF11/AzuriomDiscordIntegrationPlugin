<?php

namespace Azuriom\Plugin\DiscordIntegration\Models;

use Azuriom\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An admin-declared Discord link with no OAuth proof yet - see the
 * create_discord_integration_pending_links_table migration and
 * Admin\LinkController for how these are created, and
 * DiscordIntegrationController::callback() for how they get promoted into a
 * real DiscordAccount.
 *
 * @property int $id
 * @property int $user_id
 * @property string $discord_user_id
 * @property string|null $discord_username
 * @property string|null $discord_avatar
 */
class PendingLink extends Model
{
    protected $table = 'discord_integration_pending_links';

    protected $fillable = [
        'user_id', 'discord_user_id', 'discord_username', 'discord_avatar',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
