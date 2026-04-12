<?php
error_reporting(E_ALL);
$pdo = new PDO('mysql:host=127.0.0.1;dbname=cro_chat_test', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

// Check calls.id type
echo "--- calls table structure ---\n";
foreach ($pdo->query('SHOW CREATE TABLE calls') as $r) {
    echo $r[1] . "\n";
}

echo "\n--- messages table structure ---\n";
foreach ($pdo->query('SHOW CREATE TABLE messages') as $r) {
    echo $r[1] . "\n";
}

echo "\nERROR from InnoDB: ";
foreach ($pdo->query('SHOW ENGINE INNODB STATUS') as $r) {
    // extract the FK error section
    preg_match('/LATEST FOREIGN KEY ERROR.*?(-{10,})/s', $r[2], $m);
    echo $m[0] ?? "not found";
}
