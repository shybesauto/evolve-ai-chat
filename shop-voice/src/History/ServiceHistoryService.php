<?php
declare(strict_types=1);

namespace ShopVoice\History;

use ShopVoice\Shopmonkey\HistoryEntry;
use ShopVoice\Shopmonkey\ShopmonkeyGateway;
use ShopVoice\Support\Clock;

/**
 * Service-filtered history (§5).
 *
 * Answer format is fixed by the spec: a short spoken summary, full detail on
 * screen. Speaking four visits at a tech under a lift is worse than speaking
 * none, so the spoken line covers at most the two that matter and the screen
 * carries the rest.
 */
final class ServiceHistoryService
{
    public function __construct(
        private ShopmonkeyGateway $gateway,
        private ServiceCategoryMatcher $matcher,
        private Clock $clock = new Clock(),
    ) {
    }

    /**
     * @return array{
     *   category: ?string,
     *   spoken: string,
     *   entries: list<array<string,mixed>>,
     *   total_visits: int
     * }
     */
    public function forVehicle(string $vehicleId, ?string $category = null, int $limit = 25): array
    {
        $entries = $this->gateway->vehicleHistory($vehicleId, $limit);

        if ($category === null || trim($category) === '') {
            return $this->present($entries, null);
        }

        $allNames = [];
        foreach ($entries as $entry) {
            foreach ($entry->serviceNames as $name) {
                $allNames[] = $name;
            }
        }
        $matchedNames = $this->matcher->match($category, array_values(array_unique($allNames)));

        $filtered = [];
        foreach ($entries as $entry) {
            $hits = array_values(array_intersect($entry->serviceNames, $matchedNames));
            if ($hits === []) {
                continue;
            }
            $filtered[] = new HistoryEntry(
                $entry->orderId,
                $entry->orderNumber,
                $entry->date,
                $entry->odometer,
                $hits,
            );
        }

        return $this->present($filtered, $category);
    }

    /**
     * @param list<HistoryEntry> $entries
     * @return array{category: ?string, spoken: string, entries: list<array<string,mixed>>, total_visits: int}
     */
    private function present(array $entries, ?string $category): array
    {
        // Oldest first: "March 2025 at 88,000. And again last month at 94,000."
        usort($entries, static fn (HistoryEntry $a, HistoryEntry $b): int => ($a->date ?? '') <=> ($b->date ?? ''));

        return [
            'category' => $category,
            'spoken' => $this->speak($entries, $category),
            'entries' => array_map(
                fn (HistoryEntry $e): array => $e->jsonSerialize() + ['relative_when' => $this->relativeWhen($e)],
                $entries
            ),
            'total_visits' => count($entries),
        ];
    }

    /** @param list<HistoryEntry> $entries */
    private function speak(array $entries, ?string $category): string
    {
        $label = $category !== null ? $this->matcher->label($category) : null;

        if ($entries === []) {
            return $label !== null
                ? sprintf("I don't have any %s history on this one.", strtolower($label))
                : "I don't have any history on this one.";
        }

        $head = $label ?? $this->summariseNames($entries[0]->serviceNames);
        $sentences = [sprintf('%s, %s.', $head, $this->relativeWhen($entries[0]))];

        if (isset($entries[1])) {
            $sentences[] = sprintf('And again %s.', $this->relativeWhen($entries[1]));
        }

        $remaining = count($entries) - 2;
        if ($remaining > 0) {
            $sentences[] = sprintf(
                '%d more %s on screen.',
                $remaining,
                $remaining === 1 ? 'visit' : 'visits'
            );
        }

        return implode(' ', $sentences);
    }

    /** @param list<string> $names */
    private function summariseNames(array $names): string
    {
        if ($names === []) {
            return 'Service';
        }
        return count($names) === 1 ? $names[0] : $names[0] . ' and more';
    }

    /**
     * "last month at 94,000" reads better than "July 2026 at 94,000" for recent
     * work, and recent work is what a tech is usually asking about.
     */
    private function relativeWhen(HistoryEntry $entry): string
    {
        $when = 'an earlier visit';

        if ($entry->date !== null) {
            $timestamp = strtotime($entry->date);
            if ($timestamp !== false) {
                $then = (new \DateTimeImmutable())->setTimestamp($timestamp);
                $now = $this->clock->now();
                $months = ((int) $now->format('Y') - (int) $then->format('Y')) * 12
                    + ((int) $now->format('n') - (int) $then->format('n'));

                $when = match (true) {
                    $months <= 0 => 'this month',
                    $months === 1 => 'last month',
                    $months < 12 => $then->format('F'),
                    default => $then->format('F Y'),
                };
            }
        }

        if ($entry->odometer !== null) {
            $when .= ' at ' . number_format($entry->odometer);
        }
        return $when;
    }
}
