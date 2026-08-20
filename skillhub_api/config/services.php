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

    /*
    |--------------------------------------------------------------------------
    | Microservice SSO (Spring Boot)
    |--------------------------------------------------------------------------
    |
    | `base_url` pointe sur le service `spring-sso` du reseau Docker prive.
    | Aucun secret n'est requis cote Laravel : la validation des JWT est
    | deleguee a `GET /auth/validate`, Laravel ne verifie pas de signature.
    |
    */

    'sso' => [
        'base_url' => env('SSO_BASE_URL', 'http://localhost:8080'),
        'jwt_issuer' => env('SSO_JWT_ISSUER', 'skillhub-sso'),
        'jwt_audience' => env('SSO_JWT_AUDIENCE', 'skillhub-laravel'),
        'timeout' => (int) env('SSO_HTTP_TIMEOUT', 5),
    ],

];
