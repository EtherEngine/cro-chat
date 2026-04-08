<?php
// Debug: run 2 "test rounds" and see if truncation actually empties all tables

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/tests/bootstrap.php';

$db = App\Support\Database::connection();

function doSetUp($db): void
{
    // Exactly like TestCase::truncateAll
    $db->exec('SET FOREIGN_KEY_CHECKS = 0');
    $tables = $db->query("SHOW TABLES")->fetchAll(\PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        $db->exec("TRUNCATE TABLE `$table`");
    }
    $db->exec('SET FOREIGN_KEY_CHECKS = 1');

    // Verify all empty
    $nonEmpty = [];
    foreach ($tables as $table) {
        $count = (int) $db->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
        if ($count > 0) {
            $nonEmpty[$table] = $count;
        }
    }
    if (!empty($nonEmpty)) {
        echo "  WARNING: Non-empty tables after truncation: " . json_encode($nonEmpty) . "\n";
    } else {
        echo "  All tables empty after truncation\n";
    }
}

function seedData($db): void
{
    $db->prepare('INSERT INTO users (email, password_hash, display_name, title, avatar_color) VALUES (?, ?, ?, ?, ?)')
        ->execute(['user1@test.dev', '$2y$10$test', 'Admin', '', '#7C3AED']);
    $uid1 = (int) $db->lastInsertId();

    $db->prepare('INSERT INTO users (email, password_hash, display_name, title, avatar_color) VALUES (?, ?, ?, ?, ?)')
        ->execute(['user2@test.dev', '$2y$10$test', 'Member', '', '#7C3AED']);
    $uid2 = (int) $db->lastInsertId();

    $db->prepare('INSERT INTO spaces (name, slug, description, owner_id) VALUES (?, ?, ?, ?)')
        ->execute(['Space 1', 'space-1', '', $uid1]);
    $sid = (int) $db->lastInsertId();

    $db->prepare('INSERT INTO space_members (space_id, user_id, role) VALUES (?, ?, ?)')
        ->execute([$sid, $uid1, 'owner']);
    $db->prepare('INSERT INTO space_members (space_id, user_id, role) VALUES (?, ?, ?)')
        ->execute([$sid, $uid2, 'member']);

    echo "  Seeded: users=$uid1,$uid2, space=$sid\n";
}

echo "Round 1:\n";
doSetUp($db);
seedData($db);

echo "Round 2:\n";
doSetUp($db);
seedData($db);

echo "Round 3:\n";
doSetUp($db);
seedData($db);

echo "PASSED - all 3 rounds worked\n";
