<?php
declare(strict_types=1);

namespace ShopVoice\Shopmonkey;

/**
 * The whole shop, in memory.
 *
 * This is not only a test double. Until §2 is answered and a token is in place,
 * `SHOPMONKEY_MODE=fixture` is how Russ demos build steps 1-5 end to end — the
 * resolver cascade, history, notes, inspections and the bay UI all run against
 * it. The sample data deliberately includes two open Tahoes (to exercise the
 * disambiguation prompt) and brake work recorded under three different names
 * (to exercise semantic history matching).
 */
final class FixtureShopmonkeyGateway implements ShopmonkeyGateway
{
    /** @var list<Order> */
    private array $orders;

    /** @var array<string,list<HistoryEntry>> keyed by vehicle id */
    private array $history;

    public function __construct(?array $fixture = null)
    {
        $fixture ??= require __DIR__ . '/../../config/fixture_shop.php';
        $this->orders = $fixture['orders'];
        $this->history = $fixture['history'];
    }

    public function findOrderByNumber(string $number, bool $openOnly = true): ?Order
    {
        $needle = self::normalizeRo($number);
        foreach ($this->orders as $order) {
            if (self::normalizeRo($order->number) !== $needle) {
                continue;
            }
            if ($openOnly && !$order->open) {
                continue;
            }
            return $order;
        }
        return null;
    }

    public function getOrder(string $orderId): ?Order
    {
        foreach ($this->orders as $order) {
            if ($order->id === $orderId) {
                return $order;
            }
        }
        return null;
    }

    public function searchOrders(OrderQuery $query): array
    {
        $matches = [];
        foreach ($this->orders as $order) {
            if ($query->openOnly && !$order->open) {
                continue;
            }
            if (!$this->matches($order, $query)) {
                continue;
            }
            $matches[] = $order;
        }
        return array_slice($matches, 0, $query->limit);
    }

    private function matches(Order $order, OrderQuery $query): bool
    {
        if ($query->roNumber !== null) {
            return self::normalizeRo($order->number) === self::normalizeRo($query->roNumber);
        }
        if ($query->vin !== null) {
            return $order->vehicle->vin !== null
                && strcasecmp($order->vehicle->vin, $query->vin) === 0;
        }
        if ($query->vinSuffix !== null) {
            $suffix = strtoupper($query->vinSuffix);
            return $order->vehicle->vin !== null
                && str_ends_with(strtoupper($order->vehicle->vin), $suffix);
        }
        if ($query->plate !== null) {
            return $order->vehicle->plate !== null
                && self::normalizePlate($order->vehicle->plate) === self::normalizePlate($query->plate);
        }
        if ($query->customerName !== null) {
            return stripos($order->customer->name, $query->customerName) !== false;
        }
        if ($query->vehicleText !== null) {
            $haystack = strtolower(implode(' ', array_filter([
                (string) $order->vehicle->year,
                $order->vehicle->make,
                $order->vehicle->model,
                $order->vehicle->color,
            ])));
            // Every meaningful word in the phrase must appear: "silver Tahoe"
            // must not match a white one.
            $words = array_filter(
                preg_split('/\s+/', strtolower(trim($query->vehicleText))) ?: [],
                static fn (string $w): bool => $w !== '' && !in_array($w, ['the', 'a', 'an'], true)
            );
            if ($words === []) {
                return false;
            }
            foreach ($words as $word) {
                if (!str_contains($haystack, $word)) {
                    return false;
                }
            }
            return true;
        }
        return false;
    }

    public function vehicleHistory(string $vehicleId, int $limit = 25): array
    {
        return array_slice($this->history[$vehicleId] ?? [], 0, $limit);
    }

    public function users(): array
    {
        return require __DIR__ . '/../../config/fixture_users.php';
    }

    public function describe(): string
    {
        return sprintf('fixture (%d orders, no network, no token)', count($this->orders));
    }

    public static function normalizeRo(string $value): string
    {
        return ltrim(preg_replace('/[^0-9A-Za-z]/', '', $value) ?? $value, '0') ?: '0';
    }

    private static function normalizePlate(string $value): string
    {
        return strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $value) ?? $value);
    }
}
