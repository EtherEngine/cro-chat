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

$testsDir = __DIR__;
$chatApiDir = dirname($testsDir);
$workspaceDir = dirname($chatApiDir, 2);

$pdo = new PDO(
    "mysql:host=$host;port=$port;charset=utf8mb4",
    $user,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// ── Schema version hash ──────────────────────────────────────
// Compute a hash over schema.sql + all migration_*.sql files.
// If the hash matches what's stored in the DB, skip all destructive setup.
// This avoids the Windows/XAMPP InnoDB DROP DATABASE lock issue entirely.
$schemaPath = $workspaceDir . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'schema.sql';
$migrationDir = $workspaceDir . DIRECTORY_SEPARATOR . 'database';
$migrations = glob($migrationDir . DIRECTORY_SEPARATOR . 'migration_*.sql') ?: [];
sort($migrations);

if (!file_exists($schemaPath)) {
    throw new RuntimeException("schema.sql not found at: $schemaPath");
}

$hashInput = file_get_contents($schemaPath);
foreach ($migrations as $mf) {
    $hashInput .= file_get_contents($mf);
}
$schemaHash = md5($hashInput);

// Check if the test DB already has all tables and the correct schema version
$needsRebuild = true;
try {
    $pdo->exec("USE `$dbName`");
    $storedHash = $pdo->query("SELECT option_value FROM _test_schema_version LIMIT 1")->fetchColumn();
    if ($storedHash === $schemaHash) {
        $needsRebuild = false;
    }
} catch (PDOException) {
    // DB doesn't exist or version table missing → rebuild needed
}

if ($needsRebuild) {
    // Find mysql binary
    $mysqlBin = 'mysql';
    foreach (['C:\\xampp\\mysql\\bin\\mysql.exe', '/usr/bin/mysql'] as $candidate) {
        if (file_exists($candidate)) {
            $mysqlBin = $candidate;
            break;
        }
    }
    $passArg = $pass !== '' ? '-p' . $pass : '';

    // Helper: run SQL file via mysql CLI (handles multi-statement files)
    $runSqlFile = function (string $sql) use ($mysqlBin, $host, $port, $user, $passArg, $dbName): void {
        $tmp = tempnam(sys_get_temp_dir(), 'cro_sql_') . '.sql';
        file_put_contents($tmp, "USE `$dbName`;\nSET FOREIGN_KEY_CHECKS=0;\n" . $sql . "\nSET FOREIGN_KEY_CHECKS=1;\n");
        $cmd = sprintf('"%s" -h %s -P %s -u %s %s --force < "%s"', $mysqlBin, $host, $port, $user, $passArg, $tmp);
        exec($cmd, $out, $code);
        @unlink($tmp);
        if ($code === 127) {
            throw new RuntimeException("mysql CLI not found");
        }
    };

    // Ensure DB exists (CREATE does not hang; only DROP can)
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$dbName`");

    // Load base schema (IF NOT EXISTS on all tables → idempotent)
    $schema = file_get_contents($schemaPath);
    $schema = ltrim($schema, "\xEF\xBB\xBF");
    $schema = preg_replace('/CREATE DATABASE.*?;/s', '', $schema);
    $schema = preg_replace('/^USE .+;/m', '', $schema);
    $runSqlFile($schema);

    // Load each migration (idempotent: errors 1005/1050/1060/1061/1068 ignored)
    foreach ($migrations as $migPath) {
        $mig = file_get_contents($migPath);
        $mig = ltrim($mig, "\xEF\xBB\xBF");
        $mig = preg_replace('/CREATE DATABASE.*?;/s', '', $mig);
        $mig = preg_replace('/^USE .+;/m', '', $mig);
        $runSqlFile($mig);
    }

    // Store schema version so subsequent runs skip the rebuild
    $pdo->exec("CREATE TABLE IF NOT EXISTS `_test_schema_version` (option_value VARCHAR(32) NOT NULL)");
    $pdo->exec("TRUNCATE TABLE `_test_schema_version`");
    $stmt = $pdo->prepare("INSERT INTO `_test_schema_version` (option_value) VALUES (?)");
    $stmt->execute([$schemaHash]);
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
