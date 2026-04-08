<?php

use App\Controllers\AuthController;
use App\Controllers\ChannelController;
use App\Controllers\MessageController;
use App\Controllers\UserController;

// Auth
$router->post('/api/auth/login', [AuthController::class, 'login']);
$router->get('/api/auth/me', [AuthController::class, 'me']);
$router->post('/api/auth/logout', [AuthController::class, 'logout']);

// Channels
$router->get('/api/channels', [ChannelController::class, 'index']);
$router->get('/api/channels/{channelId}/members', [ChannelController::class, 'members']);

// Messages
$router->get('/api/channels/{channelId}/messages', [MessageController::class, 'listChannelMessages']);
$router->post('/api/channels/{channelId}/messages', [MessageController::class, 'createChannelMessage']);

// Conversations
$router->get('/api/conversations', [ChannelController::class, 'conversations']);
$router->get('/api/conversations/{conversationId}/messages', [MessageController::class, 'listConversationMessages']);
$router->post('/api/conversations/{conversationId}/messages', [MessageController::class, 'createConversationMessage']);

// Users
$router->get('/api/users', [UserController::class, 'index']);