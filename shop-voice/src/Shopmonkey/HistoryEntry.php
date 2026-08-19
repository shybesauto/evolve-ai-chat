<?php
declare(strict_types=1);

namespace ShopVoice\Shopmonkey;

/** One past visit, with the odometer Shopmonkey records per order (§5). */
final class HistoryEntry implements \JsonSerializable
{
    /** @param list<string> $serviceNames */
    public function __construct(
        public readonly string $orderId,
        public readonly string $orderNumber,
        public readonly ?string $date,
        public readonly ?int $odometer,
        public readonly array $serviceNames,
    ) {
    }

    /** "March 2025 at 88,000" */
    public function spokenWhen(): string
    {
        $when = 'an earlier visit';
        if ($this->date !== null) {
            $ts = strtotime($this->date);
            if ($ts !== false) {
                $when = date('F Y', $ts);
            }
        }
        if ($this->odometer !== null) {
            $when .= ' at ' . number_format($this->odometer);
        }
        return $when;
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'order_id' => $this->orderId,
            'order_number' => $this->orderNumber,
            'date' => $this->date,
            'odometer' => $this->odometer,
            'services' => $this->serviceNames,
            'spoken_when' => $this->spokenWhen(),
        ];
    }
}
