<?php

namespace App\Support;

final class Router
{
    private array $routes = [];

    public function get(string $path, array $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, array $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    private function add(string $method, string $path, array $handler): void
    {
        $this->routes[] = compact('method', 'path', 'handler');
    }

    public function dispatch(string $method, string $path): void
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $method)
                continue;

            $pattern = preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $route['path']);
            $pattern = "#^$pattern$#";

            if (preg_match($pattern, $path, $matches)) {
                [$class, $action] = $route['handler'];
                (new $class())->$action($matches);
                return;
            }
        }

        http_response_code(404);
        echo json_encode(['message' => 'Route not found']);
    }
}