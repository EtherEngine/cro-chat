<?php

namespace App\Controllers;

use App\Exceptions\ApiException;
use App\Services\AttachmentService;
use App\Services\JobService;
use App\Services\MessageService;
use App\Support\Request;
use App\Support\Response;

final class AttachmentController
{
    /**
     * POST /api/messages/{messageId}/attachments
     * Accepts multipart/form-data with a "file" field.
     * User must own the message or be admin of the space.
     */
    public function upload(array $params): void
    {
        $userId = Request::requireUserId();
        $messageId = (int) $params['messageId'];

        // Verify message exists and user is the author
        $message = \App\Repositories\MessageRepository::find($messageId);
        if (!$message || $message['deleted_at'] !== null) {
            throw ApiException::notFound('Nachricht nicht gefunden', 'MESSAGE_NOT_FOUND');
        }
        if ((int) $message['user_id'] !== $userId) {
            throw ApiException::forbidden('Nur eigene Nachrichten', 'MESSAGE_OWNER_REQUIRED');
        }

        if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
            throw ApiException::validation('Kein "file" Feld im Upload', 'UPLOAD_MISSING');
        }

        $attachment = AttachmentService::store($_FILES['file'], $messageId);
        Response::json(['attachment' => $attachment], 201);
    }

    /**
     * GET /api/attachments/{attachmentId}
     * Streams the file after authorization check.
     */
    public function download(array $params): void
    {
        $userId = Request::requireUserId();
        $attachmentId = (int) $params['attachmentId'];

        // AttachmentService handles auth + streaming + exit
        AttachmentService::download($attachmentId, $userId);
    }
}
