<?php
declare(strict_types=1);

namespace ShopVoice\Notes;

use ShopVoice\Auth\AuthException;
use ShopVoice\Auth\Session;
use ShopVoice\Db\Db;
use ShopVoice\Intent\IntentProvider;
use ShopVoice\Support\Clock;
use ShopVoice\Support\Id;
use ShopVoice\Support\Logger;

/**
 * Dictated notes, in two versions, with two-stage approval (§6).
 *
 * The rule that shapes this class: a model rewriting diagnostic language can
 * quietly change meaning — "possible" becomes "failed", a maybe becomes a
 * definitely — and that lands on an invoice a customer signs. So:
 *
 *   - the internal note is the raw transcript, stored verbatim, never rewritten;
 *   - the customer-facing version is a draft that never posts automatically;
 *   - the tech approves accuracy at the lift, while the context is in his head;
 *   - the advisor edits wording later at the desk;
 *   - that second approval is a high-risk write: screen tap, not voice.
 *
 * Today one person does both taps. The flow splits cleanly when Russ hires.
 */
final class NoteService
{
    public function __construct(
        private Db $db,
        private NoteStore $store,
        private IntentProvider $provider,
        private Logger $logger,
        private Clock $clock = new Clock(),
    ) {
    }

    /**
     * Record a dictation. Idempotent on client_uuid, because the tablet may
     * replay a queued note after a reconnect (§10) and a duplicated diagnosis
     * on an RO is its own kind of wrong.
     */
    public function dictate(
        Session $session,
        string $rawTranscript,
        string $roNumber,
        ?string $orderId,
        ?string $vehicleId,
        ?string $vehicleDescription = null,
        ?string $clientUuid = null,
        ?string $dictatedAt = null,
    ): DictatedNote {
        $transcript = trim($rawTranscript);
        if ($transcript === '') {
            throw new AuthException('Nothing to save — I did not hear a note.', 422);
        }

        $clientUuid ??= Id::generate($this->clock);

        $existing = $this->db->first(
            'SELECT * FROM dictated_notes WHERE client_uuid = :uuid',
            ['uuid' => $clientUuid]
        );
        if ($existing !== null) {
            return DictatedNote::fromRow($existing);
        }

        // The draft is generated from the transcript, and a failure here is not
        // allowed to lose the dictation — the tech's words are the thing that
        // cannot be recreated.
        $draft = '';
        try {
            $draft = $this->provider->draftCustomerNote($transcript, $vehicleDescription);
        } catch (\Throwable $e) {
            $this->logger->warn('notes.draft_failed', ['error' => $e->getMessage(), 'ro_number' => $roNumber]);
        }

        $now = $this->clock->iso();
        $id = Id::generate($this->clock);

        $this->db->insert('dictated_notes', [
            'id' => $id,
            'client_uuid' => $clientUuid,
            'ro_number' => $roNumber,
            'order_id' => $orderId,
            'vehicle_id' => $vehicleId,
            'user_id' => $session->userId,
            'device_id' => $session->deviceId,
            'raw_transcript' => $transcript,
            'customer_draft' => $draft !== '' ? $draft : null,
            'sync_state' => 'local_only',
            // When the tech spoke it, which for a queued note predates its arrival.
            'dictated_at' => $dictatedAt ?? $now,
            'created_at' => $now,
        ]);

        $this->logger->info('notes.dictated', [
            'note_id' => $id,
            'ro_number' => $roNumber,
            'user_id' => $session->userId,
            'has_draft' => $draft !== '',
            'queued' => $dictatedAt !== null && $dictatedAt !== $now,
        ]);

        return $this->require($id);
    }

    /**
     * Stage one: the tech confirms the internal note says what he meant.
     *
     * Happens immediately after dictation, on the tablet, while the car is
     * still on the lift. Offline too — approval happens at dictation time, so
     * nothing sits unapproved in the queue (§10).
     */
    public function approveAccuracy(Session $session, string $noteId): DictatedNote
    {
        $note = $this->require($noteId);

        if ($note->undoneAt !== null) {
            throw new AuthException('That note was undone.', 409);
        }
        if ($note->accuracyApprovedAt !== null) {
            return $note;
        }

        $this->db->update('dictated_notes', [
            'accuracy_approved_at' => $this->clock->iso(),
            'accuracy_approved_by' => $session->userId,
        ], ['id' => $noteId]);

        // Only now does the note go anywhere: an unapproved transcript is not a
        // note, it is an open mic.
        $result = $this->store->write($this->require($noteId));
        $this->db->update('dictated_notes', [
            'sync_state' => $result['sync_state'],
            'remote_note_id' => $result['remote_id'],
        ], ['id' => $noteId]);

        return $this->require($noteId);
    }

    /**
     * Stage two: the advisor approves the customer-facing wording.
     *
     * High-risk by construction — this is the text that reaches an invoice a
     * customer signs. The caller must have already cleared the screen-tap gate;
     * this method refuses a voice-only confirmation outright rather than
     * trusting the caller to have checked.
     */
    public function approveCustomerVersion(
        Session $session,
        string $noteId,
        ?string $editedText,
        string $via,
    ): DictatedNote {
        if ($via !== 'tap' && $via !== 'pin') {
            throw new AuthException('The customer version needs a tap on the tablet.', 403);
        }

        $note = $this->require($noteId);

        if ($note->undoneAt !== null) {
            throw new AuthException('That note was undone.', 409);
        }
        if ($note->accuracyApprovedAt === null) {
            throw new AuthException('The technician has not approved that note yet.', 409);
        }

        $text = $editedText !== null ? trim($editedText) : null;
        if (($text ?? $note->customerDraft ?? '') === '') {
            throw new AuthException('There is no customer wording to approve.', 422);
        }

        $this->db->update('dictated_notes', [
            'customer_draft_edited' => $text,
            'customer_approved_at' => $this->clock->iso(),
            'customer_approved_by' => $session->userId,
        ], ['id' => $noteId]);

        $this->logger->info('notes.customer_approved', [
            'note_id' => $noteId,
            'user_id' => $session->userId,
            'via' => $via,
            'edited' => $text !== null && $text !== $note->customerDraft,
        ]);

        return $this->require($noteId);
    }

    /** Reverse a note inside the undo window (§6). */
    public function undo(string $noteId): bool
    {
        $note = $this->require($noteId);
        if ($note->undoneAt !== null) {
            return false;
        }

        $this->db->update('dictated_notes', ['undone_at' => $this->clock->iso()], ['id' => $noteId]);
        $this->logger->info('notes.undone', ['note_id' => $noteId, 'ro_number' => $note->roNumber]);
        return true;
    }

    /** @return list<DictatedNote> */
    public function forOrder(string $roNumber, int $limit = 50): array
    {
        $rows = $this->db->all(
            'SELECT * FROM dictated_notes
             WHERE ro_number = :ro AND undone_at IS NULL
             ORDER BY dictated_at DESC LIMIT ' . max(1, $limit),
            ['ro' => $roNumber]
        );
        return array_map(static fn (array $r): DictatedNote => DictatedNote::fromRow($r), $rows);
    }

    /** Notes waiting on an advisor's wording pass — the desk-side queue. */
    public function awaitingAdvisor(int $limit = 50): array
    {
        $rows = $this->db->all(
            'SELECT * FROM dictated_notes
             WHERE accuracy_approved_at IS NOT NULL
               AND customer_approved_at IS NULL
               AND undone_at IS NULL
             ORDER BY dictated_at ASC LIMIT ' . max(1, $limit)
        );
        return array_map(static fn (array $r): DictatedNote => DictatedNote::fromRow($r), $rows);
    }

    /**
     * Retry notes that could not reach Shopmonkey. A no-op while the note
     * endpoint is locked, which is the expected state today.
     */
    public function retrySync(int $limit = 25): int
    {
        if (!$this->store->isRemote()) {
            return 0;
        }

        $rows = $this->db->all(
            "SELECT * FROM dictated_notes
             WHERE sync_state IN ('failed', 'local_only')
               AND accuracy_approved_at IS NOT NULL
               AND undone_at IS NULL
             ORDER BY dictated_at ASC LIMIT " . max(1, $limit)
        );

        $synced = 0;
        foreach ($rows as $row) {
            $note = DictatedNote::fromRow($row);
            $result = $this->store->write($note);
            $this->db->update('dictated_notes', [
                'sync_state' => $result['sync_state'],
                'remote_note_id' => $result['remote_id'],
            ], ['id' => $note->id]);
            if ($result['sync_state'] === 'synced') {
                $synced++;
            }
        }
        return $synced;
    }

    public function require(string $noteId): DictatedNote
    {
        $row = $this->db->first('SELECT * FROM dictated_notes WHERE id = :id', ['id' => $noteId]);
        if ($row === null) {
            throw new AuthException('Note not found.', 404);
        }
        return DictatedNote::fromRow($row);
    }
}
