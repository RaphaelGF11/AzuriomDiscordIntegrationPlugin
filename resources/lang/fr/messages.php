<?php

return [
    'navbar' => 'Connexion Discord',

    'duplicate' => 'Ce compte Discord est déjà lié à un autre compte.',
    'email_mismatch' => "Connexion réussie. Attention : l'adresse e-mail de votre compte Discord ne correspond pas à celle de votre compte.",
    'password_login_disabled' => "Ce compte n'a pas de mot de passe défini : connectez-vous avec Discord.",
    'guild_required' => 'Vous devez rejoindre notre serveur Discord avant de pouvoir vous connecter ou vous inscrire avec Discord.',
    'registration_disabled' => "Les inscriptions sont actuellement désactivées.",
    'guild_notice' => 'Vous devrez rejoindre notre serveur Discord pour lier votre compte.',

    'login' => [
        'button' => 'Se connecter avec Discord',
    ],

    'register' => [
        'button' => "S'inscrire avec Discord",
        'title' => 'Finaliser votre inscription',
        'not_found' => "Aucun compte n'est encore lié à ce Discord. Complétez les informations ci-dessous pour créer votre compte.",
        'duplicate_notice' => "Ce compte Discord est déjà lié à un autre compte, mais vous avez choisi d'en créer un nouveau quand même. Complétez les informations ci-dessous.",
        'email_help' => "Adresse e-mail de votre compte Discord, utilisée pour votre compte.",
        'password_optional' => 'Mot de passe (facultatif)',
        'password_help' => "Si vous ne définissez pas de mot de passe, vous ne pourrez vous connecter que via Discord (vous pourrez en définir un plus tard depuis votre profil).",
        'submit' => 'Créer mon compte',
        'email_used' => 'Cette adresse e-mail est déjà utilisée par un autre compte.',
    ],

    'choose' => [
        'title' => 'Plusieurs comptes sont liés à ce Discord',
        'description' => 'Choisissez le compte auquel vous souhaitez vous connecter.',
    ],

    'choose_method' => [
        'title' => 'Comment voulez-vous continuer ?',
        'sso_button' => 'Continuer avec :name',
        'discord_button' => 'Continuer avec Discord',
    ],

    'conflict' => [
        'title' => 'Ce Discord est déjà lié',
        'already_linked' => "Ce compte Discord est déjà lié à un compte existant sur le site. Vous pouvez vous connecter à ce compte, ou en créer un nouveau si les doublons sont autorisés.",
        'login' => 'Se connecter au compte existant',
        'register' => 'Créer un nouveau compte quand même',
    ],

    'confirm' => [
        'description' => "Votre compte n'a pas de mot de passe : confirmez votre identité en vous reconnectant à Discord.",
        'button' => 'Confirmer avec Discord',
        'mismatch' => "Ce n'est pas le compte Discord lié à votre profil.",
    ],

    'profile' => [
        'title' => 'Discord Login',
        'linked_as' => 'Connexion Discord liée à :name.',
        'bypass_2fa' => 'Autoriser la connexion Discord à contourner la double authentification',
        'no_password' => "Votre compte n'a pas de mot de passe défini. Vous pouvez en créer un ici pour pouvoir aussi vous connecter sans Discord.",
        'set_password' => 'Définir un mot de passe',
        'unlink_locked' => "Vous devez d'abord définir un mot de passe avant de pouvoir délier votre compte Discord, sinon vous ne pourriez plus vous connecter à votre compte.",
    ],

    'tools' => [
        'recovery_dm' => "Bonjour ! Un administrateur de :site a généré un nouveau mot de passe pour votre compte :\n\n:password\n\nIl vous sera demandé de le changer lors de votre prochaine connexion avec celui-ci.",
        'recovery_codes_dm' => "Bonjour ! Un administrateur de :site a régénéré vos codes de secours de double authentification. Vos anciens codes ne fonctionnent plus. Voici vos nouveaux codes — conservez-les en lieu sûr :\n\n:codes",
    ],

    'interactions' => [
        'unsupported' => "Ce type d'interaction n'est pas pris en charge.",
        'not_found' => "Cette commande n'est plus disponible.",
        'forbidden' => "Vous ne remplissez pas les conditions pour utiliser cette commande.",
        'not_linked' => "Liez d'abord votre compte Discord sur le site.",
        'form_not_found' => "Ce formulaire n'est plus disponible.",
        'done' => '✅',
    ],
];
