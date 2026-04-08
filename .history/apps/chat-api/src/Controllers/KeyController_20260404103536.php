<?php

namespace App\Controllers;

use App\Repositories\KeyRepository;
use App\Repositories\ConversationRepository;
use App\Repositories\SpaceRepository;
use App\Services\ConversationService;
use App\Support\Request;
use App\Support\Response;
use App\Support\Validator;

final class KeyController
{
    /**
     * PUT /api/keys/bundle
     * Upload / update the caller's key bundle for a device.
     * Body: { device_id, identity_key, signed_pre_key, pre_key_sig, one_time_keys?[] }
     */
    public function uploadBundle(): void
    {
        $userId = Request::requireUserId();
        $input = Request::json();
        (new Validator($input))
            ->required('device_id', 'identity_key', 'signed_pre_key', 'pre_key_sig')
            ->validate();

        $oneTimeKeys = isset($input['one_time_keys']) && is_array($input['one_time_keys'])
            ? $input['one_time_keys']
            : null;

        KeyRepository::upsertUserKeys(
            $userId,
            $input['device_id'],
            $input['identity_key'],
            $input['signed_pre_key'],
            $input['pre_key_sig'],
            $oneTimeKeys
        );

        Response::json(['ok' => true]);
    }

    /**
     * GET /api/users/{userId}/keys
     * Fetch all key bundles for a user (one per device).
     */
    public function getUserKeys(array $params): void
    {
        $userId = Request::requireUserId();
        $targetId = (int) $params['userId'];

        if ($targetId !== $userId && !SpaceRepository::sharesSpace($userId, $targetId)) {
            Response::error('Kein Zugriff', 403);
        }

        $keys = KeyRepository::getUserKeys($targetId);
        Response::json(['keys' => $keys]);
    }

    /**
     * POST /api/users/{userId}/keys/claim
     * Claim a one-time pre-key for a user+device.
     * Body: { device_id }
     */
    public function claimKey(array $params): void
    {
        Request::requireUserId();
        $input = Request::json();
        (new Validator($input))->required('device_id')->validate();

        $key = KeyRepository::claimOneTimeKey(
            (int) $params['userId'],
            $input['device_id']
        );

        Response::json(['one_time_key' => $key]);
    }

    /**
     * PUT /api/conversations/{conversationId}/keys
     * Store the caller's encrypted session key for this conversation.
     * Body: { device_id, encrypted_key }
     */
    public function storeConversationKey(array $params): void
    {
        $userId = Request::requireUserId();
        $convId = (int) $params['conversationId'];
        ConversationService::requireMember($convId, $userId);

        $input = Request::json();
        (new Validator($input))->required('device_id', 'encrypted_key')->validate();

        KeyRepository::upsertConversationKey(
            $convId,
            $userId,
            $input['device_id'],
            $input['encrypted_key']
        );

        Response::json(['ok' => true]);
    }

    /**
     * GET /api/conversations/{conversationId}/keys
     * Get all encrypted session keys for a conversation (all members' devices).
     */
    public function getConversationKeys(array $params): void
    {
        $userId = Request::requireUserId();
        $convId = (int) $params['conversationId'];
        ConversationService::requireMember($convId, $userId);

        $keys = KeyRepository::getConversationKeys($convId);
        Response::json(['keys' => $keys]);
    }
}
