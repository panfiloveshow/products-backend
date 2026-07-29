<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Калибровка прогноза на основе план-факта (этап 4)
    |--------------------------------------------------------------------------
    | Корректор систематического смещения (bias) прогноза спроса, посчитанный
    | из plan_line_evaluations. По умолчанию ВЫКЛЮЧЕН — включается осознанно,
    | когда накоплено достаточно оценок и точности доверяют.
    */
    'calibration' => [
        'enabled' => (bool) env('AUTOPLAN_CALIBRATION_ENABLED', false),

        // Минимальное число оценок (sku × cluster), чтобы применять корректор.
        'min_sample' => (int) env('AUTOPLAN_CALIBRATION_MIN_SAMPLE', 3),

        // Максимальное отклонение корректора от 1.0 (±доля). 0.25 = ±25%.
        'max_adjust' => (float) env('AUTOPLAN_CALIBRATION_MAX_ADJUST', 0.25),

        // Демпфирование: применять корректор на эту долю силы (0.5 = вполсилы).
        'damping' => (float) env('AUTOPLAN_CALIBRATION_DAMPING', 0.5),

        // Сколько последних оценок учитывать.
        'window' => (int) env('AUTOPLAN_CALIBRATION_WINDOW', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Деманд-математика (правки точности, gated)
    |--------------------------------------------------------------------------
    | Обе правки меняют рекомендованные количества по всем строкам, поэтому
    | по умолчанию ВЫКЛЮЧЕНЫ — включать после прогона demand:parity на проде.
    */
    'demand' => [
        // Доверять OOS-скорректированному спросу (effective_daily_sales), когда
        // days_in_stock неизвестен (null). Off → старое поведение (берётся EWMA,
        // который делит на полное окно и занижает спрос недавно стокнувшихся SKU).
        'oos_adjust_when_unknown_days' => (bool) env('AUTOPLAN_DEMAND_OOS_WHEN_UNKNOWN', false),
    ],

    'safety_stock' => [
        // Формула страхового запаса SS = z × σ × √(lead_time) вместо линейной
        // эвристики SS = demand × lead_time × (1 + vol×1.5). Off → старая формула.
        // ponytail: включено по умолчанию — это эталонная (textbook) модель страхзапаса,
        // которой нет у конкурентов. Откатить можно env AUTOPLAN_SS_SIGMA_LT_ENABLED=false.
        'sigma_lead_time_enabled' => (bool) env('AUTOPLAN_SS_SIGMA_LT_ENABLED', true),

        // Целевой уровень сервиса (z-фактор). 1.65 ≈ 95%, 1.28 ≈ 90%, 1.96 ≈ 97.5%.
        'service_level_z' => (float) env('AUTOPLAN_SS_SERVICE_LEVEL_Z', 1.65),
    ],

    /*
    |--------------------------------------------------------------------------
    | SLA источников фактов
    |--------------------------------------------------------------------------
    | Блокирующие источники должны быть не старше SLA на момент валидации.
    | Ограничения и экономика показываются как предупреждения: отсутствие файла
    | ограничений не должно останавливать прямую FBO-поставку, а неизвестная
    | себестоимость исключает строку из profit-режима, но не из anti-OOS.
    */
    'freshness_sla_hours' => [
        'products' => (int) env('AUTOPLAN_SLA_PRODUCTS_HOURS', 24),
        'inventory' => (int) env('AUTOPLAN_SLA_INVENTORY_HOURS', 18),
        'demand' => (int) env('AUTOPLAN_SLA_DEMAND_HOURS', 18),
        'economics' => (int) env('AUTOPLAN_SLA_ECONOMICS_HOURS', 48),
        'constraints' => (int) env('AUTOPLAN_SLA_CONSTRAINTS_HOURS', 48),
        'supplies' => (int) env('AUTOPLAN_SLA_SUPPLIES_HOURS', 24),
    ],

    'versions' => [
        'engine' => 'ozon-v2',
        'forecast' => 'forecast-2.0.0',
        'allocation' => 'allocation-2.0.0',
        'adapter' => 'ozon-seller-fbo-2.0.0',
    ],

    'execution' => [
        'queue' => env('AUTOPLAN_EXECUTION_QUEUE', 'ozon-supply-execution'),
        'draft_attempts' => (int) env('AUTOPLAN_DRAFT_ATTEMPTS', 4),
        'retry_after_seconds' => (int) env('AUTOPLAN_DRAFT_RETRY_AFTER', 90),
        'status_poll_minutes' => (int) env('AUTOPLAN_STATUS_POLL_MINUTES', 15),
        'status_poll_enabled' => (bool) env('AUTOPLAN_STATUS_POLL_ENABLED', true),
    ],

    'facts' => [
        // Полный контур: products → inventory/sales → unit economics плюс
        // postings/supplies/constraints/credential health.
        'refresh_enabled' => (bool) env('AUTOPLAN_FACT_REFRESH_ENABLED', true),
        'refresh_cron' => env('AUTOPLAN_FACT_REFRESH_CRON', '17 2,10,18 * * *'),
        'queue' => env('AUTOPLAN_FACT_REFRESH_QUEUE', 'ozon-fact-refresh'),
    ],

    'credential_notifications' => [
        'enabled' => (bool) env('AUTOPLAN_CREDENTIAL_NOTIFICATIONS_ENABLED', true),
        'schedule_time' => env('AUTOPLAN_CREDENTIAL_NOTIFICATIONS_TIME', '08:00'),
    ],

    'plan_fact' => [
        'schedule_enabled' => (bool) env('PLAN_ACCURACY_SCHEDULE', true),
        'schedule_time' => env('PLAN_ACCURACY_SCHEDULE_TIME', '06:00'),
    ],
];
