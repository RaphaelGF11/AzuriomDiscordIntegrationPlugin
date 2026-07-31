<?php

namespace Azuriom\Plugin\DiscordIntegration\Support;

use Azuriom\Models\User;

/**
 * Whether an account is still relying on the random password generated at
 * Discord registration time, determined by comparing the user's current
 * password hash against the one stored at registration - instead of tracking
 * a separate "is passwordless" flag that every password-changing code path
 * (profile, admin edit, forgot-password, this plugin's own setPassword()...)
 * would otherwise have to remember to clear. However the password ends up
 * changing, it stops matching the stored hash and the account is no longer
 * considered passwordless, automatically.
 */
class DiscordPasswordless
{
    public static function isPasswordless(?User $user): bool
    {
        return $user !== null
            && $user->discord_integration_generated_password !== null
            && $user->discord_integration_generated_password === $user->password;
    }
}
