<?php

use App\Support\Env;

return [
    'env' => Env::get('APP_ENV', 'local'),
    'debug' => Env::bool('APP_DEBUG', false),
    'url' => Env::get('APP_URL', 'http://localhost/chat-api/public'),
    'cors_origin' => Env::get('CORS_ORIGIN', 'http://localhost:5173'),
    'upload_max_size' => Env::int('UPLOAD_MAX_SIZE', 10 * 1024 * 1024),
    'session_lifetime' => Env::int('SESSION_LIFETIME', 7200),
    'log_level' => Env::get('LOG_LEVEL', 'info'),
];

