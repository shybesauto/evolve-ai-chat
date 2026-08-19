<?php
declare(strict_types=1);

namespace ShopVoice\Intent;

final class ValidationResult implements \JsonSerializable
{
    public const ACCEPTED = 'accepted';
    public const REJECTED = 'rejected';
    public const CLARIFY = 'clarify';

    private function __construct(
        public readonly string $status,
        public readonly ?Intent $intent,
        public readonly ?string $reason,
        public readonly string $spoken,
    ) {
    }

    public static function accepted(Intent $intent): self
    {
        return new self(self::ACCEPTED, $intent, null, '');
    }

    /** Outside the closed list, or malformed. Spoken response is deliberately flat (§4). */
    public static function rejected(string $reason, ?Intent $intent = null, string $spoken = "I didn't catch that."): self
    {
        return new self(self::REJECTED, $intent, $reason, $spoken);
    }

    /** Below the confidence threshold: ask rather than act (§4). */
    public static function clarify(string $question, ?Intent $intent = null, string $reason = 'low_confidence'): self
    {
        return new self(self::CLARIFY, $intent, $reason, $question);
    }

    public function isAccepted(): bool
    {
        return $this->status === self::ACCEPTED;
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'status' => $this->status,
            'reason' => $this->reason,
            'spoken' => $this->spoken,
            'intent' => $this->intent,
        ];
    }
}
