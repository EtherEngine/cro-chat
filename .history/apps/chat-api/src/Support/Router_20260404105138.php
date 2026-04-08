<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\ApiException;

final class Router
{
    private array $routes = [];
    private array $groupMiddleware = [];

    public function get(string $path, array $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }

    public function post(string $path, array $handler, array $middleware = []): void
    {
        $this->add('POST', $path, $handler, $middleware);
    }

    public function put(string $path, array $handler, array $middleware = []): void
    {
        $this->add('PUT', $path, $handler, $middleware);
    }

    public function delete(string $path, array $handler, array $middleware = []): void
    {
        $this->add('DELETE', $path, $handler, $middleware);
    }

    /** @param callable(Router): void $callback */
    public function group(array $middleware, callable $callback): void
    {
        $prev = $this->groupMiddleware;
        $this->groupMiddleware = array_merge($this->groupMiddleware, $middleware);
        $callback($this);
        $this->groupMiddleware = $prev;
    }

    private function add(string $method, string $path, array $handler, array $middleware): void
    {
        $mw = array_merge($this->groupMiddleware, $middleware);
        $this->routes[] = compact('method', 'path', 'handler', 'mw');
    }

    /** @throws ApiException */
    public function dispatch(string $method, string $path): void
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            $pattern = preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $route['path']);
            $pattern = "#^$pattern$#";

            if (preg_match($pattern, $path, $matches)) {
                // Run middleware
                foreach ($route['mw'] as $mwClass) {
                    (new $mwClass())->handle();
                }

                [$class, $action] = $route['handler'];
                (new $class())->$action($matches);
                return;
            }
        }

        throw ApiException::notFound('Route nicht gefunden', 'ROUTE_NOT_FOUND');
    }
}