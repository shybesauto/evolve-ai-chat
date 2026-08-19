<?php
declare(strict_types=1);

namespace ShopVoice\Support;

interface HttpClient
{
    /** @param array<string,string> $headers */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse;
}
