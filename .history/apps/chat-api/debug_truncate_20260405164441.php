<?php
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/tests/bootstrap.php';

$db = App\Support\Database::connection();

// Insert test data  
$db->exec('SET FOREIGN_KEY_CHECKS = 0');
$db->exec("INSERT INTO users (email, password_hash, display_name, title, avatar_color) VALUES ('a@b.c', 'x', 'A', '', '#000')");
$uid = (int) $db->lastInsertId();
$db->exec("INSERT INTO spaces (name, slug, description, owner_id) VALUES ('t', 'test-slug', '', $uid)");
$db->exec('SET FOREIGN_KEY_CHECKS = 1');
echo "Before truncate: " . $db->query('SELECT COUNT(*) FROM spaces')->fetchColumn() . " rows\n";

// Truncate like TestCase does
$db->exec('SET FOREIGN_KEY_CHECKS = 0');
$tables = $db->query("SHOW TABLES")->fetchAll(\PDO::FETCH_COLUMN);
echo "Tables found: " . count($tables) . "\n";
foreach ($tables as $table) {
    try {
        $db->exec("TRUNCATE TABLE `$table`");
    } catch (\Exception $e) {
        echo "  TRUNCATE $table FAILED: " . $e->getMessage() . "\n";
    }
}
$db->exec('SET FOREIGN_KEY_CHECKS = 1');

echo "After truncate spaces: " . $db->query('SELECT COUNT(*) FROM spaces')->fetchColumn() . " rows\n";
echo "After truncate users: " . $db->query('SELECT COUNT(*) FROM users')->fetchColumn() . " rows\n";

// Re-insert (simulates second test)
$db->exec('SET FOREIGN_KEY_CHECKS = 0');
$db->exec("INSERT INTO users (email, password_hash, display_name, title, avatar_color) VALUES ('a@b.c', 'x', 'A', '', '#000')");
$uid2 = (int) $db->lastInsertId();
echo "User auto_inc after truncate: $uid2\n";
$db->exec("INSERT INTO spaces (name, slug, description, owner_id) VALUES ('t', 'test-slug', '', $uid2)");
echo "Re-insert succeeded\n";
$db->exec('SET FOREIGN_KEY_CHECKS = 1');
echo "DONE\n";
