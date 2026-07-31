<?php

namespace Azuriom\Plugin\DiscordIntegration\Support;

use Azuriom\Extensions\Plugin\UserProfileCardComposer;
use Illuminate\Support\Facades\Auth;

class DiscordIntegrationProfileCard extends UserProfileCardComposer
{
    /**
     * Get the cards to add to the user profile.
     *
     * @return array{name: string, view: string, data?: array}[]
     */
    public function getCards()
    {
        $user = Auth::user();
        $account = $user?->discordAccount;

        if ($account === null) {
            return [];
        }

        return [
            [
                'name' => trans('discord-integration::messages.profile.title'),
                'view' => 'discord-integration::profile.card',
                'data' => [
                    'discordAccount' => $account,
                    'showBypass2fa' => $user->hasTwoFactorAuth(),
                    'passwordless' => DiscordPasswordless::isPasswordless($user),
                ],
            ],
        ];
    }
}
