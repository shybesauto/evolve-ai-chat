<?php
declare(strict_types=1);

/**
 * php tools/migrate.php
 *
 * Applies any pending migrations and syncs the shop's users from Shopmonkey.
 * Safe to re-run: applied migrations are recorded and skipped.
 */

require __DIR__ . '/../src/autoload.php';

use ShopVoice\App;

$app = App::boot();

$applied = $app->db()->migrate();
echo $applied === []
    ? "Schema already up to date.\n"
    : "Applied: " . implode(', ', $applied) . "\n";

$count = $app->auth()->syncUsers();
echo "Synced {$count} user(s) from " . $app->gateway()->describe() . "\n";

echo "\nPosture:\n";
foreach ($app->posture() as $key => $value) {
    printf("  %-22s %s\n", $key, is_bool($value) ? var_export($value, true) : (string) $value);
}
