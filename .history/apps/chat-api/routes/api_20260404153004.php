<?php

use App\Controllers\AttachmentController;
use App\Controllers\AuthController;
use App\Controllers\ChannelController;
use App\Controllers\ConversationController;
use App\Controllers\JobController;
use App\Controllers\KeyController;
use App\Controllers\MentionController;
use App\Controllers\MessageController;
use App\Controllers\ModerationController;
use App\Controllers\NotificationController;
use App\Controllers\PinController;
use App\Controllers\PresenceController;
use App\Controllers\ReactionController;
use App\Controllers\ReadReceiptController;
use App\Controllers\SavedMessageController;
use App\Controllers\SearchController;
use App\Controllers\SpaceController;
use App\Controllers\ThreadController;
use App\Controllers\UserController;
use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;

// --- Public routes ---
$router->post('/api/auth/login', [AuthController::class, 'login']);

// --- Authenticated routes ---
$router->group([AuthMiddleware::class, CsrfMiddleware::class], function ($router) {

    // Auth
    $router->get('/api/auth/me', [AuthController::class, 'me']);
    $router->post('/api/auth/logout', [AuthController::class, 'logout']);
    $router->post('/api/auth/ws-ticket', [AuthController::class, 'wsTicket']);

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

    // Reactions
    $router->post('/api/messages/{messageId}/reactions', [ReactionController::class, 'add']);
    $router->delete('/api/messages/{messageId}/reactions', [ReactionController::class, 'remove']);
    $router->get('/api/messages/{messageId}/reactions', [ReactionController::class, 'list']);

    // Pins
    $router->post('/api/messages/{messageId}/pin', [PinController::class, 'pin']);
    $router->delete('/api/messages/{messageId}/pin', [PinController::class, 'unpin']);
    $router->get('/api/channels/{channelId}/pins', [PinController::class, 'channelPins']);
    $router->get('/api/conversations/{conversationId}/pins', [PinController::class, 'conversationPins']);

    // Saved messages
    $router->post('/api/messages/{messageId}/save', [SavedMessageController::class, 'save']);
    $router->delete('/api/messages/{messageId}/save', [SavedMessageController::class, 'unsave']);
    $router->get('/api/saved-messages', [SavedMessageController::class, 'index']);

    // Attachments
    $router->post('/api/messages/{messageId}/attachments', [AttachmentController::class, 'upload']);
    $router->get('/api/attachments/{attachmentId}', [AttachmentController::class, 'download']);

    // Threads
    $router->post('/api/messages/{messageId}/thread', [ThreadController::class, 'startThread']);
    $router->get('/api/threads/{threadId}', [ThreadController::class, 'show']);
    $router->post('/api/threads/{threadId}/replies', [ThreadController::class, 'createReply']);
    $router->post('/api/threads/{threadId}/read', [ThreadController::class, 'markRead']);

    // Conversations (DMs)
    $router->get('/api/conversations', [ConversationController::class, 'index']);
    $router->post('/api/conversations', [ConversationController::class, 'create']);
    $router->get('/api/conversations/{conversationId}', [ConversationController::class, 'show']);
    $router->put('/api/conversations/{conversationId}', [ConversationController::class, 'update']);
    $router->get('/api/conversations/{conversationId}/members', [ConversationController::class, 'members']);
    $router->post('/api/conversations/{conversationId}/members', [ConversationController::class, 'addMember']);
    $router->delete('/api/conversations/{conversationId}/members/{userId}', [ConversationController::class, 'removeMember']);
    $router->post('/api/conversations/{conversationId}/leave', [ConversationController::class, 'leave']);
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

    // Mention autocomplete
    $router->get('/api/mentions/search', [MentionController::class, 'search']);

    // Notifications
    $router->get('/api/notifications', [NotificationController::class, 'index']);
    $router->get('/api/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    $router->post('/api/notifications/{notificationId}/read', [NotificationController::class, 'markRead']);
    $router->post('/api/notifications/read-all', [NotificationController::class, 'markAllRead']);

    // E2EE key exchange
    $router->put('/api/keys/bundle', [KeyController::class, 'uploadBundle']);
    $router->get('/api/users/{userId}/keys', [KeyController::class, 'getUserKeys']);
    $router->post('/api/users/{userId}/keys/claim', [KeyController::class, 'claimKey']);
    $router->put('/api/conversations/{conversationId}/keys', [KeyController::class, 'storeConversationKey']);
    $router->get('/api/conversations/{conversationId}/keys', [KeyController::class, 'getConversationKeys']);

    // Moderation
    $router->delete('/api/moderation/messages/{messageId}', [ModerationController::class, 'deleteMessage']);
    $router->post('/api/channels/{channelId}/moderation/mute', [ModerationController::class, 'muteUser']);
    $router->post('/api/channels/{channelId}/moderation/unmute', [ModerationController::class, 'unmuteUser']);
    $router->delete('/api/channels/{channelId}/moderation/members/{userId}', [ModerationController::class, 'kickUser']);
    $router->put('/api/spaces/{spaceId}/moderation/roles/{userId}', [ModerationController::class, 'changeSpaceRole']);
    $router->put('/api/channels/{channelId}/moderation/roles/{userId}', [ModerationController::class, 'changeChannelRole']);
    $router->get('/api/spaces/{spaceId}/moderation/log', [ModerationController::class, 'spaceLog']);
    $router->get('/api/channels/{channelId}/moderation/log', [ModerationController::class, 'channelLog']);

    // Jobs
    $router->get('/api/jobs/stats', [JobController::class, 'stats']);
    $router->post('/api/jobs/schedule-maintenance', [JobController::class, 'scheduleMaintenance']);
});