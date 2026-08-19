<?php
declare(strict_types=1);

namespace ShopVoice\Intent;

/**
 * The small structured object the model's only job is to produce (§4).
 *
 * `raw_transcript` is carried through unmodified from the tablet to the note
 * store. It is never the model's paraphrase — for notes we store the tech's
 * exact words.
 */
final class Intent implements \JsonSerializable
{
    /** @param array<string,mixed> $params */
    public function __construct(
        public readonly string $action,
        public readonly IntentTarget $target,
        public readonly array $params,
        public readonly float $confidence,
        public readonly string $rawTranscript,
        public readonly ?string $provider = null,
        public readonly ?string $model = null,
    ) {
    }

    /**
     * Build from a decoded model response. Anything missing or wrong-typed
     * becomes a shape the validator will reject — this constructor never
     * repairs a payload into something plausible.
     *
     * @param array<mixed> $data
     */
    public static function fromArray(array $data, string $rawTranscript, ?string $provider = null, ?string $model = null): self
    {
        $params = $data['params'] ?? [];

        return new self(
            action: is_string($data['action'] ?? null) ? trim($data['action']) : '',
            target: IntentTarget::fromArray(is_array($data['target'] ?? null) ? $data['target'] : []),
            params: is_array($params) ? $params : [],
            confidence: is_numeric($data['confidence'] ?? null) ? (float) $data['confidence'] : 0.0,
            // The transcript we were given always wins over anything the model
            // echoed back, so a model cannot rewrite what we store.
            rawTranscript: $rawTranscript,
            provider: $provider,
            model: $model,
        );
    }

    public function tier(): ?RiskTier
    {
        return ActionCatalog::tier($this->action);
    }

    public function param(string $key, mixed $default = null): mixed
    {
        $value = $this->params[$key] ?? null;
        return $value === null || $value === '' ? $default : $value;
    }

    public function stringParam(string $key, ?string $default = null): ?string
    {
        $value = $this->param($key);
        return is_string($value) ? $value : $default;
    }

    public function withParams(array $params): self
    {
        return new self($this->action, $this->target, $params, $this->confidence, $this->rawTranscript, $this->provider, $this->model);
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'action' => $this->action,
            'target' => $this->target,
            'params' => (object) $this->params,
            'confidence' => round($this->confidence, 2),
            'raw_transcript' => $this->rawTranscript,
            'tier' => $this->tier()?->value,
            'provider' => $this->provider,
            'model' => $this->model,
        ];
    }
}
