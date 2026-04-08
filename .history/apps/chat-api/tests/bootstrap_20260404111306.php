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
$pdo->exec("USE `$dbName`");

// Run schema — resolve workspace root from __DIR__
$testsDir = __DIR__;
$chatApiDir = dirname($testsDir);
$workspaceDir = dirname($chatApiDir, 2);  // up from apps/chat-api → workspace root
$schemaPath = $workspaceDir . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'schema.sql';

if (!file_exists($schemaPath)) {
    throw new RuntimeException("schema.sql not found at: $schemaPath");
}

$schema = file_get_contents($schemaPath);
// Strip BOM if present
$schema = ltrim($schema, "\xEF\xBB\xBF");
$schema = preg_replace('/^CREATE DATABASE .+$/m', '', $schema);
$schema = preg_replace('/^USE .+$/m', '', $schema);

// PDO::exec can't run multi-statement SQL — split on statement boundaries
$statements = array_filter(
    array_map('trim', explode(';', $schema)),
    fn(string $s) => $s !== '' && !str_starts_with($s, '--')
);

foreach ($statements as $stmt) {
    $pdo->exec($stmt);
}

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
