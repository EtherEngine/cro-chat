<?php

namespace App\Middleware;

final class CorsMiddleware
{
    public function handle(): void
    {
        // CORS is handled in public/index.php before session_start
    }
}
