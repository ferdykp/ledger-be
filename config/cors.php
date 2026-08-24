<?php

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    /*
    | Tambahkan origin lokal dan produksi (baik HTTP maupun HTTPS).
    | Menggunakan '*' juga bisa untuk menguji, namun mendaftarkan domain secara spesifik jauh lebih aman.
    */
    'allowed_origins' => [
        'http://localhost:5173',
        'http://127.0.0.1:5173',
        'http://ledger.fdevsite.cloud',
        'https://ledger.fdevsite.cloud',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
