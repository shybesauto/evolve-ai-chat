<?php
declare(strict_types=1);

namespace ShopVoice\Voice;

use ShopVoice\Auth\Authorization;
use ShopVoice\Auth\AuthService;
use ShopVoice\Auth\Session;
use ShopVoice\Db\Db;
use ShopVoice\Diagrams\DiagramProvider;
use ShopVoice\History\ServiceHistoryService;
use ShopVoice\Inspection\InspectionService;
use ShopVoice\Intent\ActionCatalog;
use ShopVoice\Intent\Intent;
use ShopVoice\Intent\IntentContext;
use ShopVoice\Intent\IntentProvider;
use ShopVoice\Intent\IntentValidator;
use ShopVoice\Intent\RiskTier;
use ShopVoice\Intent\ValidationResult;
use ShopVoice\Notes\NoteService;
use ShopVoice\Resolver\RoResolver;
use ShopVoice\Resolver\Scope;
use ShopVoice\Shopmonkey\ShopmonkeyGateway;
use ShopVoice\Support\Clock;
use ShopVoice\Support\Id;
use ShopVoice\Support\Logger;
use ShopVoice\Undo\UndoService;

/**
 * The one path an utterance takes.
 *
 * transcript -> model -> validator -> authorization -> handler -> response
 *
 * The model never touches Shopmonkey (§4). It produces a structured guess;
 * everything after the validator runs on data this layer fetched itself. Any
 * action that is not on the closed list stops here, and every write passes the
 * confirmation rules in §6 whether the tech remembers them or not.
 */
final class VoiceService
{
    public function __construct(
        private Db $db,
        private AuthService $auth,
        private IntentProvider $provider,
        private IntentValidator $validator,
        private ShopmonkeyGateway $gateway,
        private RoResolver $resolver,
        private ServiceHistoryService $history,
        private NoteService $notes,
        private InspectionService $inspections,
        private UndoService $undo,
        private DiagramProvider $diagrams,
        private Logger $logger,
        private Clock $clock = new Clock(),
    ) {
    }

    /** Entry point for everything the tablet hears. */
    public function handle(Session $session, string $transcript): VoiceResponse
    {
        $context = $this->buildContext($session);
        $started = microtime(true);

        $intent = $this->provider->parse($transcript, $context);
        $validation = $this->validator->validate($intent, $context);

        $this->logIntent($session, $intent, $validation, (int) round((microtime(true) - $started) * 1000));

        if (!$validation->isAccepted()) {
            // Rejections speak the flat "I didn't catch that"; low confidence
            // asks a question instead of acting (§4).
            return (new VoiceResponse(
                speak: $validation->spoken,
                view: $validation->status === ValidationResult::CLARIFY ? 'listening' : null,
            ))->withSession($session, $this->undo->remainingSeconds($session))
              ->withIntent($intent->jsonSerialize());
        }

        $accepted = $validation->intent;
        $tier = ActionCatalog::tier($accepted->action) ?? RiskTier::Read;
        $authorization = Authorization::decide($session, $tier);

        if (!$authorization->allowed) {
            return (new VoiceResponse($authorization->spokenPrompt() ?? 'Not allowed.'))
                ->withSession($session);
        }

        // High-risk stops at the gate. A spoken "yes" is never sufficient (§6),
        // so nothing is performed here — the tablet must send it back as a tap.
        if ($authorization->requiresScreenTap) {
            return $this->highRiskGate($session, $accepted, $authorization);
        }

        return $this->dispatch($session, $accepted, $context)
            ->withIntent($accepted->jsonSerialize());
    }

    private function dispatch(Session $session, Intent $intent, IntentContext $context): VoiceResponse
    {
        return match ($intent->action) {
            ActionCatalog::GET_ORDER => $this->handleGetOrder($session, $intent),
            ActionCatalog::GET_HISTORY => $this->handleGetHistory($session, $intent),
            ActionCatalog::GET_DIAGRAM => $this->handleGetDiagram($session, $intent),
            ActionCatalog::GET_SPECS => $this->handleGetSpecs($session, $intent),
            ActionCatalog::ADD_NOTE => $this->handleAddNote($session, $intent),
            ActionCatalog::START_INSPECTION => $this->handleStartInspection($session, $intent),
            ActionCatalog::ADD_INSPECTION_ITEM => $this->handleInspectionItem($session, $intent),
            ActionCatalog::END_INSPECTION => $this->handleEndInspection($session),
            ActionCatalog::SWITCH_USER => $this->handleSwitchUser($session, $intent),
            ActionCatalog::RELEASE_CONTEXT => $this->handleReleaseContext($session),
            ActionCatalog::UNDO => $this->handleUndo($session),
            default => (new VoiceResponse("I didn't catch that."))->withSession($session),
        };
    }

    // --- reads ------------------------------------------------------------

    private function handleGetOrder(Session $session, Intent $intent): VoiceResponse
    {
        $spokenTarget = $intent->target->value ?? $intent->rawTranscript;
        $widen = Scope::wantsHistory($intent->rawTranscript);

        $result = $this->resolver->resolve($spokenTarget, !$widen, $intent->target->type);

        if ($result->outcome === 'not_found') {
            return (new VoiceResponse(
                speak: $result->question ?? "I couldn't find that.",
                view: 'search',
            ))->withSession($session);
        }

        if ($result->outcome === 'ambiguous') {
            // Never guess: a wrong resolve sends a tech down the wrong wiring
            // diagram. The candidates go on screen as tappable rows.
            return (new VoiceResponse(
                speak: (string) $result->question,
                screen: ['candidates' => $result->jsonSerialize()['candidates']],
                confirm: ['type' => 'choice', 'options' => $result->jsonSerialize()['candidates']],
                view: 'disambiguate',
            ))->withSession($session);
        }

        $order = $result->order;
        $session = $this->auth->loadContext($session, $order);
        $this->auth->audit($session, ActionCatalog::GET_ORDER, 'read', 'voice', ['matched_by' => $result->matchedBy], 'order', $order->id);

        // Two seconds of readback catches the error class that is hardest to
        // detect later — 4471 heard as 4470 (§6).
        return (new VoiceResponse(
            speak: $order->readbackLabel() . '.',
            screen: ['order' => $order->jsonSerialize(), 'notes' => $this->notes->forOrder($order->number)],
            view: 'order',
        ))->withSession($session, $this->undo->remainingSeconds($session));
    }

    private function handleGetHistory(Session $session, Intent $intent): VoiceResponse
    {
        [$session, $order] = $this->resolveContextOrder($session, $intent);
        if ($order === null) {
            return (new VoiceResponse('Which vehicle?', view: 'search'))->withSession($session);
        }

        $category = $intent->stringParam('service_category');
        $history = $this->history->forVehicle($order->vehicle->id, $category);

        $this->auth->audit($session, ActionCatalog::GET_HISTORY, 'read', 'voice', ['category' => $category], 'vehicle', $order->vehicle->id);

        return (new VoiceResponse(
            speak: $history['spoken'],
            screen: ['history' => $history, 'order' => $order->jsonSerialize()],
            view: 'history',
        ))->withSession($session);
    }

    private function handleGetDiagram(Session $session, Intent $intent): VoiceResponse
    {
        [$session, $order] = $this->resolveContextOrder($session, $intent);
        if ($order === null) {
            return (new VoiceResponse('Which vehicle?', view: 'search'))->withSession($session);
        }

        $vehicle = $order->vehicle;
        $located = $this->diagrams->locate(
            $vehicle->year,
            $vehicle->make,
            $vehicle->model,
            $vehicle->engine,
            $intent->stringParam('system', 'unspecified') ?? 'unspecified',
            $intent->stringParam('component'),
        );

        return (new VoiceResponse(
            speak: $located['spoken'],
            screen: ['diagram' => $located],
            view: 'diagram',
        ))->withSession($session);
    }

    private function handleGetSpecs(Session $session, Intent $intent): VoiceResponse
    {
        // Specs come from the same ALLDATA session as diagrams, so they arrive
        // together in build step 6. Saying so plainly beats a spinner (§10).
        return (new VoiceResponse(
            speak: 'Specs are not wired up yet — they come with the ALLDATA link.',
            screen: ['spec_type' => $intent->stringParam('spec_type')],
            view: 'order',
        ))->withSession($session);
    }

    // --- low-risk writes --------------------------------------------------

    private function handleAddNote(Session $session, Intent $intent): VoiceResponse
    {
        if (!$session->hasContext()) {
            return (new VoiceResponse('I don\'t have a repair order loaded. Which one?', view: 'search'))
                ->withSession($session);
        }

        $body = $intent->stringParam('body', $intent->rawTranscript) ?? $intent->rawTranscript;

        $note = $this->notes->dictate(
            session: $session,
            // Verbatim. What the tech said is what gets stored; the model's
            // paraphrase only ever becomes the separate customer draft (§4, §6).
            rawTranscript: $body,
            roNumber: (string) $session->contextRoNumber,
            orderId: $session->contextOrderId,
            vehicleId: $session->contextVehicleId,
            vehicleDescription: $session->contextLabel,
        );

        $this->undo->record($session, ActionCatalog::ADD_NOTE, 'note', $note->id, 'the note on RO ' . $note->roNumber);
        $this->auth->audit($session, ActionCatalog::ADD_NOTE, 'low_risk', 'voice', [], 'note', $note->id);

        // Dictation ends -> readback -> approve. If shop conversation leaked in,
        // he hears it before it saves (§9).
        return (new VoiceResponse(
            speak: sprintf('On RO %s: %s. Is that right?', $note->roNumber, $note->rawTranscript),
            screen: ['note' => $note->jsonSerialize()],
            confirm: [
                'type' => 'voice',
                'action' => 'approve_note',
                'note_id' => $note->id,
                'prompt' => 'Is that right?',
            ],
            view: 'note_review',
        ))->withSession($session, $this->undo->remainingSeconds($session));
    }

    private function handleStartInspection(Session $session, Intent $intent): VoiceResponse
    {
        $started = $this->inspections->start($session, $intent->stringParam('template', 'tpl_default') ?? 'tpl_default');
        $this->auth->audit($session, ActionCatalog::START_INSPECTION, 'mode', 'voice', [], 'inspection', $started['inspection_id']);

        return (new VoiceResponse(
            speak: $started['spoken'],
            screen: ['inspection' => $started],
            view: 'inspection',
        ))->withSession($session);
    }

    private function handleInspectionItem(Session $session, Intent $intent): VoiceResponse
    {
        // The raw transcript, not the model's extracted value: the slotting
        // pass wants the whole sentence, and the item is stored verbatim.
        $result = $this->inspections->addItem($session, $intent->rawTranscript);

        if ($result['item'] === null) {
            return (new VoiceResponse(
                speak: (string) $result['spoken'],
                ack: InspectionService::ACK_SPEAK,
                view: 'inspection',
            ))->withSession($session);
        }

        $this->undo->record(
            $session,
            ActionCatalog::ADD_INSPECTION_ITEM,
            'inspection_item',
            (string) $result['item']['id'],
            (string) $result['item']['label']
        );

        // A chirp, not a spoken readback. Thirty spoken readbacks would drive
        // him mad (§8).
        return (new VoiceResponse(
            speak: '',
            screen: ['item' => $result['item'], 'items' => $this->currentInspectionItems($session)],
            ack: InspectionService::ACK_CHIRP,
            view: 'inspection',
        ))->withSession($session, $this->undo->remainingSeconds($session));
    }

    private function handleEndInspection(Session $session): VoiceResponse
    {
        $result = $this->inspections->end($session);
        $this->auth->audit($session, ActionCatalog::END_INSPECTION, 'mode', 'voice', ['missing' => count($result['missing'])]);

        return (new VoiceResponse(
            speak: $result['spoken'],
            screen: ['inspection_summary' => $result],
            view: 'inspection_summary',
        ))->withSession($session);
    }

    // --- session ----------------------------------------------------------

    private function handleSwitchUser(Session $session, Intent $intent): VoiceResponse
    {
        $name = $intent->stringParam('user_name', $intent->target->value);
        if ($name === null || trim($name) === '') {
            return (new VoiceResponse('Who am I switching to?'))->withSession($session);
        }

        $switched = $this->auth->voiceSwitch($session, $name);
        $new = $switched['session'];

        return (new VoiceResponse(
            speak: sprintf('%s has the tablet. Nothing loaded.', $new->userName),
            screen: ['session_token' => $switched['token']],
            view: 'idle',
        ))->withSession($new);
    }

    private function handleReleaseContext(Session $session): VoiceResponse
    {
        $session = $this->auth->releaseContext($session);
        return (new VoiceResponse('Cleared.', view: 'idle'))->withSession($session);
    }

    private function handleUndo(Session $session): VoiceResponse
    {
        $result = $this->undo->undoLast($session);
        return (new VoiceResponse(
            speak: $result['spoken'],
            screen: ['undone' => $result['undone']],
        ))->withSession($session, $this->undo->remainingSeconds($session));
    }

    // --- high risk --------------------------------------------------------

    private function highRiskGate(Session $session, Intent $intent, Authorization $authorization): VoiceResponse
    {
        $this->auth->audit($session, $intent->action, 'high_risk', 'voice', [
            'outcome' => 'gated',
            'requires_identity_unlock' => $authorization->requiresIdentityUnlock,
        ]);

        return (new VoiceResponse(
            speak: (string) $authorization->spokenPrompt(),
            screen: [
                'pending' => [
                    'action' => $intent->action,
                    'params' => (object) $intent->params,
                    'ro_number' => $session->contextRoNumber,
                    'raw_transcript' => $intent->rawTranscript,
                ],
            ],
            confirm: [
                'type' => 'tap',
                'action' => $intent->action,
                'params' => (object) $intent->params,
                'requires_identity_unlock' => $authorization->requiresIdentityUnlock,
                'prompt' => $this->highRiskPrompt($intent, $session),
            ],
            view: 'confirm_tap',
        ))->withSession($session)->withIntent($intent->jsonSerialize());
    }

    private function highRiskPrompt(Intent $intent, Session $session): string
    {
        return match ($intent->action) {
            ActionCatalog::SET_STATUS => sprintf(
                'Set RO %s to "%s"?',
                $session->contextRoNumber ?? '?',
                $intent->stringParam('status', '?')
            ),
            ActionCatalog::EDIT_PRICE => sprintf('Change the price to %s?', $intent->stringParam('amount', '?')),
            default => ActionCatalog::isDelete($intent->action)
                ? 'Delete this? This cannot be undone by voice.'
                : 'Confirm this change?',
        };
    }

    // --- helpers ----------------------------------------------------------

    /**
     * Resolve what the utterance is about, preferring sticky context.
     *
     * @return array{0:Session,1:?\ShopVoice\Shopmonkey\Order}
     */
    private function resolveContextOrder(Session $session, Intent $intent): array
    {
        if ($intent->target->needsResolution()) {
            $result = $this->resolver->resolve(
                (string) $intent->target->value,
                !Scope::wantsHistory($intent->rawTranscript),
                $intent->target->type
            );
            if ($result->isResolved()) {
                $session = $this->auth->loadContext($session, $result->order);
                return [$session, $result->order];
            }
            return [$session, null];
        }

        if (!$session->hasContext()) {
            return [$session, null];
        }

        $order = $this->gateway->getOrder((string) $session->contextOrderId);
        return [$session, $order];
    }

    public function buildContext(Session $session): IntentContext
    {
        $open = $this->inspections->openFor($session);
        $missing = [];

        if ($open !== null) {
            $recorded = array_column($this->inspections->items((string) $open['id']), 'field_key');
            foreach ($this->inspections->fields((string) $open['template_id']) as $field) {
                if (!in_array($field['field_key'], $recorded, true)) {
                    $missing[] = (string) $field['label'];
                }
            }
        }

        return new IntentContext(
            roNumber: $session->contextRoNumber,
            vehicleDescription: $session->contextLabel,
            userName: $session->userName,
            inspectionOpen: $open !== null,
            inspectionFields: $missing,
        );
    }

    /** @return list<array<string,mixed>> */
    private function currentInspectionItems(Session $session): array
    {
        $open = $this->inspections->openFor($session);
        return $open === null ? [] : $this->inspections->items((string) $open['id']);
    }

    private function logIntent(Session $session, Intent $intent, ValidationResult $validation, int $latencyMs): void
    {
        $this->db->insert('intent_log', [
            'id' => Id::generate($this->clock),
            'session_id' => $session->id,
            'user_id' => $session->userId,
            'device_id' => $session->deviceId,
            'provider' => $intent->provider ?? $this->provider->name(),
            'model' => $intent->model ?? $this->provider->model(),
            // Kept verbatim: this log is the only feedback loop the prompt has,
            // and a cleaned-up transcript would hide exactly the misfires worth
            // tuning for (§4).
            'raw_transcript' => $intent->rawTranscript,
            'action' => $intent->action !== '' ? $intent->action : null,
            'confidence' => (string) round($intent->confidence, 2),
            'accepted' => $validation->isAccepted() ? 1 : 0,
            'reject_reason' => $validation->reason,
            'latency_ms' => $latencyMs,
            'created_at' => $this->clock->iso(),
        ]);
    }
}
