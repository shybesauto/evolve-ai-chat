"""
Pipeline orchestrator — runs the full research → script → video → upload flow.

Can be invoked manually (python pipeline/main.py run) or called by the
APScheduler job that the dashboard starts.
"""
import json
import logging
from datetime import datetime, timezone
from typing import Optional

import sys, os
sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))

from agents import research_agent, script_agent, video_agent, upload_agent
from core import database as db
from core import scheduler
from config import RESEARCH_INTERVAL_HOURS, MAX_QUEUE_SIZE

log = logging.getLogger(__name__)

# ── State ─────────────────────────────────────────────────

_running = False
_current_stage: str = "idle"
_progress_pct: int = 0


def is_running() -> bool:
    return _running


def current_status() -> dict:
    return {
        "running": _running,
        "stage": _current_stage,
        "progress": _progress_pct,
    }


def _set_stage(stage: str, pct: int = 0):
    global _current_stage, _progress_pct
    _current_stage = stage
    _progress_pct = pct
    log.info("[pipeline] %s (%d%%)", stage, pct)


# ── Stage 1: Research ─────────────────────────────────────

def stage_research(num_topics: int = 5) -> list[int]:
    """Find trending topics and save them. Returns list of saved topic IDs."""
    _set_stage("research", 5)
    topics = research_agent.run(num_topics=num_topics)
    saved_ids = []
    for t in topics:
        tid = db.save_topic(
            title=t["title"],
            source=t["source"],
            trend_score=t["trend_score"],
            keywords=t["keywords"],
            notes=t.get("notes", ""),
        )
        saved_ids.append(tid)
        log.info("Saved topic %d: %s (score %.1f)", tid, t["title"], t["trend_score"])
    return saved_ids


# ── Stage 2: Script generation ────────────────────────────

def stage_scripts(topic_ids: list[int], formats: tuple = ("short", "long")) -> list[int]:
    """Generate scripts for each topic. Returns list of script IDs."""
    _set_stage("scripting", 20)
    all_topics = db.get_unused_topics(limit=len(topic_ids) + 10)
    topic_map = {t["id"]: t for t in all_topics}

    script_ids = []
    for i, tid in enumerate(topic_ids):
        topic = topic_map.get(tid)
        if not topic:
            continue
        results = script_agent.run(topic, formats=list(formats))
        for r in results:
            fmt = r["format"]
            sd = r["script_data"]
            sid = db.save_script(
                topic_id=tid,
                fmt=fmt,
                hook=sd.get("hook", ""),
                body=json.dumps(sd.get("body_segments", [])),
                cta=sd.get("cta", ""),
                full_text=sd.get("full_script_text", ""),
                word_count=sd.get("word_count", 0),
                estimated_secs=sd.get("estimated_duration_secs", 0),
                hashtags=sd.get("hashtags", []),
                seo_title=sd.get("seo_title", topic["title"]),
                seo_description=sd.get("seo_description", ""),
            )
            script_ids.append(sid)
            log.info("Saved script %d (%s) for topic '%s'", sid, fmt, topic["title"])

        db.mark_topic_used(tid)
        pct = 20 + int(40 * (i + 1) / max(len(topic_ids), 1))
        _set_stage("scripting", pct)

    return script_ids


# ── Stage 3: Video assembly ───────────────────────────────

def stage_videos(script_ids: list[int]) -> list[int]:
    """Assemble videos for each script. Returns list of video IDs."""
    _set_stage("video_assembly", 60)
    pending = db.get_pending_scripts()
    pending_map = {s["id"]: s for s in pending}

    # Only process scripts we just created
    target = [pending_map[sid] for sid in script_ids if sid in pending_map]

    # Also grab any pre-existing pending scripts
    for s in pending:
        if s["id"] not in script_ids:
            target.append(s)

    video_ids = []
    for i, script in enumerate(target):
        sid = script["id"]
        fmt = script["format"]

        # Need the topic for keywords
        all_topics = db.get_unused_topics(limit=100)
        # topic may already be marked used — fetch directly
        import sqlite3
        from config import DB_PATH
        con = sqlite3.connect(DB_PATH)
        con.row_factory = sqlite3.Row
        topic_row = con.execute("SELECT * FROM topics WHERE id=?",
                                (script["topic_id"],)).fetchone()
        con.close()
        topic = dict(topic_row) if topic_row else {"title": "Motivation", "keywords": []}
        if isinstance(topic.get("keywords"), str):
            try:
                topic["keywords"] = json.loads(topic["keywords"])
            except Exception:
                topic["keywords"] = []

        db.update_script_status(sid, "video_in_progress")
        result = video_agent.run(sid, script, topic, fmt)

        if result:
            vid = db.save_video(
                script_id=sid,
                fmt=fmt,
                audio_path=result["audio_path"],
                video_path=result["video_path"],
                thumbnail_path=result["thumbnail_path"],
                duration_secs=result["duration_secs"],
            )
            video_ids.append(vid)
            db.update_script_status(sid, "video_ready")
            log.info("Video %d ready: %s", vid, result["video_path"])
        else:
            db.update_script_status(sid, "failed")
            log.error("Video assembly failed for script %d", sid)

        pct = 60 + int(25 * (i + 1) / max(len(target), 1))
        _set_stage("video_assembly", pct)

    return video_ids


# ── Stage 4: Upload & scheduling ──────────────────────────

def stage_uploads(video_ids: list[int], platforms: list[str] = ("youtube", "tiktok")) -> int:
    """Queue videos for upload. Returns count of uploads initiated."""
    _set_stage("uploading", 85)
    ready = db.get_ready_videos()
    ready_map = {v["id"]: v for v in ready}

    # Only upload the ones we just made
    target = [ready_map[vid] for vid in video_ids if vid in ready_map]

    count = 0
    for video in target:
        vid = video["id"]
        fmt = video["format"]

        # Fetch script for metadata
        import sqlite3
        from config import DB_PATH
        con = sqlite3.connect(DB_PATH)
        con.row_factory = sqlite3.Row
        script_row = con.execute("SELECT * FROM scripts WHERE id=?",
                                 (video["script_id"],)).fetchone()
        con.close()
        if not script_row:
            continue
        script = dict(script_row)
        tags = json.loads(script.get("hashtags", "[]"))
        title = script.get("seo_title", "Motivational Video")
        description = script.get("seo_description", "")

        for platform in platforms:
            scheduled_at = scheduler.schedule_upload(platform)
            upload_id = db.save_upload(
                video_id=vid,
                platform=platform,
                title=title,
                description=description,
                tags=tags,
                scheduled_at=scheduled_at,
            )

            # Attempt immediate upload (platform decides publish time via scheduling)
            result = upload_agent.upload(
                video_path=video["video_path"],
                thumbnail_path=video["thumbnail_path"],
                platform=platform,
                title=title,
                description=description,
                tags=tags,
                fmt=fmt,
                scheduled_at=scheduled_at,
            )

            if "error" in result:
                db.mark_upload_failed(upload_id, result["error"])
            else:
                db.mark_upload_published(
                    upload_id, result["platform_id"], result["url"]
                )
                db.update_video_status(vid, "uploaded")
                count += 1

    return count


# ── Full pipeline run ─────────────────────────────────────

def run_pipeline(
    num_topics: int = 5,
    formats: tuple = ("short", "long"),
    platforms: list = ("youtube", "tiktok"),
    skip_research: bool = False,
    skip_video: bool = False,
    skip_upload: bool = False,
) -> dict:
    """
    Execute the full pipeline.
    Returns a summary dict with counts for each stage.
    """
    global _running
    if _running:
        return {"error": "Pipeline already running"}

    _running = True
    run_id = db.start_run()
    log.info("=== Pipeline run %d started ===", run_id)

    topics_found = scripts_written = videos_made = uploads_done = 0

    try:
        # Stage 1
        topic_ids: list[int] = []
        if not skip_research:
            topic_ids = stage_research(num_topics)
            topics_found = len(topic_ids)
        else:
            # Use existing unused topics
            existing = db.get_unused_topics(limit=num_topics)
            topic_ids = [t["id"] for t in existing]
            topics_found = len(topic_ids)

        if not topic_ids:
            log.warning("No topics to process — aborting pipeline")
            db.finish_run(run_id, "done", 0, 0, 0, 0, "No topics found")
            return {"topics": 0, "scripts": 0, "videos": 0, "uploads": 0}

        # Stage 2
        script_ids = stage_scripts(topic_ids, formats=formats)
        scripts_written = len(script_ids)

        # Stage 3
        video_ids: list[int] = []
        if not skip_video:
            video_ids = stage_videos(script_ids)
            videos_made = len(video_ids)

        # Stage 4
        if not skip_upload and video_ids:
            uploads_done = stage_uploads(video_ids, platforms=list(platforms))

        _set_stage("done", 100)
        db.finish_run(run_id, "done", topics_found, scripts_written, videos_made, uploads_done)
        log.info("=== Pipeline run %d complete: %d topics, %d scripts, %d videos, %d uploads ===",
                 run_id, topics_found, scripts_written, videos_made, uploads_done)

        return {
            "run_id": run_id,
            "topics": topics_found,
            "scripts": scripts_written,
            "videos": videos_made,
            "uploads": uploads_done,
        }

    except Exception as exc:
        log.exception("Pipeline run %d failed", run_id)
        db.finish_run(run_id, "failed", topics_found, scripts_written, videos_made, 0,
                      str(exc))
        _set_stage("error", 0)
        return {"error": str(exc)}

    finally:
        _running = False
        _set_stage("idle", 0)
