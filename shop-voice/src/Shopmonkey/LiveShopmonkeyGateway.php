<?php
declare(strict_types=1);

namespace ShopVoice\Shopmonkey;

use ShopVoice\Support\HttpClient;
use ShopVoice\Support\Logger;
use ShopVoice\Support\Payload;

/**
 * The real Shopmonkey API.
 *
 * Do not enable this (SHOPMONKEY_MODE=live) until the endpoint map in
 * config/shopmonkey_endpoints.php has been corrected against a probe run —
 * see §2 and docs/shopmonkey-api-findings.md. Every request shape here is a
 * documented assumption, and the class is written so a wrong assumption
 * produces an empty result and a log line rather than a fatal in a bay.
 *
 * The token lives here, server-side, and only here. The tablet never sees it.
 */
final class LiveShopmonkeyGateway implements ShopmonkeyGateway
{
    /** @var array<string,mixed> */
    private array $wire;

    public function __construct(
        private HttpClient $http,
        private string $token,
        private string $baseUrl,
        private Logger $logger,
        ?array $wire = null,
    ) {
        $this->wire = $wire ?? require __DIR__ . '/../../config/shopmonkey_endpoints.php';
        $this->baseUrl = rtrim($this->baseUrl, '/') . '/';
    }

    public function findOrderByNumber(string $number, bool $openOnly = true): ?Order
    {
        $matches = $this->searchOrders(new OrderQuery(roNumber: $number, openOnly: $openOnly, limit: 1));
        return $matches[0] ?? null;
    }

    public function getOrder(string $orderId): ?Order
    {
        $response = $this->call('order.get', ['id' => $orderId]);
        if ($response === null) {
            return null;
        }
        $record = isset($response['data']) && is_array($response['data']) ? $response['data'] : $response;
        return $this->mapOrder($record);
    }

    public function searchOrders(OrderQuery $query): array
    {
        $response = $this->call('order.search', [], $this->searchBody($query));
        if ($response === null) {
            return [];
        }

        $orders = [];
        foreach (Payload::collection($response, $this->wire['collection_keys']) as $record) {
            $order = $this->mapOrder($record);
            if ($order !== null) {
                $orders[] = $order;
            }
        }
        return array_slice($orders, 0, $query->limit);
    }

    public function vehicleHistory(string $vehicleId, int $limit = 25): array
    {
        // History means closed orders for this vehicle, newest first (§5).
        $response = $this->call('order.search', [], [
            'where' => ['vehicleId' => $vehicleId],
            'includeClosed' => true,
            'limit' => $limit,
        ]);
        if ($response === null) {
            return [];
        }

        $entries = [];
        foreach (Payload::collection($response, $this->wire['collection_keys']) as $record) {
            $order = $this->mapOrder($record);
            if ($order === null || $order->open) {
                continue;
            }
            $entries[] = new HistoryEntry(
                $order->id,
                $order->number,
                $order->closedAt ?? $order->createdAt,
                $order->odometer,
                array_map(static fn (ServiceLine $s): string => $s->name, $order->services),
            );
        }

        usort($entries, static fn (HistoryEntry $a, HistoryEntry $b): int => ($b->date ?? '') <=> ($a->date ?? ''));
        return array_slice($entries, 0, $limit);
    }

    public function users(): array
    {
        $response = $this->call('user.list');
        if ($response === null) {
            return [];
        }

        $field = $this->wire['fields'];
        $users = [];
        foreach (Payload::collection($response, $this->wire['collection_keys']) as $record) {
            $id = Payload::pickString($record, $field['user.id']);
            if ($id === null) {
                continue;
            }
            $users[] = [
                'id' => $id,
                'name' => Payload::pickString($record, $field['user.name'], 'Unknown') ?? 'Unknown',
                'email' => Payload::pickString($record, $field['user.email']),
                'role' => strtolower(Payload::pickString($record, $field['user.role'], 'tech') ?? 'tech'),
                'cert_number' => Payload::pickString($record, $field['user.cert']),
            ];
        }
        return $users;
    }

    public function describe(): string
    {
        $unverified = array_keys(array_filter(
            $this->wire['endpoints'],
            static fn (array $e): bool => $e['verified'] === false
        ));
        return sprintf(
            'live (%s)%s',
            $this->baseUrl,
            $unverified === [] ? '' : ' — UNVERIFIED endpoints: ' . implode(', ', $unverified)
        );
    }

    /** @return array<string,mixed> */
    private function searchBody(OrderQuery $query): array
    {
        $where = [];
        if ($query->roNumber !== null) {
            $where['number'] = FixtureShopmonkeyGateway::normalizeRo($query->roNumber);
        }
        if ($query->vin !== null) {
            $where['vin'] = strtoupper($query->vin);
        }
        if ($query->vinSuffix !== null) {
            $where['vinEndsWith'] = strtoupper($query->vinSuffix);
        }
        if ($query->plate !== null) {
            $where['licensePlate'] = $query->plate;
        }
        if ($query->customerName !== null) {
            $where['customerName'] = $query->customerName;
        }
        if ($query->vehicleText !== null) {
            $where['q'] = $query->vehicleText;
        }

        return [
            'where' => $where,
            'includeClosed' => !$query->openOnly,
            'limit' => $query->limit,
        ];
    }

    /**
     * @param array<string,string> $pathParams
     * @param array<string,mixed>|null $body
     * @return array<mixed>|null
     */
    private function call(string $key, array $pathParams = [], ?array $body = null): ?array
    {
        $endpoint = $this->wire['endpoints'][$key] ?? null;
        if ($endpoint === null) {
            $this->logger->error('shopmonkey.unknown_endpoint', ['key' => $key]);
            return null;
        }

        $path = $endpoint['path'];
        foreach ($pathParams as $name => $value) {
            $path = str_replace('{' . $name . '}', rawurlencode($value), $path);
        }

        $response = $this->http->request(
            $endpoint['method'],
            $this->baseUrl . $path,
            [
                'Authorization' => 'Bearer ' . $this->token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            $body === null ? null : json_encode($body, JSON_UNESCAPED_SLASHES)
        );

        if (!$response->ok()) {
            $this->logger->error('shopmonkey.request_failed', [
                'endpoint' => $key,
                'status' => $response->status,
                'error' => $response->error,
                // Body is truncated: it can echo customer data into the log.
                'body' => substr($response->body, 0, 300),
            ]);
            return null;
        }

        $json = $response->json();
        if ($json === null) {
            $this->logger->error('shopmonkey.bad_json', ['endpoint' => $key]);
        }
        return $json;
    }

    /** @param array<mixed> $record */
    private function mapOrder(array $record): ?Order
    {
        $field = $this->wire['fields'];

        $id = Payload::pickString($record, $field['order.id']);
        $number = Payload::pickString($record, $field['order.number']);
        if ($id === null || $number === null) {
            $this->logger->warn('shopmonkey.unmappable_order', ['keys' => array_keys($record)]);
            return null;
        }

        $status = Payload::pickString($record, $field['order.status'], 'Unknown') ?? 'Unknown';
        $closedAt = Payload::pickString($record, $field['order.closedAt']);

        $services = [];
        foreach (Payload::pickArray($record, $field['order.services']) as $line) {
            if (!is_array($line)) {
                continue;
            }
            $name = Payload::pickString($line, $field['service.name']);
            if ($name === null) {
                continue;
            }
            $declined = Payload::pickBool($line, $field['service.declined']);
            $authorized = Payload::pickBool($line, $field['service.authorized'], true);
            $services[] = new ServiceLine(
                id: Payload::pickString($line, $field['service.id'], $name) ?? $name,
                name: $name,
                note: Payload::pickString($line, $field['service.note']),
                authorized: $authorized,
                declined: $declined,
                // Unauthorised-but-not-declined work is what the shop means by
                // deferred: recommended, not yet sold (§8).
                deferred: !$authorized && !$declined,
                total: Payload::pickFloat($line, $field['service.total']),
            );
        }

        return new Order(
            id: $id,
            number: $number,
            status: $status,
            open: $closedAt === null && !self::looksClosed($status),
            vehicle: $this->mapVehicle(Payload::pickArray($record, $field['order.vehicle'])),
            customer: $this->mapCustomer(Payload::pickArray($record, $field['order.customer'])),
            concern: Payload::pickString($record, $field['order.concern']),
            services: $services,
            odometer: Payload::pickInt($record, $field['order.odometer']),
            createdAt: Payload::pickString($record, $field['order.createdAt']),
            closedAt: $closedAt,
        );
    }

    /** @param array<mixed> $record */
    private function mapVehicle(array $record): Vehicle
    {
        $field = $this->wire['fields'];
        return new Vehicle(
            id: Payload::pickString($record, $field['vehicle.id'], 'unknown') ?? 'unknown',
            year: Payload::pickInt($record, $field['vehicle.year']),
            make: Payload::pickString($record, $field['vehicle.make']),
            model: Payload::pickString($record, $field['vehicle.model']),
            color: Payload::pickString($record, $field['vehicle.color']),
            vin: Payload::pickString($record, $field['vehicle.vin']),
            plate: Payload::pickString($record, $field['vehicle.plate']),
            engine: Payload::pickString($record, $field['vehicle.engine']),
        );
    }

    /** @param array<mixed> $record */
    private function mapCustomer(array $record): Customer
    {
        $field = $this->wire['fields'];
        $name = Payload::pickString($record, $field['customer.name']);
        if ($name === null) {
            $first = Payload::pickString($record, ['firstName', 'first_name'], '') ?? '';
            $last = Payload::pickString($record, ['lastName', 'last_name'], '') ?? '';
            $name = trim($first . ' ' . $last);
        }
        return new Customer(
            id: Payload::pickString($record, $field['customer.id'], 'unknown') ?? 'unknown',
            name: $name !== '' ? $name : 'Unknown customer',
            phone: Payload::pickString($record, $field['customer.phone']),
        );
    }

    private static function looksClosed(string $status): bool
    {
        $closed = ['invoiced', 'paid', 'closed', 'complete', 'completed', 'archived'];
        return in_array(strtolower(trim($status)), $closed, true);
    }
}
