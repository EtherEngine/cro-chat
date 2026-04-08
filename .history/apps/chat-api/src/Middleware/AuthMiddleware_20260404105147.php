<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Exceptions\ApiException;
use App\Support\Request;

final class AuthMiddleware
{
    public function handle(): void
    {
        if (!Request::userId()) {
            throw ApiException::unauthorized();
        }
    }
}
