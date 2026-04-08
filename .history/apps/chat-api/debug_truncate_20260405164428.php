<?php
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/tests/bootstrap.php';

$db = App\Support\Database::connection();

// Insert test data
$db->exec("INSERT INTO spaces (name, slug, description, owner_id) VALUES ('t', 'test-slug', '', NULL)");
echo "Before truncate: " . $db->query('SELECT COUNT(*) FROM spaces')->fetchColumn() . " rows\n";

// Truncate like TestCase does
$db->exec('SET FOREIGN_KEY_CHECKS = 0');
$tables = $db->query("SHOW TABLES")->fetchAll(\PDO::FETCH_COLUMN);
echo "Tables: " . count($tables) . "\n";
foreach ($tables as $table) {
    $db->exec("TRUNCATE TABLE `$table`");
}
$db->exec('SET FOREIGN_KEY_CHECKS = 1');

echo "After truncate: " . $db->query('SELECT COUNT(*) FROM spaces')->fetchColumn() . " rows\n";

// Re-insert (simulates second test)
$db->exec("INSERT INTO spaces (name, slug, description, owner_id) VALUES ('t', 'test-slug', '', NULL)");
echo "After re-insert: " . $db->query('SELECT COUNT(*) FROM spaces')->fetchColumn() . " rows\n";
echo "Auto increment ID: " . $db->lastInsertId() . "\n";
echo "DONE\n";
