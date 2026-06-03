/* ===== Merch Mogul HQ — game logic ===== */
/* Everything saves to the browser (localStorage). AI replies come from /api/agent. */

// ---- The AI team. `id` must match the agentId in /api/agent.js ----
const AGENTS = [
  {
    id: "designer", name: "Pixel", emoji: "🎨", role: "Design Director",
    color: "linear-gradient(135deg,#ff5ea8,#8a5cff)",
    desc: "Invents cool product designs, colors, and slogans.",
    jobs: ["Design a new sticker pack", "Pick colors for our brand", "Make a t-shirt slogan", "Design a logo idea"],
    quick: ["Give me 3 sticker ideas!", "What colors should our brand use?", "Make a slogan for a t-shirt"]
  },
  {
    id: "marketing", name: "Buzz", emoji: "📣", role: "Hype Specialist",
    color: "linear-gradient(135deg,#ff8c42,#ffd23f)",
    desc: "Gets fans excited and brings in more customers.",
    jobs: ["Plan a social media post", "Think of a launch idea", "Make catchy hashtags", "Plan a giveaway"],
    quick: ["How do we get more customers?", "Write a fun post about our shop", "Give me 5 hashtag ideas"]
  },
  {
    id: "support", name: "Sunny", emoji: "🌟", role: "Customer Helper",
    color: "linear-gradient(135deg,#38b6ff,#1fd6c4)",
    desc: "Keeps customers happy and writes friendly replies.",
    jobs: ["Write a thank-you message", "Answer a customer question", "Make a return policy", "Reply to a complaint nicely"],
    quick: ["Write a thank-you note for a customer", "A customer is upset — what do I say?", "How do we make customers happy?"]
  },
  {
    id: "money", name: "Penny", emoji: "💰", role: "Money Manager",
    color: "linear-gradient(135deg,#43d16b,#1fd6c4)",
    desc: "Helps with prices, budgets, and counting profit.",
    jobs: ["Set prices for products", "Make a budget", "Figure out our profit", "Plan how to save coins"],
    quick: ["How should we price our stickers?", "What is profit?", "Help me make a simple budget"]
  },
  {
    id: "ceo", name: "Ace", emoji: "🚀", role: "Business Coach",
    color: "linear-gradient(135deg,#8a5cff,#38b6ff)",
    desc: "Gives big plans and tells you what to do next.",
    jobs: ["Plan our next big goal", "Decide what to focus on", "Pump up the team", "Review how we're doing"],
    quick: ["What should we do next?", "Give us a big goal for this week", "How is our business doing?"]
  }
];

// ---- Missions: complete to earn coins + XP ----
const MISSIONS = [
  { id: "name",    emoji: "✏️", title: "Name Your Shop",          desc: "Click the shop name at the top and make it yours!", coins: 20, xp: 30 },
  { id: "idea",    emoji: "💡", title: "Brainstorm a Product",     desc: "Ask Pixel for product ideas, then save one in the Idea Vault.", coins: 30, xp: 40 },
  { id: "price",   emoji: "🏷️", title: "Set Your Prices",          desc: "Ask Penny to help you price your products.", coins: 30, xp: 40 },
  { id: "promo",   emoji: "📢", title: "Make Some Hype",           desc: "Ask Buzz to write a fun social media post.", coins: 30, xp: 40 },
  { id: "service", emoji: "💌", title: "Wow a Customer",           desc: "Ask Sunny to write a friendly thank-you message.", coins: 30, xp: 40 },
  { id: "plan",    emoji: "🗺️", title: "Make a Big Plan",          desc: "Ask Ace what your next big goal should be.", coins: 40, xp: 50 },
  { id: "assign",  emoji: "🧑‍💼", title: "Be the Boss",              desc: "Give any teammate a job using the 'Assign Job' button.", coins: 25, xp: 30 },
  { id: "vault3",  emoji: "🗄️", title: "Fill the Idea Vault",      desc: "Save 3 product ideas in your Idea Vault.", coins: 50, xp: 60 }
];

// ---- Default game state ----
function freshState() {
  return {
    shopName: "Merch Mogul HQ",
    level: 1, xp: 0, coins: 0,
    chats: 0, products: 0, streak: 1, lastPlayed: today(),
    missionsDone: [],     // ids
    assignments: {},      // agentId -> job text
    ideas: [],            // strings
    history: {}           // agentId -> [{role, content}]
  };
}

const KEY = "merch-mogul-hq-v1";
let S = load();

function today() { return new Date().toISOString().slice(0, 10); }
function xpNeeded(lvl) { return 100 + (lvl - 1) * 80; } // grows each level

function load() {
  try {
    const raw = localStorage.getItem(KEY);
    if (!raw) return freshState();
    const s = { ...freshState(), ...JSON.parse(raw) };
    // daily streak update
    if (s.lastPlayed !== today()) {
      const y = new Date(Date.now() - 86400000).toISOString().slice(0, 10);
      s.streak = (s.lastPlayed === y) ? (s.streak + 1) : 1;
      s.lastPlayed = today();
    }
    return s;
  } catch { return freshState(); }
}
function save() { localStorage.setItem(KEY, JSON.stringify(S)); }

// ---------- Rendering ----------
const $ = (sel) => document.querySelector(sel);
const $$ = (sel) => document.querySelectorAll(sel);

function renderHUD() {
  $("#shopName").value = S.shopName;
  $("#coins").textContent = S.coins;
  $("#level").textContent = S.level;
  const need = xpNeeded(S.level);
  $("#xpfill").style.width = Math.min(100, (S.xp / need) * 100) + "%";
  $("#xptext").textContent = `${S.xp} / ${need} XP`;
}

function renderStats() {
  $("#s-coins").textContent = S.coins;
  $("#s-level").textContent = S.level;
  $("#s-missions").textContent = S.missionsDone.length;
  $("#s-chats").textContent = S.chats;
  $("#s-products").textContent = S.ideas.length;
  $("#s-streak").textContent = S.streak;
}

function renderAgents() {
  const grid = $("#agentGrid");
  grid.innerHTML = "";
  AGENTS.forEach(a => {
    const job = S.assignments[a.id];
    const card = document.createElement("div");
    card.className = "agent-card";
    card.style.background = a.color;
    card.innerHTML = `
      <div class="agent-emoji">${a.emoji}</div>
      <div class="agent-name">${a.name}</div>
      <div class="agent-role">${a.role}</div>
      <div class="agent-desc">${a.desc}</div>
      <div class="agent-status ${job ? "working" : ""}">
        <span class="dot"></span>${job ? "Working: " + esc(job) : "Ready for orders"}
      </div>
      <div class="agent-actions">
        <button class="btn btn-light" data-talk="${a.id}">💬 Chat</button>
        <button class="btn btn-light" data-assign="${a.id}">🧑‍💼 Assign Job</button>
      </div>`;
    grid.appendChild(card);
  });
}

function renderMissions() {
  const list = $("#missionList");
  list.innerHTML = "";
  MISSIONS.forEach(m => {
    const done = S.missionsDone.includes(m.id);
    const row = document.createElement("div");
    row.className = "mission" + (done ? " done" : "");
    row.innerHTML = `
      <div class="mission-emoji">${m.emoji}</div>
      <div class="mission-body">
        <div class="mission-title">${m.title}</div>
        <div class="mission-desc">${m.desc}</div>
        <div class="mission-reward">Reward: 🪙 ${m.coins} coins · ${m.xp} XP</div>
      </div>
      ${done
        ? `<div class="mission-done-badge">✅ Done!</div>`
        : `<button class="btn btn-primary" data-mission="${m.id}">Mark Done</button>`}`;
    list.appendChild(row);
  });
}

function renderIdeas() {
  const ul = $("#ideaList");
  ul.innerHTML = "";
  if (!S.ideas.length) {
    ul.innerHTML = `<li style="justify-content:center;color:#5b5183;border-style:dashed">No ideas saved yet — ask Pixel for some! ✨</li>`;
    return;
  }
  S.ideas.forEach((idea, i) => {
    const li = document.createElement("li");
    li.innerHTML = `<span>💡 ${esc(idea)}</span><button data-idea="${i}">Delete</button>`;
    ul.appendChild(li);
  });
}

function renderAll() { renderHUD(); renderStats(); renderAgents(); renderMissions(); renderIdeas(); }
function esc(s) { return String(s).replace(/[&<>"]/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c])); }

// ---------- Rewards ----------
function award(coins, xp, msg) {
  S.coins += coins;
  S.xp += xp;
  let leveled = false;
  while (S.xp >= xpNeeded(S.level)) {
    S.xp -= xpNeeded(S.level);
    S.level++;
    leveled = true;
  }
  save(); renderHUD(); renderStats();
  if (msg) toast(msg);
  if (leveled) { setTimeout(() => { toast(`🎉 LEVEL UP! You're now Level ${S.level}!`); confetti(); }, 700); }
}

function completeMission(id) {
  if (S.missionsDone.includes(id)) return;
  const m = MISSIONS.find(x => x.id === id);
  if (!m) return;
  S.missionsDone.push(id);
  award(m.coins, m.xp, `🏆 Mission complete: ${m.title}!`);
  confetti();
  renderMissions();
}

// auto-complete some missions when their action happens
function checkAutoMissions() {
  if (S.ideas.length >= 1) maybeDone("idea");
  if (S.ideas.length >= 3) maybeDone("vault3");
  if (Object.keys(S.assignments).length >= 1) maybeDone("assign");
  if (S.shopName !== "Merch Mogul HQ" && S.shopName.trim()) maybeDone("name");
}
function maybeDone(id) { if (!S.missionsDone.includes(id)) completeMission(id); }

// chatting with a specific agent can finish that agent's mission
const AGENT_MISSION = { designer: null, marketing: "promo", support: "service", money: "price", ceo: "plan" };

// ---------- Tabs ----------
$$(".tab").forEach(t => t.addEventListener("click", () => {
  $$(".tab").forEach(x => x.classList.remove("active"));
  $$(".panel").forEach(p => p.classList.remove("active"));
  t.classList.add("active");
  $("#tab-" + t.dataset.tab).classList.add("active");
}));

// ---------- Shop name ----------
$("#shopName").addEventListener("input", e => {
  S.shopName = e.target.value.slice(0, 22);
  save();
});
$("#shopName").addEventListener("blur", () => { checkAutoMissions(); });

// ---------- Ideas ----------
$("#ideaAddBtn").addEventListener("click", addIdea);
$("#ideaInput").addEventListener("keydown", e => { if (e.key === "Enter") addIdea(); });
function addIdea() {
  const v = $("#ideaInput").value.trim();
  if (!v) return;
  S.ideas.push(v);
  $("#ideaInput").value = "";
  save(); renderIdeas(); renderStats();
  toast("💡 Idea saved to the vault!");
  checkAutoMissions();
}
$("#ideaList").addEventListener("click", e => {
  const i = e.target.dataset.idea;
  if (i === undefined) return;
  S.ideas.splice(Number(i), 1);
  save(); renderIdeas(); renderStats();
});

// ---------- Reset ----------
$("#resetBtn").addEventListener("click", () => {
  if (confirm("Start over? This erases your coins, level, and saved ideas.")) {
    S = freshState(); save(); renderAll();
    toast("🆕 Fresh start! Good luck, boss!");
  }
});

// ---------- Delegated clicks for agent cards & missions ----------
document.addEventListener("click", e => {
  const talk = e.target.dataset.talk;
  const assign = e.target.dataset.assign;
  const mission = e.target.dataset.mission;
  if (talk) openChat(talk);
  if (assign) openAssign(assign);
  if (mission) completeMission(mission);
});

// ---------- Assign Job modal ----------
let assignAgentId = null;
function openAssign(id) {
  assignAgentId = id;
  const a = AGENTS.find(x => x.id === id);
  $("#assignTitle").textContent = `Give ${a.name} a Job 🧑‍💼`;
  const box = $("#assignOptions");
  box.innerHTML = "";
  a.jobs.forEach(job => {
    const b = document.createElement("button");
    b.className = "assign-opt";
    b.innerHTML = `<span>${a.emoji}</span> ${esc(job)}`;
    b.onclick = () => {
      S.assignments[id] = job;
      save(); renderAgents();
      closeAssign();
      toast(`${a.name} is now working on: ${job}`);
      checkAutoMissions();
    };
    box.appendChild(b);
  });
  // option to clear
  const clr = document.createElement("button");
  clr.className = "assign-opt";
  clr.innerHTML = `<span>🛑</span> Take a break (no job)`;
  clr.onclick = () => { delete S.assignments[id]; save(); renderAgents(); closeAssign(); };
  box.appendChild(clr);
  $("#assignOverlay").hidden = false;
}
function closeAssign() { $("#assignOverlay").hidden = true; assignAgentId = null; }
$("#assignCancel").addEventListener("click", closeAssign);
$("#assignOverlay").addEventListener("click", e => { if (e.target.id === "assignOverlay") closeAssign(); });

// ---------- Chat drawer ----------
let chatAgent = null;
function openChat(id) {
  chatAgent = AGENTS.find(a => a.id === id);
  $("#chatHead").style.background = chatAgent.color;
  $("#chatAvatar").textContent = chatAgent.emoji;
  $("#chatName").textContent = chatAgent.name;
  $("#chatRole").textContent = chatAgent.role;

  // render quick chips
  const q = $("#chatQuick");
  q.innerHTML = "";
  chatAgent.quick.forEach(text => {
    const c = document.createElement("button");
    c.className = "quick-chip";
    c.textContent = text;
    c.onclick = () => { $("#chatField").value = text; sendChat(); };
    q.appendChild(c);
  });

  // render history (or greeting)
  const box = $("#chatMessages");
  box.innerHTML = "";
  const hist = S.history[id] || [];
  if (!hist.length) {
    const job = S.assignments[id];
    const greet = job
      ? `Hey boss! I'm ${chatAgent.name}, your ${chatAgent.role}. I'm on it: "${job}". What do you need? 😄`
      : `Hi! I'm ${chatAgent.name}, your ${chatAgent.role}. ${chatAgent.desc} What can I help with? 😄`;
    addBubble("bot", greet);
  } else {
    hist.forEach(m => addBubble(m.role === "user" ? "me" : "bot", m.content));
  }

  $("#chatOverlay").hidden = false;
  $("#chatDrawer").hidden = false;
  setTimeout(() => $("#chatField").focus(), 100);
}
function closeChat() { $("#chatDrawer").hidden = true; $("#chatOverlay").hidden = true; chatAgent = null; }
$("#chatClose").addEventListener("click", closeChat);
$("#chatOverlay").addEventListener("click", closeChat);

function addBubble(who, text) {
  const div = document.createElement("div");
  div.className = "msg " + who;
  div.textContent = text;
  $("#chatMessages").appendChild(div);
  $("#chatMessages").scrollTop = $("#chatMessages").scrollHeight;
  return div;
}

$("#chatForm").addEventListener("submit", e => { e.preventDefault(); sendChat(); });

async function sendChat() {
  const field = $("#chatField");
  const text = field.value.trim();
  if (!text || !chatAgent) return;
  field.value = "";

  addBubble("me", text);
  const id = chatAgent.id;
  S.history[id] = S.history[id] || [];
  S.history[id].push({ role: "user", content: text });

  // typing indicator
  const typing = addBubble("bot", "");
  typing.classList.add("typing");
  typing.innerHTML = `${chatAgent.name} is thinking <span class="dotty"><span>.</span><span>.</span><span>.</span></span>`;

  const sendBtn = $("#chatSend");
  sendBtn.disabled = true;

  try {
    const res = await fetch("/api/agent", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        agentId: id,
        messages: S.history[id],
        shop: { name: S.shopName, level: S.level, coins: S.coins }
      })
    });
    const data = await res.json();
    typing.remove();

    if (!res.ok) {
      const hint = (data.error || "").includes("OPENAI_API_KEY")
        ? "⚙️ A grown-up needs to add the OPENAI_API_KEY in Vercel to wake up the AI team. (See the README!)"
        : ("Oops, something went wrong: " + (data.error || "unknown error"));
      addBubble("bot", hint);
      return;
    }

    const reply = data.reply || "Hmm, try asking me again!";
    addBubble("bot", reply);
    S.history[id].push({ role: "assistant", content: reply });

    // rewards for chatting
    S.chats++;
    award(5, 12); // small reward each exchange
    if (AGENT_MISSION[id]) maybeDone(AGENT_MISSION[id]);
    save();
  } catch (err) {
    typing.remove();
    addBubble("bot", "📡 I couldn't reach my AI brain. Check the internet connection and try again!");
  } finally {
    sendBtn.disabled = false;
    field.focus();
  }
}

// ---------- Toast ----------
let toastTimer;
function toast(msg) {
  const t = $("#toast");
  t.textContent = msg;
  t.hidden = false;
  requestAnimationFrame(() => t.classList.add("show"));
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => {
    t.classList.remove("show");
    setTimeout(() => (t.hidden = true), 300);
  }, 2600);
}

// ---------- Confetti ----------
function confetti() {
  const cv = $("#confetti");
  cv.hidden = false;
  const ctx = cv.getContext("2d");
  cv.width = innerWidth; cv.height = innerHeight;
  const colors = ["#ff5ea8", "#8a5cff", "#38b6ff", "#1fd6c4", "#ffd23f", "#ff8c42", "#43d16b"];
  const bits = Array.from({ length: 140 }, () => ({
    x: Math.random() * cv.width, y: -20 - Math.random() * cv.height * 0.5,
    r: 4 + Math.random() * 7, c: colors[(Math.random() * colors.length) | 0],
    vy: 2 + Math.random() * 4, vx: -2 + Math.random() * 4, rot: Math.random() * 6, vr: -0.2 + Math.random() * 0.4
  }));
  let frames = 0;
  (function frame() {
    ctx.clearRect(0, 0, cv.width, cv.height);
    bits.forEach(b => {
      b.y += b.vy; b.x += b.vx; b.rot += b.vr;
      ctx.save(); ctx.translate(b.x, b.y); ctx.rotate(b.rot);
      ctx.fillStyle = b.c; ctx.fillRect(-b.r / 2, -b.r / 2, b.r, b.r * 1.6);
      ctx.restore();
    });
    if (frames++ < 130) requestAnimationFrame(frame);
    else { ctx.clearRect(0, 0, cv.width, cv.height); cv.hidden = true; }
  })();
}

// ---------- Go! ----------
renderAll();
save();
