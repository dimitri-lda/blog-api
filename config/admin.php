<?php

$appHost = parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST) ?: 'localhost';

return [
    'domain' => env('ADMIN_DOMAIN', 'admin.'.$appHost),
];
