<?php
declare(strict_types=1);

namespace ShopVoice\Support;

/** Records outbound calls and replays canned responses. Used by the test suite. */
final class FakeHttpClient implements HttpClient
{
    /** @var list<array{method:string,url:string,headers:array<string,string>,body:?string}> */
    public array $calls = [];

    /** @var list<HttpResponse> */
    private array $queue = [];

    public function queue(HttpResponse $response): void
    {
        $this->queue[] = $response;
    }

    /** @param array<string,string> $headers */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        $this->calls[] = compact('method', 'url', 'headers', 'body');
        return array_shift($this->queue)
            ?? new HttpResponse(0, '', [], 'FakeHttpClient: no response queued for ' . $method . ' ' . $url);
    }

    public function lastCall(): ?array
    {
        return $this->calls === [] ? null : $this->calls[count($this->calls) - 1];
    }
}
