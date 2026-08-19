<?php
declare(strict_types=1);

namespace ShopVoice\Shopmonkey;

/**
 * The whole of our dependency on Shopmonkey.
 *
 * Two implementations ship: a fixture gateway (sample shop data, no token, how
 * the system runs before §2 is answered) and a live gateway. Nothing outside
 * this namespace knows which one is in play.
 */
interface ShopmonkeyGateway
{
    public function findOrderByNumber(string $number, bool $openOnly = true): ?Order;

    public function getOrder(string $orderId): ?Order;

    /** @return list<Order> */
    public function searchOrders(OrderQuery $query): array;

    /** Closed orders for a vehicle, newest first (§5 history). @return list<HistoryEntry> */
    public function vehicleHistory(string $vehicleId, int $limit = 25): array;

    /**
     * Shop users, so writes carry a real Shopmonkey userId with no mapping
     * table (§7).
     *
     * @return list<array{id:string,name:string,email:?string,role:string,cert_number:?string}>
     */
    public function users(): array;

    /** Human-readable description of where the data is coming from. */
    public function describe(): string;
}
