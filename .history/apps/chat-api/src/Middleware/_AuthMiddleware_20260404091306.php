<?php

namespace App\Middleware;

use App\Support\Request;
use App\Support\Response;

final class AuthMiddleware
{
    public function handle(): void
    {
        if (!Request::userId()) {
            Response::error('Nicht eingeloggt', 401);
        }
    }
}
