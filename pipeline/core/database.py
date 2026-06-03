"""SQLite persistence layer for the pipeline."""
import sqlite3
import json
import logging
from datetime import datetime, timezone
from contextlib import contextmanager
from pathlib import Path
from typing import Optional

from config import DB_PATH

log = logging.getLogger(__name__)


def _utcnow() -> str:
    return datetime.now(timezone.utc).isoformat()


@contextmanager
def _conn():
    con = sqlite3.connect(DB_PATH)
    con.row_factory = sqlite3.Row
    con.execute("PRAGMA journal_mode=WAL")
    try:
        yield con
        con.commit()
    except Exception:
        con.rollback()
        raise
    finally:
        con.close()


def init_db():
    with _conn() as con:
        con.executescript("""
        CREATE TABLE IF NOT EXISTS topics (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            title       TEXT NOT NULL,
            source      TEXT,          -- youtube | tiktok | reddit | ai
            trend_score REAL DEFAULT 0,
            keywords    TEXT,          -- JSON list
            notes       TEXT,
            created_at  TEXT NOT NULL,
            used        INTEGER DEFAULT 0
        );

        CREATE TABLE IF NOT EXISTS scripts (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            topic_id        INTEGER REFERENCES topics(id),
            format          TEXT NOT NULL,  -- short | long
            hook            TEXT,
            body            TEXT,
            cta             TEXT,
            full_text       TEXT,
            word_count      INTEGER,
            estimated_secs  INTEGER,
            hashtags        TEXT,   -- JSON list
            seo_title       TEXT,
            seo_description TEXT,
            created_at      TEXT NOT NULL,
            status          TEXT DEFAULT 'pending'  -- pending|scripted|video_ready|uploaded|failed
        );

        CREATE TABLE IF NOT EXISTS videos (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            script_id     INTEGER REFERENCES scripts(id),
            format        TEXT NOT NULL,
            audio_path    TEXT,
            video_path    TEXT,
            thumbnail_path TEXT,
            duration_secs REAL,
            created_at    TEXT NOT NULL,
            status        TEXT DEFAULT 'pending'  -- pending|rendering|ready|uploaded|failed
        );

        CREATE TABLE IF NOT EXISTS uploads (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            video_id     INTEGER REFERENCES videos(id),
            platform     TEXT NOT NULL,  -- youtube | tiktok
            platform_id  TEXT,           -- YouTube videoId / TikTok itemId
            url          TEXT,
            title        TEXT,
            description  TEXT,
            tags         TEXT,           -- JSON list
            scheduled_at TEXT,
            published_at TEXT,
            status       TEXT DEFAULT 'queued',  -- queued|scheduled|published|failed
            created_at   TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS metrics (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            upload_id   INTEGER REFERENCES uploads(id),
            fetched_at  TEXT NOT NULL,
            views       INTEGER DEFAULT 0,
            likes       INTEGER DEFAULT 0,
            comments    INTEGER DEFAULT 0,
            shares      INTEGER DEFAULT 0,
            watch_time_mins REAL DEFAULT 0,
            est_revenue_usd REAL DEFAULT 0
        );

        CREATE TABLE IF NOT EXISTS pipeline_runs (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            started_at TEXT NOT NULL,
            ended_at   TEXT,
            status     TEXT DEFAULT 'running',  -- running|done|failed
            topics_found    INTEGER DEFAULT 0,
            scripts_written INTEGER DEFAULT 0,
            videos_made     INTEGER DEFAULT 0,
            uploads_done    INTEGER DEFAULT 0,
            error_msg  TEXT
        );
        """)
    log.info("Database initialised at %s", DB_PATH)


# ── Topics ────────────────────────────────────────────────

def save_topic(title: str, source: str, trend_score: float,
               keywords: list[str], notes: str = "") -> int:
    with _conn() as con:
        cur = con.execute(
            "INSERT INTO topics (title,source,trend_score,keywords,notes,created_at) "
            "VALUES (?,?,?,?,?,?)",
            (title, source, trend_score, json.dumps(keywords), notes, _utcnow()),
        )
        return cur.lastrowid


def get_unused_topics(limit: int = 5) -> list[dict]:
    with _conn() as con:
        rows = con.execute(
            "SELECT * FROM topics WHERE used=0 ORDER BY trend_score DESC LIMIT ?",
            (limit,),
        ).fetchall()
        return [dict(r) for r in rows]


def mark_topic_used(topic_id: int):
    with _conn() as con:
        con.execute("UPDATE topics SET used=1 WHERE id=?", (topic_id,))


# ── Scripts ───────────────────────────────────────────────

def save_script(topic_id: int, fmt: str, hook: str, body: str, cta: str,
                full_text: str, word_count: int, estimated_secs: int,
                hashtags: list[str], seo_title: str, seo_description: str) -> int:
    with _conn() as con:
        cur = con.execute(
            "INSERT INTO scripts "
            "(topic_id,format,hook,body,cta,full_text,word_count,estimated_secs,"
            "hashtags,seo_title,seo_description,created_at) "
            "VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
            (topic_id, fmt, hook, body, cta, full_text, word_count, estimated_secs,
             json.dumps(hashtags), seo_title, seo_description, _utcnow()),
        )
        return cur.lastrowid


def get_pending_scripts(fmt: Optional[str] = None) -> list[dict]:
    with _conn() as con:
        if fmt:
            rows = con.execute(
                "SELECT * FROM scripts WHERE status='pending' AND format=? ORDER BY id",
                (fmt,),
            ).fetchall()
        else:
            rows = con.execute(
                "SELECT * FROM scripts WHERE status='pending' ORDER BY id",
            ).fetchall()
        return [dict(r) for r in rows]


def update_script_status(script_id: int, status: str):
    with _conn() as con:
        con.execute("UPDATE scripts SET status=? WHERE id=?", (status, script_id))


# ── Videos ───────────────────────────────────────────────

def save_video(script_id: int, fmt: str, audio_path: str,
               video_path: str, thumbnail_path: str, duration_secs: float) -> int:
    with _conn() as con:
        cur = con.execute(
            "INSERT INTO videos "
            "(script_id,format,audio_path,video_path,thumbnail_path,duration_secs,created_at,status) "
            "VALUES (?,?,?,?,?,?,?,?)",
            (script_id, fmt, audio_path, video_path, thumbnail_path,
             duration_secs, _utcnow(), "ready"),
        )
        return cur.lastrowid


def get_ready_videos() -> list[dict]:
    with _conn() as con:
        rows = con.execute(
            "SELECT * FROM videos WHERE status='ready' ORDER BY id",
        ).fetchall()
        return [dict(r) for r in rows]


def update_video_status(video_id: int, status: str):
    with _conn() as con:
        con.execute("UPDATE videos SET status=? WHERE id=?", (status, video_id))


# ── Uploads ───────────────────────────────────────────────

def save_upload(video_id: int, platform: str, title: str, description: str,
                tags: list[str], scheduled_at: Optional[str] = None) -> int:
    with _conn() as con:
        cur = con.execute(
            "INSERT INTO uploads "
            "(video_id,platform,title,description,tags,scheduled_at,created_at) "
            "VALUES (?,?,?,?,?,?,?)",
            (video_id, platform, title, description, json.dumps(tags),
             scheduled_at, _utcnow()),
        )
        return cur.lastrowid


def mark_upload_published(upload_id: int, platform_id: str, url: str):
    with _conn() as con:
        con.execute(
            "UPDATE uploads SET status='published', platform_id=?, url=?, published_at=? "
            "WHERE id=?",
            (platform_id, url, _utcnow(), upload_id),
        )


def mark_upload_failed(upload_id: int, error: str):
    with _conn() as con:
        con.execute(
            "UPDATE uploads SET status='failed' WHERE id=?", (upload_id,)
        )
        log.error("Upload %d failed: %s", upload_id, error)


# ── Dashboard queries ─────────────────────────────────────

def dashboard_summary() -> dict:
    with _conn() as con:
        def scalar(sql, *params):
            row = con.execute(sql, params).fetchone()
            return row[0] if row else 0

        published = con.execute(
            "SELECT u.id, u.platform, u.url, u.title, u.published_at, "
            "       COALESCE(m.views,0) as views, COALESCE(m.likes,0) as likes, "
            "       COALESCE(m.est_revenue_usd,0) as revenue "
            "FROM uploads u "
            "LEFT JOIN metrics m ON m.upload_id=u.id "
            "WHERE u.status='published' ORDER BY u.published_at DESC LIMIT 20"
        ).fetchall()

        queued = con.execute(
            "SELECT u.id, u.platform, u.title, u.scheduled_at, u.status "
            "FROM uploads u WHERE u.status IN ('queued','scheduled') ORDER BY u.scheduled_at"
        ).fetchall()

        run = con.execute(
            "SELECT * FROM pipeline_runs ORDER BY id DESC LIMIT 1"
        ).fetchone()

        return {
            "topics_total":   scalar("SELECT COUNT(*) FROM topics"),
            "topics_unused":  scalar("SELECT COUNT(*) FROM topics WHERE used=0"),
            "scripts_total":  scalar("SELECT COUNT(*) FROM scripts"),
            "videos_total":   scalar("SELECT COUNT(*) FROM videos"),
            "uploads_published": scalar("SELECT COUNT(*) FROM uploads WHERE status='published'"),
            "uploads_queued": scalar("SELECT COUNT(*) FROM uploads WHERE status IN ('queued','scheduled')"),
            "total_views":    scalar("SELECT COALESCE(SUM(views),0) FROM metrics"),
            "total_revenue":  scalar("SELECT COALESCE(SUM(est_revenue_usd),0) FROM metrics"),
            "published":      [dict(r) for r in published],
            "queued":         [dict(r) for r in queued],
            "last_run":       dict(run) if run else None,
        }


def recent_metrics_chart() -> list[dict]:
    """Return last 30 days of aggregate daily views for charting."""
    with _conn() as con:
        rows = con.execute("""
            SELECT substr(fetched_at,1,10) as day,
                   SUM(views) as views,
                   SUM(est_revenue_usd) as revenue
            FROM metrics
            WHERE fetched_at >= date('now','-30 days')
            GROUP BY day ORDER BY day
        """).fetchall()
        return [dict(r) for r in rows]


# ── Pipeline runs ─────────────────────────────────────────

def start_run() -> int:
    with _conn() as con:
        cur = con.execute(
            "INSERT INTO pipeline_runs (started_at) VALUES (?)", (_utcnow(),)
        )
        return cur.lastrowid


def finish_run(run_id: int, status: str, topics: int = 0, scripts: int = 0,
               videos: int = 0, uploads: int = 0, error: str = ""):
    with _conn() as con:
        con.execute(
            "UPDATE pipeline_runs SET ended_at=?,status=?,topics_found=?,"
            "scripts_written=?,videos_made=?,uploads_done=?,error_msg=? WHERE id=?",
            (_utcnow(), status, topics, scripts, videos, uploads, error, run_id),
        )
