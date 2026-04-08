<?php
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/src/bootstrap.php';

$db = App\Support\Database::connection();
echo 'DB: ' . $db->query('SELECT DATABASE()')->fetchColumn() . PHP_EOL;

$tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
echo 'Tables before: ' . count($tables) . PHP_EOL;

$db->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($tables as $t) {
    $db->exec("TRUNCATE TABLE `$t`");
}
$db->exec('SET FOREIGN_KEY_CHECKS = 1');

$tables2 = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
echo 'Tables after: ' . count($tables2) . PHP_EOL;

$knowledge = $db->query("SHOW TABLES LIKE 'knowledge%'")->fetchAll(PDO::FETCH_COLUMN);
echo 'Knowledge tables after: ' . implode(', ', $knowledge) . PHP_EOL;
