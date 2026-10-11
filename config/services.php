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

    'shop_pay' => [
        'enabled' => (bool) env('SHOP_PAY_ENABLED', false),
        'shop_domain' => env('SHOP_PAY_SHOP_DOMAIN'),
        'api_version' => env('SHOP_PAY_API_VERSION', '2026-07'),
        'shop_id' => env('SHOP_PAY_SHOP_ID'),
        'client_id' => env('SHOP_PAY_CLIENT_ID'),
        'storefront_private_token' => env('SHOP_PAY_STOREFRONT_PRIVATE_TOKEN'),
        'admin_access_token' => env('SHOP_PAY_ADMIN_ACCESS_TOKEN'),
        'app_client_secret' => env('SHOP_PAY_APP_CLIENT_SECRET'),
    ],

];
