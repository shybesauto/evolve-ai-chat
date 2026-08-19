<?php
declare(strict_types=1);

namespace ShopVoice\Support;

/**
 * Reads the .env file that lives OUTSIDE the web root.
 *
 * Values are held in this class rather than putenv()/$_ENV so they cannot leak
 * into phpinfo(), error pages, or a subprocess environment.
 */
final class Env
{
    /** @var array<string,string> */
    private static array $vars = [];
    private static bool $loaded = false;

    public static function load(string $path): void
    {
        self::$loaded = true;

        if (!is_file($path)) {
            // Not fatal: fixture/mock mode runs with defaults so a fresh
            // checkout boots. Anything genuinely secret is required() later.
            return;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }
            $key = trim(substr($line, 0, $eq));
            $value = trim(substr($line, $eq + 1));

            // Strip one layer of matching quotes, if present.
            $len = strlen($value);
            if ($len >= 2
                && (($value[0] === '"' && $value[$len - 1] === '"')
                    || ($value[0] === "'" && $value[$len - 1] === "'"))) {
                $value = substr($value, 1, -1);
            }
            self::$vars[$key] = $value;
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = self::$vars[$key] ?? null;
        if ($value === null || $value === '') {
            return $default;
        }
        return $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }
        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public static function int(string $key, int $default): int
    {
        $value = self::get($key);
        return $value === null ? $default : (int) $value;
    }

    public static function float(string $key, float $default): float
    {
        $value = self::get($key);
        return $value === null ? $default : (float) $value;
    }

    /** Fail loudly at startup rather than at 2am in a bay. */
    public static function required(string $key): string
    {
        $value = self::get($key);
        if ($value === null) {
            throw new \RuntimeException(
                "Missing required env var {$key}. Add it to the .env file outside public_html."
            );
        }
        return $value;
    }

    /** @param array<string,string> $vars */
    public static function fake(array $vars): void
    {
        self::$vars = $vars;
        self::$loaded = true;
    }

    public static function isLoaded(): bool
    {
        return self::$loaded;
    }
}
