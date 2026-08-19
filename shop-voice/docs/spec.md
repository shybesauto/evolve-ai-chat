# Shop Voice Assistant — Build Spec

**Project:** Hands-free voice assistant for technicians at Shybes Automotive
**Owner:** Russ
**Status:** Design complete, ready for build
**Related:** ShopCore (shares the intent layer with the AI front-desk agent)

---

## 1. What this is

A wake-word voice assistant technicians talk to in the bays. It reads repair
orders, service history, and wiring diagrams out loud and on screen, and it
writes internal notes and inspection findings back into Shopmonkey — all
without the tech putting down a tool or washing his hands.

Three users at launch: Russ plus two techs. Techs currently double as service
advisors; that will split as the shop hires, so the design keeps the two roles
separate from day one.

### Non-goals for v1

- Voice-logged labor time. Money risk plus multi-tech split ambiguity — labor
  goes in by tablet tap in v1, voice in v2.
- Circuit-level diagram scoping. The assistant lands the tech on the right
  diagram; he scans it himself.
- Any local model hosting. See §3.

---

## 2. Step zero — API discovery (do this before anything else)

Shopmonkey's public docs cover eleven objects (appointment, customer,
inspection, inventory, message, order, payment, purchase order, user, vehicle,
vendor) and do **not** document whether order-level internal notes are
writable. Russ has asked Shopmonkey directly; they escalated and have not
answered.

Internal notes in their UI support attachments and @-mentions of shop users,
which strongly suggests notes are their own record type rather than a text
field on the order — so a separate, possibly undocumented, endpoint likely
exists.

**Task:** using Russ's API token, probe `https://api.shopmonkey.cloud/v3/` and
enumerate what actually responds. Undocumented endpoints frequently work.
Specifically determine:

1. Is there a note/comment resource? What are its fields?
2. Does the order object accept a notes property on PUT?
3. Can a note be attributed to a specific `userId`?
4. Is internal vs. customer-facing a flag, or separate resources?

Write findings to `docs/shopmonkey-api-findings.md` and **stop for review**
before building any note-writing code.

**Fallback if no write endpoint exists:** dictated notes go to our own
`dictated_notes` table keyed by RO number, surfaced in the bay client, with a
sync path added later if the endpoint appears. Nothing else in the
architecture changes — this is why the note store sits behind an interface.

---

## 3. Architecture

Four layers. Only the bay client is new hardware.

```
┌─────────────────────────────────────────────────────┐
│  BAY CLIENT  (Android tablet, one per bay)          │
│  openWakeWord ("hey Shybes") · Whisper (on-device)  │
│  push-to-talk · local note queue · UI               │
└────────────────────┬────────────────────────────────┘
                     │ HTTPS
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

### Runtime decision

Self-hosting a local model was evaluated and rejected. The goal was lowest
total cost with least effort; a GPU box means hardware spend, a tunnel, home
power as a dependency, and AMD driver work. A hosted API at roughly $10–30/mo
is cheaper than the hardware amortised and removes all of that.

**Start on a free tier** (Groq's free tier is ~1k–14k requests/day depending on
model; three techs will run 50–100 queries/day, nowhere near it). Free tiers
are goodwill, not contract, and rate-limit per minute — so:

> **Build the model provider as a swappable seam**, same pattern as the
> `VehicleDataProvider` in ShopCore. `IntentProvider` interface, one adapter
> per vendor, provider chosen by config. Swapping to a paid key must be a
> config change, never a rewrite.

### Security

- API keys live in a `.env` file **outside the public web root**, read by PHP
  at startup. Inside `public_html` a server misconfiguration serves the file as
  plain text and leaks the Shopmonkey token.
- **The tablet never holds the Shopmonkey token.** Tablets get stolen and left
  in bays. The device authenticates to our server; our server holds all
  third-party credentials.
- ALLDATA session cookies are server-side only.

---

## 4. Intent schema

The model's only job is turning speech into a small structured object. It never
touches Shopmonkey directly. The service layer validates every intent against a
whitelist and **rejects** anything unrecognised rather than guessing.

```json
{
  "action": "get_history",
  "target": { "type": "context" },
  "params": { "service_category": "brakes" },
  "confidence": 0.91,
  "raw_transcript": "when did we last do brakes on this one"
}
```

### Closed action list

| Action | Tier | Notes |
|---|---|---|
| `get_order` | read | Loads RO into context |
| `get_history` | read | Optional `service_category` filter |
| `get_diagram` | read | Opens ALLDATA page |
| `get_specs` | read | Fluids, torque, capacities |
| `add_note` | low-risk write | Dual version, see §6 |
| `start_inspection` | mode | See §8 |
| `add_inspection_item` | low-risk write | Inside inspection mode |
| `end_inspection` | mode | Triggers missing-items readback |
| `switch_user` | session | See §7 |
| `release_context` | session | Unloads current RO |
| `undo` | session | 30s window, low-risk writes only |
| `set_status` | **high-risk** | Requires screen tap |
| `edit_price` | **high-risk** | Requires screen tap |
| `delete_*` | **high-risk** | Requires screen tap |

Anything the model emits outside this list → rejected, spoken "I didn't catch
that", logged for prompt tuning.

### Required fields

- `confidence` — below threshold, ask rather than act.
- `raw_transcript` — **always** passed through unmodified. For notes we store
  the tech's exact words, never the model's paraphrase.

---

## 5. Lookup and context

### Resolver cascade

Ordered by uniqueness. Exact identifiers return immediately; fuzzy matches
return candidates and the assistant asks.

1. **RO number** — unique, immediate
2. **Full VIN** — unique, immediate
3. **VIN last 6 / last 8** — near-unique
4. **Plate** — near-unique
5. **Customer name** — fuzzy
6. **Vehicle description** ("the silver Tahoe") — fuzzy
7. Any other customer or vehicle detail held in Shopmonkey

On multiple matches: *"I've got two Tahoes open — Henderson or Wozniak?"*
Never guess. A wrong resolve sends a tech down the wrong wiring diagram.

### Scope defaulting

Default to **open orders** — that's ~90% of bay queries, and it collapses most
ambiguity because only so many cars are in the shop at once. Widen to closed
ROs when the transcript contains history language ("history", "past",
"previous", "last time"). No required keyword; the model infers scope.

### Service-filtered history

Service is the container object in Shopmonkey (labor, parts, tires, subcontract
and fees nest under it), so history search runs against **service names across
a vehicle's closed orders**, not raw line items.

Naming is inconsistent in real data — "brakes" may be written *front pads and
rotors*, *brake job*, or *BR-FRT*. Match **semantically** via the model
(category → candidate names), not by string equality.

Answer format: short spoken summary, full detail on screen.

> *"Front brakes, March 2025 at 88,000. And again last month at 94,000."*

Both entries render as tappable rows; tapping opens the full RO with every
service on it. Odometer comes free — Shopmonkey records it per order.

### Sticky context

The loaded RO **stays loaded** until explicitly released or a different RO is
requested. A tech is on one car for an hour; follow-ups assume the same
vehicle.

- Context clears on user switch (it follows the user, not the device).
- Context clears at nightly auto-logout.
- The loaded RO is **always visible** in the header. Non-negotiable — this is
  what stops a note landing in the wrong car.

---

## 6. Writes and confirmation

### Confirmation is a system rule, not a user choice

No command bypasses confirmation. Techs cannot opt in or forget. Two tiers:

**Low-risk** (notes, inspection findings) — spoken readback + verbal yes.
**High-risk** (status changes, deletions, price edits) — required screen tap.
A spoken "yes" is never sufficient here.

### Readback on load

When an RO loads, speak the number and vehicle: *"RO 4471, Henderson's
Tahoe."* Two seconds, and it catches the error class that's hardest to detect
later (4471 heard as 4470). **Do not repeat it** for follow-ups on the same RO
— context is sticky, so only re-announce on RO change or user switch.

### Undo window

30 seconds after any low-risk write, "undo that" reverses it. Cheap to build,
catches the "wait, wrong RO" moment.

### Dictated notes — dual version

Every dictated diagnosis produces two records:

1. **Internal note** — the tech's raw transcript, verbatim, never rewritten.
   Order-level internal notes, not service-level (service notes print on the
   invoice).
2. **Customer-facing draft** — model-generated plain-language version.

**The customer version never posts automatically.** A model rewriting
diagnostic language can quietly change meaning — "possible" becomes "failed", a
maybe becomes a definitely — and that lands on an invoice a customer signs.
That's liability.

Two-stage approval:

- **Tech approves accuracy** immediately after dictation, on the tablet, while
  the car is still on the lift and the context is in his head.
- **Advisor edits wording** later at the desk, before it reaches the customer.

Today one person does both taps. The flow splits cleanly when Russ hires. Treat
the customer-facing version as a high-risk write — screen tap, not voice.

---

## 7. Identity and sessions

Techs and advisors log in at shift start against **their existing Shopmonkey
logins**. `user` is one of the eleven API objects, so writes carry the correct
Shopmonkey user ID natively — no mapping table.

This matters legally: **MCL 257.1313b** requires each mechanic's name and state
certification number on the invoice for diagnosis *and* repair. Attribution
can't be approximate.

### Voice user switch

*"Hey, this is Russ, switch to my user."* Solves the walk-off problem by making
logout irrelevant — whoever speaks next claims the session.

Trust boundary, since a voice claim alone is spoofable:

| After voice switch | Allowed |
|---|---|
| Reads | Immediately |
| Low-risk writes | Immediately |
| First high-risk write | Requires tap or PIN on the tablet |

Plus a **nightly auto-logout** so no session runs silently into the next day.

---

## 8. Inspection mode

A tech doing an inspection isn't looking at every item at once — he walks the
car. Requiring the wake word between findings would kill it.

**"Start inspection"** opens a persistent listening session. Inside it the
timeout logic inverts: silence ends the *current item*, not the session.

- Tech calls out a finding → short **confirmation chirp**, not a spoken
  readback. Thirty spoken readbacks would drive him mad.
- Spoken response only when the parse is uncertain.
- Session closes on "end inspection" or ~5 minutes idle.

### Free order, not scripted

Russ's inspection route **varies by vehicle**. A system reading items in list
order would fight him on every car. So:

- Findings are called in **any order** — *"front pads four millimetres,
  yellow"* — and the model slots them into the correct sheet field regardless
  of sequence.
- The tablet shows the sheet with items ticking off as they land, so he can
  glance rather than listen.
- At `end_inspection`, read back **only what's missing**: *"I don't have rear
  brakes or the battery test."* Short, and it's the one moment prompting is
  actually useful.

Yellow inspection items and declined services both become deferred work.

---

## 9. Listening behaviour

| Mode | Silence timeout | Ends on |
|---|---|---|
| Command | ~1.5s | Silence |
| Dictation | 3–4s (90s hard cap) | End phrase **or** silence |
| Inspection item | ~2s | Silence (session stays open) |

Dictation uses **both** an explicit end phrase ("end note", "that's it") and a
silence timeout. The end phrase keeps the tech in control so nothing closes
mid-thought; the timeout is the safety net. Dictation ends → readback →
approve, so if shop conversation leaked in, he hears it before it saves.

### Wake word

"Hey Shybes" via openWakeWord. Bays are loud; false triggers are what make
people abandon these.

- **Push-to-talk on the tablet** as a first-class alternative — likely the
  primary input in a loud bay, with the wake word for when he's under a car.
- Because PTT always exists, tune the wake word **aggressively toward fewer
  false positives**. A missed wake is annoying; a false trigger that starts
  recording a conversation is worse.
- **Per-device toggle** to disable the wake word entirely — one obvious switch,
  not buried in settings. Default on. A bay next to a compressor may be
  hopeless.

---

## 10. Offline behaviour

Whisper runs on-device, so **transcription works without a network**.

- **Dictated notes queue locally** with RO number, user ID and timestamp, then
  post on reconnect. Dictation is the one thing you can't ask a tech to repeat.
- **Approval happens at dictation time**, offline, so nothing sits unapproved
  in the queue.
- **Visible pending count** on screen so nobody assumes it went through.
- **Lookups can't work offline** — say so plainly. Never fail silently.

---

## 11. Bay UI

Read at arm's length, greasy fingers, bad lighting. Big, scannable, high
contrast. Clean and simple but genuinely detailed.

Hierarchy, top to bottom:

1. **Persistent header** — current user + loaded RO + vehicle. Always visible.
2. **Customer concern** — the most prominent element on the screen. It's why
   the car is there and what a tech re-reads three times during a diagnosis.
3. **Deferred work** — a tappable **count badge**, one line. Visible but not
   competing with the concern.
4. **Services** on the RO.
5. **Action row** — Diagrams / History / Notes, reachable **without
   scrolling**.
6. **Voice bar** — wake state, live transcript, confirmation prompts.

See `bay-ui-mockup.html` for the clickable layout.

---

## 12. Diagrams

Shopmonkey's built-in diagrams and procedures are **not** exposed on the public
API — that content lives in their app UI. Fall back to the shop's existing
ALLDATA and Identifix subscriptions via a server-side headless browser session,
deep-linking the tech to the right page.

The tech gets ALLDATA's own viewer, which keeps zoom, pan and full page context
— it matters when a circuit runs across three diagrams. Don't parse or re-render
their pages; that breaks on every redesign.

This is the most brittle piece in the system, which is exactly why it's built
last.

---

## 13. Build order

Each step ships something testable.

**0. API discovery** — §2. Stop for review.

**1. Shopmonkey service layer** (PHP/MySQL on cPanel)
Endpoints that fetch an order by number, plate, VIN or customer name and return
clean JSON. Plus service-filtered history. Test in a browser. No voice, no AI.
Prove RO retrieval is reliable.

**2. UI mockup review**
Russ reacts to `bay-ui-mockup.html` at tablet size before backend UI work.
Cheap to change now.

**3. Intent layer**
One endpoint: text in, validated structured intent out, behind the
`IntentProvider` seam. Type "pull up the RO for the silver Tahoe" and watch it
resolve. Wire to step 1.

**4. Bay client**
Android tablet: wake word, Whisper, PTT, offline queue, the UI. This is where
it becomes real — and where you find out whether the mic survives an impact
wrench.

**5. Notes + inspection flows**
Dual-version notes, confirmation tiers, undo, inspection mode.

**6. Diagrams**
ALLDATA headless session. Hardest and most brittle — last on purpose.

**Then let the techs beat on it for a month before adding anything.**

---

## 14. Open questions

- Shopmonkey internal-note write endpoint — blocked on §2 / their escalation.
- Whether tablet mics hold up in bay noise; may need external mics.
- Whether Identifix adds anything ALLDATA doesn't, for diagram routing.
