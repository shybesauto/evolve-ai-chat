<?php
declare(strict_types=1);

namespace ShopVoice\Resolver;

use ShopVoice\Shopmonkey\Order;

/**
 * The outcome of one trip down the cascade.
 *
 * There are exactly three outcomes and "probably that one" is not among them.
 * A wrong resolve sends a tech down the wrong wiring diagram (§5), so anything
 * short of a unique match becomes a question.
 */
final class ResolveResult implements \JsonSerializable
{
    private function __construct(
        public readonly string $outcome,        // resolved | ambiguous | not_found
        public readonly ?Order $order,
        /** @var list<Order> */
        public readonly array $candidates,
        public readonly ?string $matchedBy,     // which rung of the cascade hit
        public readonly ?string $question,      // spoken disambiguation prompt
        public readonly bool $scopeWidened,     // did we have to look past open orders?
    ) {
    }

    public static function resolved(Order $order, string $matchedBy, bool $scopeWidened = false): self
    {
        return new self('resolved', $order, [$order], $matchedBy, null, $scopeWidened);
    }

    /** @param list<Order> $candidates */
    public static function ambiguous(array $candidates, string $matchedBy, string $question, bool $scopeWidened = false): self
    {
        return new self('ambiguous', null, $candidates, $matchedBy, $question, $scopeWidened);
    }

    public static function notFound(?string $question = null): self
    {
        return new self('not_found', null, [], null, $question, false);
    }

    public function isResolved(): bool
    {
        return $this->outcome === 'resolved';
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'outcome' => $this->outcome,
            'matched_by' => $this->matchedBy,
            'question' => $this->question,
            'scope_widened' => $this->scopeWidened,
            'order' => $this->order,
            'candidates' => array_map(
                static fn (Order $o): array => [
                    'id' => $o->id,
                    'number' => $o->number,
                    'label' => $o->readbackLabel(),
                    'customer' => $o->customer->name,
                    'vehicle' => $o->vehicle->description(),
                    'color' => $o->vehicle->color,
                ],
                $this->candidates
            ),
        ];
    }
}
