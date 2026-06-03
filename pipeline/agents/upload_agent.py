"""
Upload Agent — publishes finished videos to YouTube and TikTok.

YouTube: Google Data API v3 (OAuth2 with stored refresh token).
TikTok:  TikTok for Business Content Posting API v2.
"""
import json
import logging
import os
from pathlib import Path
from typing import Optional

import requests

import sys
sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))
from config import (
    YOUTUBE_CLIENT_ID, YOUTUBE_CLIENT_SECRET, YOUTUBE_REFRESH_TOKEN,
    TIKTOK_ACCESS_TOKEN, TIKTOK_OPEN_ID,
)

log = logging.getLogger(__name__)

# ── YouTube ───────────────────────────────────────────────

def _get_youtube_access_token() -> Optional[str]:
    if not all([YOUTUBE_CLIENT_ID, YOUTUBE_CLIENT_SECRET, YOUTUBE_REFRESH_TOKEN]):
        log.warning("YouTube credentials not configured — skipping upload")
        return None
    resp = requests.post(
        "https://oauth2.googleapis.com/token",
        data={
            "client_id": YOUTUBE_CLIENT_ID,
            "client_secret": YOUTUBE_CLIENT_SECRET,
            "refresh_token": YOUTUBE_REFRESH_TOKEN,
            "grant_type": "refresh_token",
        },
        timeout=15,
    )
    if resp.status_code != 200:
        log.error("Failed to refresh YouTube token: %s", resp.text)
        return None
    return resp.json().get("access_token")


def upload_to_youtube(
    video_path: Path,
    thumbnail_path: Optional[Path],
    title: str,
    description: str,
    tags: list[str],
    fmt: str = "short",
    scheduled_at: Optional[str] = None,  # ISO-8601 UTC string
) -> dict:
    """
    Upload a video to YouTube.
    Returns {"platform_id": "...", "url": "..."} or {"error": "..."}.
    """
    token = _get_youtube_access_token()
    if not token:
        return {"error": "No YouTube token"}

    # Category 26 = Howto & Style, 28 = Science & Tech, 22 = People & Blogs
    category_id = "22"
    is_short = fmt == "short"

    # Compose snippet & status
    snippet = {
        "title": title[:100],
        "description": description[:5000],
        "tags": tags[:500],
        "categoryId": category_id,
    }
    status: dict = {"privacyStatus": "private" if scheduled_at else "public"}
    if scheduled_at:
        status["privacyStatus"] = "private"
        status["publishAt"] = scheduled_at

    # Step 1 — initiate resumable upload
    init_resp = requests.post(
        "https://www.googleapis.com/upload/youtube/v3/videos"
        "?uploadType=resumable&part=snippet,status",
        headers={
            "Authorization": f"Bearer {token}",
            "Content-Type": "application/json",
            "X-Upload-Content-Type": "video/mp4",
        },
        json={"snippet": snippet, "status": status},
        timeout=30,
    )
    if init_resp.status_code != 200:
        log.error("YouTube init upload failed: %s", init_resp.text)
        return {"error": init_resp.text}

    upload_url = init_resp.headers.get("Location")
    if not upload_url:
        return {"error": "No upload URL in response"}

    # Step 2 — stream the file
    file_size = video_path.stat().st_size
    with open(video_path, "rb") as f:
        upload_resp = requests.put(
            upload_url,
            data=f,
            headers={
                "Content-Length": str(file_size),
                "Content-Type": "video/mp4",
            },
            timeout=600,
        )

    if upload_resp.status_code not in (200, 201):
        log.error("YouTube upload body failed: %s", upload_resp.text)
        return {"error": upload_resp.text}

    video_data = upload_resp.json()
    video_id = video_data.get("id", "")
    url = f"https://www.youtube.com/{'shorts/' if is_short else 'watch?v='}{video_id}"

    # Step 3 — upload thumbnail (best-effort)
    if thumbnail_path and thumbnail_path.exists():
        _upload_youtube_thumbnail(token, video_id, thumbnail_path)

    log.info("YouTube upload success: %s", url)
    return {"platform_id": video_id, "url": url}


def _upload_youtube_thumbnail(token: str, video_id: str, thumb_path: Path):
    try:
        with open(thumb_path, "rb") as f:
            requests.post(
                f"https://www.googleapis.com/upload/youtube/v3/thumbnails/set"
                f"?videoId={video_id}&uploadType=media",
                headers={
                    "Authorization": f"Bearer {token}",
                    "Content-Type": "image/jpeg",
                },
                data=f,
                timeout=60,
            )
    except Exception as e:
        log.warning("Thumbnail upload failed: %s", e)


# ── TikTok ────────────────────────────────────────────────

def upload_to_tiktok(
    video_path: Path,
    title: str,
    description: str,
    hashtags: list[str],
    scheduled_at: Optional[str] = None,
) -> dict:
    """
    Upload a video to TikTok using the Content Posting API.
    Returns {"platform_id": "...", "url": "..."} or {"error": "..."}.
    """
    if not TIKTOK_ACCESS_TOKEN:
        log.warning("TIKTOK_ACCESS_TOKEN not configured — skipping upload")
        return {"error": "No TikTok token"}

    headers = {
        "Authorization": f"Bearer {TIKTOK_ACCESS_TOKEN}",
        "Content-Type": "application/json",
    }
    file_size = video_path.stat().st_size

    # Caption: title + description + hashtags (TikTok limit = 2200 chars)
    caption_parts = [title]
    if description:
        caption_parts.append(description[:200])
    caption_parts.append(" ".join(f"#{h}" for h in hashtags[:10]))
    caption = " ".join(caption_parts)[:2200]

    # Step 1 — request video init
    init_payload: dict = {
        "post_info": {
            "title": caption,
            "privacy_level": "PUBLIC_TO_EVERYONE",
            "disable_duet": False,
            "disable_comment": False,
            "disable_stitch": False,
        },
        "source_info": {
            "source": "FILE_UPLOAD",
            "video_size": file_size,
            "chunk_size": file_size,
            "total_chunk_count": 1,
        },
    }
    if scheduled_at:
        # TikTok wants a Unix timestamp
        from datetime import datetime, timezone
        try:
            dt = datetime.fromisoformat(scheduled_at.replace("Z", "+00:00"))
            init_payload["post_info"]["privacy_level"] = "SELF_ONLY"
            init_payload["post_info"]["scheduled_publish_time"] = int(dt.timestamp())
        except ValueError:
            pass

    init_resp = requests.post(
        "https://open.tiktokapis.com/v2/post/publish/video/init/",
        headers=headers,
        json=init_payload,
        timeout=30,
    )
    if init_resp.status_code != 200:
        log.error("TikTok init failed: %s", init_resp.text)
        return {"error": init_resp.text}

    init_data = init_resp.json().get("data", {})
    publish_id = init_data.get("publish_id", "")
    upload_url = init_data.get("upload_url", "")

    if not upload_url:
        return {"error": "No TikTok upload URL"}

    # Step 2 — upload the file
    with open(video_path, "rb") as f:
        upload_resp = requests.put(
            upload_url,
            data=f,
            headers={
                "Content-Type": "video/mp4",
                "Content-Length": str(file_size),
                "Content-Range": f"bytes 0-{file_size - 1}/{file_size}",
            },
            timeout=600,
        )

    if upload_resp.status_code not in (200, 201, 206):
        log.error("TikTok upload failed: %s", upload_resp.text)
        return {"error": upload_resp.text}

    url = f"https://www.tiktok.com/@me/video/{publish_id}"
    log.info("TikTok upload success: publish_id=%s", publish_id)
    return {"platform_id": publish_id, "url": url}


# ── Unified upload interface ──────────────────────────────

def upload(
    video_path: str,
    thumbnail_path: str,
    platform: str,
    title: str,
    description: str,
    tags: list[str],
    fmt: str = "short",
    scheduled_at: Optional[str] = None,
) -> dict:
    """
    Route an upload to the appropriate platform.
    platform: "youtube" | "tiktok"
    """
    vp = Path(video_path)
    tp = Path(thumbnail_path) if thumbnail_path else None

    if platform == "youtube":
        return upload_to_youtube(vp, tp, title, description, tags, fmt, scheduled_at)
    if platform == "tiktok":
        return upload_to_tiktok(vp, title, description, tags, scheduled_at)

    return {"error": f"Unknown platform: {platform}"}
