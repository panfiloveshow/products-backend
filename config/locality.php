<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Locality Engine configuration
    |--------------------------------------------------------------------------
    |
    | Централизованные параметры расчёта локальности продаж Ozon FBO.
    | Меняй через ENV без передеплоя: LOCALITY_*.
    |
    */

    'period' => [
        'default_days' => (int) env('LOCALITY_PERIOD_DAYS', 28),
        'allowed_days' => [7, 28],
    ],

    'recommendation' => [
        // min_orders_28d — минимум заказов per (sku, cluster) за 28 дней,
        // чтобы считать спрос устойчивым. 3 — компромисс: ниже = шум, выше = пропускаем хвосты.
        'min_orders_28d' => (int) env('LOCALITY_MIN_ORDERS_28D', 3),
        'min_markup_percent' => (float) env('LOCALITY_MIN_MARKUP_PERCENT', 5.0),
        'min_expected_savings_rub' => (float) env('LOCALITY_MIN_EXPECTED_SAVINGS', 300),
        'target_days_of_cover' => (int) env('LOCALITY_TARGET_DAYS_OF_COVER', 28),
        'supply_lead_time_days' => (int) env('LOCALITY_SUPPLY_LEAD_TIME_DAYS', 14),
        'max_cover_days' => (int) env('LOCALITY_MAX_COVER_DAYS', 60),
        'top_n_overpayers' => (int) env('LOCALITY_TOP_N_OVERPAYERS', 100),
        'storage_cost_savings_ratio_cutoff' => (float) env('LOCALITY_STORAGE_COST_CUTOFF', 0.3),
        'dedup_coverage_threshold' => (float) env('LOCALITY_DEDUP_COVERAGE', 0.7),
        'stale_after_days' => (int) env('LOCALITY_STALE_AFTER_DAYS', 14),
        'purge_after_days' => (int) env('LOCALITY_PURGE_AFTER_DAYS', 30),
    ],

    'forecast' => [
        'ewma_alpha' => (float) env('LOCALITY_EWMA_ALPHA', 0.3),
        'cold_start_min_sales_14d' => 3,
        'cold_start_min_sales_28d' => 5,
    ],

    'reconciliation' => [
        'match_tolerance_percent' => (float) env('LOCALITY_MATCH_TOLERANCE', 2.0),
        'drift_tolerance_percent' => (float) env('LOCALITY_DRIFT_TOLERANCE', 10.0),
        'per_posting_tolerance_rub' => (float) env('LOCALITY_POSTING_TOLERANCE', 5.0),
        'sync_batch_size' => 1000,
    ],

    'cache' => [
        'explanation_ttl_minutes' => (int) env('LOCALITY_EXPLAIN_TTL', 30),
        'recompute_lock_seconds' => (int) env('LOCALITY_RECOMPUTE_LOCK', 600),
        'cluster_list_ttl_minutes' => (int) env('LOCALITY_CLUSTER_LIST_TTL', 1440),
    ],

    'ozon_rules' => [
        'min_fbo_orders_7d_for_markup' => 50,
        'max_last_mile_cost_rub' => 25,
    ],

];
