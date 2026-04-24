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

    'sso' => [
        'base_url' => env('SSO_BASE_URL', 'http://localhost:8081'),
        'jwt_secret' => env('SSO_JWT_SECRET', 'Y2hhbmdlLW1lLXNraWxsaHViLXNzby1tYXN0ZXIta2V5LTIwMjYtMzJieXRlcy1taW4='),
        'jwt_issuer' => env('SSO_JWT_ISSUER', 'skillhub-sso'),
        'jwt_audience' => env('SSO_JWT_AUDIENCE', 'skillhub-laravel'),
        'timeout' => (int) env('SSO_HTTP_TIMEOUT', 5),
    ],

];
