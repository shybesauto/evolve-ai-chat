<?php
declare(strict_types=1);

namespace ShopVoice\Shopmonkey;

final class Vehicle implements \JsonSerializable
{
    public function __construct(
        public readonly string $id,
        public readonly ?int $year = null,
        public readonly ?string $make = null,
        public readonly ?string $model = null,
        public readonly ?string $color = null,
        public readonly ?string $vin = null,
        public readonly ?string $plate = null,
        public readonly ?string $engine = null,
    ) {
    }

    public function description(): string
    {
        $parts = array_filter([$this->year, $this->make, $this->model]);
        return $parts === [] ? 'Vehicle' : implode(' ', $parts);
    }

    /** "Henderson's Tahoe" — short enough to speak, specific enough to catch a wrong resolve. */
    public function shortLabel(?Customer $customer = null): string
    {
        $model = $this->model ?: $this->description();
        if ($customer !== null && $customer->lastName() !== '') {
            return sprintf("%s's %s", $customer->lastName(), $model);
        }
        return trim(($this->color ? $this->color . ' ' : '') . $model);
    }

    public function vinLast(int $n): ?string
    {
        if ($this->vin === null || strlen($this->vin) < $n) {
            return null;
        }
        return strtoupper(substr($this->vin, -$n));
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'year' => $this->year,
            'make' => $this->make,
            'model' => $this->model,
            'color' => $this->color,
            'vin' => $this->vin,
            'plate' => $this->plate,
            'engine' => $this->engine,
            'description' => $this->description(),
        ];
    }
}
