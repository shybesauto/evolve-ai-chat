<?php
declare(strict_types=1);

namespace ShopVoice\Resolver;

use ShopVoice\Shopmonkey\Order;
use ShopVoice\Shopmonkey\OrderQuery;
use ShopVoice\Shopmonkey\ShopmonkeyGateway;

/**
 * The resolver cascade (§5), ordered by uniqueness.
 *
 *   1. RO number        unique      -> return immediately
 *   2. full VIN         unique      -> return immediately
 *   3. VIN last 6 / 8   near-unique
 *   4. plate            near-unique
 *   5. customer name    fuzzy       -> ask on >1
 *   6. vehicle text     fuzzy       -> ask on >1
 *
 * Exact identifiers short-circuit. Fuzzy rungs return candidates and the
 * assistant asks. Never guess.
 */
final class RoResolver
{
    public function __construct(private ShopmonkeyGateway $gateway)
    {
    }

    /**
     * @param string|null $hintType if the model already classified the target
     *                              (ro_number|vin|plate|customer|vehicle), start there
     */
    public function resolve(string $spoken, bool $openOnly = true, ?string $hintType = null): ResolveResult
    {
        $text = self::normalize($spoken);
        if ($text === '') {
            return ResolveResult::notFound('Which vehicle?');
        }

        $attempts = $this->rungs($text, $hintType);

        foreach ($attempts as [$matchedBy, $query, $unique]) {
            $result = $this->tryQuery($query, $matchedBy, $unique, $openOnly);
            if ($result !== null) {
                return $result;
            }
        }

        return ResolveResult::notFound(
            $openOnly
                ? "I couldn't find that on any open order."
                : "I couldn't find that."
        );
    }

    /**
     * Runs one rung, widening from open orders to all orders if the open-only
     * pass comes up empty.
     */
    private function tryQuery(OrderQuery $query, string $matchedBy, bool $unique, bool $openOnly): ?ResolveResult
    {
        $scoped = $openOnly ? $query : $query->widened();
        $matches = $this->gateway->searchOrders($scoped);
        $widened = !$openOnly;

        if ($matches === [] && $openOnly) {
            // The car may be here on a closed ticket, or the tech may be asking
            // about an old visit without saying so.
            $matches = $this->gateway->searchOrders($query->widened());
            $widened = $matches !== [];
        }

        if ($matches === []) {
            return null;
        }

        if (count($matches) === 1) {
            return ResolveResult::resolved($matches[0], $matchedBy, $widened);
        }

        if ($unique) {
            // An identifier that is supposed to be unique matched more than one
            // record. Do not pick one — that is exactly the wrong-diagram case.
            return ResolveResult::ambiguous($matches, $matchedBy, self::question($matches), $widened);
        }

        return ResolveResult::ambiguous($matches, $matchedBy, self::question($matches), $widened);
    }

    /**
     * Build the ordered list of cascade attempts for this utterance.
     *
     * @return list<array{0:string,1:OrderQuery,2:bool}>
     */
    private function rungs(string $text, ?string $hintType): array
    {
        $rungs = [];

        if (($ro = self::extractRoNumber($text)) !== null) {
            $rungs[] = ['ro_number', new OrderQuery(roNumber: $ro), true];
        }
        if (($vin = self::extractVin($text)) !== null) {
            $rungs[] = ['vin', new OrderQuery(vin: $vin), true];
        }
        foreach (self::extractVinSuffixes($text) as $suffix) {
            $rungs[] = ['vin_suffix', new OrderQuery(vinSuffix: $suffix), false];
        }
        if (($plate = self::extractPlate($text)) !== null) {
            $rungs[] = ['plate', new OrderQuery(plate: $plate), false];
        }

        $descriptive = self::stripLeadIn($text);
        if ($descriptive !== '') {
            $rungs[] = ['customer_name', new OrderQuery(customerName: $descriptive), false];
            $rungs[] = ['vehicle_description', new OrderQuery(vehicleText: $descriptive), false];
        }

        if ($hintType !== null) {
            // A model hint reorders the cascade; it never removes a rung, because
            // the hint is itself a guess.
            $preferred = match ($hintType) {
                'ro_number' => 'ro_number',
                'vin' => 'vin',
                'plate' => 'plate',
                'customer', 'customer_name' => 'customer_name',
                'vehicle', 'vehicle_description' => 'vehicle_description',
                default => null,
            };
            if ($preferred !== null) {
                usort($rungs, static fn (array $a, array $b): int
                    => ($b[0] === $preferred ? 1 : 0) <=> ($a[0] === $preferred ? 1 : 0));
            }
        }

        return $rungs;
    }

    /** "I've got two Tahoes open — Henderson or Wozniak?" (§5) */
    private static function question(array $matches): string
    {
        $models = array_values(array_unique(array_map(
            static fn (Order $o): string => $o->vehicle->model ?? $o->vehicle->description(),
            $matches
        )));

        $names = array_map(static fn (Order $o): string => $o->customer->lastName() ?: $o->customer->name, $matches);
        $names = array_values(array_unique($names));

        // Same model, different owners: ask by name. Different models: ask by vehicle.
        if (count($models) === 1 && count($names) === count($matches)) {
            return sprintf(
                "I've got %s %ss open — %s?",
                self::countWord(count($matches)),
                $models[0],
                self::orList($names)
            );
        }

        $labels = array_map(
            static fn (Order $o): string => trim(($o->vehicle->color ? $o->vehicle->color . ' ' : '') . ($o->vehicle->model ?? '')) ?: $o->customer->lastName(),
            $matches
        );
        return sprintf("I've got %s — %s?", self::countWord(count($matches)) . ' matches', self::orList($labels));
    }

    private static function countWord(int $n): string
    {
        return match ($n) {
            2 => 'two',
            3 => 'three',
            4 => 'four',
            default => (string) $n,
        };
    }

    /** @param list<string> $items */
    private static function orList(array $items): string
    {
        if (count($items) <= 1) {
            return $items[0] ?? '';
        }
        $last = array_pop($items);
        return implode(', ', $items) . ' or ' . $last;
    }

    // --- extraction -------------------------------------------------------

    public static function normalize(string $spoken): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $spoken) ?? $spoken);
        return SpokenNumbers::digitize($text);
    }

    public static function extractRoNumber(string $text): ?string
    {
        if (preg_match('/\b(?:r\.?o\.?|repair order|order|ticket|work order)\s*(?:number\s*)?#?\s*(\d{3,7})\b/i', $text, $m)) {
            return $m[1];
        }
        // A bare number on its own is an RO number — nobody says "4471" meaning
        // anything else in a bay.
        if (preg_match('/^#?(\d{3,7})$/', trim($text), $m)) {
            return $m[1];
        }
        return null;
    }

    public static function extractVin(string $text): ?string
    {
        // 17 characters, and VINs never contain I, O or Q.
        if (preg_match('/\b([A-HJ-NPR-Z0-9]{17})\b/i', $text, $m)) {
            return strtoupper($m[1]);
        }
        return null;
    }

    /** @return list<string> */
    public static function extractVinSuffixes(string $text): array
    {
        $suffixes = [];
        if (preg_match('/\b(?:last|final)\s*(?:six|6|eight|8)\D{0,12}?([A-HJ-NPR-Z0-9]{6,8})\b/i', $text, $m)) {
            $suffixes[] = strtoupper($m[1]);
        }
        if (preg_match('/\bvin\D{0,12}?([A-HJ-NPR-Z0-9]{6,8})\b/i', $text, $m)) {
            $suffixes[] = strtoupper($m[1]);
        }
        return array_values(array_unique($suffixes));
    }

    public static function extractPlate(string $text): ?string
    {
        if (preg_match('/\b(?:plate|tag|license)(?:\s+(?:number|is))?\s*[:#]?\s*([A-Z0-9]{1,4}[\s-]?[A-Z0-9]{1,6})(?![A-Z0-9])/i', $text, $m)) {
            return strtoupper(trim($m[1]));
        }
        return null;
    }

    /** Drop the "pull up / bring up / show me the" preamble before fuzzy matching. */
    public static function stripLeadIn(string $text): string
    {
        $stripped = preg_replace(
            '/^\s*(?:please\s+)?(?:pull up|bring up|open|show me|show|load|get me|get|find|look up|switch to|go to)\s+(?:the\s+)?/i',
            '',
            $text
        ) ?? $text;
        $stripped = preg_replace('/\b(?:r\.?o\.?|repair order|work order|ticket|order)\b/i', '', $stripped) ?? $stripped;
        $stripped = preg_replace('/\bfor\b/i', ' ', $stripped) ?? $stripped;
        $stripped = trim(preg_replace('/\s+/', ' ', $stripped) ?? $stripped);

        // Articles left behind by the strips above would otherwise be searched
        // for literally: "the order for Henderson" must look up "Henderson",
        // not "the Henderson".
        while (preg_match('/^(?:the|a|an)\s+/i', $stripped) === 1) {
            $stripped = trim((string) preg_replace('/^(?:the|a|an)\s+/i', '', $stripped));
        }

        return $stripped;
    }
}
