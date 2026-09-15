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
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Sign-in providers. Every client id that may appear as the "aud" of an
    // id_token we accept has to be listed: a native app normally has one per
    // platform (an Android id, an iOS id, a web id), and a token addressed to
    // an id we don't list is rejected.
    'google' => [
        'client_ids' => array_filter(array_map(
            'trim',
            explode(',', (string) env('GOOGLE_CLIENT_IDS', env('GOOGLE_CLIENT_ID', '')))
        )),
    ],

    // For Apple these are the bundle id of the app and, if the web flow is
    // used, the Services ID.
    'apple' => [
        'client_ids' => array_filter(array_map(
            'trim',
            explode(',', (string) env('APPLE_CLIENT_IDS', env('APPLE_CLIENT_ID', '')))
        )),
    ],

];
