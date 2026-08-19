<?php
declare(strict_types=1);

namespace ShopVoice\Shopmonkey;

/**
 * A repair order, normalised to the shape the bay client consumes.
 *
 * Everything upstream of this class deals in Shopmonkey's wire format; nothing
 * downstream does. When the probe in §2 corrects an assumption about their
 * payloads, the change lands in the gateway's mapper, not here.
 */
final class Order implements \JsonSerializable
{
    /**
     * @param list<ServiceLine> $services
     */
    public function __construct(
        public readonly string $id,
        public readonly string $number,          // the RO number a tech says out loud
        public readonly string $status,
        public readonly bool $open,
        public readonly Vehicle $vehicle,
        public readonly Customer $customer,
        public readonly ?string $concern,        // the customer's complaint (§11: most prominent element)
        public readonly array $services = [],
        public readonly ?int $odometer = null,
        public readonly ?string $createdAt = null,
        public readonly ?string $closedAt = null,
    ) {
    }

    /** Spoken on load: "RO 4471, Henderson's Tahoe." (§6) */
    public function readbackLabel(): string
    {
        return sprintf('RO %s, %s', $this->number, $this->vehicle->shortLabel($this->customer));
    }

    /** @return list<ServiceLine> yellow inspection findings and declined work both land here (§8) */
    public function deferredServices(): array
    {
        return array_values(array_filter(
            $this->services,
            static fn (ServiceLine $s): bool => $s->deferred || $s->declined
        ));
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status,
            'open' => $this->open,
            'vehicle' => $this->vehicle,
            'customer' => $this->customer,
            'concern' => $this->concern,
            'services' => $this->services,
            'deferred_count' => count($this->deferredServices()),
            'odometer' => $this->odometer,
            'created_at' => $this->createdAt,
            'closed_at' => $this->closedAt,
            'readback' => $this->readbackLabel(),
        ];
    }
}
