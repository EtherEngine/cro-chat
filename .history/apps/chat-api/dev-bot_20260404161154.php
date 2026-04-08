<?php

/**
 * Developer Bot – Simulates incoming DMs for heather.mason@cro.dev.
 *
 * Every ~5 minutes a random co-member sends Heather a direct message.
 * Uses the Service layer directly → DB row + domain_event + WebSocket delivery.
 *
 * Usage:
 *   php dev-bot.php                        # default 300s interval
 *   php dev-bot.php --interval=60          # every 60s (for testing)
 *   php dev-bot.php --jitter=0             # no random delay
 *   php dev-bot.php --once                 # send one message and exit
 */

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use App\Repositories\SpaceRepository;
use App\Repositories\UserRepository;
use App\Services\ConversationService;
use App\Services\MessageService;
use App\Support\Database;
use App\Support\Env;

// ── Bootstrap (minimal, no HTTP session) ─────────────────────
Env::load(__DIR__ . '/.env');
$dbConfig = require __DIR__ . '/src/Config/database.php';
Database::init($dbConfig);
date_default_timezone_set('Europe/Berlin');
$_SESSION = [];

// ── CLI args ─────────────────────────────────────────────────
$intervalSec = 300;
$jitterSec   = 30;
$once        = false;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--interval=')) {
        $intervalSec = max(10, (int) substr($arg, 11));
    }
    if (str_starts_with($arg, '--jitter=')) {
        $jitterSec = max(0, (int) substr($arg, 9));
    }
    if ($arg === '--once') {
        $once = true;
    }
}

// ── Constants ────────────────────────────────────────────────
const TARGET_EMAIL = 'heather.mason@cro.dev';

const MESSAGES = [
    'Hey Heather, läuft alles?',
    'Kurzer Test für Realtime 👀',
    'Kannst du das sehen?',
    'Ping 🚀',
    'Frontend reagiert korrekt?',
    'Test für Notifications 🔔',
    'Neue Nachricht für dich',
    'Check DM flow',
    'Alles stabil bei dir?',
    'Random Test Message',
];

// ── Signal handling ──────────────────────────────────────────
$shouldStop = false;
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, function () use (&$shouldStop) { $shouldStop = true; });
    pcntl_signal(SIGINT,  function () use (&$shouldStop) { $shouldStop = true; });
}

// ── Resolve target user ──────────────────────────────────────
$heather = UserRepository::findByEmail(TARGET_EMAIL);
if (!$heather) {
    logLine("FATAL: User " . TARGET_EMAIL . " nicht gefunden.");
    exit(1);
}
$heatherId = (int) $heather['id'];
logLine("Target: {$heather['display_name']} (ID {$heatherId})");

// ── Resolve space + co-members ───────────────────────────────
$spaces = SpaceRepository::forUser($heatherId);
if (empty($spaces)) {
    logLine("FATAL: Heather gehört keinem Space an.");
    exit(1);
}
$spaceId = (int) $spaces[0]['id'];
logLine("Space: {$spaces[0]['name']} (ID {$spaceId})");

$allMembers = SpaceRepository::members($spaceId);
$senders = array_values(array_filter($allMembers, fn($m) => (int) $m['id'] !== $heatherId));
if (empty($senders)) {
    logLine("FATAL: Keine anderen Mitglieder im Space.");
    exit(1);
}
logLine("Sender-Pool: " . count($senders) . " User");

// ── Main loop ────────────────────────────────────────────────
$lastMessageBody = '';

logLine("Dev-Bot gestartet (interval={$intervalSec}s, jitter=±{$jitterSec}s)");

while (!$shouldStop) {
    if (function_exists('pcntl_signal_dispatch')) {
        pcntl_signal_dispatch();
    }

    try {
        // 1. Pick random sender
        $sender = $senders[array_rand($senders)];
        $senderId = (int) $sender['id'];

        // 2. Pick random message (no consecutive duplicates)
        $body = pickMessage($lastMessageBody);
        $lastMessageBody = $body;

        // 3. Simulate auth as sender
        $_SESSION['user_id'] = $senderId;

        // 4. Get-or-create 1:1 DM conversation
        $conv = ConversationService::getOrCreateDirect($spaceId, $senderId, $heatherId);
        $convId = (int) $conv['id'];

        // 5. Send message (creates DB row + domain_event + triggers notifications)
        $message = MessageService::createConversation($convId, $senderId, [
            'body' => $body,
        ]);

        logLine(sprintf(
            "OK  sender=%d (%s)  target=%d  conv=%d  msg=%d  body=\"%s\"",
            $senderId,
            $sender['display_name'],
            $heatherId,
            $convId,
            (int) $message['id'],
            mb_substr($body, 0, 40)
        ));
    } catch (\Throwable $e) {
        logLine("ERR " . $e->getMessage() . " in " . $e->getFile() . ':' . $e->getLine());
    }

    if ($once) {
        break;
    }

    // 6. Sleep with jitter
    $sleepSec = $intervalSec;
    if ($jitterSec > 0) {
        $sleepSec += random_int(-$jitterSec, $jitterSec);
    }
    $sleepSec = max(10, $sleepSec);
    logLine("Nächste Nachricht in {$sleepSec}s ...");

    // Sleep in 1s increments for signal responsiveness
    for ($i = 0; $i < $sleepSec && !$shouldStop; $i++) {
        sleep(1);
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
    }
}

logLine("Dev-Bot beendet.");

// ── Helpers ──────────────────────────────────────────────────

function pickMessage(string $exclude): string
{
    $pool = MESSAGES;
    if ($exclude !== '' && count($pool) > 1) {
        $pool = array_values(array_filter($pool, fn($m) => $m !== $exclude));
    }
    return $pool[array_rand($pool)];
}

function logLine(string $msg): void
{
    $ts = date('Y-m-d H:i:s');
    fwrite(STDERR, "[{$ts}] [dev-bot] {$msg}" . PHP_EOL);
}
