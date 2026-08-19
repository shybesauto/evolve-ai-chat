<?php
declare(strict_types=1);

namespace ShopVoice\Intent\Providers;

use ShopVoice\Intent\Intent;
use ShopVoice\Intent\IntentContext;
use ShopVoice\Intent\IntentProvider;
use ShopVoice\Intent\PromptBuilder;
use ShopVoice\Support\HttpClient;
use ShopVoice\Support\Logger;

/**
 * Shared behaviour for every hosted vendor: prompt assembly, JSON extraction,
 * and the rule that a model failure degrades rather than explodes.
 *
 * Subclasses supply only the vendor's request shape and where the text sits in
 * its response. That is the whole difference between vendors, and it is why
 * moving off a free tier is a config change (§3).
 */
abstract class HostedProvider implements IntentProvider
{
    public function __construct(
        protected HttpClient $http,
        protected string $apiKey,
        protected string $model,
        protected Logger $logger,
    ) {
    }

    /** @return array{url:string,headers:array<string,string>,body:array<string,mixed>} */
    abstract protected function buildRequest(string $systemPrompt, string $userMessage, int $maxTokens): array;

    /** @param array<mixed> $response */
    abstract protected function extractText(array $response): ?string;

    public function model(): string
    {
        return $this->model;
    }

    public function parse(string $transcript, IntentContext $context): Intent
    {
        $decoded = $this->ask(PromptBuilder::render($context), $transcript, 400, 'parse');

        if ($decoded === null) {
            // A dead or throttled provider must not look like a confident
            // answer. Zero confidence routes this to a clarifying question.
            return new Intent('', new \ShopVoice\Intent\IntentTarget('none'), [], 0.0, $transcript, $this->name(), $this->model);
        }

        return Intent::fromArray($decoded, $transcript, $this->name(), $this->model);
    }

    public function matchServiceCategory(string $category, array $serviceNames): array
    {
        if ($serviceNames === []) {
            return [];
        }

        $decoded = $this->ask(
            'You classify automotive service names. Reply with JSON only.',
            PromptBuilder::categoryMatch($category, $serviceNames),
            400,
            'match_category'
        );

        $matches = $decoded['matches'] ?? null;
        if (!is_array($matches)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $m): string => is_string($m) ? $m : '', $matches),
            static fn (string $m): bool => $m !== ''
        ));
    }

    public function draftCustomerNote(string $rawTranscript, ?string $vehicleDescription = null): string
    {
        $decoded = $this->ask(
            'You rewrite technician dictation for customers. Reply with JSON only.',
            PromptBuilder::customerDraft($rawTranscript, $vehicleDescription),
            600,
            'customer_draft'
        );

        $draft = $decoded['draft'] ?? null;
        if (!is_string($draft) || trim($draft) === '') {
            // No draft is safer than a bad draft: the tech still approves the
            // verbatim internal note, and the advisor writes the customer copy.
            return '';
        }
        return trim($draft);
    }

    public function slotInspectionItem(string $transcript, array $fields): array
    {
        $decoded = $this->ask(
            'You slot spoken inspection findings into sheet fields. Reply with JSON only.',
            PromptBuilder::inspectionSlot($transcript, $fields),
            300,
            'inspection_slot'
        );

        if ($decoded === null) {
            return ['field_key' => null, 'value' => null, 'condition' => null, 'confidence' => 0.0];
        }

        $condition = is_string($decoded['condition'] ?? null) ? strtolower($decoded['condition']) : null;

        return [
            'field_key' => is_string($decoded['field_key'] ?? null) ? $decoded['field_key'] : null,
            'value' => is_string($decoded['value'] ?? null) ? $decoded['value'] : null,
            'condition' => in_array($condition, ['green', 'yellow', 'red'], true) ? $condition : null,
            'confidence' => is_numeric($decoded['confidence'] ?? null) ? (float) $decoded['confidence'] : 0.0,
        ];
    }

    /** @return array<mixed>|null */
    protected function ask(string $systemPrompt, string $userMessage, int $maxTokens, string $job): ?array
    {
        if ($this->apiKey === '') {
            $this->logger->error('intent.missing_key', ['provider' => $this->name(), 'job' => $job]);
            return null;
        }

        $request = $this->buildRequest($systemPrompt, $userMessage, $maxTokens);
        $started = microtime(true);

        $response = $this->http->request(
            'POST',
            $request['url'],
            $request['headers'],
            json_encode($request['body'], JSON_UNESCAPED_SLASHES) ?: ''
        );

        $latency = (int) round((microtime(true) - $started) * 1000);

        if (!$response->ok()) {
            $this->logger->error('intent.provider_error', [
                'provider' => $this->name(),
                'job' => $job,
                'status' => $response->status,
                // 429 here is the free tier's per-minute limit doing its job.
                'rate_limited' => $response->status === 429,
                'error' => $response->error,
                'latency_ms' => $latency,
            ]);
            return null;
        }

        $payload = $response->json();
        if ($payload === null) {
            $this->logger->error('intent.bad_envelope', ['provider' => $this->name(), 'job' => $job]);
            return null;
        }

        $text = $this->extractText($payload);
        if ($text === null) {
            $this->logger->error('intent.no_text', ['provider' => $this->name(), 'job' => $job]);
            return null;
        }

        $decoded = self::decodeJson($text);
        if ($decoded === null) {
            $this->logger->warn('intent.unparsable_json', [
                'provider' => $this->name(),
                'job' => $job,
                'snippet' => substr($text, 0, 200),
            ]);
            return null;
        }

        $this->logger->info('intent.ok', [
            'provider' => $this->name(),
            'job' => $job,
            'latency_ms' => $latency,
        ]);

        return $decoded;
    }

    /**
     * Pull a JSON object out of a model response, tolerating the ```json fences
     * and stray prose that every vendor emits occasionally despite instructions.
     *
     * @return array<mixed>|null
     */
    public static function decodeJson(string $text): ?array
    {
        $text = trim($text);

        if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $text, $m) === 1) {
            $text = trim($m[1]);
        }

        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }
}
