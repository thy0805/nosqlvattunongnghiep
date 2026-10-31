<?php

return [
    'default' => 'mongodb',
    'connections' => [
        'mongodb' => [
            'driver' => 'mongodb',
            'dsn' => env('MONGODB_URI'),
            'database' => env('MONGODB_DATABASE'),
            'options' => ['appName' => 'nosql186-laravel', 'serverSelectionTimeoutMS' => 2500],
        ],
    ],
];
