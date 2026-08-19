<?php
declare(strict_types=1);

/**
 * php tools/say.php "pull up the RO for the silver Tahoe"
 *
 * Build step 3's acceptance test, at the command line: type a sentence and
 * watch it resolve. Runs the full path — intent, validation, authorization,
 * dispatch — against the fixture shop in a scratch database, so it needs no
 * keys, no network and no MySQL.
 *
 * Pass several sentences to run them as one conversation, which is how the
 * sticky context and the undo window become visible:
 *
 *   php tools/say.php "pull up 4471" "when did we last do brakes" "undo that"
 */

require __DIR__ . '/../src/autoload.php';

use ShopVoice\App;
use ShopVoice\Support\Config;
use ShopVoice\Support\Env;

$sentences = array_slice($argv, 1);
if ($sentences === []) {
    fwrite(STDERR, "Usage: php tools/say.php \"pull up the silver Tahoe\" [\"...\"]\n");
    exit(1);
}

Env::load(dirname(__DIR__) . '/.env');

// Deliberately not the production database: this is a scratchpad for trying
// phrasings, and it should never leave rows behind in the shop's data.
$config = Config::fromEnv();
$app = new App($config->with('db.dsn', 'sqlite::memory:')->with('db.user', null)->with('db.pass', null));

$app->db()->migrate();
$app->auth()->syncUsers();

$code = $app->auth()->createEnrollmentCode('CLI');
$device = $app->auth()->enrollDevice($code);
$login = $app->auth()->login($device['token'], 'usr_tech_a');
$session = $login['session'];

echo "shopmonkey: ", $app->gateway()->describe(), "\n";
echo "intent:     ", $app->intentProvider()->name(), " (", $app->intentProvider()->model(), ")\n";
echo str_repeat('─', 72), "\n";

foreach ($sentences as $sentence) {
    $response = $app->voice()->handle($session, $sentence);
    $session = $response->session ?? $session;

    echo "\n\033[1m» {$sentence}\033[0m\n";

    if ($response->intent !== null) {
        printf(
            "  intent   %s  confidence %.2f  target %s\n",
            $response->intent['action'] ?: '(unrecognised)',
            $response->intent['confidence'],
            $response->intent['target']->type ?? '?'
        );
    }

    if ($response->speak !== '') {
        echo "  \033[36mspeaks\033[0m   \"{$response->speak}\"\n";
    }
    if ($response->ack !== null) {
        echo "  ack      {$response->ack}\n";
    }
    if ($response->confirm !== null) {
        echo "  confirm  {$response->confirm['type']}";
        echo isset($response->confirm['prompt']) ? " — {$response->confirm['prompt']}" : '';
        echo "\n";
    }
    if ($response->view !== null) {
        echo "  view     {$response->view}\n";
    }
    if ($session->hasContext()) {
        echo "  context  {$session->contextLabel}\n";
    }
    if ($response->undoSeconds > 0) {
        echo "  undo     {$response->undoSeconds}s remaining\n";
    }
}

echo "\n";
