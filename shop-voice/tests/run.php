<?php
declare(strict_types=1);

/**
 * php tests/run.php
 *
 * Runs against SQLite in memory, the fixture shop and the deterministic intent
 * parser — no database server, no API keys, no network.
 */

require __DIR__ . '/../src/autoload.php';
require __DIR__ . '/harness.php';

use ShopVoice\Tests\Harness;

$files = glob(__DIR__ . '/test_*.php') ?: [];
sort($files);

foreach ($files as $file) {
    require $file;
}

exit(Harness::summary());
