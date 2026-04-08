<?php

/**
 * Channel Bot – Simuliert Channel-Nachrichten alle 5 Minuten.
 *
 * Ein zufälliger User schreibt in einen zufälligen Channel.
 * Nutzt die Service-Schicht direkt → DB + domain_event + WebSocket.
 *
 * Usage:
 *   php channel-bot.php                      # default 300s (5 min)
 *   php channel-bot.php --interval=60        # every 60s
 *   php channel-bot.php --channel=1          # nur Channel ID 1
 *   php channel-bot.php --once               # eine Nachricht, dann Exit
 */

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use App\Repositories\ChannelRepository;
use App\Repositories\SpaceRepository;
use App\Repositories\UserRepository;
use App\Services\MessageService;
use App\Support\Database;
use App\Support\Env;

// ── Bootstrap ────────────────────────────────────────────────
Env::load(__DIR__ . '/.env');
$dbConfig = require __DIR__ . '/src/Config/database.php';
Database::init($dbConfig);
date_default_timezone_set('Europe/Berlin');
$_SESSION = [];

// ── CLI args ─────────────────────────────────────────────────
$intervalSec = 300;
$jitterSec = 30;
$once = false;
$fixedChannelId = null;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--interval=')) {
        $intervalSec = max(10, (int) substr($arg, 11));
    }
    if (str_starts_with($arg, '--jitter=')) {
        $jitterSec = max(0, (int) substr($arg, 9));
    }
    if (str_starts_with($arg, '--channel=')) {
        $fixedChannelId = (int) substr($arg, 10);
    }
    if ($arg === '--once') {
        $once = true;
    }
}

// ── Nachrichten-Pool ─────────────────────────────────────────
const MESSAGES = [
    'Guten Morgen zusammen! ☀️',
    'Hat jemand das Meeting gerade gesehen?',
    'Kurzes Update: Ich bin fertig mit dem Task.',
    'Kann jemand mal drüberschauen? 👀',
    'Das sieht richtig gut aus!',
    'Wer hat Lust auf Kaffee? ☕',
    'Reminder: Standup in 15 Minuten',
    'Ich push das gleich auf main',
    'Bug gefunden – ich kümmere mich drum 🐛',
    'Danke für den Fix! 🎉',
    'Hab gerade den PR reviewed – sieht sauber aus',
    'Jemand Erfahrung mit WebSockets?',
    'Bin kurz AFK, bis gleich',
    'Das Deploy lief durch ✅',
    'Wochenende! 🥳',
    'Neuer Vorschlag im Dokument – Feedback willkommen',
    'Ich teste gerade den neuen Flow',
    'Alles klar, merged!',
    'Kann mir jemand den Kontext geben?',
    'Läuft bei uns 🚀',
];

// ── Signal handling ──────────────────────────────────────────
$shouldStop = false;
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, function () use (&$shouldStop) {
        $shouldStop = true;
    });
    pcntl_signal(SIGINT, function () use (&$shouldStop) {
        $shouldStop = true;
    });
}

// ── Space + Channels laden ───────────────────────────────────
$spaces = SpaceRepository::forUser(1); // Heather = User 1
if (empty($spaces)) {
    logLine("FATAL: Kein Space gefunden.");
    exit(1);
}
$spaceId = (int) $spaces[0]['id'];
logLine("Space: {$spaces[0]['name']} (ID {$spaceId})");

// Alle Channels im Space laden
$pdo = Database::connection();
$stmt = $pdo->prepare('SELECT id, name FROM channels WHERE space_id = ? ORDER BY id');
$stmt->execute([$spaceId]);
$channels = $stmt->fetchAll(\PDO::FETCH_ASSOC);

if ($fixedChannelId !== null) {
    $channels = array_values(array_filter($channels, fn($c) => (int) $c['id'] === $fixedChannelId));
    if (empty($channels)) {
        logLine("FATAL: Channel ID {$fixedChannelId} nicht gefunden.");
        exit(1);
    }
}

if (empty($channels)) {
    logLine("FATAL: Keine Channels im Space.");
    exit(1);
}
logLine("Channels: " . implode(', ', array_map(fn($c) => "#{$c['name']} ({$c['id']})", $channels)));

// ── Main loop ────────────────────────────────────────────────
$lastMessageBody = '';

logLine("Channel-Bot gestartet (interval={$intervalSec}s, jitter=±{$jitterSec}s)");

while (!$shouldStop) {
    if (function_exists('pcntl_signal_dispatch')) {
        pcntl_signal_dispatch();
    }

    // DB keep-alive
    try {
        Database::connection()->query('SELECT 1');
    } catch (\Throwable) {
        logLine("DB-Reconnect ...");
        Database::reconnect($dbConfig);
    }

    try {
        // 1. Zufälligen Channel wählen
        $channel = $channels[array_rand($channels)];
        $channelId = (int) $channel['id'];

        // 2. Mitglieder des Channels laden
        $stmt = $pdo->prepare('SELECT user_id FROM channel_members WHERE channel_id = ?');
        $stmt->execute([$channelId]);
        $memberIds = array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'user_id');

        if (empty($memberIds)) {
            logLine("SKIP: Channel #{$channel['name']} hat keine Mitglieder.");
            sleep(5);
            continue;
        }

        // 3. Zufälligen Sender aus den Channel-Mitgliedern wählen
        $senderId = (int) $memberIds[array_rand($memberIds)];

        // Sender-Name holen
        $sender = UserRepository::find($senderId);
        $senderName = $sender ? $sender['display_name'] : "User {$senderId}";

        // 4. Nachricht wählen (keine Wiederholung)
        $body = pickMessage($lastMessageBody);
        $lastMessageBody = $body;

        // 5. Session simulieren
        $_SESSION['user_id'] = $senderId;

        // 6. Nachricht senden
        $message = MessageService::createChannel($channelId, $senderId, [
            'body' => $body,
        ]);

        logLine(sprintf(
            "OK  #%s (ch=%d)  sender=%d (%s)  msg=%d  \"%s\"",
            $channel['name'],
            $channelId,
            $senderId,
            $senderName,
            (int) $message['id'],
            mb_substr($body, 0, 50)
        ));
    } catch (\Throwable $e) {
        logLine("ERR " . $e->getMessage() . " in " . $e->getFile() . ':' . $e->getLine());
    }

    if ($once) {
        break;
    }

    // 7. Sleep mit Jitter
    $sleepSec = $intervalSec;
    if ($jitterSec > 0) {
        $sleepSec += random_int(-$jitterSec, $jitterSec);
    }
    $sleepSec = max(10, $sleepSec);
    logLine("Nächste Nachricht in {$sleepSec}s ...");

    for ($i = 0; $i < $sleepSec && !$shouldStop; $i++) {
        sleep(1);
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
    }
}

logLine("Channel-Bot beendet.");

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
    fwrite(STDERR, "[{$ts}] [channel-bot] {$msg}" . PHP_EOL);
}
