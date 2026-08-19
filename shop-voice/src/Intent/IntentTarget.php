<?php
declare(strict_types=1);

namespace ShopVoice\Intent;

/**
 * What the utterance is about.
 *
 * `context` means the currently loaded RO — the common case, because context is
 * sticky and a tech is on one car for an hour (§5).
 */
final class IntentTarget implements \JsonSerializable
{
    public const TYPES = ['context', 'ro_number', 'vin', 'plate', 'customer', 'vehicle', 'user', 'none'];

    public function __construct(
        public readonly string $type = 'context',
        public readonly ?string $value = null,
    ) {
    }

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        $type = is_string($data['type'] ?? null) ? strtolower(trim($data['type'])) : 'context';
        $value = $data['value'] ?? null;

        return new self(
            in_array($type, self::TYPES, true) ? $type : 'none',
            is_string($value) || is_int($value) ? (string) $value : null,
        );
    }

    public function isContext(): bool
    {
        return $this->type === 'context';
    }

    /** Does this target need a trip through the resolver cascade? */
    public function needsResolution(): bool
    {
        return in_array($this->type, ['ro_number', 'vin', 'plate', 'customer', 'vehicle'], true)
            && $this->value !== null;
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return ['type' => $this->type, 'value' => $this->value];
    }
}
