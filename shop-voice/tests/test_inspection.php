<?php
declare(strict_types=1);

use ShopVoice\Inspection\InspectionService;
use ShopVoice\Tests\Harness;

use function ShopVoice\Tests\makeApp;
use function ShopVoice\Tests\signIn;

Harness::group('§8 Inspection mode');

[$app, $clock] = makeApp();
[$session] = signIn($app);
$order = $app->gateway()->findOrderByNumber('4471');
$session = $app->auth()->loadContext($session, $order);

$started = $app->inspections()->start($session);
Harness::ok($started['inspection_id'] !== '', 'start inspection opens a session');
Harness::ok(count($started['fields']) > 10, 'and hands the tablet the whole sheet to render');

// Findings arrive in whatever order the tech walks the car.
$first = $app->inspections()->addItem($session, 'front pads four millimetres, yellow');
Harness::same(InspectionService::ACK_CHIRP, $first['ack'], 'a landed finding gets a chirp, not a spoken readback');
Harness::same('front_brakes', $first['field_key'], 'and is slotted into the right field');
Harness::same('yellow', $first['item']['condition'], 'with the technician\'s own colour call');
Harness::same('4 mm', $first['item']['value'], 'and the measurement as spoken');

$second = $app->inspections()->addItem($session, 'battery holds twelve point four volts, green');
Harness::same('battery_test', $second['field_key'], 'a finding called out of order still lands correctly');

$unclear = $app->inspections()->addItem($session, 'yeah that thing over there');
Harness::same(InspectionService::ACK_SPEAK, $unclear['ack'], 'an uncertain parse is the one case that earns a spoken response');
Harness::same(null, $unclear['item'], 'and nothing is filed under a guess');

// Re-calling an item corrects it.
$app->inspections()->addItem($session, 'actually front pads three millimetres, red');
$open = $app->inspections()->openFor($session);
$items = $app->inspections()->items((string) $open['id']);
$front = array_values(array_filter($items, static fn (array $i): bool => $i['field_key'] === 'front_brakes'));

Harness::same(1, count($front), 're-calling an item replaces it rather than stacking a second reading');
Harness::same('red', $front[0]['condition'], 'and the correction is what sticks');

Harness::group('§8 Missing-items readback');

$summary = $app->inspections()->end($session);
Harness::contains("I don't have", $summary['spoken'], 'ending reads back only what is missing');
Harness::same(false, str_contains($summary['spoken'], 'front brakes'), 'what was called out is not read back');
Harness::same(false, str_contains($summary['spoken'], 'the battery test'), 'nor the battery test');
Harness::contains('rear brakes', $summary['spoken'], 'but rear brakes, which were never called, are');
Harness::contains(' or ', $summary['spoken'], 'and the list reads like a sentence');

Harness::ok(count($summary['deferred']) > 0, 'yellow and red findings become deferred work');

Harness::group('§8 Inspection idle timeout');

[$app2, $clock2] = makeApp();
[$s2] = signIn($app2);
$order2 = $app2->gateway()->findOrderByNumber('4471');
$s2 = $app2->auth()->loadContext($s2, $order2);
$app2->inspections()->start($s2);

$clock2->advance(120);
Harness::same(0, $app2->inspections()->expireIdle(), 'two minutes of quiet does not close the session — silence ends the item, not the inspection');

$clock2->advance(400);
Harness::same(1, $app2->inspections()->expireIdle(), 'but around five minutes idle does');

Harness::group('§8 The readback stays short');

[$app3, $clock3] = makeApp();
[$s3] = signIn($app3);
$order3 = $app3->gateway()->findOrderByNumber('4471');
$s3 = $app3->auth()->loadContext($s3, $order3);
$app3->inspections()->start($s3);
$app3->inspections()->addItem($s3, 'front pads four millimetres, yellow');

$summary3 = $app3->inspections()->end($s3);
Harness::contains('more on the sheet', $summary3['spoken'], 'a barely-started inspection names a few items and points at the sheet');
Harness::ok(
    substr_count($summary3['spoken'], ',') <= 3,
    'rather than reading out a list nobody can hold in their head'
);
Harness::ok(count($summary3['missing']) > 4, 'though the full list is still on screen');
