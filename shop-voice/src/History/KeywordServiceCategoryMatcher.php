<?php
declare(strict_types=1);

namespace ShopVoice\History;

/**
 * Deterministic category matching from a keyword table.
 *
 * This is the floor, not the ceiling: it is what runs when the model is
 * unreachable or slow. A model outage should cost the tech nuance, not the
 * ability to look up brake history — so the table covers the categories that
 * actually come up in a bay, including the shop's abbreviations.
 */
final class KeywordServiceCategoryMatcher implements ServiceCategoryMatcher
{
    /** @var array<string,array{label:string,keywords:list<string>}> */
    private const CATEGORIES = [
        'brakes' => ['label' => 'Brakes', 'keywords' => [
            'brake', 'brakes', 'pad', 'pads', 'rotor', 'rotors', 'caliper', 'calipers',
            'br-frt', 'br-rr', 'brk', 'shoe', 'shoes', 'drum', 'drums', 'brake fluid',
        ]],
        'oil' => ['label' => 'Oil change', 'keywords' => [
            'oil', 'lof', 'lube', 'oil and filter', 'synthetic', 'oil change',
        ]],
        'tires' => ['label' => 'Tires', 'keywords' => [
            'tire', 'tires', 'rotation', 'rotate', 'balance', 'tpms', 'mount',
        ]],
        'alignment' => ['label' => 'Alignment', 'keywords' => [
            'alignment', 'align', 'toe', 'camber', 'caster',
        ]],
        'suspension' => ['label' => 'Suspension', 'keywords' => [
            'strut', 'struts', 'shock', 'shocks', 'spring', 'control arm', 'ball joint',
            'tie rod', 'bushing', 'sway bar',
        ]],
        'cooling' => ['label' => 'Cooling system', 'keywords' => [
            'coolant', 'radiator', 'thermostat', 'water pump', 'antifreeze', 'flush', 'hose',
        ]],
        'electrical' => ['label' => 'Electrical', 'keywords' => [
            'battery', 'alternator', 'starter', 'wiring', 'fuse', 'relay', 'ground', 'charging',
        ]],
        'battery' => ['label' => 'Battery', 'keywords' => ['battery', 'batt', 'charging system']],
        'ac' => ['label' => 'A/C', 'keywords' => [
            'a/c', 'ac ', 'air conditioning', 'evaporator', 'condenser', 'compressor', 'refrigerant', 'recharge',
        ]],
        'engine' => ['label' => 'Engine', 'keywords' => [
            'engine', 'spark plug', 'plugs', 'coil', 'timing', 'valve cover', 'gasket', 'misfire',
        ]],
        'transmission' => ['label' => 'Transmission', 'keywords' => [
            'transmission', 'trans', 'clutch', 'atf', 'differential', 'transfer case', 'gear oil',
        ]],
        'belts' => ['label' => 'Belts', 'keywords' => ['belt', 'serpentine', 'tensioner', 'pulley']],
        'filters' => ['label' => 'Filters', 'keywords' => ['filter', 'cabin air', 'air filter', 'fuel filter']],
        'diagnostics' => ['label' => 'Diagnostics', 'keywords' => [
            'diagnose', 'diagnosis', 'diag', 'scan', 'check engine', 'inspect', 'test',
        ]],
        'exhaust' => ['label' => 'Exhaust', 'keywords' => ['exhaust', 'muffler', 'catalytic', 'o2 sensor', 'evap']],
    ];

    public function match(string $category, array $serviceNames): array
    {
        $keywords = $this->keywordsFor($category);
        if ($keywords === []) {
            return [];
        }

        $matched = [];
        foreach ($serviceNames as $name) {
            $haystack = ' ' . strtolower($name) . ' ';
            foreach ($keywords as $keyword) {
                if (str_contains($haystack, strtolower($keyword))) {
                    $matched[] = $name;
                    break;
                }
            }
        }
        return array_values(array_unique($matched));
    }

    public function label(string $category): string
    {
        $key = self::normalize($category);
        return self::CATEGORIES[$key]['label'] ?? ucfirst(trim($category));
    }

    /** @return list<string> */
    private function keywordsFor(string $category): array
    {
        $key = self::normalize($category);
        if (isset(self::CATEGORIES[$key])) {
            return self::CATEGORIES[$key]['keywords'];
        }
        // An unlisted category still matches on its own words: "wiper blades".
        $words = array_filter(
            preg_split('/[^a-z0-9]+/', strtolower($category)) ?: [],
            static fn (string $w): bool => strlen($w) > 2
        );
        return array_values($words);
    }

    /** @return list<string> the categories this matcher knows, for the model prompt */
    public static function known(): array
    {
        return array_keys(self::CATEGORIES);
    }

    private static function normalize(string $category): string
    {
        $key = strtolower(trim($category));
        return match ($key) {
            'brake', 'brake job', 'front brakes', 'rear brakes' => 'brakes',
            'oil change', 'lof' => 'oil',
            'tire' => 'tires',
            'air conditioning', 'a/c', 'a c' => 'ac',
            default => $key,
        };
    }
}
