/*
  Bay client.

  Speech input has three sources, tried in order:

    1. window.ShopVoiceNative — the Android shell (§3, §9). It owns openWakeWord
       and on-device Whisper, and pushes finished transcripts in here. That is
       what makes transcription work with no network (§10); a browser cannot do
       it, which is why the wake word and offline dictation are the native
       layer's job and not this file's.
    2. webkitSpeechRecognition — good enough to demo and test on a laptop, but
       it needs the network, so it is never the offline path.
    3. typing — always available, and how the whole flow gets tested without a
       microphone at all.

  Push-to-talk is first-class in all three (§9): in a loud bay it is likely the
  primary input, with the wake word for when he is under a car.
*/
(() => {
  'use strict';

  const API = '/api/v1';
  const $ = (id) => document.getElementById(id);

  const store = {
    get: (k) => { try { return JSON.parse(localStorage.getItem('shopvoice.' + k)); } catch { return null; } },
    set: (k, v) => localStorage.setItem('shopvoice.' + k, JSON.stringify(v)),
    del: (k) => localStorage.removeItem('shopvoice.' + k),
  };

  const state = {
    deviceToken: store.get('deviceToken'),
    sessionToken: store.get('sessionToken'),
    session: null,
    order: null,
    notes: [],
    inspection: null,
    pendingConfirm: null,
    online: navigator.onLine,
    listening: false,
  };

  // --- transport ---------------------------------------------------------

  async function api(path, { method = 'GET', body = null, device = false } = {}) {
    const headers = { 'Content-Type': 'application/json' };
    if (device && state.deviceToken) headers['X-Device-Token'] = state.deviceToken;
    if (state.sessionToken) headers['Authorization'] = 'Bearer ' + state.sessionToken;

    let response;
    try {
      response = await fetch(API + path, {
        method,
        headers,
        body: body === null ? null : JSON.stringify(body),
      });
    } catch (e) {
      // Lookups cannot work offline. Say so plainly; never fail silently (§10).
      setOnline(false);
      throw new OfflineError();
    }

    setOnline(true);
    const payload = await response.json().catch(() => ({}));

    if (!response.ok) {
      const error = new Error(payload?.error?.message || 'Something went wrong.');
      error.status = response.status;
      error.code = payload?.error?.code;
      throw error;
    }
    return payload;
  }

  class OfflineError extends Error {
    constructor() {
      super('No connection — lookups need the network.');
      this.offline = true;
    }
  }

  function setOnline(online) {
    if (state.online === online) return;
    state.online = online;
    $('hdrDot').classList.toggle('offline', !online);
    renderStrip();
    if (online) flushQueue();
  }

  window.addEventListener('online', () => setOnline(true));
  window.addEventListener('offline', () => setOnline(false));

  // --- offline note queue (§10) -----------------------------------------

  const queue = {
    all: () => store.get('queue') || [],
    add(note) {
      const items = queue.all();
      items.push(note);
      store.set('queue', items);
      renderStrip();
    },
    remove(clientUuid) {
      store.set('queue', queue.all().filter((n) => n.client_uuid !== clientUuid));
      renderStrip();
    },
    count: () => queue.all().length,
  };

  async function flushQueue() {
    const items = queue.all();
    if (items.length === 0 || !state.sessionToken) return;

    try {
      const result = await api('/sync/notes', { method: 'POST', body: { notes: items } });
      (result.accepted || []).forEach((a) => queue.remove(a.client_uuid));
      if (result.accepted?.length) {
        toast(`${result.accepted.length} queued note${result.accepted.length === 1 ? '' : 's'} sent.`);
      }
    } catch (e) {
      // Stay queued. The count on screen is the honest status.
    }
  }

  function uuid() {
    return 'bay-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);
  }

  // --- speech ------------------------------------------------------------

  const speech = {
    native: () => typeof window.ShopVoiceNative !== 'undefined',

    speak(text) {
      if (!text) return;
      if (speech.native() && window.ShopVoiceNative.speak) {
        window.ShopVoiceNative.speak(text);
        return;
      }
      if ('speechSynthesis' in window) {
        window.speechSynthesis.cancel();
        const utterance = new SpeechSynthesisUtterance(text);
        utterance.rate = 1.05;
        window.speechSynthesis.speak(utterance);
      }
    },

    // A short confirmation chirp, not a spoken readback (§8). Thirty spoken
    // readbacks during one inspection would drive a tech mad.
    chirp() {
      if (speech.native() && window.ShopVoiceNative.chirp) {
        window.ShopVoiceNative.chirp();
        return;
      }
      try {
        const ctx = new (window.AudioContext || window.webkitAudioContext)();
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.frequency.value = 880;
        gain.gain.setValueAtTime(0.0001, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.25, ctx.currentTime + 0.01);
        gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.16);
        osc.connect(gain).connect(ctx.destination);
        osc.start();
        osc.stop(ctx.currentTime + 0.18);
      } catch { /* a missing chirp is not worth an error */ }
    },

    recognizer: null,

    start() {
      if (state.listening) return;

      if (speech.native() && window.ShopVoiceNative.startListening) {
        state.listening = true;
        setWakeState('Listening', true);
        window.ShopVoiceNative.startListening();
        return;
      }

      const Recognition = window.SpeechRecognition || window.webkitSpeechRecognition;
      if (!Recognition) {
        promptTyped();
        return;
      }

      const recognizer = new Recognition();
      recognizer.lang = 'en-US';
      recognizer.interimResults = true;
      recognizer.continuous = false;

      recognizer.onresult = (event) => {
        let transcript = '';
        for (const result of event.results) transcript += result[0].transcript;
        setTranscript(transcript, false);
        if (event.results[event.results.length - 1].isFinal) {
          submitUtterance(transcript.trim());
        }
      };
      recognizer.onerror = () => { speech.stop(); toast('Did not catch that.', true); };
      recognizer.onend = () => { state.listening = false; setWakeState('Hold the mic to talk', false); };

      speech.recognizer = recognizer;
      state.listening = true;
      setWakeState('Listening', true);
      setTranscript('…', true);
      recognizer.start();
    },

    stop() {
      state.listening = false;
      setWakeState('Hold the mic to talk', false);
      if (speech.native() && window.ShopVoiceNative.stopListening) {
        window.ShopVoiceNative.stopListening();
      }
      speech.recognizer?.stop();
      speech.recognizer = null;
    },
  };

  /**
   * The native shell's entry point.
   *
   * The Android layer calls window.ShopVoice.onTranscript(text) when Whisper
   * finishes an utterance, whether it was started by the wake word or by
   * push-to-talk. Everything below this line is identical either way.
   */
  window.ShopVoice = {
    onTranscript: (text) => submitUtterance(String(text || '').trim()),
    onPartialTranscript: (text) => setTranscript(String(text || ''), false),
    onWakeWord: () => { setWakeState('Listening', true); },
    onWakeWordAvailable: (available) => {
      $('wakeToggle').classList.toggle('hidden', !available);
    },
  };

  function promptTyped() {
    const typed = window.prompt('Say something (typed):');
    if (typed) submitUtterance(typed.trim());
  }

  // --- the main loop -----------------------------------------------------

  async function submitUtterance(transcript) {
    if (!transcript) return;
    speech.stop();
    setTranscript(transcript, false);

    // A verbal yes/no answers an open low-risk confirmation rather than
    // starting a new command.
    if (state.pendingConfirm?.type === 'voice') {
      const answer = transcript.toLowerCase();
      if (/^(yes|yeah|yep|correct|that's right|right)\b/.test(answer)) return approveNote();
      if (/^(no|nope|wrong|scratch that)\b/.test(answer)) return cancelConfirm();
    }

    try {
      const response = await api('/voice/utterance', { method: 'POST', body: { transcript } });
      applyResponse(response);
    } catch (e) {
      if (e.offline) return handleOffline(transcript);
      if (e.status === 401) return showLogin(e.message);
      toast(e.message, true);
      speech.speak(e.message);
    }
  }

  /**
   * Offline (§10).
   *
   * Dictation is the one thing you cannot ask a tech to repeat, so a note is
   * queued locally with the RO number, user and the time it was actually
   * spoken. Approval happens here, now, at dictation time — nothing sits in the
   * queue unapproved. Everything else needs the network, and says so.
   */
  function handleOffline(transcript) {
    const looksLikeNote = /^(ok(ay)?[,\s]+)?(make|add|start|write|take)\s+(a|an|the)?\s*note\b/i.test(transcript)
      || state.pendingConfirm?.type === 'dictation';

    if (!looksLikeNote || !state.session?.context) {
      const message = state.session?.context
        ? "No connection — I can't look anything up. Notes still work."
        : "No connection, and no RO loaded — I can't look that up.";
      setTranscript(message, true);
      speech.speak(message);
      return;
    }

    const body = transcript.replace(/^(ok(ay)?[,\s]+)?(make|add|start|write|take)\s+(a|an|the)?\s*note\b[:,]?\s*/i, '').trim();

    showVoiceConfirm({
      type: 'voice',
      offline: true,
      note: {
        client_uuid: uuid(),
        transcript: body,
        ro_number: state.session.context.ro_number,
        order_id: state.session.context.order_id,
        vehicle_id: state.session.context.vehicle_id,
        dictated_at: new Date().toISOString(),
        approved: false,
      },
      speak: `On RO ${state.session.context.ro_number}: ${body}. Is that right?`,
      hint: 'Offline — this will send when the connection is back.',
    });
  }

  function applyResponse(response) {
    if (response.session) state.session = response.session;

    if (response.screen?.order) state.order = response.screen.order;
    if (response.screen?.notes) state.notes = response.screen.notes;
    if (response.screen?.session_token) {
      // A voice switch mints a new session; the tablet has to follow it.
      state.sessionToken = response.screen.session_token;
      store.set('sessionToken', state.sessionToken);
      state.order = null;
      state.notes = [];
    }

    if (response.ack === 'chirp') speech.chirp();
    if (response.speak) speech.speak(response.speak);

    setTranscript(response.speak || '…', !response.speak);

    if (response.confirm?.type === 'voice') {
      showVoiceConfirm({ type: 'voice', noteId: response.confirm.note_id, speak: response.speak });
    } else if (response.confirm?.type === 'tap') {
      showTapConfirm(response.confirm);
    } else {
      hideConfirms();
    }

    render(response);
  }

  // --- confirmation (§6) -------------------------------------------------

  function showVoiceConfirm(pending) {
    state.pendingConfirm = pending;
    $('confirmText').textContent = pending.speak || 'Is that right?';
    $('confirmHint').textContent = pending.hint || 'Say “yes” — or tap. Undo for 30s after saving.';
    $('voiceBar').classList.add('hidden');
    $('confirmTap').classList.add('hidden');
    $('confirmVoice').classList.remove('hidden');
    if (pending.offline) speech.speak(pending.speak);
  }

  function showTapConfirm(confirm) {
    state.pendingConfirm = { type: 'tap', ...confirm };
    $('tapText').textContent = confirm.prompt || 'Confirm this change?';
    $('voiceBar').classList.add('hidden');
    $('confirmVoice').classList.add('hidden');
    $('confirmTap').classList.remove('hidden');
  }

  function hideConfirms() {
    state.pendingConfirm = null;
    $('confirmVoice').classList.add('hidden');
    $('confirmTap').classList.add('hidden');
    $('voiceBar').classList.remove('hidden');
  }

  function cancelConfirm() {
    hideConfirms();
    setTranscript('Cancelled.', true);
  }

  async function approveNote() {
    const pending = state.pendingConfirm;
    if (!pending) return;

    // Offline: approve locally and queue. The approval is real — it happened at
    // the lift, with the car in front of him — it just travels with the note.
    if (pending.offline) {
      queue.add({ ...pending.note, approved: true });
      hideConfirms();
      const message = 'Saved on the tablet. It will send when we are back online.';
      setTranscript(message, true);
      speech.speak(message);
      return;
    }

    try {
      await api(`/notes/${pending.noteId}/approve-accuracy`, { method: 'POST', body: { via: 'tap' } });
      hideConfirms();
      setTranscript('Saved.', true);
      speech.speak('Saved.');
      await refresh();
    } catch (e) {
      toast(e.message, true);
    }
  }

  async function confirmTapAction() {
    const pending = state.pendingConfirm;
    if (!pending) return;

    try {
      const result = await api('/actions/confirm', {
        method: 'POST',
        body: { action: pending.action, params: pending.params, via: 'tap' },
      });
      hideConfirms();
      if (result.speak) speech.speak(result.speak);
      await refresh();
    } catch (e) {
      if (e.code === 'identity_unlock_required') return showPin();
      // A 501 here is the expected state today: the gate works, the Shopmonkey
      // write behind it is still waiting on the §2 review.
      hideConfirms();
      toast(e.message, true);
      speech.speak(e.message);
    }
  }

  // --- rendering ---------------------------------------------------------

  function setWakeState(text, live) {
    $('wakeState').textContent = text;
    $('wakeState').classList.toggle('live', !!live);
    $('pttBtn').classList.toggle('live', !!live);
    $('voiceBar').classList.toggle('listening', !!live);
  }

  function setTranscript(text, dim) {
    $('transcript').textContent = text;
    $('transcript').classList.toggle('dim', !!dim);
  }

  function toast(message, warn) {
    const el = document.createElement('div');
    el.className = 'toast' + (warn ? ' warn' : '');
    el.textContent = message;
    document.body.appendChild(el);
    setTimeout(() => el.remove(), 4200);
  }

  function renderStrip() {
    const pending = queue.count();
    const parts = [];
    if (!state.online) parts.push('Offline — lookups unavailable, dictation still works');
    if (pending > 0) parts.push(`${pending} note${pending === 1 ? '' : 's'} waiting to send`);

    $('strip').textContent = parts.join(' · ');
    $('strip').classList.toggle('hidden', parts.length === 0);
  }

  function render(response) {
    const session = state.session;

    // Header: user + loaded RO + vehicle. Always visible.
    $('hdrUser').textContent = session?.user?.name || '—';

    if (session?.context) {
      $('hdrRo').className = 'ro';
      $('hdrRo').innerHTML = 'RO <span class="num">' + escapeHtml(session.context.ro_number) + '</span>';
      const vehicle = state.order?.vehicle;
      $('hdrVehicle').textContent = vehicle
        ? [state.order.customer?.name, vehicle.description, vehicle.color,
           state.order.odometer ? state.order.odometer.toLocaleString() + ' mi' : null]
          .filter(Boolean).join(' · ')
        : session.context.label || '';
    } else {
      $('hdrRo').className = 'ro none';
      $('hdrRo').textContent = 'No RO loaded';
      $('hdrVehicle').textContent = 'Say a repair order number, a plate, or a name';
    }

    renderStrip();
    renderMain(response);
  }

  function renderMain(response) {
    const main = $('main');
    const view = response?.view;

    if (view === 'disambiguate' && response.screen?.candidates) {
      main.innerHTML = `<div class="card"><span class="label">Which one?</span>
        ${response.screen.candidates.map((c) => `
          <div class="row tappable" data-load="${escapeHtml(c.number)}">
            <span class="grow"><strong>${escapeHtml(c.customer)}</strong> · ${escapeHtml(c.vehicle)}${c.color ? ' · ' + escapeHtml(c.color) : ''}</span>
            <span class="num">RO ${escapeHtml(c.number)}</span>
          </div>`).join('')}
      </div>`;
      wireLoadRows();
      return;
    }

    if (view === 'inspection' || view === 'inspection_summary') return renderInspection(response);

    if (!state.order) {
      main.innerHTML = `<div class="card"><div class="row"><span class="grow" style="color:var(--ink-dim)">
        Nothing loaded. Say a repair order number, a plate, a name, or “the silver Tahoe”.
      </span></div></div>`;
      return;
    }

    const order = state.order;
    const deferred = (order.services || []).filter((s) => s.deferred || s.declined);

    main.innerHTML = `
      <section class="concern">
        <span class="label">Customer concern</span>
        <p>${escapeHtml(order.concern || 'No concern recorded on this order.')}</p>
      </section>

      ${deferred.length ? `
      <div class="deferred" id="deferredBar">
        <span class="badge">${deferred.length}</span>
        <span>deferred item${deferred.length === 1 ? '' : 's'} on this vehicle</span>
        <span class="chev">›</span>
      </div>` : ''}

      <section class="card">
        <span class="label">Services on this order</span>
        ${(order.services || []).map((s) => `
          <div class="row">
            <span class="grow">${escapeHtml(s.name)}</span>
            ${s.declined ? '<span class="tag bad">Declined</span>' : ''}
            ${s.deferred ? '<span class="tag warn">Deferred</span>' : ''}
            ${s.total != null ? `<span class="num">$${Number(s.total).toFixed(2)}</span>` : ''}
          </div>`).join('')}
      </section>`;

    $('deferredBar')?.addEventListener('click', () => openSheet('deferred'));
    $('notesCount').textContent = state.notes.length
      ? `${state.notes.length} · ${state.notes.filter((n) => n.stage !== 'customer_approved').length} pending`
      : '';
  }

  function renderInspection(response) {
    const summary = response.screen?.inspection_summary;
    const fields = response.screen?.inspection?.fields || state.inspection?.fields || [];
    const items = response.screen?.items || state.inspection?.items || [];

    if (summary) {
      $('main').innerHTML = `
        <section class="card"><span class="label">Inspection closed</span>
          ${summary.missing.length
            ? `<div class="row"><span class="grow">Not recorded: ${summary.missing.map(escapeHtml).join(', ')}</span></div>`
            : '<div class="row"><span class="grow">Everything recorded.</span></div>'}
          ${summary.recorded.map((i) => inspectionRow(i.label || i.field_key, i.value, i.condition)).join('')}
        </section>`;
      state.inspection = null;
      return;
    }

    state.inspection = { fields, items };
    const byKey = Object.fromEntries(items.map((i) => [i.field_key, i]));

    $('main').innerHTML = `
      <section class="card"><span class="label">Inspection — call them out in any order</span>
        ${fields.map((f) => {
          const item = byKey[f.field_key];
          return inspectionRow(f.label, item?.value, item?.condition, !item);
        }).join('')}
      </section>`;
  }

  function inspectionRow(label, value, condition, pending) {
    const mark = condition === 'red' ? '!' : condition === 'yellow' ? '!' : '✓';
    return `<div class="insp-item${pending ? ' pending' : ''}">
      <span class="tick ${pending ? '' : (condition || 'green')}">${pending ? '' : mark}</span>
      <span>${escapeHtml(label)}</span>
      ${value ? `<span class="val">${escapeHtml(value)}</span>` : ''}
    </div>`;
  }

  function wireLoadRows() {
    document.querySelectorAll('[data-load]').forEach((row) => {
      row.addEventListener('click', async () => {
        try {
          const result = await api(`/orders/${encodeURIComponent(row.dataset.load)}/load`, { method: 'POST' });
          state.session = result.session;
          state.order = result.order;
          speech.speak(result.speak);
          await refresh();
        } catch (e) { toast(e.message, true); }
      });
    });
  }

  // --- sheets ------------------------------------------------------------

  async function openSheet(kind) {
    const body = $('sheetBody');
    $('sheetView').classList.remove('hidden');

    if (kind === 'deferred') {
      $('sheetTitle').textContent = 'Deferred work';
      const deferred = (state.order?.services || []).filter((s) => s.deferred || s.declined);
      body.innerHTML = deferred.map((s) => `<div class="row">
        <span class="grow">${escapeHtml(s.name)}</span>
        ${s.declined ? '<span class="tag bad">Declined</span>' : '<span class="tag warn">Deferred</span>'}
        ${s.total != null ? `<span class="num">$${Number(s.total).toFixed(2)}</span>` : ''}
      </div>`).join('') || '<div class="row"><span class="grow">Nothing deferred.</span></div>';
      return;
    }

    if (kind === 'history') {
      $('sheetTitle').textContent = 'Service history';
      body.innerHTML = '<div class="row"><span class="grow">Loading…</span></div>';
      if (!state.order) { body.innerHTML = '<div class="row"><span class="grow">Load a repair order first.</span></div>'; return; }
      try {
        const history = await api(`/vehicles/${encodeURIComponent(state.order.vehicle.id)}/history`);
        body.innerHTML = history.entries.map((e) => `<div class="row">
          <span class="grow">${e.services.map(escapeHtml).join(', ')}</span>
          <span class="muted">${escapeHtml(e.relative_when || '')}</span>
        </div>`).join('') || '<div class="row"><span class="grow">No history on this vehicle.</span></div>';
      } catch (e) {
        body.innerHTML = `<div class="row"><span class="grow">${escapeHtml(e.message)}</span></div>`;
      }
      return;
    }

    if (kind === 'notes') {
      $('sheetTitle').textContent = 'Notes';
      body.innerHTML = state.notes.map((n) => `<div class="note-row">
        <div class="meta">${escapeHtml(n.dictated_at || '')} <span class="tag ${n.stage === 'customer_approved' ? 'ok' : 'warn'}">${escapeHtml(n.stage.replace(/_/g, ' '))}</span></div>
        <div class="verbatim">${escapeHtml(n.internal_note)}</div>
        ${n.customer_draft ? `<div class="draft"><strong>Customer draft:</strong> ${escapeHtml(n.customer_draft)}</div>` : ''}
      </div>`).join('') || '<div class="row"><span class="grow">No notes on this order.</span></div>';
      return;
    }

    if (kind === 'diagrams') {
      $('sheetTitle').textContent = 'Diagrams';
      body.innerHTML = `<div class="row"><span class="grow">Not wired up yet — build step 6. Pull it up on ALLDATA.</span></div>`;
    }
  }

  // --- setup screens -----------------------------------------------------

  function showView(id) {
    ['enrollView', 'loginView', 'pinView', 'appView'].forEach((v) => $(v).classList.toggle('hidden', v !== id));
    if (id === 'appView') $('appView').style.display = 'flex';
  }

  async function showLogin(message) {
    $('loginErr').textContent = message || '';
    showView('loginView');
    try {
      const result = await api('/users', { device: true });
      $('userList').innerHTML = result.users
        .map((u) => `<button class="btn no" data-user="${escapeHtml(u.id)}">${escapeHtml(u.name)}${u.role !== 'tech' ? ' · ' + escapeHtml(u.role) : ''}</button>`)
        .join('');
      document.querySelectorAll('[data-user]').forEach((btn) => {
        btn.addEventListener('click', () => login(btn.dataset.user));
      });
    } catch (e) {
      $('loginErr').textContent = e.message;
    }
  }

  async function login(userId) {
    try {
      const result = await api('/auth/login', { method: 'POST', device: true, body: { user_id: userId } });
      state.sessionToken = result.session_token;
      state.session = result.session;
      store.set('sessionToken', state.sessionToken);

      $('wakeToggle').classList.toggle('on', !!result.device?.wake_word_enabled);
      $('wakeToggle').textContent = 'Wake word: ' + (result.device?.wake_word_enabled ? 'on' : 'off');

      showView('appView');
      await refresh();
      flushQueue();
    } catch (e) {
      $('loginErr').textContent = e.message;
    }
  }

  function showPin() {
    $('pinErr').textContent = '';
    $('pinInput').value = '';
    showView('pinView');
    $('pinInput').focus();
  }

  async function refresh() {
    try {
      const result = await api('/session');
      state.session = result.session;
      if (state.session?.context?.ro_number) {
        const order = await api(`/orders/${encodeURIComponent(state.session.context.ro_number)}`);
        state.order = order.order;
        state.notes = order.notes || [];
      } else {
        state.order = null;
        state.notes = [];
      }
      render({});
    } catch (e) {
      if (e.status === 401) return showLogin(e.message);
      if (!e.offline) toast(e.message, true);
    }
  }

  function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (c) =>
      ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }

  // --- wiring ------------------------------------------------------------

  $('enrollBtn').addEventListener('click', async () => {
    const code = $('enrollCode').value.trim().toUpperCase();
    if (!code) return;
    try {
      const result = await api('/devices/enroll', { method: 'POST', body: { code } });
      state.deviceToken = result.token;
      store.set('deviceToken', result.token);
      showLogin();
    } catch (e) {
      $('enrollErr').textContent = e.message;
    }
  });

  $('pinBtn').addEventListener('click', async () => {
    try {
      await api('/auth/unlock', { method: 'POST', body: { pin: $('pinInput').value } });
      showView('appView');
      toast('Unlocked. Tap to confirm the change.');
    } catch (e) {
      $('pinErr').textContent = e.message;
    }
  });
  $('pinCancel').addEventListener('click', () => { showView('appView'); hideConfirms(); });

  // Push-to-talk: first-class, and likely the primary input in a loud bay (§9).
  const ptt = $('pttBtn');
  ['mousedown', 'touchstart'].forEach((evt) =>
    ptt.addEventListener(evt, (e) => { e.preventDefault(); speech.start(); }, { passive: false }));
  ['mouseup', 'touchend', 'mouseleave'].forEach((evt) =>
    ptt.addEventListener(evt, () => { if (state.listening) speech.stop(); }));

  $('wakeToggle').addEventListener('click', async () => {
    const on = !$('wakeToggle').classList.contains('on');
    try {
      await api('/devices/wake-word', { method: 'POST', body: { enabled: on } });
      $('wakeToggle').classList.toggle('on', on);
      $('wakeToggle').textContent = 'Wake word: ' + (on ? 'on' : 'off');
      window.ShopVoiceNative?.setWakeWordEnabled?.(on);
    } catch (e) { toast(e.message, true); }
  });

  $('confirmYes').addEventListener('click', approveNote);
  $('confirmNo').addEventListener('click', cancelConfirm);
  $('confirmSpeakBtn').addEventListener('click', () => speech.speak($('confirmText').textContent));
  $('tapConfirm').addEventListener('click', confirmTapAction);
  $('tapCancel').addEventListener('click', cancelConfirm);

  document.querySelectorAll('[data-sheet]').forEach((el) =>
    el.addEventListener('click', () => openSheet(el.dataset.sheet)));
  $('sheetClose').addEventListener('click', () => $('sheetView').classList.add('hidden'));
  $('sheetView').addEventListener('click', (e) => { if (e.target === $('sheetView')) $('sheetView').classList.add('hidden'); });

  $('hdrWho').addEventListener('click', () => showLogin());

  // --- boot --------------------------------------------------------------

  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('sw.js').catch(() => { /* the app still works */ });
  }

  (async function boot() {
    renderStrip();

    if (!state.deviceToken) return showView('enrollView');
    if (!state.sessionToken) return showLogin();

    showView('appView');
    await refresh();
    flushQueue();
  })();

  setInterval(() => { if (state.online) flushQueue(); }, 60000);
})();
