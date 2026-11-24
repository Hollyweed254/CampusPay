<?php
declare(strict_types=1);

namespace App\Core;

final class Router
{
    private array $routes = [];
    private string $base;

    public function __construct(string $base)
    {
        $this->base = rtrim($base, '/');
    }

    public function get(string $path, callable $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, callable $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    private function add(string $method, string $path, callable $handler): void
    {
        $pattern = preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $path);
        $this->routes[$method][] = ['#^' . $pattern . '/?$#', $handler];
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = rawurldecode(parse_url($uri, PHP_URL_PATH) ?? '/');
        if ($this->base !== '' && str_starts_with($path, $this->base)) {
            $path = substr($path, strlen($this->base));
        }
        $path = $path === '' ? '/' : $path;

        foreach ($this->routes[$method] ?? [] as [$pattern, $handler]) {
            if (preg_match($pattern, $path, $matches) === 1) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                $handler(...array_values($params));
                return;
            }
        }

        http_response_code(404);
        if (str_starts_with($path, '/api/')) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo json_encode(['ok' => false, 'error' => 'not_found', 'message' => 'No such API endpoint.'], JSON_UNESCAPED_SLASHES);
            return;
        }
        View::render('errors/404', ['title' => 'Not found'], 'layouts/base');
    }
}
