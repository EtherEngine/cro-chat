<?php

use App\Controllers\AuthController;
use App\Controllers\MessageController;

$router->post('/api/auth/login', [AuthController::class, 'login']);
$router->get('/api/auth/me', [AuthController::class, 'me']);

$router->get('/api/channels/{channelId}/messages', [MessageController::class, 'listChannelMessages']);
$router->post('/api/channels/{channelId}/messages', [MessageController::class, 'createChannelMessage']);