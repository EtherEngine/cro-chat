<?php

declare(strict_types=1);

session_set_cookie_params([
    'httponly' => true,
    'secure' => false,
    'samesite' => 'Lax',
]);

session_start();

date_default_timezone_set('Europe/Berlin');

require_once __DIR__ . '/Config/app.php';

$dbConfig = require __DIR__ . '/Config/database.php';

use App\Support\Database;
Database::init($dbConfig);