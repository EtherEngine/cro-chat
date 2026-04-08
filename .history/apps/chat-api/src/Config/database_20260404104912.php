<?php

use App\Support\Env;

return [
    'host' => Env::get('DB_HOST', '127.0.0.1'),
    'port' => Env::int('DB_PORT', 3306),
    'dbname' => Env::get('DB_NAME', 'cro_chat'),
    'charset' => Env::get('DB_CHARSET', 'utf8mb4'),
    'username' => Env::get('DB_USER', 'root'),
    'password' => Env::get('DB_PASS', ''),
];