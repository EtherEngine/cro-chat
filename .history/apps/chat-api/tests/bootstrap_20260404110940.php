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

// Run schema (skip the CREATE DATABASE / USE lines — we already selected the DB)
$schema = file_get_contents(__DIR__ . '/../../database/schema.sql');
$schema = preg_replace('/^CREATE DATABASE .+$/m', '', $schema);
$schema = preg_replace('/^USE .+$/m', '', $schema);
$pdo->exec($schema);

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
