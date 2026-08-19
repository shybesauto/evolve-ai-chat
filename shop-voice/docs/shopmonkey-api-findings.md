# Shopmonkey API findings

**Status: NOT YET RUN — this is the §2 gate, and it is still closed.**

§2 of the spec says: probe the API with Russ's token, write the findings here,
and **stop for review** before building any note-writing code.

That probe has not been run. Two reasons:

1. It needs Russ's live API token, which does not belong in a repository.
2. The build environment this was written in has no outbound route to
   `api.shopmonkey.cloud` (the egress proxy refuses the CONNECT), so nothing
   could be verified from here even with a token.

So everything downstream of this file was built to work **without** the answer,
and to keep working whichever way the answer goes.

## How to run it

```bash
php tools/probe_shopmonkey.php --token=sk_live_xxx --write-findings
```

The probe is **read-only by default**. It enumerates the documented eleven
objects plus every plausible note-shaped path, reports what actually responds,
and rewrites this file with the results. It deliberately does **not** fire a
speculative `PUT` at a live order to answer question 2 — discovering an API by
corrupting a real repair order is not a method. It tells you what to try by hand
on a throwaway RO instead.

## The four questions

| # | Question | Status |
|---|---|---|
| 1 | Is there a note/comment resource? What are its fields? | unanswered |
| 2 | Does the order object accept a notes property on PUT? | unanswered |
| 3 | Can a note be attributed to a specific `userId`? | unanswered |
| 4 | Is internal vs. customer-facing a flag, or separate resources? | unanswered |

Question 3 is the one with teeth. **MCL 257.1313b** requires each mechanic's
name and state certification number on the invoice for diagnosis *and* repair.
If the API cannot attribute a note to a user, attribution has to be carried in
the note body — which is a design change, not a detail.

Question 4 has the second-worst failure mode: getting it wrong prints a
technician's raw diagnostic language on a customer's invoice.

## What ships in the meantime

The fallback from §2, in full:

- Dictated notes go to our own `dictated_notes` table, keyed by RO number, and
  are surfaced in the bay client.
- Every row already carries what a sync would need — RO number, Shopmonkey user
  id, verbatim text, and the time it was actually spoken — so a backfill later
  is a job, not a migration.
- `ShopmonkeyNoteStore` exists and knows the shape a write would take, but
  **refuses to send one** while `config/shopmonkey_endpoints.php` marks
  `note.create` as `verified => false`. It logs the refusal and returns
  `local_only`. There is no code path that writes to a guessed endpoint.
- The same lock covers order writes: `POST /api/v1/actions/confirm` runs the
  full high-risk gate and then returns `501 shopmonkey_write_unverified`.

Nothing else in the architecture changes when the answer arrives. That is what
the `NoteStore` interface is for.

## Closing this gate

Once the probe has run and a person has read the output:

- [ ] Findings reviewed by Russ
- [ ] `config/shopmonkey_endpoints.php` — paths, methods and field names corrected
- [ ] `note.create` marked `verified => true`
- [ ] `ShopmonkeyNoteStore::buildPayload()` field names matched to reality
- [ ] `NOTE_STORE=shopmonkey` set in `.env`
- [ ] `php tools/nightly_logout.php` run once to backfill queued notes
      (`retrySync()` becomes live the moment the store reports `isRemote()`)
