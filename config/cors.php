<?php

$allowedOrigins = array_values(array_filter(array_map(
    static fn (string $origin): string => trim($origin),
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'https://sellico.ru,https://www.sellico.ru'))
)));

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => $allowedOrigins,
    'allowed_origins_patterns' => [
        '#^https?://localhost(:\d+)?$#',
        '#^https?://127\.0\.0\.1(:\d+)?$#',
    ],
    'allowed_headers' => ['*'],
    'exposed_headers' => [
        'Content-Disposition',
        'X-Unit-Economics-Export-Version',
        'X-Unit-Economics-Export-Format',
        'X-Unit-Economics-Export-Source',
    ],
    'max_age' => 0,
    'supports_credentials' => false,
];
