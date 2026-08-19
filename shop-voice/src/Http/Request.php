<?php
declare(strict_types=1);

namespace ShopVoice\Http;

final class Request
{
    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $body
     * @param array<string,string> $headers
     * @param array<string,string> $params  route placeholders
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $body = [],
        public readonly array $headers = [],
        public array $params = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        $raw = file_get_contents('php://input') ?: '';
        $decoded = json_decode($raw, true);

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with((string) $key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr((string) $key, 5)));
                $headers[$name] = (string) $value;
            }
        }

        return new self(
            $method,
            '/' . trim($path, '/'),
            $_GET,
            is_array($decoded) ? $decoded : [],
            $headers,
        );
    }

    public function input(string $key, mixed $default = null): mixed
    {
        $value = $this->body[$key] ?? $this->query[$key] ?? null;
        return $value === null || $value === '' ? $default : $value;
    }

    public function string(string $key, ?string $default = null): ?string
    {
        $value = $this->input($key);
        return is_string($value) ? $value : (is_numeric($value) ? (string) $value : $default);
    }

    public function requireString(string $key): string
    {
        $value = $this->string($key);
        if ($value === null || trim($value) === '') {
            throw new HttpException("Missing required field: {$key}", 422);
        }
        return $value;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->input($key);
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }
        return $default;
    }

    public function param(string $key): ?string
    {
        return $this->params[$key] ?? null;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** Bearer token from the Authorization header. */
    public function bearer(): ?string
    {
        $header = $this->header('authorization');
        if ($header === null || !preg_match('/^Bearer\s+(.+)$/i', trim($header), $m)) {
            return null;
        }
        return trim($m[1]);
    }
}
