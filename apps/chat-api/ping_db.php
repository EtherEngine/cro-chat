<?php
try {
    $p = new PDO('mysql:host=127.0.0.1', 'root', '');
    echo 'OK ' . $p->query('SELECT VERSION()')->fetchColumn() . PHP_EOL;
    $dbs = $p->query("SHOW DATABASES LIKE 'cro_chat_test'")->fetchAll();
    echo 'Test DB exists: ' . (count($dbs) > 0 ? 'YES' : 'NO') . PHP_EOL;
    if (count($dbs) > 0) {
        $cnt = $p->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='cro_chat_test'")->fetchColumn();
        echo "Tables: $cnt" . PHP_EOL;
    }
} catch (Exception $e) {
    echo 'FAIL: ' . $e->getMessage() . PHP_EOL;
}
