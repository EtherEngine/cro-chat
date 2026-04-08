<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Support\Database;

// ── Create test database from schema ────────────────────────
$host = $_ENV['DB_HOST'] ?? '127.0.0.1';
$port = $_ENV['DB_PORT'] ?? '3306';
$user = $_ENV['DB_USER'] ?? 'root';
$pass = $_ENV['DB_PASS'] ?? '';
$dbName = $_ENV['DB_NAME'] ?? 'cro_chat_test';

$pdo = new PDO(
    "mysql:host=$host;port=$port;charset=utf8mb4",
    $user,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$pdo->exec("DROP DATABASE IF EXISTS `$dbName`");
$pdo->exec("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

// Load schema via mysql CLI (handles multi-statement SQL correctly)
$testsDir = __DIR__;
$chatApiDir = dirname($testsDir);
$workspaceDir = dirname($chatApiDir, 2);
$schemaPath = $workspaceDir . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'schema.sql';

if (!file_exists($schemaPath)) {
    throw new RuntimeException("schema.sql not found at: $schemaPath");
}

// Strip CREATE DATABASE / USE from schema — we already created the DB
$schema = file_get_contents($schemaPath);
$schema = ltrim($schema, "\xEF\xBB\xBF");
$schema = preg_replace('/^CREATE DATABASE .+$/m', '', $schema);
$schema = preg_replace('/^USE .+$/m', '', $schema);

// Execute through the PDO connection
$pdo->exec("USE `$dbName`");
$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

// Split on ; at end of line
$statements = preg_split('/;\s*$/m', $schema);
foreach ($statements as $stmt) {
    $stmt = trim($stmt);
    // Skip empty statements and comment-only blocks
    $withoutComments = trim(preg_replace('/--.*$/m', '', $stmt));
    if ($withoutComments === '') {
        continue;
    }
    $pdo->exec($stmt);
}

$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

$pdo = null; // close bootstrap connection

// ── Init the app's Database singleton for tests ─────────────
Database::init([
    'host' => $host,
    'port' => (int) $port,
    'dbname' => $dbName,
    'charset' => 'utf8mb4',
    'username' => $user,
    'password' => $pass,
]);
