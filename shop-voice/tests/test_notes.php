<?php
declare(strict_types=1);

use ShopVoice\Notes\LocalNoteStore;
use ShopVoice\Notes\ShopmonkeyNoteStore;
use ShopVoice\Support\FakeHttpClient;
use ShopVoice\Tests\Harness;

use function ShopVoice\Tests\makeApp;
use function ShopVoice\Tests\signIn;

Harness::group('§6 Dictated notes — dual version');

[$app, $clock] = makeApp();
[$session] = signIn($app);
$order = $app->gateway()->findOrderByNumber('4471');
$session = $app->auth()->loadContext($session, $order);

$spoken = 'left front caliper is sticking, possible collapsed hose, needs a road test to confirm';
$note = $app->notes()->dictate($session, $spoken, '4471', $order->id, $order->vehicle->id, $order->readbackLabel());

Harness::same($spoken, $note->rawTranscript, 'the internal note is the raw transcript, verbatim');
Harness::same('awaiting_tech', $note->stage(), 'and starts unapproved');
Harness::same(null, $note->customerApprovedAt, 'the customer version never posts automatically');

Harness::group('§6 Two-stage approval');

Harness::throws(
    static fn () => $app->notes()->approveCustomerVersion($session, $note->id, 'Your caliper failed.', 'tap'),
    'the advisor cannot approve wording before the tech has approved accuracy',
    'has not approved'
);

$note = $app->notes()->approveAccuracy($session, $note->id);
Harness::same('awaiting_advisor', $note->stage(), 'stage one: the tech approves accuracy at the lift');

Harness::throws(
    static fn () => $app->notes()->approveCustomerVersion($session, $note->id, 'Anything', 'voice'),
    'a spoken yes cannot approve the customer version',
    'tap on the tablet'
);

$note = $app->notes()->approveCustomerVersion(
    $session,
    $note->id,
    'We found the left front brake caliper sticking. We would like to road test it to confirm.',
    'tap'
);
Harness::same('customer_approved', $note->stage(), 'stage two: a tap from the advisor releases the customer wording');
Harness::contains('road test', (string) $note->customerText(), 'the advisor\'s edit is what a customer would see');
Harness::same($spoken, $note->rawTranscript, 'and the internal note is still the tech\'s exact words');

Harness::group('§10 Offline queue is idempotent');

$uuid = 'tablet-uuid-0001';
$first = $app->notes()->dictate($session, 'rear pads are down to two millimetres', '4471', $order->id, $order->vehicle->id, null, $uuid, '2026-08-18T13:40:00Z');
$replay = $app->notes()->dictate($session, 'rear pads are down to two millimetres', '4471', $order->id, $order->vehicle->id, null, $uuid, '2026-08-18T13:40:00Z');

Harness::same($first->id, $replay->id, 'replaying a queued note does not double-post the diagnosis');
Harness::same('2026-08-18T13:40:00Z', $first->dictatedAt, 'and the note keeps the time it was actually spoken, not the time it arrived');

Harness::group('§2 The Shopmonkey note endpoint is locked until reviewed');

Harness::same(false, (new LocalNoteStore())->isRemote(), 'the default store is our own table');

$http = new FakeHttpClient();
$store = new ShopmonkeyNoteStore($http, 'sk_test', 'https://api.shopmonkey.cloud/v3/', $app->logger());

$result = $store->write($first);
Harness::same(false, $store->isRemote(), 'the Shopmonkey store reports itself unavailable while unverified');
Harness::same('local_only', $result['sync_state'], 'a write against an unverified endpoint is refused');
Harness::same(0, count($http->calls), 'and no HTTP request is made at all');
Harness::contains('LOCKED', $store->describe(), 'the store says plainly that it is waiting on the §2 review');

$app2 = makeApp()[0];
Harness::same(0, $app2->notes()->retrySync(), 'the sync job is a no-op while the endpoint is locked');
