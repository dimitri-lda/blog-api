<?php

return [
    'cache' => [
        'enabled' => (bool) env('STOREFRONT_CACHE_ENABLED', true),
        'store' => env('STOREFRONT_CACHE_STORE'),            // null => default store приложения
        'prefix' => 'sf',
        'locales' => ['en', 'ru', 'pl'],
        'ttl' => [
            'tree' => ['fresh' => 600, 'total' => 1800],
            'list' => ['fresh' => 120, 'total' => 600],
            'card' => 1800,
            'exchange_rate' => 300,
            'translations' => 3600,
            'geoip_miss' => 600,
        ],
        'generation_ttl' => 2592000,
        'lock_seconds' => 10,
        'lock_wait_ms' => 500,
        'max_cached_search_length' => 32,
        'max_cached_page' => 20,
        'warm' => [
            'after_invalidation' => (bool) env('STOREFRONT_CACHE_WARM_AFTER_INVALIDATION', false),
            'pages' => (int) env('STOREFRONT_CACHE_WARM_PAGES', 2),
            'debounce_seconds' => 10,
        ],
    ],
];
