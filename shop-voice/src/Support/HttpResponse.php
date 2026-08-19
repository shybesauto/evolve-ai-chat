<?php
declare(strict_types=1);

namespace ShopVoice\Support;

final class HttpResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        /** @var array<string,string> */
        public readonly array $headers = [],
        public readonly ?string $error = null,
    ) {
    }

    public function ok(): bool
    {
        return $this->error === null && $this->status >= 200 && $this->status < 300;
    }

    /** @return array<mixed>|null */
    public function json(): ?array
    {
        $decoded = json_decode($this->body, true);
        return is_array($decoded) ? $decoded : null;
    }
}
