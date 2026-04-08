<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Heuristic-based AI provider – no external API calls.
 *
 * Uses pattern matching and text statistics to generate summaries,
 * extract action items, create pseudo-embeddings, and suggest replies.
 *
 * Used as fallback when no API key is configured and for testing.
 */
final class HeuristicAiProvider implements AiProvider
{
    public function summarize(array $messages, string $scope, array $config = []): array
    {
        if (empty($messages)) {
            return ['summary' => '', 'key_points' => [], 'action_items' => [], 'title' => '', 'model' => 'heuristic', 'tokens_used' => 0];
        }

        $participants = array_values(array_unique(array_map(fn($m) => $m['display_name'] ?? 'User', $messages)));
        $keyPoints = $this->extractKeyPoints($messages);
        $actionItems = $this->findActionItems($messages);

        // Title from first message
        $firstBody = strip_tags($messages[0]['body'] ?? '');
        $title = mb_substr($firstBody, 0, 120);
        if (mb_strlen($firstBody) > 120) {
            $title .= '…';
        }

        // Build summary text
        $count = count($messages);
        $label = $scope === 'thread' ? 'Thread' : 'Channel-Zeitraum';
        $lines = [];
        $lines[] = "{$label} mit {$count} Nachrichten von " . implode(', ', array_slice($participants, 0, 5));
        if (count($participants) > 5) {
            $lines[] = '(und ' . (count($participants) - 5) . ' weitere)';
        }
        $lines[] = '';
        foreach ($keyPoints as $point) {
            $lines[] = '• ' . $point;
        }

        return [
            'summary' => implode("\n", $lines),
            'key_points' => $keyPoints,
            'action_items' => $actionItems,
            'title' => $title,
            'model' => 'heuristic',
            'tokens_used' => 0,
        ];
    }

    public function extractActions(array $messages, array $config = []): array
    {
        $items = $this->findActionItems($messages);
        return [
            'items' => $items,
            'model' => 'heuristic',
            'tokens_used' => 0,
        ];
    }

    public function embed(string $text, array $config = []): array
    {
        // Simple bag-of-words hash-based pseudo-embedding (64 dimensions)
        $dimensions = 64;
        $embedding = array_fill(0, $dimensions, 0.0);

        $words = preg_split('/\s+/', mb_strtolower(strip_tags($text)));
        foreach ($words as $word) {
            if (mb_strlen($word) < 2) continue;
            $hash = crc32($word);
            $idx = abs($hash) % $dimensions;
            $embedding[$idx] += 1.0;
        }

        // Normalize to unit vector
        $norm = sqrt(array_sum(array_map(fn($v) => $v * $v, $embedding)));
        if ($norm > 0) {
            $embedding = array_map(fn($v) => $v / $norm, $embedding);
        }

        return [
            'embedding' => $embedding,
            'model' => 'heuristic',
            'dimensions' => $dimensions,
            'tokens_used' => 0,
        ];
    }

    public function suggest(array $messages, string $userName, array $config = []): array
    {
        if (empty($messages)) {
            return ['suggestions' => [], 'model' => 'heuristic', 'tokens_used' => 0];
        }

        $lastMsg = end($messages);
        $lastBody = strip_tags($lastMsg['body'] ?? '');
        $suggestions = [];

        // Question detection → answer-type suggestions
        if (preg_match('/\?$|\bfrage\b|\bwas\b|\bwie\b|\bwarum\b|\bwann\b/iu', $lastBody)) {
            $suggestions[] = 'Gute Frage, lass mich kurz nachschauen.';
            $suggestions[] = 'Ich denke, wir sollten das im Team besprechen.';
            $suggestions[] = 'Hier ist mein Vorschlag: …';
        }
        // Agreement/decision → confirm-type suggestions
        elseif (preg_match('/\bentschieden\b|\bagreed\b|\bdecided\b|\bfazit\b/iu', $lastBody)) {
            $suggestions[] = 'Einverstanden, so machen wir das.';
            $suggestions[] = 'Klingt gut, ich kümmere mich darum.';
            $suggestions[] = 'Gibt es noch offene Punkte dazu?';
        }
        // Default
        else {
            $suggestions[] = 'Danke für die Info!';
            $suggestions[] = 'Verstanden, ich schaue mir das an.';
            $suggestions[] = 'Können wir das morgen besprechen?';
        }

        return [
            'suggestions' => $suggestions,
            'model' => 'heuristic',
            'tokens_used' => 0,
        ];
    }

    // ── Private helpers ──────────────────────────────────────

    private function extractKeyPoints(array $messages): array
    {
        $scored = [];
        foreach ($messages as $i => $msg) {
            $body = strip_tags($msg['body'] ?? '');
            if (mb_strlen($body) < 15) continue;

            $score = 0;
            $score += min(mb_strlen($body) / 50, 5);

            // Decision/conclusion keywords
            if (preg_match('/entschieden|beschlossen|agreed|decided|fazit|conclusion/iu', $body)) {
                $score += 10;
            }
            // Questions
            if (preg_match('/\?/', $body)) {
                $score += 2;
            }
            // Action items
            if (preg_match('/TODO|FIXME|aufgabe|task|action item|muss noch|needs to/iu', $body)) {
                $score += 5;
            }
            // Code blocks
            if (preg_match('/```/', $body)) {
                $score += 3;
            }
            // First/last bonus
            if ($i === 0 || $i === count($messages) - 1) {
                $score += 3;
            }

            $scored[] = ['body' => $body, 'score' => $score, 'name' => $msg['display_name'] ?? 'User'];
        }

        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

        $points = [];
        foreach (array_slice($scored, 0, 5) as $s) {
            $text = mb_substr($s['body'], 0, 150);
            if (mb_strlen($s['body']) > 150) $text .= '…';
            $points[] = $s['name'] . ': ' . $text;
        }

        return $points;
    }

    private function findActionItems(array $messages): array
    {
        $items = [];
        $patterns = [
            ['pattern' => '/\bTODO\b:?\s*(.{5,})/u', 'confidence' => 0.85],
            ['pattern' => '/\bFIXME\b:?\s*(.{5,})/u', 'confidence' => 0.80],
            ['pattern' => '/(aufgabe|task|action item)\s*:\s*(.{5,})/iu', 'confidence' => 0.75],
            ['pattern' => '/(muss noch|must|needs to|soll)\s+(.{5,})/iu', 'confidence' => 0.65],
        ];

        foreach ($messages as $i => $msg) {
            $body = strip_tags($msg['body'] ?? '');
            foreach ($patterns as $p) {
                if (preg_match($p['pattern'], $body, $m)) {
                    $title = trim($m[count($m) - 1]);
                    $title = mb_substr($title, 0, 200);
                    $items[] = [
                        'title' => $title,
                        'assignee_hint' => $msg['display_name'] ?? null,
                        'due_hint' => null,
                        'confidence' => $p['confidence'],
                        'source_index' => $i,
                    ];
                }
            }
        }

        return $items;
    }
}
