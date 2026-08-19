<?php
declare(strict_types=1);

/**
 * A very small test harness.
 *
 * No composer, no PHPUnit: cPanel shared hosting has no dependable shell for
 * installing them, and the point of these tests is that Russ can run them on
 * the box the thing actually deploys to.
 */

namespace ShopVoice\Tests;

use ShopVoice\App;
use ShopVoice\Support\Config;
use ShopVoice\Support\FrozenClock;

final class Harness
{
    public static int $passed = 0;
    /** @var list<string> */
    public static array $failures = [];
    public static string $group = '';

    public static function group(string $name): void
    {
        self::$group = $name;
        echo "\n\033[1m{$name}\033[0m\n";
    }

    public static function ok(bool $condition, string $description): void
    {
        if ($condition) {
            self::$passed++;
            echo "  \033[32m✓\033[0m {$description}\n";
            return;
        }
        self::$failures[] = self::$group . ' — ' . $description;
        echo "  \033[31m✗ {$description}\033[0m\n";
    }

    public static function same(mixed $expected, mixed $actual, string $description): void
    {
        $pass = $expected === $actual;
        if (!$pass) {
            $description .= sprintf(
                ' (expected %s, got %s)',
                var_export($expected, true),
                var_export($actual, true)
            );
        }
        self::ok($pass, $description);
    }

    public static function contains(string $needle, string $haystack, string $description): void
    {
        self::ok(
            str_contains(strtolower($haystack), strtolower($needle)),
            $description . sprintf(' (looking for "%s" in "%s")', $needle, $haystack)
        );
    }

    public static function throws(callable $work, string $description, ?string $expectMessage = null): void
    {
        try {
            $work();
            self::ok(false, $description . ' — but nothing was thrown');
        } catch (\Throwable $e) {
            if ($expectMessage !== null) {
                self::contains($expectMessage, $e->getMessage(), $description);
                return;
            }
            self::ok(true, $description);
        }
    }

    public static function summary(): int
    {
        $failed = count(self::$failures);
        echo "\n" . str_repeat('─', 60) . "\n";

        if ($failed === 0) {
            echo "\033[32m" . self::$passed . " passed\033[0m\n";
            return 0;
        }

        echo "\033[31m{$failed} failed\033[0m, " . self::$passed . " passed\n\n";
        foreach (self::$failures as $failure) {
            echo "  \033[31m✗\033[0m {$failure}\n";
        }
        return 1;
    }
}

/**
 * A fully wired application on an in-memory database, a frozen clock and the
 * fixture shop. No network, no keys.
 */
function makeApp(array $overrides = []): array
{
    $clock = new FrozenClock(new \DateTimeImmutable('2026-08-18T14:00:00+00:00'));

    $config = new Config(array_merge([
        'app.env' => 'testing',
        'app.timezone' => 'UTC',
        'db.dsn' => 'sqlite::memory:',
        'shopmonkey.mode' => 'fixture',
        'intent.provider' => 'mock',
        'intent.confidence_threshold' => 0.70,
        'notes.store' => 'local',
        'session.idle_minutes' => 720,
        'undo.window_seconds' => 30,
        'inspection.idle_seconds' => 300,
    ], $overrides));

    $app = new App($config, null, $clock, sys_get_temp_dir() . '/shopvoice-tests');
    $app->db()->migrate();
    $app->auth()->syncUsers();

    return [$app, $clock];
}

/** Enrol a tablet and sign a user in, the way a shift starts. */
function signIn(App $app, string $userId = 'usr_tech_a'): array
{
    $code = $app->auth()->createEnrollmentCode('Bay 1');
    $device = $app->auth()->enrollDevice($code);
    $login = $app->auth()->login($device['token'], $userId);

    return [$login['session'], $login['token'], $device];
}
