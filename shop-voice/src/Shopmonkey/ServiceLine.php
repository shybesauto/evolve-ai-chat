<?php
declare(strict_types=1);

namespace ShopVoice\Shopmonkey;

/**
 * Service is the container object in Shopmonkey — labor, parts, tires,
 * subcontract and fees all nest under it. History search runs against these
 * names, never raw line items (§5).
 */
final class ServiceLine implements \JsonSerializable
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $note = null,
        public readonly bool $authorized = true,
        public readonly bool $declined = false,
        public readonly bool $deferred = false,
        public readonly ?float $total = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'note' => $this->note,
            'authorized' => $this->authorized,
            'declined' => $this->declined,
            'deferred' => $this->deferred,
            'total' => $this->total,
        ];
    }
}
