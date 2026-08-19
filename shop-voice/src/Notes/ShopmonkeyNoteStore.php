<?php
declare(strict_types=1);

namespace ShopVoice\Notes;

use ShopVoice\Support\HttpClient;
use ShopVoice\Support\Logger;

/**
 * Pushes internal notes to Shopmonkey — and refuses to until someone has
 * actually confirmed the endpoint.
 *
 * §2 is explicit: probe the API, write up the findings, and STOP FOR REVIEW
 * before building any note-writing code. That review has not happened. The
 * endpoint map still carries `'note.create' => verified: false`, and this build
 * environment has no route to their API to check.
 *
 * So this class is a seam with a lock on it. It knows the shape a write would
 * take, and it will not send one against a guessed endpoint: writing invented
 * payloads into a live shop's repair orders is not a thing to discover in
 * production. Flipping the lock is a two-line change once the findings are in:
 * set `verified => true` in config/shopmonkey_endpoints.php and correct the
 * field names in buildPayload() to match what the probe found.
 */
final class ShopmonkeyNoteStore implements NoteStore
{
    /** @var array<string,mixed> */
    private array $wire;

    public function __construct(
        private HttpClient $http,
        private string $token,
        private string $baseUrl,
        private Logger $logger,
        ?array $wire = null,
    ) {
        $this->wire = $wire ?? require __DIR__ . '/../../config/shopmonkey_endpoints.php';
    }

    public function write(DictatedNote $note): array
    {
        if (!$this->endpointVerified()) {
            // Loud, logged, and non-destructive: the note is already safe in our
            // own table, so the shop loses nothing while this stays locked.
            $this->logger->warn('notes.remote_write_blocked', [
                'note_id' => $note->id,
                'ro_number' => $note->roNumber,
                'reason' => 'note.create endpoint unverified — see docs/shopmonkey-api-findings.md (§2)',
            ]);
            return ['remote_id' => null, 'sync_state' => 'local_only'];
        }

        $endpoint = $this->wire['endpoints']['note.create'];
        $response = $this->http->request(
            $endpoint['method'],
            rtrim($this->baseUrl, '/') . '/' . ltrim($endpoint['path'], '/'),
            [
                'Authorization' => 'Bearer ' . $this->token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            json_encode($this->buildPayload($note), JSON_UNESCAPED_SLASHES) ?: ''
        );

        if (!$response->ok()) {
            $this->logger->error('notes.remote_write_failed', [
                'note_id' => $note->id,
                'status' => $response->status,
                'error' => $response->error,
            ]);
            return ['remote_id' => null, 'sync_state' => 'failed'];
        }

        $body = $response->json() ?? [];
        $remoteId = $body['id'] ?? ($body['data']['id'] ?? null);

        return [
            'remote_id' => is_string($remoteId) || is_int($remoteId) ? (string) $remoteId : null,
            'sync_state' => 'synced',
        ];
    }

    public function isRemote(): bool
    {
        return $this->endpointVerified();
    }

    public function describe(): string
    {
        return $this->endpointVerified()
            ? 'Shopmonkey note endpoint (verified)'
            : 'Shopmonkey note endpoint — LOCKED, awaiting §2 review. Falling back to local storage.';
    }

    /**
     * The payload a write would take, based on §2's reasoning: internal notes in
     * their UI support attachments and @-mentions of shop users, which points at
     * a note being its own record type rather than a text field on the order.
     *
     * Every key here is a hypothesis. The probe confirms or replaces them.
     *
     * @return array<string,mixed>
     */
    private function buildPayload(DictatedNote $note): array
    {
        return [
            'orderId' => $note->orderId,
            // §2 question 4: whether internal vs. customer-facing is a flag or a
            // separate resource. Only the internal note is ever pushed from here
            // — the customer version goes out through the advisor, not the bay.
            'internal' => true,
            'body' => $note->rawTranscript,
            // §2 question 3: whether a note can be attributed to a userId. If it
            // cannot, attribution has to be carried in the body text instead,
            // and MCL 257.1313b makes that a requirement, not a nicety.
            'userId' => $note->userId,
            'createdDate' => $note->dictatedAt,
        ];
    }

    private function endpointVerified(): bool
    {
        return ($this->wire['endpoints']['note.create']['verified'] ?? false) === true;
    }
}
