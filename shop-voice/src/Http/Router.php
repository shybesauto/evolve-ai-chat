<?php
declare(strict_types=1);

namespace ShopVoice\Http;

use ShopVoice\Auth\AuthException;
use ShopVoice\Support\Logger;

final class Router
{
    /** @var list<array{method:string,pattern:string,regex:string,handler:callable}> */
    private array $routes = [];

    public function __construct(private ?Logger $logger = null)
    {
    }

    public function add(string $method, string $pattern, callable $handler): void
    {
        $regex = '#^' . preg_replace('#\{([a-z_]+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $pattern,
            'regex' => $regex,
            'handler' => $handler,
        ];
    }

    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function dispatch(Request $request): Response
    {
        $pathMatched = false;

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $request->path, $matches) !== 1) {
                continue;
            }
            $pathMatched = true;

            if ($route['method'] !== $request->method) {
                continue;
            }

            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $request->params[$key] = $value;
                }
            }

            return $this->run($route['handler'], $request);
        }

        return $pathMatched
            ? Response::error('Method not allowed.', 405)
            : Response::error('Not found.', 404);
    }

    private function run(callable $handler, Request $request): Response
    {
        try {
            return $handler($request);
        } catch (AuthException $e) {
            return Response::error($e->getMessage(), $e->status, 'auth');
        } catch (HttpException $e) {
            return Response::error($e->getMessage(), $e->status, 'request');
        } catch (\Throwable $e) {
            $this->logger?->error('http.unhandled', [
                'path' => $request->path,
                'message' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]);
            // Never leak an exception message to a tablet: it can carry a query,
            // a path, or a fragment of a third-party error containing a token.
            return Response::error('Something went wrong on our end.', 500, 'server');
        }
    }
}
