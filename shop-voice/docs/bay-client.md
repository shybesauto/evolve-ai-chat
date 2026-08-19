# The bay client

## What is built

`public_html/bay/` is the tablet UI, working today: enrollment, sign-in, the
§11 screen hierarchy, push-to-talk, the confirmation tiers, inspection mode, the
offline note queue with a visible pending count, and the service worker that
keeps the shell alive without a network.

It is the real client, not a mock. What it does **not** contain is the two
pieces that cannot live in a web page:

- **openWakeWord** — "hey Shybes" (§9)
- **on-device Whisper** — which is what makes transcription work offline (§10)

Those belong to the Android shell, which is build step 4. Everything else is
done and testable now, on a laptop, with a keyboard.

## Speech input: three sources, one path

`app.js` takes a transcript from whichever is available, in this order:

1. **`window.ShopVoiceNative`** — the Android shell. Owns the wake word and
   Whisper, pushes finished transcripts in.
2. **`webkitSpeechRecognition`** — enough to demo and test in a browser, but it
   needs the network, so it is never the offline path.
3. **Typing** — always available, and how the whole flow gets tested with no
   microphone at all.

Past the point a transcript exists, all three are identical.

## The native bridge

The Android shell wraps the client in a WebView and implements
`window.ShopVoiceNative`:

```js
window.ShopVoiceNative = {
  startListening(),                  // PTT pressed, or wake word fired
  stopListening(),
  setWakeWordEnabled(bool),          // mirrors the on-screen toggle (§9)
  speak(text),                       // TTS
  chirp(),                           // the inspection confirmation tone (§8)
};
```

And calls into the page:

```js
window.ShopVoice.onWakeWord();               // wake word detected
window.ShopVoice.onPartialTranscript(text);  // live transcript in the voice bar
window.ShopVoice.onTranscript(text);         // Whisper finished — this is the one that matters
window.ShopVoice.onWakeWordAvailable(bool);  // hide the toggle if the model failed to load
```

Both halves already exist on the web side. The shell has to supply four methods
and call one function.

## Tuning notes for step 4

From §9, and worth not relearning the hard way:

- **Tune the wake word aggressively toward fewer false positives.** Push-to-talk
  always exists, so a missed wake is annoying; a false trigger that starts
  recording a shop conversation is worse.
- **The per-device wake word toggle is one obvious switch**, not buried in
  settings. It is already wired: `POST /api/v1/devices/wake-word`, persisted per
  device, returned at login. A bay next to a compressor may be hopeless, and
  that bay should be able to give up on the wake word in one tap.
- **Timeouts** (§9), which the shell owns because they are about audio:

  | Mode | Silence timeout | Ends on |
  |---|---|---|
  | Command | ~1.5s | silence |
  | Dictation | 3–4s (90s hard cap) | end phrase **or** silence |
  | Inspection item | ~2s | silence — the session stays open |

  Dictation needs **both** an end phrase ("end note", "that's it") and the
  silence timeout. The end phrase keeps the tech in control so nothing closes
  mid-thought; the timeout is the safety net.

- **Find out whether the tablet mic survives an impact wrench** before ordering
  three of them. This is listed as an open question in §14 for a reason, and it
  is cheap to answer early with one tablet and a loud bay.

## Offline behaviour, as implemented (§10)

- A dictated note is queued in `localStorage` with its RO number, the user, and
  the time it was **actually spoken** — not the time it arrived.
- **Approval happens at dictation time**, offline, on the tablet. Nothing sits
  in the queue unapproved.
- The pending count is on screen in the amber strip whenever the queue is not
  empty. Nobody has to assume a note went through.
- Lookups say so plainly: *"No connection — I can't look anything up. Notes
  still work."* They never fail silently.
- The flush is idempotent on `client_uuid`, so replaying the queue after a flaky
  reconnect cannot double-post a diagnosis.
