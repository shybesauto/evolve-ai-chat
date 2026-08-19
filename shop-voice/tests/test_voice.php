<?php
declare(strict_types=1);

use ShopVoice\Tests\Harness;

use function ShopVoice\Tests\makeApp;
use function ShopVoice\Tests\signIn;

Harness::group('End to end — a shift in the bay');

[$app, $clock] = makeApp();
[$session] = signIn($app);
$voice = $app->voice();

// 1. Load a car.
$response = $voice->handle($session, 'pull up the RO for the silver Tahoe');
Harness::same("RO 4471, Henderson's Tahoe.", $response->speak, 'loading an RO reads back the number and vehicle (§6)');
Harness::same('4471', $response->session->contextRoNumber, 'and it becomes the sticky context');
Harness::ok(isset($response->screen['order']), 'with the full order on screen');

// 2. A follow-up assumes the same car, and does not re-announce it.
$session = $response->session;
$response = $voice->handle($session, 'when did we last do brakes on this one');
Harness::contains('brakes', $response->speak, 'a follow-up answers about the same vehicle');
Harness::contains('at 88,240', $response->speak, 'with the odometer Shopmonkey records per order');
Harness::same(false, str_contains($response->speak, 'RO 4471'), 'and does not repeat the readback — context is sticky');

// The two brake jobs were recorded as "Front pads and rotors" and "BR-FRT".
$entries = $response->screen['history']['entries'];
Harness::same(2, count($entries), 'both brake visits are found despite being named differently');

// 3. Ambiguity is a question, never a guess.
$response = $voice->handle($session, 'pull up the Tahoe');
Harness::contains('Henderson or Wozniak', $response->speak, 'two open Tahoes produce a question');
Harness::same('disambiguate', $response->view, 'and the candidates go on screen as tappable rows');

// 4. Dictate a note.
$session = $app->auth()->sessionFromToken(signIn($app)[1]);
$order = $app->gateway()->findOrderByNumber('4471');
$session = $app->auth()->loadContext($session, $order);

$response = $voice->handle($session, 'make a note the left front caliper is possibly sticking');
Harness::contains('Is that right?', $response->speak, 'dictation ends with a readback before it saves (§9)');
Harness::same('voice', $response->confirm['type'], 'a low-risk write confirms by voice');
Harness::contains('possibly sticking', $response->speak, 'and the readback is the tech\'s own words');

$noteId = $response->confirm['note_id'];
$note = $app->notes()->require($noteId);
Harness::same('the left front caliper is possibly sticking', $note->rawTranscript, 'stored verbatim, preamble stripped');

// 5. Undo, inside the window.
Harness::ok($response->undoSeconds > 0 && $response->undoSeconds <= 30, 'the undo window is open and bounded to 30 seconds');
$undo = $voice->handle($response->session, 'undo that');
Harness::contains('Undone', $undo->speak, '"undo that" reverses the note');

// 6. High-risk stops at the gate.
$response = $voice->handle($undo->session, 'mark it ready');
Harness::same('tap', $response->confirm['type'], 'a status change requires a screen tap');
Harness::contains('Tap to confirm', $response->speak, 'and says so out loud');
Harness::same('confirm_tap', $response->view, 'the tablet is sent to the confirmation screen');

// 7. Anything outside the closed list is refused flatly.
$response = $voice->handle($response->session, 'order me a pizza');
Harness::contains("didn't catch that", $response->speak, 'an out-of-scope request is refused, not attempted');
Harness::same(null, $response->confirm, 'and nothing is queued up for confirmation');

Harness::group('§6 Undo expires');

[$app2, $clock2] = makeApp();
[$s2] = signIn($app2);
$order2 = $app2->gateway()->findOrderByNumber('4471');
$s2 = $app2->auth()->loadContext($s2, $order2);

$app2->voice()->handle($s2, 'make a note rear pads are low');
$clock2->advance(45);

$result = $app2->undo()->undoLast($app2->auth()->requireSessionById($s2->id));
Harness::same(false, $result['undone'], 'after 30 seconds the window has closed');
Harness::contains('tablet', $result['spoken'], 'and the tech is told where to go instead');

Harness::group('§12 Diagrams answer honestly until they are built');

$response = $app2->voice()->handle($app2->auth()->requireSessionById($s2->id), 'pull up the wiring diagram for the evap system');
Harness::contains('not wired up yet', $response->speak, 'an unbuilt feature says so rather than failing silently (§10)');
