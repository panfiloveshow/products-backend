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
    | Marketplace API Credentials
    |--------------------------------------------------------------------------
    */

    'wildberries' => [
        'api_key' => env('WILDBERRIES_API_KEY'),
        'base_url' => env('WILDBERRIES_BASE_URL', 'https://suppliers-api.wildberries.ru'),
        'stats_url' => env('WILDBERRIES_STATS_URL', 'https://statistics-api.wildberries.ru'),
    ],

    'ozon' => [
        'client_id' => env('OZON_CLIENT_ID'),
        'api_key' => env('OZON_API_KEY'),
        'base_url' => env('OZON_BASE_URL', 'https://api-seller.ozon.ru'),
    ],

    'yandex_market' => [
        'token' => env('YANDEX_MARKET_TOKEN'),
        'campaign_id' => env('YANDEX_MARKET_CAMPAIGN_ID'),
        'base_url' => env('YANDEX_MARKET_BASE_URL', 'https://api.partner.market.yandex.ru'),
    ],

    'sellico' => [
        'base_url' => env('SELLICO_API_URL', 'https://sellico.ru/api'),
    ],

];
