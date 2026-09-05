<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Broadcaster
    |--------------------------------------------------------------------------
    |
    | This option controls the default broadcaster that will be used by the
    | framework when an event needs to be broadcast. You may set this to
    | any of the connections defined in the "connections" array below.
    |
    | Supported: "reverb", "pusher", "ably", "redis", "log", "null"
    |
    */

    'default' => env('BROADCAST_CONNECTION', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Broadcast Connections
    |--------------------------------------------------------------------------
    |
    | Here you may define all of the broadcast connections that will be used
    | to broadcast events to other systems or over WebSockets. Samples of
    | each available type of connection are provided inside this array.
    |
    */

    'connections' => [
        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                'host' => env('REVERB_HOST'),
                'port' => env('REVERB_PORT', 443),
                'scheme' => env('REVERB_SCHEME', 'https'),
                'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
            ],
            // **Ce que le navigateur recoit.** Le PHP parle au serveur Reverb par `options` — en
            // production le conteneur voisin, en HTTP — tandis que le navigateur passe par l'hote
            // public, en TLS. Les deux adresses ne coincident que sur un poste de developpement ;
            // ces trois valeurs retombent sur `options` quand rien ne les distingue.
            //
            // Le layout les lit par `config()`, jamais par `env()` : sous `config:cache`, Laravel ne
            // charge plus `.env`, et `env()` ne rend que l'environnement du processus — vide dans le
            // conteneur de production.
            'client' => [
                'host' => env('REVERB_CLIENT_HOST', env('REVERB_HOST')),
                'port' => env('REVERB_CLIENT_PORT', env('REVERB_PORT', 443)),
                'scheme' => env('REVERB_CLIENT_SCHEME', env('REVERB_SCHEME', 'https')),
            ],
            'client_options' => [
                // Guzzle client options: https://docs.guzzlephp.org/en/stable/request-options.html
                //
                // Un appel au serveur de diffusion est borne : sans cela, un diffuseur suspendu sur un
                // serveur muet tiendrait son bail sans battre, et sa releve attendrait la fin de
                // l'attente reseau, pas la tolerance du bail. Cinq secondes suffisent a un aller-retour
                // local ; au-dela, le lot est tenu pour non parti et repartira.
                'connect_timeout' => 2,
                'timeout' => 5,
            ],
        ],

        'pusher' => [
            'driver' => 'pusher',
            'key' => env('PUSHER_APP_KEY'),
            'secret' => env('PUSHER_APP_SECRET'),
            'app_id' => env('PUSHER_APP_ID'),
            'options' => [
                'cluster' => env('PUSHER_APP_CLUSTER'),
                'host' => env('PUSHER_HOST') ?: 'api-' . env('PUSHER_APP_CLUSTER', 'mt1') . '.pusher.com',
                'port' => env('PUSHER_PORT', 443),
                'scheme' => env('PUSHER_SCHEME', 'https'),
                'encrypted' => true,
                'useTLS' => env('PUSHER_SCHEME', 'https') === 'https',
            ],
            'client_options' => [
                // Guzzle client options: https://docs.guzzlephp.org/en/stable/request-options.html
            ],
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],
    ],
];
