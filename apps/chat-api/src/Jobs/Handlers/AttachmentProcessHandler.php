<?php

declare(strict_types=1);

namespace App\Jobs\Handlers;

use App\Jobs\JobHandler;
use App\Repositories\MessageRepository;
use App\Support\Database;

/**
 * Post-processes an uploaded attachment:
 *  - Generates image thumbnails (if GD available)
 *  - Extracts metadata (dimensions for images)
 *  - Updates the attachment record with processed data
 *
 * Idempotent: checks if metadata already exists before processing.
 *
 * Payload:
 *   attachment_id: int
 */
final class AttachmentProcessHandler implements JobHandler
{
    private const THUMBNAIL_MAX = 400;

    public function handle(array $payload): void
    {
        $attachmentId = (int) ($payload['attachment_id'] ?? 0);
        if ($attachmentId <= 0) {
            return;
        }

        $attachment = MessageRepository::findAttachment($attachmentId);
        if (!$attachment) {
            return;
        }

        // Idempotency: skip if already processed
        if (!empty($attachment['metadata'])) {
            $meta = json_decode($attachment['metadata'], true);
            if (!empty($meta['processed'])) {
                return;
            }
        }

        $storagePath = $this->storagePath($attachment['storage_name']);
        if (!file_exists($storagePath)) {
            return;
        }

        $metadata = [];

        // Extract image metadata if applicable
        if (str_starts_with($attachment['mime_type'], 'image/')) {
            $metadata = $this->processImage($storagePath, $attachment['mime_type']);
        }

        $metadata['processed'] = true;
        $metadata['processed_at'] = date('Y-m-d H:i:s');

        // Store metadata on the attachment
        Database::connection()->prepare(
            'UPDATE attachments SET metadata = ? WHERE id = ?'
        )->execute([json_encode($metadata, JSON_UNESCAPED_UNICODE), $attachmentId]);
    }

    private function processImage(string $path, string $mime): array
    {
        $info = @getimagesize($path);
        if ($info === false) {
            return [];
        }

        $meta = [
            'width' => $info[0],
            'height' => $info[1],
        ];

        // Generate thumbnail if GD is available
        if (extension_loaded('gd')) {
            $meta['has_thumbnail'] = $this->generateThumbnail($path, $mime, $info[0], $info[1]);
        }

        return $meta;
    }

    private function generateThumbnail(string $path, string $mime, int $w, int $h): bool
    {
        // Skip if already small enough
        if ($w <= self::THUMBNAIL_MAX && $h <= self::THUMBNAIL_MAX) {
            return false;
        }

        $src = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/gif' => @imagecreatefromgif($path),
            'image/webp' => @imagecreatefromwebp($path),
            default => false,
        };

        if (!$src) {
            return false;
        }

        $ratio = min(self::THUMBNAIL_MAX / $w, self::THUMBNAIL_MAX / $h);
        $newW = (int) ($w * $ratio);
        $newH = (int) ($h * $ratio);

        $thumb = imagecreatetruecolor($newW, $newH);
        if (!$thumb) {
            imagedestroy($src);
            return false;
        }

        // Preserve transparency for PNG/GIF/WebP
        if (in_array($mime, ['image/png', 'image/gif', 'image/webp'])) {
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
        }

        imagecopyresampled($thumb, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);

        $thumbPath = $path . '.thumb.webp';
        $result = imagewebp($thumb, $thumbPath, 80);

        imagedestroy($src);
        imagedestroy($thumb);

        return $result;
    }

    private function storagePath(string $storageName): string
    {
        $dir = realpath(__DIR__ . '/../../../storage/uploads') ?: __DIR__ . '/../../../storage/uploads';
        return $dir . DIRECTORY_SEPARATOR . $storageName;
    }
}
