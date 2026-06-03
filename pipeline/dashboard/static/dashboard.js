/* ── Evolve AI Pipeline Dashboard — client logic ── */

// ── State ─────────────────────────────────────────────────
let pipelineRunning = false;
let metricsChart = null;

// ── Bootstrap ─────────────────────────────────────────────
window.addEventListener("DOMContentLoaded", () => {
  initChart();
  refresh();
  setInterval(refresh, 15_000);   // poll every 15s as SSE fallback
  connectSSE();
});

// ── SSE ───────────────────────────────────────────────────
function connectSSE() {
  const es = new EventSource("/api/stream");

  es.addEventListener("status", (e) => {
    const data = JSON.parse(e.data);
    applyStatus(data);
  });

  es.addEventListener("pipeline_done", (e) => {
    const data = JSON.parse(e.data);
    appendLog(
      data.error
        ? `Pipeline error: ${data.error}`
        : `Pipeline complete — ${data.topics} topics, ${data.scripts} scripts, ` +
          `${data.videos} videos, ${data.uploads} uploads`,
      data.error ? "error" : "ok",
    );
    refresh();
  });

  es.onerror = () => {
    setTimeout(connectSSE, 5000);
  };
}

// ── Apply status update from server ───────────────────────
function applyStatus({ running, stage, progress }) {
  pipelineRunning = running;

  const dot    = document.getElementById("pulseDot");
  const text   = document.getElementById("statusText");
  const wrap   = document.getElementById("progressWrap");
  const bar    = document.getElementById("progressBar");
  const badge  = document.getElementById("stageBadge");
  const btnAll = document.getElementById("btnRunAll");

  if (running) {
    dot.classList.add("active");
    dot.classList.remove("error");
    text.textContent = "Running";
    wrap.hidden = false;
    bar.style.width = `${progress}%`;
    badge.textContent = humanStage(stage);
    badge.className = "stage-badge active";
    btnAll.disabled = true;
  } else if (stage === "error") {
    dot.classList.add("error");
    dot.classList.remove("active");
    text.textContent = "Error";
    badge.textContent = "Error";
    badge.className = "stage-badge error";
    wrap.hidden = true;
    btnAll.disabled = false;
  } else {
    dot.classList.remove("active", "error");
    text.textContent = "Idle";
    wrap.hidden = true;
    badge.textContent = "Idle";
    badge.className = "stage-badge";
    btnAll.disabled = false;
  }
}

function humanStage(s) {
  const map = {
    research: "Researching trends…",
    scripting: "Writing scripts…",
    video_assembly: "Assembling video…",
    uploading: "Uploading…",
    done: "Done",
    idle: "Idle",
    error: "Error",
    starting: "Starting…",
  };
  return map[s] || s;
}

// ── Trigger pipeline stages ───────────────────────────────
async function runStage(stage) {
  if (pipelineRunning) return;
  const routes = {
    all:      "/api/run",
    research: "/api/run/research",
    scripts:  "/api/run/scripts",
    videos:   "/api/run/videos",
    uploads:  "/api/run/uploads",
  };
  const route = routes[stage];
  if (!route) return;

  appendLog(`Triggering ${stage === "all" ? "full pipeline" : stage + " stage"}…`, "info");

  const resp = await fetch(route, { method: "POST", headers: { "Content-Type": "application/json" } });
  const data = await resp.json();
  if (data.error) {
    appendLog(`Error: ${data.error}`, "error");
  } else {
    appendLog(`Started — watch the progress bar.`, "ok");
    applyStatus({ running: true, stage: "starting", progress: 0 });
  }
}

// ── Refresh dashboard data ─────────────────────────────────
async function refresh() {
  try {
    const [summary, metrics, status] = await Promise.all([
      fetch("/api/summary").then(r => r.json()),
      fetch("/api/metrics").then(r => r.json()),
      fetch("/api/status").then(r => r.json()),
    ]);
    renderStats(summary);
    renderQueue(summary.queued || []);
    renderPublished(summary.published || []);
    updateChart(metrics);
    applyStatus(status);
  } catch (e) {
    console.error("Refresh error:", e);
  }
}

// ── Stat cards ────────────────────────────────────────────
function renderStats(s) {
  const row = document.getElementById("statRow");
  row.innerHTML = [
    stat("Topics Found",   s.topics_total,          "stat-blue"),
    stat("Scripts Written",s.scripts_total,          ""),
    stat("Videos Made",    s.videos_total,           ""),
    stat("Published",      s.uploads_published,      "stat-green"),
    stat("In Queue",       s.uploads_queued,         "stat-accent"),
    stat("Total Views",    fmt(s.total_views),       "stat-blue"),
    stat("Est. Revenue",   "$" + (s.total_revenue || 0).toFixed(2), "stat-green"),
  ].join("");
}

function stat(label, value, cls) {
  return `<div class="stat-card">
    <div class="stat-label">${label}</div>
    <div class="stat-value ${cls}">${value ?? 0}</div>
  </div>`;
}

// ── Queue ─────────────────────────────────────────────────
function renderQueue(items) {
  const list = document.getElementById("queueList");
  const count = document.getElementById("queueCount");
  count.textContent = items.length;
  if (!items.length) {
    list.innerHTML = `<div class="empty-state">No videos queued.</div>`;
    return;
  }
  list.innerHTML = items.map(item => `
    <div class="queue-item">
      <div class="queue-item-title">${esc(item.title || "Untitled")}</div>
      <div class="queue-item-meta">
        <span class="platform-tag tag-${item.platform}">${item.platform}</span>
        <span>${item.scheduled_at ? fmtTime(item.scheduled_at) : item.status}</span>
      </div>
    </div>
  `).join("");
}

// ── Published table ───────────────────────────────────────
function renderPublished(items) {
  const body  = document.getElementById("pubBody");
  const count = document.getElementById("pubCount");
  count.textContent = items.length;
  if (!items.length) {
    body.innerHTML = `<tr><td colspan="7" class="empty-cell">No published videos yet.</td></tr>`;
    return;
  }
  body.innerHTML = items.map(v => `
    <tr>
      <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(v.title || "Untitled")}</td>
      <td><span class="platform-tag tag-${v.platform}">${v.platform}</span></td>
      <td>${v.published_at ? fmtTime(v.published_at) : "—"}</td>
      <td>${fmt(v.views)}</td>
      <td>${fmt(v.likes)}</td>
      <td>$${(v.revenue || 0).toFixed(2)}</td>
      <td>${v.url ? `<a href="${esc(v.url)}" target="_blank" rel="noreferrer">View ↗</a>` : "—"}</td>
    </tr>
  `).join("");
}

// ── Chart ─────────────────────────────────────────────────
function initChart() {
  const ctx = document.getElementById("metricsChart").getContext("2d");
  metricsChart = new Chart(ctx, {
    type: "line",
    data: {
      labels: [],
      datasets: [
        {
          label: "Views",
          data: [],
          borderColor: "#3c9eff",
          backgroundColor: "rgba(60,158,255,0.1)",
          fill: true,
          tension: 0.4,
          yAxisID: "y",
        },
        {
          label: "Revenue ($)",
          data: [],
          borderColor: "#22d47a",
          backgroundColor: "rgba(34,212,122,0.1)",
          fill: true,
          tension: 0.4,
          yAxisID: "y1",
        },
      ],
    },
    options: {
      responsive: true,
      interaction: { mode: "index", intersect: false },
      plugins: {
        legend: {
          labels: { color: "#6b6b80", font: { family: "Inter", size: 11 } },
        },
        tooltip: {
          backgroundColor: "#1a1a24",
          borderColor: "#252535",
          borderWidth: 1,
          titleColor: "#e8e8f0",
          bodyColor: "#e8e8f0",
        },
      },
      scales: {
        x:  { ticks: { color: "#6b6b80", font: { size: 10 } }, grid: { color: "#1a1a24" } },
        y:  { ticks: { color: "#6b6b80", font: { size: 10 } }, grid: { color: "#1a1a24" }, position: "left" },
        y1: { ticks: { color: "#22d47a", font: { size: 10 } }, grid: { display: false }, position: "right" },
      },
    },
  });
}

function updateChart(rows) {
  if (!rows || !metricsChart) return;
  metricsChart.data.labels = rows.map(r => r.day.slice(5));   // MM-DD
  metricsChart.data.datasets[0].data = rows.map(r => r.views || 0);
  metricsChart.data.datasets[1].data = rows.map(r => +(r.revenue || 0).toFixed(2));
  metricsChart.update();
}

// ── Log box ───────────────────────────────────────────────
function appendLog(msg, type = "") {
  const box = document.getElementById("logBox");
  const line = document.createElement("div");
  line.className = `log-entry ${type}`;
  const ts = new Date().toLocaleTimeString();
  line.textContent = `[${ts}] ${msg}`;
  box.appendChild(line);
  box.scrollTop = box.scrollHeight;
  // Keep max 60 entries
  while (box.children.length > 60) box.removeChild(box.firstChild);
}

// ── Helpers ───────────────────────────────────────────────
function esc(s) {
  return String(s)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}

function fmt(n) {
  if (!n) return "0";
  if (n >= 1_000_000) return (n / 1_000_000).toFixed(1) + "M";
  if (n >= 1_000)     return (n / 1_000).toFixed(1) + "K";
  return String(n);
}

function fmtTime(iso) {
  try {
    return new Date(iso).toLocaleString(undefined, {
      month: "short", day: "numeric", hour: "2-digit", minute: "2-digit",
    });
  } catch { return iso; }
}
