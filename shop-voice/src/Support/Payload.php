<?php
declare(strict_types=1);

namespace ShopVoice\Support;

/**
 * Defensive reads out of a third-party JSON payload.
 *
 * We do not control Shopmonkey's field naming and have not yet been able to
 * verify it (§2). Reading through a candidate list means an unexpected key
 * costs one null field rather than a fatal.
 */
final class Payload
{
    /**
     * @param array<mixed> $data
     * @param list<string> $candidates
     */
    public static function pick(array $data, array $candidates, mixed $default = null): mixed
    {
        foreach ($candidates as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== null && $data[$key] !== '') {
                return $data[$key];
            }
        }
        return $default;
    }

    /** @param array<mixed> $data @param list<string> $candidates */
    public static function pickString(array $data, array $candidates, ?string $default = null): ?string
    {
        $value = self::pick($data, $candidates);
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        return $default;
    }

    /** @param array<mixed> $data @param list<string> $candidates */
    public static function pickInt(array $data, array $candidates, ?int $default = null): ?int
    {
        $value = self::pick($data, $candidates);
        return is_numeric($value) ? (int) $value : $default;
    }

    /** @param array<mixed> $data @param list<string> $candidates */
    public static function pickFloat(array $data, array $candidates, ?float $default = null): ?float
    {
        $value = self::pick($data, $candidates);
        return is_numeric($value) ? (float) $value : $default;
    }

    /** @param array<mixed> $data @param list<string> $candidates */
    public static function pickBool(array $data, array $candidates, bool $default = false): bool
    {
        $value = self::pick($data, $candidates);
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes'], true);
        }
        if (is_numeric($value)) {
            return (int) $value === 1;
        }
        return $default;
    }

    /** @param array<mixed> $data @param list<string> $candidates @return array<mixed> */
    public static function pickArray(array $data, array $candidates): array
    {
        $value = self::pick($data, $candidates);
        return is_array($value) ? $value : [];
    }

    /**
     * Find the list of records inside whatever envelope came back.
     *
     * @param array<mixed> $response
     * @param list<string> $collectionKeys
     * @return list<array<mixed>>
     */
    public static function collection(array $response, array $collectionKeys): array
    {
        foreach ($collectionKeys as $key) {
            if (isset($response[$key]) && is_array($response[$key])) {
                $candidate = $response[$key];
                // Some APIs wrap twice: {data: {data: [...]}}
                if (array_is_list($candidate)) {
                    return array_values(array_filter($candidate, 'is_array'));
                }
                $nested = self::collection($candidate, $collectionKeys);
                if ($nested !== []) {
                    return $nested;
                }
            }
        }
        if (array_is_list($response)) {
            return array_values(array_filter($response, 'is_array'));
        }
        return [];
    }
}
