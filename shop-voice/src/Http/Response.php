<?php
declare(strict_types=1);

namespace ShopVoice\Http;

final class Response
{
    /** @param array<string,string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly mixed $body,
        public readonly array $headers = [],
    ) {
    }

    public static function json(mixed $body, int $status = 200): self
    {
        return new self($status, $body, ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public static function error(string $message, int $status = 400, ?string $code = null): self
    {
        return self::json(['error' => ['message' => $message, 'code' => $code]], $status);
    }

    public function send(): void
    {
        http_response_code($this->status);

        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }

        // The bay client is served from the same origin, so no CORS is opened
        // up here. A tablet is not a browser tab someone else's page can reach.
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('Cache-Control: no-store');

        echo json_encode($this->body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
