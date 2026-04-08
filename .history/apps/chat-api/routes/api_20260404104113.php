<?php

use App\Controllers\AttachmentController;
use App\Controllers\AuthController;
use App\Controllers\ChannelController;
use App\Controllers\ConversationController;
use App\Controllers\KeyController;
use App\Controllers\MessageController;
use App\Controllers\PresenceController;
use App\Controllers\ReadReceiptController;
use App\Controllers\SearchController;
use App\Controllers\SpaceController;
use App\Controllers\UserController;
use App\Middleware\AuthMiddleware;

// --- Public routes ---
$router->post('/api/auth/login', [AuthController::class, 'login']);

// --- Authenticated routes ---
$router->group([AuthMiddleware::class], function ($router) {

    // Auth
    $router->get('/api/auth/me', [AuthController::class, 'me']);
    $router->post('/api/auth/logout', [AuthController::class, 'logout']);

    // Users
    $router->get('/api/users', [UserController::class, 'index']);
    $router->get('/api/users/{userId}', [UserController::class, 'show']);

    // Spaces
    $router->get('/api/spaces', [SpaceController::class, 'index']);
    $router->post('/api/spaces', [SpaceController::class, 'create']);
    $router->get('/api/spaces/{spaceId}', [SpaceController::class, 'show']);
    $router->get('/api/spaces/{spaceId}/members', [SpaceController::class, 'members']);
    $router->post('/api/spaces/{spaceId}/members', [SpaceController::class, 'addMember']);
    $router->put('/api/spaces/{spaceId}/members/{userId}', [SpaceController::class, 'updateMember']);
    $router->delete('/api/spaces/{spaceId}/members/{userId}', [SpaceController::class, 'removeMember']);

    // Channels (scoped to space)
    $router->get('/api/spaces/{spaceId}/channels', [ChannelController::class, 'index']);
    $router->post('/api/spaces/{spaceId}/channels', [ChannelController::class, 'create']);
    $router->get('/api/channels/{channelId}', [ChannelController::class, 'show']);
    $router->put('/api/channels/{channelId}', [ChannelController::class, 'update']);
    $router->delete('/api/channels/{channelId}', [ChannelController::class, 'delete']);
    $router->get('/api/channels/{channelId}/members', [ChannelController::class, 'members']);
    $router->post('/api/channels/{channelId}/join', [ChannelController::class, 'join']);
    $router->post('/api/channels/{channelId}/members', [ChannelController::class, 'addMember']);
    $router->delete('/api/channels/{channelId}/members/{userId}', [ChannelController::class, 'removeMember']);

    // Channel messages
    $router->get('/api/channels/{channelId}/messages', [MessageController::class, 'listChannel']);
    $router->post('/api/channels/{channelId}/messages', [MessageController::class, 'createChannel']);

    // Message edit / delete / history
    $router->put('/api/messages/{messageId}', [MessageController::class, 'update']);
    $router->delete('/api/messages/{messageId}', [MessageController::class, 'delete']);
    $router->get('/api/messages/{messageId}/history', [MessageController::class, 'history']);

    // Attachments
    $router->post('/api/messages/{messageId}/attachments', [AttachmentController::class, 'upload']);
    $router->get('/api/attachments/{attachmentId}', [AttachmentController::class, 'download']);

    // Conversations (DMs)
    $router->get('/api/conversations', [ConversationController::class, 'index']);
    $router->post('/api/conversations', [ConversationController::class, 'create']);
    $router->get('/api/conversations/{conversationId}', [ConversationController::class, 'show']);
    $router->get('/api/conversations/{conversationId}/members', [ConversationController::class, 'members']);
    $router->get('/api/conversations/{conversationId}/messages', [MessageController::class, 'listConversation']);
    $router->post('/api/conversations/{conversationId}/messages', [MessageController::class, 'createConversation']);

    // Read receipts
    $router->post('/api/channels/{channelId}/read', [ReadReceiptController::class, 'markChannelRead']);
    $router->post('/api/conversations/{conversationId}/read', [ReadReceiptController::class, 'markConversationRead']);
    $router->get('/api/unread', [ReadReceiptController::class, 'unreadCounts']);

    // Presence
    $router->post('/api/presence/heartbeat', [PresenceController::class, 'heartbeat']);
    $router->get('/api/presence/status', [PresenceController::class, 'status']);

    // Search
    $router->get('/api/search', [SearchController::class, 'search']);

    // E2EE key exchange
    $router->put('/api/keys/bundle', [KeyController::class, 'uploadBundle']);
    $router->get('/api/users/{userId}/keys', [KeyController::class, 'getUserKeys']);
    $router->post('/api/users/{userId}/keys/claim', [KeyController::class, 'claimKey']);
    $router->put('/api/conversations/{conversationId}/keys', [KeyController::class, 'storeConversationKey']);
    $router->get('/api/conversations/{conversationId}/keys', [KeyController::class, 'getConversationKeys']);
});