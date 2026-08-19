<?php
declare(strict_types=1);

namespace ShopVoice\Notes;

/**
 * Where an approved internal note ends up.
 *
 * This interface exists because of §2: Shopmonkey's public docs do not document
 * whether order-level internal notes are writable, Russ's question to them is
 * unanswered, and the fallback is our own table. Putting the store behind an
 * interface means answering that question later changes one line of config —
 * nothing else in the architecture moves.
 */
interface NoteStore
{
    /**
     * Persist the internal note for an order.
     *
     * @return array{remote_id:?string,sync_state:string} sync_state is one of
     *         local_only | pending | synced | failed
     */
    public function write(DictatedNote $note): array;

    /** Is this store able to push to Shopmonkey right now? */
    public function isRemote(): bool;

    public function describe(): string;
}
