"""
Video Agent — assembles motivational videos from scripts.

Pipeline:
  1. ElevenLabs TTS  → audio file (.mp3)
  2. Pexels API      → stock video clips matching topic keywords
  3. ffmpeg          → merge clips + audio, add captions burn-in, colour grade
  4. Pillow          → generate thumbnail
"""
import json
import logging
import os
import shutil
import subprocess
import tempfile
import textwrap
from pathlib import Path
from typing import Optional

import requests
from PIL import Image, ImageDraw, ImageFont

import sys
sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))
from config import (
    ELEVENLABS_API_KEY, ELEVENLABS_VOICE_ID, ELEVENLABS_VOICE_ID_FEMALE,
    PEXELS_API_KEY, OUTPUT_DIR, ANTHROPIC_API_KEY,
)

log = logging.getLogger(__name__)

# ── ElevenLabs ────────────────────────────────────────────

def generate_voiceover(script_text: str, output_path: Path,
                       voice_id: str = ELEVENLABS_VOICE_ID) -> bool:
    """Call ElevenLabs TTS and save to output_path (.mp3)."""
    if not ELEVENLABS_API_KEY:
        log.warning("ELEVENLABS_API_KEY not set — using silent placeholder audio")
        return _create_silent_audio(output_path, duration_secs=10)

    url = f"https://api.elevenlabs.io/v1/text-to-speech/{voice_id}"
    headers = {
        "xi-api-key": ELEVENLABS_API_KEY,
        "Content-Type": "application/json",
        "Accept": "audio/mpeg",
    }
    payload = {
        "text": script_text,
        "model_id": "eleven_turbo_v2",
        "voice_settings": {
            "stability": 0.45,
            "similarity_boost": 0.80,
            "style": 0.35,
            "use_speaker_boost": True,
        },
    }
    try:
        resp = requests.post(url, json=payload, headers=headers, timeout=120)
        resp.raise_for_status()
        output_path.write_bytes(resp.content)
        log.info("Voiceover saved: %s (%.1f KB)", output_path, len(resp.content) / 1024)
        return True
    except Exception as e:
        log.error("ElevenLabs error: %s", e)
        return False


def _create_silent_audio(path: Path, duration_secs: int = 45) -> bool:
    """Create a silent audio file via ffmpeg for testing without API key."""
    try:
        subprocess.run(
            ["ffmpeg", "-y", "-f", "lavfi", "-i", f"anullsrc=r=44100:cl=stereo",
             "-t", str(duration_secs), "-q:a", "9", "-acodec", "libmp3lame", str(path)],
            capture_output=True, check=True,
        )
        return True
    except Exception as e:
        log.error("Silent audio creation failed: %s", e)
        return False


# ── Pexels stock footage ──────────────────────────────────

def fetch_stock_clips(keywords: list[str], target_duration_secs: int,
                      tmp_dir: Path, orientation: str = "portrait") -> list[Path]:
    """
    Download stock video clips from Pexels that total at least target_duration_secs.
    orientation: "portrait" (9:16) for Shorts/TikTok, "landscape" (16:9) for YouTube.
    """
    if not PEXELS_API_KEY:
        log.warning("PEXELS_API_KEY not set — using colour placeholder clips")
        return _generate_placeholder_clips(tmp_dir, target_duration_secs, orientation)

    headers = {"Authorization": PEXELS_API_KEY}
    collected: list[Path] = []
    total_secs = 0

    for keyword in keywords:
        if total_secs >= target_duration_secs:
            break
        try:
            params = {
                "query": keyword,
                "per_page": 5,
                "orientation": orientation,
                "size": "medium",
            }
            r = requests.get(
                "https://api.pexels.com/videos/search",
                headers=headers, params=params, timeout=15,
            )
            r.raise_for_status()
            videos = r.json().get("videos", [])

            for vid in videos:
                if total_secs >= target_duration_secs:
                    break
                duration = vid.get("duration", 0)
                # Pick smallest file that's ≥ what we need
                files = sorted(vid.get("video_files", []),
                               key=lambda f: f.get("file_size", 0))
                best = next(
                    (f for f in files if f.get("width", 0) >= 720),
                    files[0] if files else None,
                )
                if not best:
                    continue
                clip_path = tmp_dir / f"clip_{len(collected)}.mp4"
                _download_file(best["link"], clip_path)
                collected.append(clip_path)
                total_secs += duration
        except Exception as e:
            log.warning("Pexels fetch error for '%s': %s", keyword, e)

    if not collected:
        return _generate_placeholder_clips(tmp_dir, target_duration_secs, orientation)

    return collected


def _download_file(url: str, dest: Path):
    r = requests.get(url, stream=True, timeout=60)
    r.raise_for_status()
    with open(dest, "wb") as f:
        for chunk in r.iter_content(chunk_size=65536):
            f.write(chunk)
    log.debug("Downloaded %s (%.1f MB)", dest.name, dest.stat().st_size / 1_048_576)


def _generate_placeholder_clips(tmp_dir: Path, target_secs: int,
                                 orientation: str) -> list[Path]:
    """Create coloured test clips when Pexels API is unavailable."""
    w, h = (1080, 1920) if orientation == "portrait" else (1920, 1080)
    colours = ["black", "0x111827", "0x1f2937", "0x111827"]
    clips = []
    per_clip = max(5, target_secs // 3)
    for i, colour in enumerate(colours[:3]):
        p = tmp_dir / f"placeholder_{i}.mp4"
        subprocess.run([
            "ffmpeg", "-y",
            "-f", "lavfi", "-i", f"color=c={colour}:s={w}x{h}:r=30",
            "-t", str(per_clip), "-c:v", "libx264", "-pix_fmt", "yuv420p", str(p),
        ], capture_output=True)
        if p.exists():
            clips.append(p)
    return clips


# ── Thumbnail generation ──────────────────────────────────

def generate_thumbnail(thumbnail_text: str, output_path: Path,
                        fmt: str = "short") -> bool:
    """Render a high-contrast thumbnail with bold text overlay."""
    try:
        w, h = (1280, 720) if fmt == "long" else (1080, 1920)
        img = Image.new("RGB", (w, h), color=(10, 10, 20))
        draw = ImageDraw.Draw(img)

        # Gradient-like effect
        for y in range(h):
            alpha = int(30 + 60 * (y / h))
            draw.line([(0, y), (w, y)], fill=(alpha, 0, alpha))

        # Neon accent bar
        draw.rectangle([(0, h // 2 - 8), (w, h // 2 + 8)], fill=(255, 50, 50))

        # Text
        font_size = max(80, w // 8)
        try:
            font = ImageFont.truetype("/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf",
                                      font_size)
        except OSError:
            font = ImageFont.load_default()

        lines = textwrap.wrap(thumbnail_text.upper(), width=12)
        total_height = len(lines) * (font_size + 20)
        y_start = (h - total_height) // 2

        for i, line in enumerate(lines):
            bbox = draw.textbbox((0, 0), line, font=font)
            text_w = bbox[2] - bbox[0]
            x = (w - text_w) // 2
            y = y_start + i * (font_size + 20)
            # Shadow
            draw.text((x + 4, y + 4), line, font=font, fill=(0, 0, 0, 180))
            draw.text((x, y), line, font=font, fill=(255, 255, 255))

        img.save(output_path, quality=95)
        log.info("Thumbnail saved: %s", output_path)
        return True
    except Exception as e:
        log.error("Thumbnail generation failed: %s", e)
        return False


# ── ffmpeg assembly ───────────────────────────────────────

def assemble_video(clips: list[Path], audio_path: Path,
                   output_path: Path, fmt: str = "short") -> Optional[float]:
    """
    Concatenate stock clips, mix in voiceover, and export final video.
    Returns the video duration in seconds, or None on failure.
    """
    with tempfile.TemporaryDirectory() as tmp:
        tmp = Path(tmp)

        # Get audio duration
        audio_dur = _get_duration(audio_path)
        if not audio_dur:
            log.error("Cannot determine audio duration")
            return None

        # Build concat list — loop clips until we have enough footage
        concat_file = tmp / "concat.txt"
        lines = []
        total = 0.0
        idx = 0
        while total < audio_dur + 2:
            clip = clips[idx % len(clips)]
            dur = _get_duration(clip) or 5.0
            lines.append(f"file '{clip}'\n")
            total += dur
            idx += 1
        concat_file.write_text("".join(lines))

        # Concatenate video
        raw_video = tmp / "raw.mp4"
        _run_ffmpeg([
            "-y", "-f", "concat", "-safe", "0", "-i", str(concat_file),
            "-t", str(audio_dur + 0.5),
            "-vf", _video_filter(fmt),
            "-c:v", "libx264", "-preset", "fast", "-crf", "23",
            "-pix_fmt", "yuv420p", "-an", str(raw_video),
        ])

        if not raw_video.exists():
            log.error("Raw video concat failed")
            return None

        # Merge audio
        _run_ffmpeg([
            "-y",
            "-i", str(raw_video),
            "-i", str(audio_path),
            "-map", "0:v", "-map", "1:a",
            "-c:v", "copy", "-c:a", "aac", "-b:a", "192k",
            "-shortest",
            str(output_path),
        ])

    if not output_path.exists():
        log.error("Final video not created")
        return None

    duration = _get_duration(output_path)
    log.info("Video assembled: %s (%.1fs)", output_path.name, duration or 0)
    return duration


def _video_filter(fmt: str) -> str:
    """Return ffmpeg -vf filter string based on format."""
    if fmt == "short":
        # 9:16 portrait, 1080×1920
        return (
            "scale=1080:1920:force_original_aspect_ratio=increase,"
            "crop=1080:1920,"
            "eq=contrast=1.1:brightness=0.02:saturation=1.2"
        )
    # 16:9 landscape, 1920×1080
    return (
        "scale=1920:1080:force_original_aspect_ratio=increase,"
        "crop=1920:1080,"
        "eq=contrast=1.05:brightness=0.01:saturation=1.1"
    )


def _run_ffmpeg(args: list[str]):
    cmd = ["ffmpeg"] + args
    result = subprocess.run(cmd, capture_output=True, text=True)
    if result.returncode != 0:
        log.debug("ffmpeg stderr: %s", result.stderr[-2000:])


def _get_duration(path: Path) -> Optional[float]:
    try:
        result = subprocess.run(
            ["ffprobe", "-v", "quiet", "-print_format", "json",
             "-show_format", str(path)],
            capture_output=True, text=True, check=True,
        )
        data = json.loads(result.stdout)
        return float(data["format"]["duration"])
    except Exception:
        return None


# ── Main entrypoint ───────────────────────────────────────

def run(script_id: int, script_data: dict, topic: dict, fmt: str) -> dict | None:
    """
    Full video assembly for one script.
    Returns {audio_path, video_path, thumbnail_path, duration_secs} or None.
    """
    slug = f"{script_id}_{fmt}"
    out_dir = OUTPUT_DIR / slug
    out_dir.mkdir(parents=True, exist_ok=True)

    audio_path = out_dir / "voiceover.mp3"
    video_path = out_dir / "video.mp4"
    thumb_path = out_dir / "thumbnail.jpg"

    orientation = "portrait" if fmt == "short" else "landscape"
    keywords = topic.get("keywords", [topic.get("title", "motivation")])

    # 1. Voiceover
    if not generate_voiceover(script_data["full_script_text"], audio_path):
        log.error("Voiceover failed for script %d", script_id)
        return None

    # 2. Stock footage
    target_dur = script_data.get("estimated_duration_secs", 45 if fmt == "short" else 420)
    with tempfile.TemporaryDirectory() as tmp:
        clips = fetch_stock_clips(keywords, target_dur, Path(tmp), orientation)
        if not clips:
            log.error("No clips available for script %d", script_id)
            return None

        # 3. Assemble
        duration = assemble_video(clips, audio_path, video_path, fmt)

    if not duration:
        return None

    # 4. Thumbnail
    generate_thumbnail(script_data.get("thumbnail_text", topic["title"]), thumb_path, fmt)

    return {
        "audio_path": str(audio_path),
        "video_path": str(video_path),
        "thumbnail_path": str(thumb_path),
        "duration_secs": duration,
    }
