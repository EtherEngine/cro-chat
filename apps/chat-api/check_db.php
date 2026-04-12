<?php
error_reporting(0);
$pdo = new PDO('mysql:host=127.0.0.1;dbname=cro_chat_test', 'root', '');
echo "--- TABLES ---\n";
foreach ($pdo->query('SHOW TABLES') as $row) {
    echo $row[0] . "\n";
}
echo "--- messages columns ---\n";
foreach ($pdo->query('SHOW COLUMNS FROM messages') as $c) {
    echo $c[0] . "\n";
}
echo "--- calls table exists? ---\n";
$r = $pdo->query("SHOW TABLES LIKE 'calls'");
echo ($r->rowCount() > 0 ? "YES" : "NO") . "\n";
