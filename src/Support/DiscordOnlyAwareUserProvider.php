<?php

namespace Azuriom\Plugin\DiscordIntegration\Support;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable as UserContract;
use Illuminate\Validation\ValidationException;

/**
 * Rejects password logins for accounts that only have the random,
 * unknown-to-anyone password generated at Discord registration time,
 * instead of silently relying on that password being unguessable. Also
 * rejects them for every account when "force Discord login" is on (see
 * Admin\AuthenticationController::forceLogin()) - that setting is meant to
 * make every account behave as if it were passwordless, not just the ones
 * that actually are.
 */
class DiscordOnlyAwareUserProvider extends EloquentUserProvider
{
    /**
     * @param  UserContract  $user
     * @param  array  $credentials
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function validateCredentials(UserContract $user, #[\SensitiveParameter] array $credentials)
    {
        if (setting('discord-integration.force_login', false) || DiscordPasswordless::isPasswordless($user)) {
            throw ValidationException::withMessages([
                'email' => trans('discord-integration::messages.password_login_disabled'),
            ]);
        }

        return parent::validateCredentials($user, $credentials);
    }
}
