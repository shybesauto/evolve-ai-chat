<?php
declare(strict_types=1);

use ShopVoice\Auth\Authorization;
use ShopVoice\Intent\RiskTier;
use ShopVoice\Tests\Harness;

use function ShopVoice\Tests\makeApp;
use function ShopVoice\Tests\signIn;

Harness::group('§7 Identity and sessions');

[$app, $clock] = makeApp();
[$session, $token] = signIn($app);

Harness::same('usr_tech_a', $session->userId, 'the session carries the Shopmonkey user id verbatim, with no mapping table');

$users = $app->auth()->activeUsers();
$danny = array_values(array_filter($users, static fn (array $u): bool => $u['id'] === 'usr_tech_a'))[0];
Harness::same('MI-A5-771903', $danny['cert_number'], 'and the state certification number MCL 257.1313b requires');

// Sticky context.
$order = $app->gateway()->findOrderByNumber('4471');
$session = $app->auth()->loadContext($session, $order);
Harness::same('4471', $session->contextRoNumber, 'the loaded RO sticks to the session');

$session = $app->auth()->sessionFromToken($token);
Harness::same('4471', $session->contextRoNumber, 'and survives the next request');

Harness::group('§7 Voice user switch');

$switched = $app->auth()->voiceSwitch($session, 'Russ');
$russ = $switched['session'];

Harness::same('usr_russ', $russ->userId, 'a spoken claim switches the user');
Harness::same('voice_switch', $russ->claimedVia, 'and records how it was claimed');
Harness::same(false, $russ->hasContext(), 'context clears on user switch — it follows the user, not the device');

Harness::throws(
    static fn () => $app->auth()->sessionFromToken($token),
    'the previous session token stops working',
    'claimed this tablet'
);

$read = Authorization::decide($russ, RiskTier::Read);
Harness::same(true, $read->voiceOnly(), 'reads work immediately after a voice switch');

$low = Authorization::decide($russ, RiskTier::LowRiskWrite);
Harness::same(true, $low->voiceOnly(), 'low-risk writes work immediately too');

$high = Authorization::decide($russ, RiskTier::HighRisk);
Harness::same(true, $high->requiresScreenTap, 'but a high-risk write needs a screen tap');
Harness::same(true, $high->requiresIdentityUnlock, 'and, after a voice switch, proof of who is speaking');
Harness::same(false, $high->voiceOnly(), 'so it can never be completed by voice alone');

// A user with a PIN set must actually produce it.
$app->auth()->setPin('usr_russ', '2468');
$russ = $app->auth()->requireSessionById($russ->id);

Harness::throws(
    static fn () => $app->auth()->unlockHighRisk($russ, '1111'),
    'a wrong PIN does not unlock',
    'not right'
);

$unlocked = $app->auth()->unlockHighRisk($russ, '2468');
Harness::same(false, $unlocked->needsIdentityUnlock(), 'the right PIN clears the identity gate');

$high = Authorization::decide($unlocked, RiskTier::HighRisk);
Harness::same(false, $high->requiresIdentityUnlock, 'and it stays cleared for the session');
Harness::same(true, $high->requiresScreenTap, 'but the tap is still required, every time');

Harness::group('§7 Nightly auto-logout');

[$app2, $clock2] = makeApp();
[$s2, $t2] = signIn($app2);
$app2->auth()->endAllSessions('nightly');

Harness::throws(
    static fn () => $app2->auth()->sessionFromToken($t2),
    'no session runs silently into the next day',
    'overnight'
);

Harness::group('§3 The tablet never holds the Shopmonkey token');

[$app3] = makeApp(['shopmonkey.token' => 'sk_live_pretend_secret']);
[$s3, $t3, $device] = signIn($app3);

$serialised = json_encode([
    'session' => $s3,
    'session_token' => $t3,
    'device' => $device,
]);
Harness::same(false, str_contains((string) $serialised, 'sk_live_pretend_secret'), 'nothing the tablet receives contains the API token');
