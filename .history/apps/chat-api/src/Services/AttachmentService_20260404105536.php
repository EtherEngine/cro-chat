<?php

namespace App\Services;

use App\Repositories\MessageRepository;
use App\Support\Env;
use App\Support\Response;

final class AttachmentService
{
    private static function maxSize(): int
    {
        return Env::int('UPLOAD_MAX_SIZE', 10 * 1024 * 1024);
    }

    /** Allowed MIME types (server-verified via finfo) */
    private const ALLOWED_TYPES = [
        // Images
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/svg+xml',
        // Documents
        'application/pdf',
        'text/plain',
        'text/csv',
        'text/markdown',
        // Archives
        'application/zip',
        'application/gzip',
        // Office
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        // Audio/Video
        'audio/mpeg',
        'audio/ogg',
        'video/mp4',
        'video/webm',
    ];

    /** Map MIME → file extension for storage name */
    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/svg+xml' => 'svg',
        'application/pdf' => 'pdf',
        'text/plain' => 'txt',
        'text/csv' => 'csv',
        'text/markdown' => 'md',
        'application/zip' => 'zip',
        'application/gzip' => 'gz',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'audio/mpeg' => 'mp3',
        'audio/ogg' => 'ogg',
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
    ];

    private static function uploadDir(): string
    {
        return realpath(__DIR__ . '/../../storage/uploads') ?: __DIR__ . '/../../storage/uploads';
    }

    /**
     * Validate and store an uploaded file, then create DB record.
     *
     * @param array $file  $_FILES entry (tmp_name, size, error, name)
     * @param int   $messageId
     * @return array  attachment record
     */
    public static function store(array $file, int $messageId): array
    {
        // ── 1. Check upload error ─────────────────
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            Response::error('Upload fehlgeschlagen (error ' . ($file['error'] ?? 'unknown') . ')', 422);
        }

        $tmpPath = $file['tmp_name'] ?? '';
        if (!is_uploaded_file($tmpPath)) {
            Response::error('Ungültiger Upload', 422);
        }

        // ── 2. Enforce size limit ─────────────────
        $fileSize = (int) $file['size'];
        $maxSize = self::maxSize();
        if ($fileSize > $maxSize || $fileSize === 0) {
            Response::error('Datei zu groß (max. ' . ($maxSize / 1024 / 1024) . ' MB)', 422);
        }

        // ── 3. Server-side MIME detection (never trust client-reported type) ──
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($tmpPath);
        if ($mimeType === false || !in_array($mimeType, self::ALLOWED_TYPES, true)) {
            Response::error('Dateityp nicht erlaubt: ' . ($mimeType ?: 'unbekannt'), 422);
        }

        // ── 4. Generate random storage name (never use original) ──
        $ext = self::EXTENSIONS[$mimeType] ?? 'bin';
        $storageName = bin2hex(random_bytes(32)) . '.' . $ext;

        // ── 5. Sanitise original name for display only ──
        $originalName = self::sanitiseFileName($file['name'] ?? 'upload');

        // ── 6. Move to storage ────────────────────
        $destPath = self::uploadDir() . DIRECTORY_SEPARATOR . $storageName;
        if (!move_uploaded_file($tmpPath, $destPath)) {
            Response::error('Speichern fehlgeschlagen', 500);
        }

        // ── 7. Persist metadata ───────────────────
        return MessageRepository::addAttachment(
            $messageId,
            $originalName,
            $storageName,
            $mimeType,
            $fileSize
        );
    }

    /**
     * Stream a file to the client after authorization check.
     *
     * @param int $attachmentId
     * @param int $userId  requesting user
     */
    public static function download(int $attachmentId, int $userId): never
    {
        $attachment = MessageRepository::findAttachment($attachmentId);
        if (!$attachment) {
            Response::error('Anhang nicht gefunden', 404);
        }

        // Authorization: user must have access to the message's context
        $message = MessageRepository::find((int) $attachment['message_id']);
        if (!$message) {
            Response::error('Nachricht nicht gefunden', 404);
        }

        self::requireMessageAccess($message, $userId);

        $filePath = self::uploadDir() . DIRECTORY_SEPARATOR . $attachment['storage_name'];

        // Path traversal protection: ensure resolved path is inside upload dir
        $realPath = realpath($filePath);
        $realUploadDir = realpath(self::uploadDir());
        if ($realPath === false || !str_starts_with($realPath, $realUploadDir)) {
            Response::error('Datei nicht gefunden', 404);
        }

        // Stream the file
        header('Content-Type: ' . $attachment['mime_type']);
        header('Content-Length: ' . $attachment['file_size']);
        header('Content-Disposition: inline; filename="' . addcslashes($attachment['original_name'], '"\\') . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=3600');

        readfile($realPath);
        exit;
    }

    /**
     * Checks that the user can access the message's channel or conversation.
     */
    private static function requireMessageAccess(array $message, int $userId): void
    {
        if ($message['channel_id']) {
            $channel = \App\Repositories\ChannelRepository::find((int) $message['channel_id']);
            if ($channel) {
                ChannelService::requireAccess($channel, $userId);
                return;
            }
        }
        if ($message['conversation_id']) {
            if (!\App\Repositories\ConversationRepository::isMember((int) $message['conversation_id'], $userId)) {
                Response::error('Kein Zugriff', 403);
            }
            return;
        }
        Response::error('Kein Zugriff', 403);
    }

    /**
     * Strip dangerous characters from filename, keep only basename.
     */
    private static function sanitiseFileName(string $name): string
    {
        // Take only basename (strip path components)
        $name = basename($name);
        // Remove null bytes and control characters
        $name = preg_replace('/[\x00-\x1f\x7f]/', '', $name);
        // Collapse to reasonable length
        if (mb_strlen($name) > 200) {
            $ext = pathinfo($name, PATHINFO_EXTENSION);
            $name = mb_substr($name, 0, 195) . '.' . $ext;
        }
        return $name ?: 'upload';
    }
}
