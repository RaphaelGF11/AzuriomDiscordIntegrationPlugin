<?php

namespace Azuriom\Plugin\DiscordIntegration\Support;

class DiscordAvatar
{
    /**
     * Build a Discord CDN avatar URL from a raw Discord user payload (or the
     * subset of it this plugin stores in session between requests: 'id',
     * 'avatar', 'discriminator'), keeping the literal "{size}" token so it
     * matches Azuriom's own convention for URL-based avatars - see
     * User::getAvatar()'s str_replace('{size}', $size, $this->avatar) in
     * app/Models/User.php.
     */
    public static function urlFrom(array $discordUser): string
    {
        $id = $discordUser['id'];
        $hash = $discordUser['avatar'] ?? null;

        if ($hash !== null) {
            $extension = str_starts_with($hash, 'a_') ? 'gif' : 'png';

            return "https://cdn.discordapp.com/avatars/{$id}/{$hash}.{$extension}?size={size}";
        }

        // No custom avatar: fall back to Discord's own default avatar, using
        // the legacy discriminator-based index if the account still has one,
        // or the modern (id >> 22) % 6 formula for migrated "username-only" accounts.
        $discriminator = (int) ($discordUser['discriminator'] ?? 0);

        $index = $discriminator > 0
            ? $discriminator % 5
            : ((int) $id >> 22) % 6;

        return "https://cdn.discordapp.com/embed/avatars/{$index}.png";
    }

    /**
     * Same as urlFrom(), but with a concrete size baked in instead of the
     * literal "{size}" token, for callers that render an <img> themselves
     * (the admin picker UI) rather than going through Azuriom's own
     * User::getAvatar() replacement.
     */
    public static function smallIconUrlFrom(array $discordUser): string
    {
        return str_replace('{size}', '32', static::urlFrom($discordUser));
    }

    /**
     * Build a Discord CDN guild icon URL, or null if the guild has no custom
     * icon set - unlike user avatars, Discord has no default guild icon to
     * fall back to, so callers should show a placeholder in that case.
     */
    public static function guildIconUrl(array $guild): ?string
    {
        $hash = $guild['icon'] ?? null;

        if ($hash === null) {
            return null;
        }

        $extension = str_starts_with($hash, 'a_') ? 'gif' : 'png';

        return "https://cdn.discordapp.com/icons/{$guild['id']}/{$hash}.{$extension}?size=32";
    }

    /**
     * Build {id, label, icon} items for the shared DiscordPicker component
     * (see resources/views/admin/partials/discord-picker-modal.blade.php)
     * from raw guild payloads (see DiscordBotClient::guilds()).
     *
     * @param  array[]  $guilds
     * @return array[]
     */
    public static function guildPickerItems(array $guilds): array
    {
        return collect($guilds)
            ->map(fn ($guild) => [
                'id' => $guild['id'],
                'label' => $guild['name'],
                'icon' => static::guildIconUrl($guild),
            ])
            ->values()
            ->all();
    }
}
