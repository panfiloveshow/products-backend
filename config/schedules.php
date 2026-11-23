<?php

return [
    'ozon_storage' => filter_var(env('OZON_STORAGE_SCHEDULE', false), FILTER_VALIDATE_BOOLEAN),
    'wb_tariff' => filter_var(env('WB_TARIFF_SCHEDULE', true), FILTER_VALIDATE_BOOLEAN),
    'mp_constraints' => filter_var(env('MP_CONSTRAINTS_SCHEDULE', false), FILTER_VALIDATE_BOOLEAN),
    'integrations_dedup' => filter_var(env('INTEGRATIONS_DEDUP_SCHEDULE', false), FILTER_VALIDATE_BOOLEAN),
    'inventory_sync' => filter_var(env('INVENTORY_SYNC_SCHEDULE', false), FILTER_VALIDATE_BOOLEAN),
];
