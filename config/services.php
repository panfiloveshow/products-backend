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
        'user_agent' => env('WILDBERRIES_USER_AGENT', 'wbas_sellico.ru9757'),
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
        'business_id' => env('YANDEX_MARKET_BUSINESS_ID'),
        'base_url' => env('YANDEX_MARKET_BASE_URL', 'https://api.partner.market.yandex.ru'),
    ],

    'uzum' => [
        'base_url' => env('UZUM_API_URL', 'https://api-seller.uzum.uz/api/seller-openapi'),
        'extension_allow_raw_commands' => env('UZUM_EXTENSION_ALLOW_RAW_COMMANDS', false),
        // Version-handshake расширения: min — ниже неё расширение показывает «обновите»,
        // latest — информационная. Пустой min = проверка выключена.
        'extension_min_version' => env('UZUM_EXTENSION_MIN_VERSION', ''),
        'extension_latest_version' => env('UZUM_EXTENSION_LATEST_VERSION', ''),
        // ponytail: Phase-1 константы — продуктовый эндпоинт не отдаёт логистику/эквайринг per-SKU.
        // Апгрейд-путь: считать из /v1/finance/expenses + /v1/finance/orders (Phase 2).
        'acquiring_percent' => env('UZUM_ACQUIRING_PERCENT', 0),
        'logistics_fbs' => env('UZUM_LOGISTICS_FBS', 0),
        'logistics_fbo' => env('UZUM_LOGISTICS_FBO', 0),
        'logistics_dbs' => env('UZUM_LOGISTICS_DBS', 0),
        // Бесплатное размещение на складе Uzum: первые N дней не тарифицируются,
        // платное хранение считается только за дни сверх лимита.
        'free_storage_days' => env('UZUM_FREE_STORAGE_DAYS', 30),
        // Тариф логистики по объёму (документация Uzum): 1-й литр + каждый следующий.
        // Применяется к FBS/FBO когда нет факта из finance; DBS/EDBS — без сбора.
        'logistics_first_liter' => env('UZUM_LOGISTICS_FIRST_LITER', 5250),
        'logistics_next_liter' => env('UZUM_LOGISTICS_NEXT_LITER', 250),
        // Налог с оборота Узбекистана (ИП/самозанятые, оборот до 1 млрд сум) — 1% с 2026.
        'tax_percent' => env('UZUM_TAX_PERCENT', 1),
        // Сбор за обработку возврата FBO по классу габаритов (с учётом скидки 50% до 31.03.2026).
        // Авто-применяется по классу (Ш+В+Д): МГТ ≤60см, СГТ 60–170см, КГТ >170см.
        // 0 = тариф не задан (заполнить из кабинета). Ручная ставка в настройках товара переопределяет.
        'return_fee_mgt' => env('UZUM_RETURN_FEE_MGT', 0),
        'return_fee_sgt' => env('UZUM_RETURN_FEE_SGT', 0),
        'return_fee_kgt' => env('UZUM_RETURN_FEE_KGT', 0),
    ],

    'sellico' => [
        'base_url' => env('SELLICO_API_URL', 'https://sellico.ru/api'),
        'email' => env('SELLICO_EMAIL'),
        'password' => env('SELLICO_PASSWORD'),
        'skip_permission_check' => env('SELLICO_SKIP_PERMISSION_CHECK', false),
    ],

    'crm' => [
        'url' => env('CRM_URL', 'https://sellico.ru'),
        'service_token' => env('SELLICO_SERVICE_TOKEN'),
    ],

];
