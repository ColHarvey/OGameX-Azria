<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
     * Le traducteur du chat general.
     *
     * **Adresse interne, jamais publique.** LibreTranslate tourne a cote du jeu sur le reseau
     * Docker et n a aucun port publie : seul le serveur lui parle, jamais le navigateur. Le
     * joueur passe par une route du jeu, qui exige une session et borne le debit.
     *
     * Vide, la fonctionnalite s eteint proprement : le bouton n est pas affiche.
     */
    'libretranslate' => [
        'url' => env('LIBRETRANSLATE_URL', ''),
        'timeout' => (int)env('LIBRETRANSLATE_TIMEOUT', 8),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
