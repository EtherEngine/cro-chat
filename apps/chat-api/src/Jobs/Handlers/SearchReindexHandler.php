<?php

declare(strict_types=1);

namespace App\Jobs\Handlers;

use App\Jobs\JobHandler;
use App\Repositories\MessageRepository;
use App\Support\Database;

/**
 * Rebuilds the FULLTEXT search index for a specific message or batch.
 *
 * Idempotent: updating the same message body to the same value is a no-op.
 * The FULLTEXT index is automatically maintained by InnoDB — this handler
 * ensures consistency after edits/deletes and can trigger OPTIMIZE TABLE
 * for bulk operations.
 *
 * Payload:
 *   action: 'single' | 'optimize'
 *   message_id?: int (for single)
 */
final class SearchReindexHandler implements JobHandler
{
    public function handle(array $payload): void
    {
        $action = $payload['action'] ?? 'single';

        switch ($action) {
            case 'single':
                $this->reindexSingle((int) ($payload['message_id'] ?? 0));
                break;

            case 'optimize':
                $this->optimizeTable();
                break;
        }
    }

    /**
     * Re-index a single message by touching its body (triggers FULLTEXT update).
     * If the message was soft-deleted, the FULLTEXT index should already exclude it
     * in queries via WHERE deleted_at IS NULL, but we still update for consistency.
     */
    private function reindexSingle(int $messageId): void
    {
        if ($messageId <= 0) {
            return;
        }

        $msg = MessageRepository::findBasic($messageId);
        if (!$msg) {
            return; // Hard-deleted or never existed
        }

        // Touch the row to force FULLTEXT re-index
        Database::connection()->prepare(
            'UPDATE messages SET body = body WHERE id = ?'
        )->execute([$messageId]);
    }

    /**
     * Run OPTIMIZE TABLE to rebuild the FULLTEXT index for better performance.
     * Should be scheduled periodically (e.g. nightly).
     */
    private function optimizeTable(): void
    {
        Database::connection()->exec('OPTIMIZE TABLE messages');
    }
}
