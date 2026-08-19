<?php
declare(strict_types=1);

namespace ShopVoice\Intent;

/**
 * What the model needs to know to resolve "this one" and "it".
 *
 * Deliberately thin: the loaded RO, who is talking, and whether an inspection is
 * open. No customer PII beyond what is already on the tablet screen, and no
 * pricing.
 */
final class IntentContext implements \JsonSerializable
{
    /** @param list<string> $inspectionFields */
    public function __construct(
        public readonly ?string $roNumber = null,
        public readonly ?string $vehicleDescription = null,
        public readonly ?string $userName = null,
        public readonly bool $inspectionOpen = false,
        public readonly array $inspectionFields = [],
        public readonly bool $dictating = false,
    ) {
    }

    public function hasContext(): bool
    {
        return $this->roNumber !== null;
    }

    public function describe(): string
    {
        $lines = [];
        $lines[] = $this->roNumber !== null
            ? sprintf('Loaded repair order: RO %s (%s).', $this->roNumber, $this->vehicleDescription ?? 'vehicle')
            : 'No repair order is loaded. A command about "this one" cannot be answered yet.';

        if ($this->userName !== null) {
            $lines[] = sprintf('Speaking: %s.', $this->userName);
        }
        if ($this->inspectionOpen) {
            $lines[] = 'An inspection is open: findings called out should be add_inspection_item.';
            if ($this->inspectionFields !== []) {
                $lines[] = 'Sheet fields still expected: ' . implode(', ', $this->inspectionFields) . '.';
            }
        }
        return implode("\n", $lines);
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'ro_number' => $this->roNumber,
            'vehicle' => $this->vehicleDescription,
            'user' => $this->userName,
            'inspection_open' => $this->inspectionOpen,
        ];
    }
}
