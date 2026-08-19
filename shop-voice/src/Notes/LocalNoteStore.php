<?php
declare(strict_types=1);

namespace ShopVoice\Notes;

/**
 * The §2 fallback, and the safe default.
 *
 * Notes live in our own `dictated_notes` table keyed by RO number and are
 * surfaced in the bay client. If a Shopmonkey write endpoint turns out to
 * exist, these rows are already carrying everything a sync needs — RO number,
 * Shopmonkey user id, verbatim text and dictation time — so the backfill is a
 * job, not a migration.
 *
 * The row is written by NoteService inside its own transaction; this store
 * records the outcome, which for a local-only note is simply that it is not
 * going anywhere yet.
 */
final class LocalNoteStore implements NoteStore
{
    public function write(DictatedNote $note): array
    {
        return ['remote_id' => null, 'sync_state' => 'local_only'];
    }

    public function isRemote(): bool
    {
        return false;
    }

    public function describe(): string
    {
        return 'local dictated_notes table (Shopmonkey note endpoint unconfirmed — see §2)';
    }
}
