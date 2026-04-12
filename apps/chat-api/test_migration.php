<?php
error_reporting(E_ALL);
$pdo = new PDO('mysql:host=127.0.0.1;dbname=cro_chat_test', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

// Test the exact migration_call_history.sql statements
$statements = [
    "ALTER TABLE messages
        ADD COLUMN type    ENUM('text','call') NOT NULL DEFAULT 'text' AFTER body,
        ADD COLUMN call_id INT UNSIGNED DEFAULT NULL AFTER type,
        ADD INDEX idx_call_id (call_id),
        ADD INDEX idx_type (conversation_id, type, id),
        ADD CONSTRAINT fk_messages_call FOREIGN KEY (call_id) REFERENCES calls(id) ON DELETE SET NULL",
];

foreach ($statements as $stmt) {
    try {
        $pdo->exec($stmt);
        echo "OK: " . substr($stmt, 0, 50) . "\n";
    } catch (PDOException $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
        echo "Statement: " . substr($stmt, 0, 100) . "\n";
    }
}

// Check current state
echo "\n--- messages columns now ---\n";
foreach ($pdo->query('SHOW COLUMNS FROM messages') as $c) {
    echo $c[0] . "\n";
}
