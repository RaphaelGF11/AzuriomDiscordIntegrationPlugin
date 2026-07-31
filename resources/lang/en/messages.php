<?php

return [
    'navbar' => 'Discord Login',

    'duplicate' => 'This Discord account is already linked to another account.',
    'email_mismatch' => "Login successful. Warning: your Discord account's email address does not match your account's email.",
    'password_login_disabled' => 'This account has no password set: log in with Discord instead.',
    'guild_required' => 'You need to join our Discord server before you can log in or register with Discord.',
    'registration_disabled' => 'Registration is currently disabled.',
    'guild_notice' => 'You will need to join our Discord server to link your account.',

    'login' => [
        'button' => 'Log in with Discord',
    ],

    'register' => [
        'button' => 'Sign up with Discord',
        'title' => 'Complete your registration',
        'not_found' => 'No account is linked to this Discord yet. Complete the information below to create your account.',
        'duplicate_notice' => 'This Discord account is already linked to another account, but you chose to create a new one anyway. Complete the information below.',
        'email_help' => 'Your Discord account email, used for your account.',
        'password_optional' => 'Password (optional)',
        'password_help' => "If you don't set a password, you will only be able to log in via Discord (you can set one later from your profile).",
        'submit' => 'Create my account',
        'email_used' => 'This email address is already used by another account.',
    ],

    'choose' => [
        'title' => 'Several accounts are linked to this Discord',
        'description' => 'Choose which account you want to log into.',
    ],

    'choose_method' => [
        'title' => 'How do you want to continue?',
        'sso_button' => 'Continue with :name',
        'discord_button' => 'Continue with Discord',
    ],

    'conflict' => [
        'title' => 'This Discord is already linked',
        'already_linked' => 'This Discord account is already linked to an existing account on the site. You can log into that account, or create a new one if duplicates are allowed.',
        'login' => 'Log into the existing account',
        'register' => 'Create a new account anyway',
    ],

    'confirm' => [
        'description' => "Your account has no password: confirm your identity by logging back into Discord.",
        'button' => 'Confirm with Discord',
        'mismatch' => 'This is not the Discord account linked to your profile.',
    ],

    'profile' => [
        'title' => 'Discord Login',
        'linked_as' => 'Discord login linked to :name.',
        'bypass_2fa' => 'Allow Discord login to bypass two-factor authentication',
        'no_password' => 'Your account has no password set. You can create one here to also be able to log in without Discord.',
        'set_password' => 'Set a password',
        'unlink_locked' => 'You must set a password first before unlinking your Discord account, otherwise you would no longer be able to log into your account.',
    ],

    'tools' => [
        'recovery_dm' => "Hi! An administrator of :site generated a new password for your account:\n\n:password\n\nYou will be asked to change it the next time you log in with it.",
        'recovery_codes_dm' => "Hi! An administrator of :site regenerated your two-factor authentication recovery codes. Your previous codes no longer work. Here are your new ones - keep them somewhere safe:\n\n:codes",
    ],

    'interactions' => [
        'unsupported' => "This interaction type isn't supported.",
        'not_found' => 'This command is no longer available.',
        'forbidden' => "You don't meet the requirements to use this command.",
        'not_linked' => 'Link your Discord account on the site first.',
        'form_not_found' => 'This form is no longer available.',
        'done' => '✅',
    ],
];
