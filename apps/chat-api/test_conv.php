<?php
require_once 'vendor/autoload.php';
use App\Support\Database;
use App\Support\Env;
use App\Services\ConversationService;
Env::load(__DIR__ . '/.env');
Database::init(require __DIR__ . '/src/Config/database.php');
$_SESSION = ['user_id' => 4];
$convs = ConversationService::listForUser(4);
echo 'Count: ' . count($convs) . PHP_EOL;
foreach ($convs as $c) {
    $names = array_map(fn($u) => $u['display_name'], $c['users']);
    echo 'Conv ' . $c['id'] . ': ' . implode(', ', $names) . PHP_EOL;
}