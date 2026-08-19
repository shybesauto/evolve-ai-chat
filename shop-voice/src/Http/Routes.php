<?php
declare(strict_types=1);

namespace ShopVoice\Http;

use ShopVoice\App;
use ShopVoice\Auth\Authorization;
use ShopVoice\Auth\Session;
use ShopVoice\Intent\ActionCatalog;
use ShopVoice\Intent\IntentValidator;
use ShopVoice\Intent\RiskTier;

/**
 * The bay client's whole API surface.
 *
 * Build step 1 is "endpoints that fetch an order by number, plate, VIN or
 * customer name and return clean JSON — test in a browser, no voice, no AI"
 * (§13). Those are the /orders routes, and they work standalone; the voice
 * routes are layered on top of the same services rather than duplicating them.
 */
final class Routes
{
    public static function register(Router $router, App $app): void
    {
        // --- health & posture ---------------------------------------------
        $router->get('/api/v1/health', static fn (Request $r): Response => Response::json([
            'ok' => true,
            'time' => $app->clock->iso(),
        ]));

        $router->get('/api/v1/posture', static fn (Request $r): Response => Response::json($app->posture()));

        // --- enrollment & auth --------------------------------------------
        $router->post('/api/v1/devices/enroll', static function (Request $r) use ($app): Response {
            $result = $app->auth()->enrollDevice($r->requireString('code'));
            // The raw device token is returned exactly once, here.
            return Response::json($result, 201);
        });

        $router->get('/api/v1/users', static function (Request $r) use ($app): Response {
            self::device($app, $r);
            return Response::json(['users' => $app->auth()->activeUsers()]);
        });

        $router->post('/api/v1/auth/login', static function (Request $r) use ($app): Response {
            $device = self::device($app, $r);
            $result = $app->auth()->login(
                (string) $r->header('x-device-token'),
                $r->requireString('user_id'),
                $r->string('pin'),
            );
            return Response::json([
                'session_token' => $result['token'],
                'session' => $result['session'],
                'device' => ['id' => $device['id'], 'name' => $device['name'], 'wake_word_enabled' => (bool) $device['wake_word_enabled']],
            ], 201);
        });

        $router->post('/api/v1/auth/logout', static function (Request $r) use ($app): Response {
            $session = self::session($app, $r);
            $app->auth()->endSession($session->id, 'logout');
            return Response::json(['ok' => true]);
        });

        $router->get('/api/v1/session', static function (Request $r) use ($app): Response {
            $session = self::session($app, $r);
            return Response::json([
                'session' => $session,
                'undo_seconds' => $app->undo()->remainingSeconds($session),
                'inspection' => $app->inspections()->openFor($session),
            ]);
        });

        /** Clears the §7 identity gate after a voice switch: tap, or PIN if one is set. */
        $router->post('/api/v1/auth/unlock', static function (Request $r) use ($app): Response {
            $session = self::session($app, $r);
            $unlocked = $app->auth()->unlockHighRisk($session, $r->string('pin'));
            return Response::json(['session' => $unlocked]);
        });

        $router->post('/api/v1/devices/wake-word', static function (Request $r) use ($app): Response {
            $session = self::session($app, $r);
            // One obvious switch (§9) — a bay next to a compressor may be hopeless.
            $app->auth()->setWakeWordEnabled($session->deviceId, $r->bool('enabled', true));
            return Response::json(['wake_word_enabled' => $r->bool('enabled', true)]);
        });

        // --- orders (build step 1: testable in a browser) -------------------
        $router->get('/api/v1/orders/resolve', static function (Request $r) use ($app): Response {
            self::session($app, $r);
            $result = $app->resolver()->resolve(
                $r->requireString('q'),
                !$r->bool('include_closed', false),
                $r->string('type'),
            );
            return Response::json($result);
        });

        $router->get('/api/v1/orders/{number}', static function (Request $r) use ($app): Response {
            self::session($app, $r);
            $order = $app->gateway()->findOrderByNumber((string) $r->param('number'), false);
            if ($order === null) {
                return Response::error('No such repair order.', 404);
            }
            return Response::json([
                'order' => $order,
                'notes' => $app->notes()->forOrder($order->number),
            ]);
        });

        $router->post('/api/v1/orders/{number}/load', static function (Request $r) use ($app): Response {
            $session = self::session($app, $r);
            $order = $app->gateway()->findOrderByNumber((string) $r->param('number'), false);
            if ($order === null) {
                return Response::error('No such repair order.', 404);
            }
            $session = $app->auth()->loadContext($session, $order);
            return Response::json(['session' => $session, 'order' => $order, 'speak' => $order->readbackLabel() . '.']);
        });

        $router->post('/api/v1/context/release', static function (Request $r) use ($app): Response {
            $session = self::session($app, $r);
            return Response::json(['session' => $app->auth()->releaseContext($session)]);
        });

        $router->get('/api/v1/vehicles/{id}/history', static function (Request $r) use ($app): Response {
            self::session($app, $r);
            return Response::json($app->history()->forVehicle(
                (string) $r->param('id'),
                $r->string('category'),
            ));
        });

        // --- intent (build step 3: text in, validated intent out) ----------
        $router->post('/api/v1/intent/parse', static function (Request $r) use ($app): Response {
            $session = self::session($app, $r);
            $transcript = $r->requireString('transcript');
            $context = $app->voice()->buildContext($session);

            $intent = $app->intentProvider()->parse($transcript, $context);
            $validation = (new IntentValidator($app->config->float('intent.confidence_threshold', 0.70)))
                ->validate($intent, $context);

            return Response::json([
                'context' => $context,
                'intent' => $intent,
                'validation' => $validation,
            ]);
        });

        // --- voice (the bay client's main entry point) ---------------------
        $router->post('/api/v1/voice/utterance', static function (Request $r) use ($app): Response {
            $session = self::session($app, $r);
            return Response::json($app->voice()->handle($session, $r->requireString('transcript')));
        });

        // --- notes ---------------------------------------------------------
        $router->post('/api/v1/notes', static function (Request $r) use ($app): Response {
            $session = self::session($app, $r);
            if (!$session->hasContext() && $r->string('ro_number') === null) {
                return Response::error('No repair order loaded.', 409);
            }

            $note = $app->notes()->dictate(
                session: $session,
                rawTranscript: $r->requireString('transcript'),
                roNumber: $r->string('ro_number', (string) $session->contextRoNumber) ?? '',
                orderId: $r->string('order_id', $session->contextOrderId),
                vehicleId: $r->string('vehicle_id', $session->contextVehicleId),
                vehicleDescription: $session->contextLabel,
                // Both supplied by the tablet for a note dictated offline (§10).
                clientUuid: $r->string('client_uuid'),
                dictatedAt: $r->string('dictated_at'),
            );

            $app->undo()->record($session, ActionCatalog::ADD_NOTE, 'note', $note->id, 'the note on RO ' . $note->roNumber);

            return Response::json([
                'note' => $note,
                'speak' => sprintf('On RO %s: %s. Is that right?', $note->roNumber, $note->rawTranscript),
                'undo_seconds' => $app->undo()->remainingSeconds($session),
            ], 201);
        });

        /** Stage one: the tech confirms the internal note is accurate (§6). */
        $router->post('/api/v1/notes/{id}/approve-accuracy', static function (Request $r) use ($app): Response {
            $session = self::session($app, $r);
            $note = $app->notes()->approveAccuracy($session, (string) $r->param('id'));
            $app->auth()->audit($session, 'approve_note_accuracy', 'low_risk', $r->string('via', 'tap') ?? 'tap', [], 'note', $note->id);
            return Response::json(['note' => $note, 'speak' => 'Saved.']);
        });

        /**
         * Stage two: the advisor approves the customer-facing wording.
         * High-risk — a screen tap, never a spoken yes.
         */
        $router->post('/api/v1/notes/{id}/approve-customer', static function (Request $r) use ($app): Response {
            $session = self::session($app, $r);

            $authorization = Authorization::decide($session, RiskTier::HighRisk);
            if ($authorization->requiresIdentityUnlock) {
                return Response::error('Enter your PIN on the tablet first.', 403, 'identity_unlock_required');
            }

            $via = $r->string('via', 'tap') ?? 'tap';
            $note = $app->notes()->approveCustomerVersion($session, (string) $r->param('id'), $r->string('text'), $via);
            $app->auth()->audit($session, 'approve_note_customer', 'high_risk', $via, [], 'note', $note->id);

            return Response::json(['note' => $note]);
        });

        $router->get('/api/v1/notes', static function (Request $r) use ($app): Response {
            $session = self::session($app, $r);
            $ro = $r->string('ro_number', $session->contextRoNumber);

            if ($r->bool('awaiting_advisor')) {
                return Response::json(['notes' => $app->notes()->awaitingAdvisor()]);
            }
            if ($ro === null) {
                return Response::error('No repair order loaded.', 409);
            }
            return Response::json(['notes' => $app->notes()->forOrder($ro)]);
        });

        /**
         * Offline queue flush (§10). Idempotent on client_uuid, so replaying the
         * whole queue after a flaky reconnect cannot double-post a diagnosis.
         */
        $router->post('/api/v1/sync/notes', static function (Request $r) use ($app): Response {
            $session = self::session($app, $r);
            $queued = $r->input('notes');
            if (!is_array($queued)) {
                throw new HttpException('Expected a notes array.', 422);
            }

            $accepted = [];
            $failed = [];

            foreach ($queued as $item) {
                if (!is_array($item)) {
                    continue;
                }
                try {
                    $note = $app->notes()->dictate(
                        session: $session,
                        rawTranscript: (string) ($item['transcript'] ?? ''),
                        roNumber: (string) ($item['ro_number'] ?? ''),
                        orderId: isset($item['order_id']) ? (string) $item['order_id'] : null,
                        vehicleId: isset($item['vehicle_id']) ? (string) $item['vehicle_id'] : null,
                        vehicleDescription: null,
                        clientUuid: isset($item['client_uuid']) ? (string) $item['client_uuid'] : null,
                        dictatedAt: isset($item['dictated_at']) ? (string) $item['dictated_at'] : null,
                    );

                    // Approval already happened on the tablet at dictation time,
                    // so a queued note does not arrive needing a second pass.
                    if (($item['approved'] ?? false) === true) {
                        $note = $app->notes()->approveAccuracy($session, $note->id);
                    }

                    $accepted[] = ['client_uuid' => $note->clientUuid, 'id' => $note->id, 'stage' => $note->stage()];
                } catch (\Throwable $e) {
                    $failed[] = ['client_uuid' => $item['client_uuid'] ?? null, 'error' => $e->getMessage()];
                }
            }

            return Response::json(['accepted' => $accepted, 'failed' => $failed]);
        });

        // --- inspections ---------------------------------------------------
        $router->post('/api/v1/inspections/start', static function (Request $r) use ($app): Response {
            $session = self::session($app, $r);
            return Response::json($app->inspections()->start($session, $r->string('template', 'tpl_default') ?? 'tpl_default'), 201);
        });

        $router->post('/api/v1/inspections/items', static function (Request $r) use ($app): Response {
            $session = self::session($app, $r);
            $result = $app->inspections()->addItem($session, $r->requireString('transcript'), $r->string('inspection_id'));

            if ($result['item'] !== null) {
                $app->undo()->record(
                    $session,
                    ActionCatalog::ADD_INSPECTION_ITEM,
                    'inspection_item',
                    (string) $result['item']['id'],
                    (string) $result['item']['label']
                );
            }

            return Response::json($result + ['undo_seconds' => $app->undo()->remainingSeconds($session)]);
        });

        $router->post('/api/v1/inspections/end', static function (Request $r) use ($app): Response {
            $session = self::session($app, $r);
            return Response::json($app->inspections()->end($session, $r->string('inspection_id')));
        });

        // --- undo ----------------------------------------------------------
        $router->post('/api/v1/undo', static function (Request $r) use ($app): Response {
            $session = self::session($app, $r);
            return Response::json($app->undo()->undoLast($session));
        });

        // --- high-risk confirmation ----------------------------------------
        /**
         * Where a tapped high-risk action would be performed.
         *
         * It is not performed. Order writes go through Shopmonkey endpoints
         * whose shapes are unverified (§2), and inventing a PUT against a live
         * shop's repair orders is not something to discover in production. The
         * gate itself is real and tested; only the write behind it is pending.
         */
        $router->post('/api/v1/actions/confirm', static function (Request $r) use ($app): Response {
            $session = self::session($app, $r);
            $action = $r->requireString('action');

            if (($r->string('via', 'tap') ?? 'tap') === 'voice') {
                return Response::error('That change needs a tap on the tablet.', 403, 'tap_required');
            }

            $tier = ActionCatalog::tier($action);
            if ($tier === null) {
                return Response::error("I didn't catch that.", 422, 'unknown_action');
            }

            $authorization = Authorization::decide($session, $tier);
            if ($authorization->requiresIdentityUnlock) {
                return Response::error('Enter your PIN on the tablet first.', 403, 'identity_unlock_required');
            }

            $app->auth()->audit($session, $action, $tier->value, 'tap', [
                'outcome' => 'blocked_pending_api_verification',
                'params' => $r->input('params'),
            ]);

            return Response::json([
                'performed' => false,
                'reason' => 'shopmonkey_write_unverified',
                'speak' => 'I can\'t change the order yet — that part of the Shopmonkey API is not confirmed.',
                'detail' => 'Order writes stay blocked until docs/shopmonkey-api-findings.md is filled in and reviewed (spec §2).',
            ], 501);
        });
    }

    /** @return array<string,mixed> */
    private static function device(App $app, Request $request): array
    {
        $token = $request->header('x-device-token');
        if ($token === null || $token === '') {
            throw new HttpException('Missing device token.', 401);
        }
        return $app->auth()->authenticateDevice($token);
    }

    private static function session(App $app, Request $request): Session
    {
        $token = $request->bearer();
        if ($token === null) {
            throw new HttpException('Not signed in on this tablet.', 401);
        }
        return $app->auth()->sessionFromToken($token);
    }
}
