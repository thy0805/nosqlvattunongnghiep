<?php

return [
    'uri' => env('MONGODB_URI'),
    'database' => env('MONGODB_DATABASE'),
    'replica_set' => env('MONGODB_REPLICA_SET'),
    'connection' => env('DB_CONNECTION'),
    'app_url' => env('APP_URL'),
    'web_origin' => env('WEB_ORIGINS'),
    'session_files' => storage_path('framework/sessions'),
    'session_cookie' => env('SESSION_COOKIE'),
    'session_driver' => env('SESSION_DRIVER'),
    'session_domain' => env('SESSION_DOMAIN'),
    'cache_store' => env('CACHE_STORE'),
    'queue_connection' => env('QUEUE_CONNECTION'),
    'app_key' => env('APP_KEY'),
    'sql_url' => env('DB_URL'),
];
