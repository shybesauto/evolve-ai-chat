"""
Flask dashboard for the Motivational Video Pipeline.

Routes:
  GET  /                 — main dashboard UI
  GET  /api/summary      — JSON snapshot (stats, queue, published)
  GET  /api/status       — pipeline running state + stage
  GET  /api/metrics      — 30-day chart data
  POST /api/run          — trigger a pipeline run (background thread)
  POST /api/run/research — research-only run
  POST /api/run/scripts  — script-only run (uses existing topics)
  POST /api/run/videos   — video-only run (uses pending scripts)
  POST /api/run/uploads  — upload-only run (uses ready videos)
  GET  /api/stream       — SSE stream for live status updates
"""
import json
import logging
import queue
import threading
import time
from pathlib import Path

from flask import Flask, Response, jsonify, render_template, request
from flask_cors import CORS

import sys, os
sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))

from config import DASHBOARD_HOST, DASHBOARD_PORT, DASHBOARD_SECRET_KEY
from core import database as db, pipeline

log = logging.getLogger(__name__)

app = Flask(__name__, template_folder="templates", static_folder="static")
app.secret_key = DASHBOARD_SECRET_KEY
CORS(app)

# SSE subscriber queues
_sse_clients: list[queue.Queue] = []
_sse_lock = threading.Lock()


def _broadcast(event_type: str, data: dict):
    msg = f"event: {event_type}\ndata: {json.dumps(data)}\n\n"
    with _sse_lock:
        dead = []
        for q in _sse_clients:
            try:
                q.put_nowait(msg)
            except queue.Full:
                dead.append(q)
        for q in dead:
            _sse_clients.remove(q)


def _run_pipeline_bg(**kwargs):
    """Run pipeline in a background thread and broadcast completion."""
    result = pipeline.run_pipeline(**kwargs)
    _broadcast("pipeline_done", result)
    _broadcast("status", pipeline.current_status())


# ── HTML dashboard ────────────────────────────────────────

@app.route("/")
def index():
    return render_template("dashboard.html")


# ── REST API ──────────────────────────────────────────────

@app.route("/api/summary")
def api_summary():
    return jsonify(db.dashboard_summary())


@app.route("/api/status")
def api_status():
    return jsonify(pipeline.current_status())


@app.route("/api/metrics")
def api_metrics():
    return jsonify(db.recent_metrics_chart())


@app.route("/api/run", methods=["POST"])
def api_run():
    if pipeline.is_running():
        return jsonify({"error": "Pipeline already running"}), 409
    body = request.get_json(silent=True) or {}
    kwargs = {
        "num_topics": int(body.get("num_topics", 5)),
        "formats": tuple(body.get("formats", ["short", "long"])),
        "platforms": list(body.get("platforms", ["youtube", "tiktok"])),
    }
    t = threading.Thread(target=_run_pipeline_bg, kwargs=kwargs, daemon=True)
    t.start()
    _broadcast("status", {"running": True, "stage": "starting", "progress": 0})
    return jsonify({"status": "started"})


@app.route("/api/run/research", methods=["POST"])
def api_run_research():
    if pipeline.is_running():
        return jsonify({"error": "Pipeline already running"}), 409
    kwargs = {
        "skip_video": True, "skip_upload": True,
        "num_topics": int((request.get_json(silent=True) or {}).get("num_topics", 5)),
    }
    threading.Thread(target=_run_pipeline_bg, kwargs=kwargs, daemon=True).start()
    return jsonify({"status": "started"})


@app.route("/api/run/scripts", methods=["POST"])
def api_run_scripts():
    if pipeline.is_running():
        return jsonify({"error": "Pipeline already running"}), 409
    kwargs = {"skip_research": True, "skip_video": True, "skip_upload": True}
    threading.Thread(target=_run_pipeline_bg, kwargs=kwargs, daemon=True).start()
    return jsonify({"status": "started"})


@app.route("/api/run/videos", methods=["POST"])
def api_run_videos():
    if pipeline.is_running():
        return jsonify({"error": "Pipeline already running"}), 409
    kwargs = {"skip_research": True, "skip_upload": True}
    threading.Thread(target=_run_pipeline_bg, kwargs=kwargs, daemon=True).start()
    return jsonify({"status": "started"})


@app.route("/api/run/uploads", methods=["POST"])
def api_run_uploads():
    if pipeline.is_running():
        return jsonify({"error": "Pipeline already running"}), 409
    kwargs = {"skip_research": True, "skip_video": True}
    threading.Thread(target=_run_pipeline_bg, kwargs=kwargs, daemon=True).start()
    return jsonify({"status": "started"})


# ── SSE stream ────────────────────────────────────────────

@app.route("/api/stream")
def api_stream():
    q: queue.Queue = queue.Queue(maxsize=50)
    with _sse_lock:
        _sse_clients.append(q)

    def generate():
        # Send current status immediately
        yield f"event: status\ndata: {json.dumps(pipeline.current_status())}\n\n"
        try:
            while True:
                try:
                    msg = q.get(timeout=25)
                    yield msg
                except queue.Empty:
                    yield ": heartbeat\n\n"
        finally:
            with _sse_lock:
                if q in _sse_clients:
                    _sse_clients.remove(q)

    return Response(
        generate(),
        mimetype="text/event-stream",
        headers={
            "Cache-Control": "no-cache",
            "X-Accel-Buffering": "no",
        },
    )


# ── Status pusher thread ──────────────────────────────────

def _status_pusher():
    """Broadcast pipeline status every 2 seconds while running."""
    while True:
        if pipeline.is_running():
            _broadcast("status", pipeline.current_status())
        time.sleep(2)


threading.Thread(target=_status_pusher, daemon=True).start()


# ── APScheduler for automated runs ───────────────────────

def start_scheduler(interval_hours: float = 6.0):
    from apscheduler.schedulers.background import BackgroundScheduler
    sched = BackgroundScheduler()

    def _scheduled_run():
        if not pipeline.is_running():
            log.info("Scheduled pipeline run starting")
            _run_pipeline_bg()

    sched.add_job(
        _scheduled_run,
        trigger="interval",
        hours=interval_hours,
        id="pipeline_auto",
        replace_existing=True,
    )
    sched.start()
    log.info("Scheduler started — pipeline runs every %.1f hours", interval_hours)
    return sched


if __name__ == "__main__":
    db.init_db()
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s [%(levelname)s] %(name)s: %(message)s",
    )
    from config import AUTO_START, RESEARCH_INTERVAL_HOURS
    if AUTO_START:
        start_scheduler(RESEARCH_INTERVAL_HOURS)
    app.run(host=DASHBOARD_HOST, port=DASHBOARD_PORT, debug=False, threaded=True)
