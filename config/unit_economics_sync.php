<?php

return [
    'enabled' => (bool) env('UE_SYNC_RELIABILITY_ENABLED', false),
    'integration_ids' => array_values(array_filter(array_map('intval', explode(',', (string) env('UE_SYNC_RELIABILITY_INTEGRATIONS', ''))))),
    'price_queue' => env('UE_PRICE_QUEUE', 'price-refresh'),
    'enrichment_queue' => env('UE_ENRICHMENT_QUEUE', 'unit-economics-enrichment'),
    'deadline_hours' => (int) env('UE_SYNC_DEADLINE_HOURS', 6),
    'wildberries_finance_deadline_hours' => (int) env('UE_WB_FINANCE_DEADLINE_HOURS', 24),
    'price_max_age_minutes' => (int) env('UE_PRICE_MAX_AGE_MINUTES', 30),
];
