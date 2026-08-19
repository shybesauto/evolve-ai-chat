# Architecture

Four layers, as in §3. Only the bay client is new hardware.

```
┌─────────────────────────────────────────────────────┐
│  BAY CLIENT  (Android tablet, one per bay)          │
│  openWakeWord ("hey Shybes") · Whisper (on-device)  │
│  push-to-talk · local note queue · UI               │
└────────────────────┬────────────────────────────────┘
                     │ HTTPS  (device token, then session token)
┌────────────────────▼────────────────────────────────┐
│  SERVICE LAYER  (PHP + MySQL on existing cPanel)    │
│  auth · intent validation · RO resolver             │
│  Shopmonkey client · note store · ALLDATA session   │
└──────┬───────────────────────────┬──────────────────┘
       │                           │
┌──────▼─────────┐        ┌────────▼─────────────────┐
│  INTENT MODEL  │        │  SHOPMONKEY API          │
│  hosted API    │        │  + ALLDATA (headless)    │
└────────────────┘        └──────────────────────────┘
```

## The path one sentence takes

```
transcript
  → IntentProvider::parse()      the model's only job: speech → a small object
  → IntentValidator              whitelist, params, confidence, context
  → Authorization::decide()      which confirmation this tier demands
  → VoiceService::dispatch()     the handler, running on data we fetched
  → VoiceResponse                { speak, screen, confirm, ack }
```

Two properties fall out of that ordering and are worth stating plainly:

**The model never touches Shopmonkey.** It produces a structured guess. Every
byte of shop data in the response was fetched by the service layer after the
validator approved the guess.

**Confirmation is decided by the tier, not the caller.** A handler cannot opt
out of it, and neither can a technician.

## Every seam, and which way it is thrown

All of these are decided in `src/App.php` and nowhere else.

| Seam | Interface | Ships as | Alternative |
|---|---|---|---|
| Model vendor (§3) | `IntentProvider` | `RuleBasedProvider` (`mock`) | `groq` · `openai` · `anthropic`, each wrapped in `FallbackProvider` |
| Shop data | `ShopmonkeyGateway` | `FixtureShopmonkeyGateway` | `LiveShopmonkeyGateway` (needs token + verified endpoints) |
| Note storage (§2) | `NoteStore` | `LocalNoteStore` | `ShopmonkeyNoteStore` (locked, see findings doc) |
| Diagrams (§12) | `DiagramProvider` | `UnavailableDiagramProvider` | ALLDATA headless session, build step 6 |
| Category matching | `ServiceCategoryMatcher` | model, with keywords underneath | keywords alone |

### Why the fixture gateway is not just a test double

Until §2 is answered there is no safe live mode, and a system that cannot be
demonstrated cannot be reviewed. `SHOPMONKEY_MODE=fixture` runs the whole
thing — resolver, history, notes, inspections, the bay UI — on a sample shop
with no token and no network. The sample data is shaped around the hard cases:
two open Tahoes, one silver and one white, and brake work recorded under three
different names.

### Why every hosted provider is wrapped in a fallback

§3 starts on a free tier and says plainly that free tiers are goodwill, not
contract, and that they rate-limit per minute. A 429 at 11am on a Tuesday
should cost the shop nuance, not the ability to load a repair order. So every
hosted provider is composed with the deterministic parser underneath it. The
seam holds because the fallback is itself an `IntentProvider`.

## Data model notes

Two choices worth knowing before reading the schema:

**Every id is application-generated** (time-prefixed, base32). The tablet has to
mint an id for a note dictated with no network, so the queue flush is idempotent
on `client_uuid` (§10). Server-side ids follow the same scheme for consistency.

**Every timestamp is an ISO-8601 UTC string in a `VARCHAR`.** They sort
lexicographically and behave identically on MySQL (production) and SQLite (the
test suite), so one DDL file serves both with no dialect translation.

## Security posture (§3)

- The `.env` holding the Shopmonkey token lives **outside** `public_html`.
  `public_html/index.php` is the only PHP file inside the web root.
- **The tablet never holds the Shopmonkey token.** It gets a device token that
  authenticates it to *us*; we hold every third-party credential and can revoke
  a device server-side when a tablet walks off.
- The device token is shown exactly once, at enrollment. Only its SHA-256 hash
  is stored.
- Exception messages never reach the tablet — they can carry a fragment of a
  third-party error containing a token.
- The log redacts anything token-shaped before it hits disk, because log files
  get emailed around when something breaks.
- ALLDATA session cookies will be server-side only (build step 6).
