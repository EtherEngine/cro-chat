<?php
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/tests/bootstrap.php';

$db = App\Support\Database::connection();
echo "Connected to DB: " . $db->query("SELECT DATABASE()")->fetchColumn() . "\n";

// Check table count
$tables = $db->query("SHOW TABLES")->fetchAll(\PDO::FETCH_COLUMN);
echo "Tables: " . count($tables) . "\n";

// Test exact same sequence as setUp 
echo "\n--- Simulating setUp ---\n";

// truncateAll
$db->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($tables as $table) {
    $db->exec("TRUNCATE TABLE `$table`");
}
$db->exec('SET FOREIGN_KEY_CHECKS = 1');

echo "Truncated all tables\n";

// createUser (admin)
$db->prepare('INSERT INTO users (email, password_hash, display_name, title, avatar_color) VALUES (?, ?, ?, ?, ?)')
    ->execute(['user1@test.dev', password_hash('password', PASSWORD_BCRYPT), 'Admin', '', '#7C3AED']);
$adminId = (int) $db->lastInsertId();
echo "Admin: id=$adminId\n";

// createUser (member)
$db->prepare('INSERT INTO users (email, password_hash, display_name, title, avatar_color) VALUES (?, ?, ?, ?, ?)')
    ->execute(['user2@test.dev', password_hash('password', PASSWORD_BCRYPT), 'Member', '', '#7C3AED']);
$userId = (int) $db->lastInsertId();
echo "User: id=$userId\n";

// createSpace
$db->prepare('INSERT INTO spaces (name, slug, description, owner_id) VALUES (?, ?, ?, ?)')
    ->execute(['Space 1', 'space-1', '', $adminId]);
$spaceId = (int) $db->lastInsertId();
$db->prepare('INSERT INTO space_members (space_id, user_id, role) VALUES (?, ?, ?)')
    ->execute([$spaceId, $adminId, 'owner']);
echo "Space: id=$spaceId\n";

// addSpaceMember
$db->prepare('INSERT INTO space_members (space_id, user_id, role) VALUES (?, ?, ?)')
    ->execute([$spaceId, $userId, 'member']);
echo "Added member to space\n";

// createChannel
$db->prepare('INSERT INTO channels (space_id, name, description, color, is_private, created_by) VALUES (?, ?, ?, ?, ?, ?)')
    ->execute([$spaceId, 'channel-1', '', '#7C3AED', 0, $adminId]);
$channelId = (int) $db->lastInsertId();
$db->prepare('INSERT INTO channel_members (channel_id, user_id, role) VALUES (?, ?, ?)')
    ->execute([$channelId, $adminId, 'admin']);
echo "Channel: id=$channelId\n";

echo "\n--- First setUp DONE. Now simulating second test setUp ---\n";

// truncateAll again
$db->exec('SET FOREIGN_KEY_CHECKS = 0');
$tables2 = $db->query("SHOW TABLES")->fetchAll(\PDO::FETCH_COLUMN);
foreach ($tables2 as $table) {
    $db->exec("TRUNCATE TABLE `$table`");
}
$db->exec('SET FOREIGN_KEY_CHECKS = 1');

echo "Truncated again\n";
echo "Users count: " . $db->query('SELECT COUNT(*) FROM users')->fetchColumn() . "\n";
echo "Spaces count: " . $db->query('SELECT COUNT(*) FROM spaces')->fetchColumn() . "\n";

// createUser (admin) - second setUp
$db->prepare('INSERT INTO users (email, password_hash, display_name, title, avatar_color) VALUES (?, ?, ?, ?, ?)')
    ->execute(['user1@test.dev', password_hash('password', PASSWORD_BCRYPT), 'Admin', '', '#7C3AED']);
$adminId2 = (int) $db->lastInsertId();
echo "Admin2: id=$adminId2\n";

echo "DONE\n";
