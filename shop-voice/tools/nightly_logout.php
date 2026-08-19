<?php
declare(strict_types=1);

/**
 * php tools/nightly_logout.php
 *
 * §7: a nightly auto-logout, so no session runs silently into the next day.
 * Also sweeps inspections left open when someone walked away mid-car.
 *
 * cPanel cron, at the hour in NIGHTLY_LOGOUT_AT (shop local time):
 *   0 3 * * *  /usr/local/bin/php /home/USER/shopvoice/tools/nightly_logout.php
 */

require __DIR__ . '/../src/autoload.php';

use ShopVoice\App;

$app = App::boot();

$sessions = $app->auth()->endAllSessions('nightly');
$inspections = $app->inspections()->expireIdle();

// A no-op while the note endpoint is locked (§2); becomes the retry path the
// moment it is verified.
$synced = $app->notes()->retrySync();

echo sprintf(
    "[%s] closed %d session(s), %d stale inspection(s), synced %d note(s)\n",
    $app->clock->iso(),
    $sessions,
    $inspections,
    $synced
);
