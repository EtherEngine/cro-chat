<?php

use App\Controllers\AdminController;
use App\Controllers\AnalyticsController;
use App\Controllers\AttachmentController;
use App\Controllers\AuthController;
use App\Controllers\ChannelController;
use App\Controllers\ComplianceController;
use App\Controllers\ConversationController;
use App\Controllers\DeviceController;
use App\Controllers\HealthController;
use App\Controllers\IncomingWebhookController;
use App\Controllers\IntegrationController;
use App\Controllers\JobController;
use App\Controllers\KeyController;
use App\Controllers\KnowledgeController;
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
use App\Controllers\RichContentController;
use App\Controllers\TaskController;
use App\Controllers\ThreadController;
use App\Controllers\UserController;
use App\Controllers\ScalingController;
use App\Middleware\ApiTokenMiddleware;
use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;

// --- Health probes (no auth) ---
$router->get('/health/live', [HealthController::class, 'live']);
$router->get('/health/ready', [HealthController::class, 'ready']);

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
    $router->put('/api/users/me/profile', [UserController::class, 'updateProfile']);
    $router->put('/api/users/me/password', [UserController::class, 'changePassword']);
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
    $router->get('/api/search/advanced', [SearchController::class, 'advanced']);
    $router->get('/api/search/saved', [SearchController::class, 'listSaved']);
    $router->post('/api/search/saved', [SearchController::class, 'createSaved']);
    $router->get('/api/search/saved/{savedSearchId}', [SearchController::class, 'getSaved']);
    $router->put('/api/search/saved/{savedSearchId}', [SearchController::class, 'updateSaved']);
    $router->delete('/api/search/saved/{savedSearchId}', [SearchController::class, 'deleteSaved']);
    $router->post('/api/search/saved/{savedSearchId}/execute', [SearchController::class, 'executeSaved']);
    $router->get('/api/search/history', [SearchController::class, 'history']);
    $router->delete('/api/search/history', [SearchController::class, 'clearHistory']);
    $router->get('/api/search/suggest', [SearchController::class, 'suggest']);

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

    // Admin panel (owner/admin only)
    $router->get('/api/spaces/{spaceId}/admin/stats', [AdminController::class, 'stats']);
    $router->get('/api/spaces/{spaceId}/admin/members', [AdminController::class, 'members']);
    $router->get('/api/spaces/{spaceId}/admin/channels', [AdminController::class, 'channels']);
    $router->delete('/api/spaces/{spaceId}/admin/members/{userId}', [AdminController::class, 'removeMember']);
    $router->put('/api/spaces/{spaceId}/admin/members/{userId}/mute', [AdminController::class, 'muteMember']);
    $router->delete('/api/spaces/{spaceId}/admin/members/{userId}/mute', [AdminController::class, 'unmuteMember']);
    $router->get('/api/spaces/{spaceId}/admin/jobs', [AdminController::class, 'jobs']);
    $router->post('/api/spaces/{spaceId}/admin/jobs/{jobId}/retry', [AdminController::class, 'retryJob']);
    $router->post('/api/spaces/{spaceId}/admin/jobs/purge', [AdminController::class, 'purgeJobs']);
    $router->get('/api/spaces/{spaceId}/admin/notifications', [AdminController::class, 'notifications']);
    $router->get('/api/spaces/{spaceId}/admin/realtime', [AdminController::class, 'realtime']);

    // Analytics (event tracking + dashboards)
    $router->post('/api/spaces/{spaceId}/analytics/events', [AnalyticsController::class, 'trackEvent']);
    $router->post('/api/spaces/{spaceId}/analytics/events/batch', [AnalyticsController::class, 'trackBatch']);
    $router->get('/api/spaces/{spaceId}/analytics/dashboard', [AnalyticsController::class, 'dashboard']);
    $router->get('/api/spaces/{spaceId}/analytics/engagement', [AnalyticsController::class, 'engagement']);
    $router->get('/api/spaces/{spaceId}/analytics/channels', [AnalyticsController::class, 'channelActivity']);
    $router->get('/api/spaces/{spaceId}/analytics/response-times', [AnalyticsController::class, 'responseTimes']);
    $router->get('/api/spaces/{spaceId}/analytics/search', [AnalyticsController::class, 'searchUsage']);
    $router->get('/api/spaces/{spaceId}/analytics/notifications', [AnalyticsController::class, 'notificationEngagement']);
    $router->get('/api/spaces/{spaceId}/analytics/system', [AnalyticsController::class, 'systemEvents']);
    $router->get('/api/spaces/{spaceId}/analytics/daily', [AnalyticsController::class, 'dailyMetrics']);
    $router->post('/api/spaces/{spaceId}/analytics/aggregate', [AnalyticsController::class, 'aggregate']);
    $router->get('/api/analytics/event-types', [AnalyticsController::class, 'eventTypes']);

    // Compliance & Data Management (owner/admin)
    $router->get('/api/spaces/{spaceId}/compliance/summary', [ComplianceController::class, 'summary']);
    $router->get('/api/spaces/{spaceId}/compliance/retention', [ComplianceController::class, 'listPolicies']);
    $router->put('/api/spaces/{spaceId}/compliance/retention', [ComplianceController::class, 'upsertPolicy']);
    $router->post('/api/spaces/{spaceId}/compliance/retention/apply', [ComplianceController::class, 'applyRetention']);
    $router->post('/api/spaces/{spaceId}/compliance/export', [ComplianceController::class, 'requestExport']);
    $router->get('/api/spaces/{spaceId}/compliance/exports', [ComplianceController::class, 'listExports']);
    $router->get('/api/spaces/{spaceId}/compliance/exports/{exportId}/download', [ComplianceController::class, 'downloadExport']);
    $router->post('/api/spaces/{spaceId}/compliance/deletion', [ComplianceController::class, 'requestDeletion']);
    $router->get('/api/spaces/{spaceId}/compliance/deletions', [ComplianceController::class, 'listDeletions']);
    $router->post('/api/spaces/{spaceId}/compliance/deletions/{requestId}/cancel', [ComplianceController::class, 'cancelDeletion']);
    $router->get('/api/spaces/{spaceId}/compliance/log', [ComplianceController::class, 'log']);

    // Devices & Push Notifications
    $router->get('/api/devices', [DeviceController::class, 'listDevices']);
    $router->post('/api/devices/register', [DeviceController::class, 'register']);
    $router->delete('/api/devices/{subscriptionId}', [DeviceController::class, 'unregister']);
    $router->post('/api/devices/{subscriptionId}/deactivate', [DeviceController::class, 'deactivate']);
    $router->get('/api/spaces/{spaceId}/push/vapid-key', [DeviceController::class, 'vapidKey']);
    $router->post('/api/devices/sync', [DeviceController::class, 'sync']);
    $router->post('/api/devices/sync/ack', [DeviceController::class, 'syncAck']);

    // Jobs
    $router->get('/api/jobs/stats', [JobController::class, 'stats']);
    $router->post('/api/jobs/schedule-maintenance', [JobController::class, 'scheduleMaintenance']);

    // Knowledge Layer
    $router->get('/api/spaces/{spaceId}/knowledge/topics', [KnowledgeController::class, 'listTopics']);
    $router->post('/api/spaces/{spaceId}/knowledge/topics', [KnowledgeController::class, 'createTopic']);
    $router->get('/api/knowledge/topics/{topicId}', [KnowledgeController::class, 'getTopic']);
    $router->put('/api/knowledge/topics/{topicId}', [KnowledgeController::class, 'updateTopic']);
    $router->delete('/api/knowledge/topics/{topicId}', [KnowledgeController::class, 'deleteTopic']);

    $router->get('/api/spaces/{spaceId}/knowledge/decisions', [KnowledgeController::class, 'listDecisions']);
    $router->post('/api/spaces/{spaceId}/knowledge/decisions', [KnowledgeController::class, 'createDecision']);
    $router->put('/api/knowledge/decisions/{decisionId}', [KnowledgeController::class, 'updateDecision']);
    $router->delete('/api/knowledge/decisions/{decisionId}', [KnowledgeController::class, 'deleteDecision']);

    $router->get('/api/spaces/{spaceId}/knowledge/summaries', [KnowledgeController::class, 'listSummaries']);
    $router->get('/api/knowledge/summaries/{summaryId}', [KnowledgeController::class, 'getSummary']);
    $router->delete('/api/knowledge/summaries/{summaryId}', [KnowledgeController::class, 'deleteSummary']);

    $router->get('/api/spaces/{spaceId}/knowledge/entries', [KnowledgeController::class, 'listEntries']);
    $router->post('/api/spaces/{spaceId}/knowledge/entries', [KnowledgeController::class, 'createEntry']);
    $router->get('/api/knowledge/entries/{entryId}', [KnowledgeController::class, 'getEntry']);
    $router->put('/api/knowledge/entries/{entryId}', [KnowledgeController::class, 'updateEntry']);
    $router->delete('/api/knowledge/entries/{entryId}', [KnowledgeController::class, 'deleteEntry']);

    $router->get('/api/spaces/{spaceId}/knowledge/search', [KnowledgeController::class, 'search']);
    $router->get('/api/messages/{messageId}/knowledge', [KnowledgeController::class, 'forMessage']);

    $router->post('/api/threads/{threadId}/knowledge/summarize', [KnowledgeController::class, 'summarizeThread']);
    $router->post('/api/channels/{channelId}/knowledge/summarize', [KnowledgeController::class, 'summarizeChannel']);
    $router->post('/api/spaces/{spaceId}/knowledge/extract', [KnowledgeController::class, 'extract']);

    // Tasks
    $router->get('/api/spaces/{spaceId}/tasks', [TaskController::class, 'index']);
    $router->post('/api/spaces/{spaceId}/tasks', [TaskController::class, 'create']);
    $router->get('/api/spaces/{spaceId}/tasks/stats', [TaskController::class, 'stats']);
    $router->get('/api/tasks/my', [TaskController::class, 'myTasks']);
    $router->get('/api/tasks/{taskId}', [TaskController::class, 'show']);
    $router->put('/api/tasks/{taskId}', [TaskController::class, 'update']);
    $router->delete('/api/tasks/{taskId}', [TaskController::class, 'delete']);
    $router->post('/api/tasks/{taskId}/assignees', [TaskController::class, 'assign']);
    $router->delete('/api/tasks/{taskId}/assignees/{userId}', [TaskController::class, 'unassign']);
    $router->get('/api/tasks/{taskId}/comments', [TaskController::class, 'listComments']);
    $router->post('/api/tasks/{taskId}/comments', [TaskController::class, 'addComment']);
    $router->post('/api/tasks/{taskId}/reminders', [TaskController::class, 'addReminder']);
    $router->delete('/api/reminders/{reminderId}', [TaskController::class, 'deleteReminder']);
    $router->get('/api/tasks/{taskId}/activity', [TaskController::class, 'activity']);
    $router->post('/api/messages/{messageId}/task', [TaskController::class, 'createFromMessage']);

    // Rich Content: Markdown, Snippets, Link Previews, Drafts
    $router->post('/api/content/render', [RichContentController::class, 'render']);
    $router->get('/api/content/languages', [RichContentController::class, 'languages']);

    $router->get('/api/spaces/{spaceId}/snippets', [RichContentController::class, 'listSnippets']);
    $router->post('/api/spaces/{spaceId}/snippets', [RichContentController::class, 'createSnippet']);
    $router->get('/api/snippets/{snippetId}', [RichContentController::class, 'getSnippet']);
    $router->put('/api/snippets/{snippetId}', [RichContentController::class, 'updateSnippet']);
    $router->delete('/api/snippets/{snippetId}', [RichContentController::class, 'deleteSnippet']);

    $router->get('/api/messages/{messageId}/previews', [RichContentController::class, 'messagePreviews']);

    $router->get('/api/spaces/{spaceId}/drafts', [RichContentController::class, 'listDrafts']);
    $router->post('/api/spaces/{spaceId}/drafts', [RichContentController::class, 'createDraft']);
    $router->get('/api/drafts/{draftId}', [RichContentController::class, 'getDraft']);
    $router->put('/api/drafts/{draftId}', [RichContentController::class, 'updateDraft']);
    $router->delete('/api/drafts/{draftId}', [RichContentController::class, 'deleteDraft']);
    $router->post('/api/drafts/{draftId}/publish', [RichContentController::class, 'publishDraft']);
    $router->post('/api/drafts/{draftId}/collaborators', [RichContentController::class, 'addCollaborator']);
    $router->delete('/api/drafts/{draftId}/collaborators/{userId}', [RichContentController::class, 'removeCollaborator']);
});

// ══════════════════════════════════════════════════════════════
// Versioned API v1 — Integration Platform
// ══════════════════════════════════════════════════════════════

// --- Public incoming webhook endpoint (no auth, uses slug+signature) ---
$router->post('/api/v1/hooks/incoming/{slug}', [IncomingWebhookController::class, 'receive']);

// --- Authenticated v1 routes (Session OR Bearer Token) ---
$router->group([ApiTokenMiddleware::class, CsrfMiddleware::class], function ($router) {

    // Events & scopes catalog
    $router->get('/api/v1/integrations/events', [IntegrationController::class, 'eventsCatalog']);

    // API Tokens
    $router->get('/api/v1/spaces/{spaceId}/tokens', [IntegrationController::class, 'listTokens']);
    $router->post('/api/v1/spaces/{spaceId}/tokens', [IntegrationController::class, 'createToken']);
    $router->delete('/api/v1/spaces/{spaceId}/tokens/{tokenId}', [IntegrationController::class, 'revokeToken']);

    // Service Accounts
    $router->get('/api/v1/spaces/{spaceId}/service-accounts', [IntegrationController::class, 'listServiceAccounts']);
    $router->post('/api/v1/spaces/{spaceId}/service-accounts', [IntegrationController::class, 'createServiceAccount']);
    $router->put('/api/v1/spaces/{spaceId}/service-accounts/{accountId}', [IntegrationController::class, 'updateServiceAccount']);
    $router->delete('/api/v1/spaces/{spaceId}/service-accounts/{accountId}', [IntegrationController::class, 'deleteServiceAccount']);

    // Outgoing Webhooks
    $router->get('/api/v1/spaces/{spaceId}/webhooks', [IntegrationController::class, 'listWebhooks']);
    $router->post('/api/v1/spaces/{spaceId}/webhooks', [IntegrationController::class, 'createWebhook']);
    $router->put('/api/v1/spaces/{spaceId}/webhooks/{webhookId}', [IntegrationController::class, 'updateWebhook']);
    $router->delete('/api/v1/spaces/{spaceId}/webhooks/{webhookId}', [IntegrationController::class, 'deleteWebhook']);
    $router->post('/api/v1/spaces/{spaceId}/webhooks/{webhookId}/test', [IntegrationController::class, 'testWebhook']);
    $router->post('/api/v1/spaces/{spaceId}/webhooks/{webhookId}/rotate-secret', [IntegrationController::class, 'rotateWebhookSecret']);
    $router->get('/api/v1/spaces/{spaceId}/webhooks/{webhookId}/deliveries', [IntegrationController::class, 'listDeliveries']);

    // Incoming Webhooks (management)
    $router->get('/api/v1/spaces/{spaceId}/incoming-webhooks', [IntegrationController::class, 'listIncoming']);
    $router->post('/api/v1/spaces/{spaceId}/incoming-webhooks', [IntegrationController::class, 'createIncoming']);
    $router->put('/api/v1/spaces/{spaceId}/incoming-webhooks/{incomingId}', [IntegrationController::class, 'updateIncoming']);
    $router->delete('/api/v1/spaces/{spaceId}/incoming-webhooks/{incomingId}', [IntegrationController::class, 'deleteIncoming']);
});