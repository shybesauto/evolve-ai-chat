<?php
declare(strict_types=1);

namespace ShopVoice\Intent\Providers;

/** Claude via the Messages API. Same seam, different request shape. */
final class AnthropicProvider extends HostedProvider
{
    private const API_VERSION = '2023-06-01';

    public function name(): string
    {
        return 'anthropic';
    }

    protected function buildRequest(string $systemPrompt, string $userMessage, int $maxTokens): array
    {
        return [
            'url' => 'https://api.anthropic.com/v1/messages',
            'headers' => [
                'x-api-key' => $this->apiKey,
                'anthropic-version' => self::API_VERSION,
                'Content-Type' => 'application/json',
            ],
            'body' => [
                'model' => $this->model,
                'max_tokens' => $maxTokens,
                'temperature' => 0.0,
                'system' => $systemPrompt,
                'messages' => [
                    ['role' => 'user', 'content' => $userMessage],
                    // Prefilling the opening brace keeps the reply to JSON
                    // without a preamble; decodeJson re-adds it.
                    ['role' => 'assistant', 'content' => '{'],
                ],
            ],
        ];
    }

    protected function extractText(array $response): ?string
    {
        $blocks = $response['content'] ?? null;
        if (!is_array($blocks)) {
            return null;
        }

        $text = '';
        foreach ($blocks as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            }
        }

        if (trim($text) === '') {
            return null;
        }

        // Put back the brace we prefilled.
        return str_starts_with(ltrim($text), '{') ? $text : '{' . $text;
    }
}
