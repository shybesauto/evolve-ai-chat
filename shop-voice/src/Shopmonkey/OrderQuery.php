<?php
declare(strict_types=1);

namespace ShopVoice\Shopmonkey;

/** One rung of the resolver cascade (§5), expressed as a search. */
final class OrderQuery
{
    public function __construct(
        public readonly ?string $roNumber = null,
        public readonly ?string $vin = null,
        public readonly ?string $vinSuffix = null,
        public readonly ?string $plate = null,
        public readonly ?string $customerName = null,
        public readonly ?string $vehicleText = null,
        /** Default scope is open orders — ~90% of bay queries (§5). */
        public readonly bool $openOnly = true,
        public readonly int $limit = 25,
    ) {
    }

    public function widened(): self
    {
        return new self(
            $this->roNumber,
            $this->vin,
            $this->vinSuffix,
            $this->plate,
            $this->customerName,
            $this->vehicleText,
            false,
            $this->limit,
        );
    }
}
