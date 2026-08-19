<?php
declare(strict_types=1);

namespace ShopVoice\Shopmonkey;

final class Customer implements \JsonSerializable
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $phone = null,
    ) {
    }

    public function lastName(): string
    {
        $parts = preg_split('/\s+/', trim($this->name)) ?: [];
        return $parts === [] ? '' : (string) end($parts);
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'phone' => $this->phone];
    }
}
