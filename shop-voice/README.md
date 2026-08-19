# Shop Voice Assistant

Hands-free voice assistant for the technicians at Shybes Automotive. Built from
`docs/spec.md` — section references throughout the code point back at it.

It reads repair orders, service history and (eventually) wiring diagrams out
loud and on screen, and writes internal notes and inspection findings back —
without the tech putting down a tool or washing his hands.

```bash
cp .env.example .env
php tools/migrate.php
php tests/run.php

php -S 127.0.0.1:8099 -t public_html tools/dev_server.php   # then /bay/
```

No API keys, no MySQL and no network are needed for any of that. The default
posture runs on a bundled sample shop with a deterministic intent parser.

Try it without a microphone:

```bash
php tools/say.php "pull up the RO for the silver Tahoe" \
                  "when did we last do brakes on this one" \
                  "make a note left front caliper is possibly sticking" \
                  "undo that" \
                  "mark it ready"
```

```
» pull up the RO for the silver Tahoe
  speaks   "RO 4471, Henderson's Tahoe."
» when did we last do brakes on this one
  speaks   "Brakes, March at 88,240. And again last month at 94,010."
» make a note left front caliper is possibly sticking
  speaks   "On RO 4471: left front caliper is possibly sticking. Is that right?"
  confirm  voice          undo 30s remaining
» undo that
  speaks   "Undone — the note on RO 4471."
» mark it ready
  speaks   "Tap to confirm on the tablet."
  confirm  tap — Set RO 4471 to "ready"?
```

## Where the build order stands (§13)

| Step | State |
|---|---|
| **0. API discovery** | **Blocked, by design.** Probe written; needs Russ's token. See below. |
| **1. Shopmonkey service layer** | Done. Resolver cascade, service-filtered history, clean JSON, testable in a browser. |
| **2. UI mockup review** | Done — `bay-ui-mockup.html`. **Waiting on Russ.** |
| **3. Intent layer** | Done. Text in, validated intent out, behind the `IntentProvider` seam. |
| **4. Bay client** | Web client done and working. The Android shell — wake word and on-device Whisper — is not. See `docs/bay-client.md`. |
| **5. Notes + inspection** | Done. Dual-version notes, both confirmation tiers, undo, inspection mode. |
| **6. Diagrams** | Not started, deliberately last (§12). Answers honestly until then. |

## Step 0 is still open, and that is the point

§2 says probe the Shopmonkey API, write up the findings, and **stop for review**
before building any note-writing code. That probe needs a live token, and this
build had no route to their API to run it.

So it was built to not need the answer:

- **Notes go to our own `dictated_notes` table** — the fallback §2 specifies,
  keyed by RO number and surfaced in the bay client. Every row already carries
  what a sync would need.
- **`ShopmonkeyNoteStore` exists and refuses to run.** It knows the shape a
  write would take and will not send one while `config/shopmonkey_endpoints.php`
  marks `note.create` unverified. No code path writes to a guessed endpoint.
- **Order writes are locked the same way.** The high-risk gate is real and
  tested; `POST /api/v1/actions/confirm` runs it and then returns
  `501 shopmonkey_write_unverified`.

Run the probe when you have the token:

```bash
php tools/probe_shopmonkey.php --token=sk_live_xxx --write-findings
```

It is read-only by default — it will not fire a speculative `PUT` at a live
repair order to see what happens. Then read `docs/shopmonkey-api-findings.md`,
correct the endpoint map, and flip two `.env` values.

## The rules that shaped the code

The spec's non-negotiables, and where each one lives:

| Rule | Where |
|---|---|
| Never guess a vehicle — ambiguity becomes a question | `Resolver/RoResolver.php` |
| Anything outside the closed action list is rejected, not interpreted | `Intent/ActionCatalog.php`, `Intent/IntentValidator.php` |
| Below the confidence threshold, ask rather than act | `Intent/IntentValidator.php` |
| `raw_transcript` is never rewritten — a note is the tech's exact words | `Intent/Intent.php`, `Notes/NoteService.php` |
| Confirmation is a system rule; high-risk needs a tap, never a spoken yes | `Auth/Authorization.php`, `Intent/RiskTier.php` |
| The customer-facing version never posts automatically | `Notes/NoteService.php` |
| A voice claim buys reads and low-risk writes, nothing more | `Auth/AuthService.php` |
| The tablet never holds the Shopmonkey token | `Auth/AuthService.php`, `App.php` |
| Findings land in any order; only what is missing is read back | `Inspection/InspectionService.php` |
| A chirp, not a readback, thirty times an inspection | `Inspection/InspectionService.php`, `bay/app.js` |
| Offline: dictation queues, lookups say so plainly | `bay/app.js`, `Http/Routes.php` |
| Swapping model vendors is config, never a rewrite | `Intent/IntentProviderFactory.php` |

## Layout

```
docs/            spec, architecture, operations, the §2 findings gate
config/          endpoint map (the only file encoding Shopmonkey assumptions) + fixture shop
src/             the service layer — OUTSIDE the web root in production
public_html/     front controller + bay client — the only things served
tools/           probe, migrate, nightly cron, CLI driver, dev server
tests/           132 checks; no keys, no database server, no network
bay-ui-mockup.html   §11 layout for review at tablet size
```

`docs/architecture.md` is the map. `docs/operations.md` is the deploy.
