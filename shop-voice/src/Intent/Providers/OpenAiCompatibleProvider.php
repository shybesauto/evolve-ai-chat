<?php
declare(strict_types=1);

namespace ShopVoice\Intent\Providers;

/**
 * Groq, OpenAI and anything else speaking /chat/completions.
 *
 * Groq is the intended starting point: its free tier is roughly 1k-14k requests
 * a day depending on model, and three techs at 50-100 queries a day are nowhere
 * near it (§3). The per-minute limit is the one that bites, and it surfaces as a
 * 429, which HostedProvider degrades on rather than failing hard.
 */
class OpenAiCompatibleProvider extends HostedProvider
{
    public function __construct(
        \ShopVoice\Support\HttpClient $http,
        string $apiKey,
        string $model,
        \ShopVoice\Support\Logger $logger,
        private string $vendor = 'openai',
        private string $baseUrl = 'https://api.openai.com/v1',
    ) {
        parent::__construct($http, $apiKey, $model, $logger);
    }

    public function name(): string
    {
        return $this->vendor;
    }

    protected function buildRequest(string $systemPrompt, string $userMessage, int $maxTokens): array
    {
        return [
            'url' => rtrim($this->baseUrl, '/') . '/chat/completions',
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ],
            'body' => [
                'model' => $this->model,
                // Parsing, not writing: near-zero temperature keeps the same
                // sentence mapping to the same intent shift after shift.
                'temperature' => 0.0,
                'max_tokens' => $maxTokens,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userMessage],
                ],
            ],
        ];
    }

    protected function extractText(array $response): ?string
    {
        $content = $response['choices'][0]['message']['content'] ?? null;
        return is_string($content) ? $content : null;
    }

    public static function groq(
        \ShopVoice\Support\HttpClient $http,
        string $apiKey,
        string $model,
        \ShopVoice\Support\Logger $logger,
    ): self {
        return new self($http, $apiKey, $model, $logger, 'groq', 'https://api.groq.com/openai/v1');
    }
}
