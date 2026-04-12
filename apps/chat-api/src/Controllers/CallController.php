<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Exceptions\ApiException;
use App\Middleware\RateLimitMiddleware;
use App\Services\AbuseDetection;
use App\Services\CallService;
use App\Support\Request;
use App\Support\Response;
use App\Support\Validator;

final class CallController
{
    /**
     * POST /api/calls
     * Body: { conversation_id }
     * Initiates a 1:1 audio call on a direct conversation.
     */
    public function initiate(): void
    {
        $userId = Request::requireUserId();
        $input = Request::json();
        (new Validator($input))->required('conversation_id')->validate();

        // Rate limit: max 5 call initiations per 60 seconds per user
        RateLimitMiddleware::check('call_initiate', (string) $userId, maxAttempts: 5, windowSeconds: 60);

        try {
            $call = CallService::initiate((int) $input['conversation_id'], $userId);
            Response::json(['call' => $call], 201);
        } catch (ApiException $e) {
            // Record abuse for repeated blocked attempts (busy, DND, already active)
            if (in_array($e->errorCode, ['CALLEE_BUSY', 'CALLER_BUSY', 'CALL_ALREADY_ACTIVE'], true)) {
                AbuseDetection::recordViolation('user', (string) $userId, 'call_spam', 5);
            }
            throw $e;
        }
    }

    /**
     * POST /api/calls/{callId}/accept
     * Callee accepts the ringing call.
     */
    public function accept(array $params): void
    {
        $userId = Request::requireUserId();
        $callId = (int) ($params['callId'] ?? 0);
        if ($callId <= 0) {
            throw ApiException::validation('Ungültige Anruf-ID', 'INVALID_CALL_ID');
        }
        // Rate limit: max 20 call actions per 60 seconds per user
        RateLimitMiddleware::check('call_action', (string) $userId, maxAttempts: 20, windowSeconds: 60);
        $call = CallService::accept($callId, $userId);
        Response::json(['call' => $call]);
    }

    /**
     * POST /api/calls/{callId}/reject
     * Callee rejects the ringing call.
     */
    public function reject(array $params): void
    {
        $userId = Request::requireUserId();
        $callId = (int) ($params['callId'] ?? 0);
        if ($callId <= 0) {
            throw ApiException::validation('Ungültige Anruf-ID', 'INVALID_CALL_ID');
        }
        RateLimitMiddleware::check('call_action', (string) $userId, maxAttempts: 20, windowSeconds: 60);
        $call = CallService::reject($callId, $userId);
        Response::json(['call' => $call]);
    }

    /**
     * POST /api/calls/{callId}/cancel
     * Caller cancels the outgoing (ringing) call before callee answers.
     */
    public function cancel(array $params): void
    {
        $userId = Request::requireUserId();
        $callId = (int) ($params['callId'] ?? 0);
        if ($callId <= 0) {
            throw ApiException::validation('Ungültige Anruf-ID', 'INVALID_CALL_ID');
        }
        RateLimitMiddleware::check('call_action', (string) $userId, maxAttempts: 20, windowSeconds: 60);
        $call = CallService::cancel($callId, $userId);
        Response::json(['call' => $call]);
    }

    /**
     * POST /api/calls/{callId}/hangup
     * Either party ends an active (accepted) call.
     */
    public function hangup(array $params): void
    {
        $userId = Request::requireUserId();
        $callId = (int) ($params['callId'] ?? 0);
        if ($callId <= 0) {
            throw ApiException::validation('Ungültige Anruf-ID', 'INVALID_CALL_ID');
        }
        RateLimitMiddleware::check('call_action', (string) $userId, maxAttempts: 20, windowSeconds: 60);
        $call = CallService::hangup($callId, $userId);
        Response::json(['call' => $call]);
    }

    /**
     * GET /api/calls/{callId}
     * Retrieve call details including sessions (participants only).
     */
    public function show(array $params): void
    {
        $userId = Request::requireUserId();
        $callId = (int) ($params['callId'] ?? 0);
        if ($callId <= 0) {
            throw ApiException::validation('Ungültige Anruf-ID', 'INVALID_CALL_ID');
        }
        $call = CallService::show($callId, $userId);
        Response::json(['call' => $call]);
    }

    /**
     * GET /api/conversations/{conversationId}/calls/active
     * Retrieve the currently active call for a conversation, or null.
     */
    public function active(array $params): void
    {
        $userId = Request::requireUserId();
        $conversationId = (int) ($params['conversationId'] ?? 0);
        if ($conversationId <= 0) {
            throw ApiException::validation('Ungültige Gespräch-ID', 'INVALID_CONVERSATION_ID');
        }
        $call = CallService::active($conversationId, $userId);
        Response::json(['call' => $call]);
    }

    /**
     * GET /api/conversations/{conversationId}/calls
     * Call history for a direct conversation.
     */
    public function history(array $params): void
    {
        $userId = Request::requireUserId();
        $conversationId = (int) ($params['conversationId'] ?? 0);
        if ($conversationId <= 0) {
            throw ApiException::validation('Ungültige Gespräch-ID', 'INVALID_CONVERSATION_ID');
        }
        $limit = isset($_GET['limit']) ? min((int) $_GET['limit'], 100) : 50;
        $offset = isset($_GET['offset']) ? max((int) $_GET['offset'], 0) : 0;

        $calls = CallService::history($conversationId, $userId, $limit, $offset);
        Response::json(['calls' => $calls]);
    }

    /**
     * GET /api/calls/ice-servers
     * Return ICE configuration with time-limited TURN credentials.
     */
    public function iceServers(): void
    {
        $userId = Request::requireUserId();
        // Rate limit: max 10 ICE config requests per 60 seconds per user
        RateLimitMiddleware::check('call_ice', (string) $userId, maxAttempts: 10, windowSeconds: 60);
        $config = CallService::iceServers($userId);
        Response::json($config);
    }
}
