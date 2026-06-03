import os
from pathlib import Path
from dotenv import load_dotenv

load_dotenv(Path(__file__).parent / ".env")
load_dotenv()  # also check cwd

# ── API keys ──────────────────────────────────────────────
ANTHROPIC_API_KEY = os.getenv("ANTHROPIC_API_KEY", "")
ELEVENLABS_API_KEY = os.getenv("ELEVENLABS_API_KEY", "")
ELEVENLABS_VOICE_ID = os.getenv("ELEVENLABS_VOICE_ID", "pNInz6obpgDQGcFmaJgB")
ELEVENLABS_VOICE_ID_FEMALE = os.getenv("ELEVENLABS_VOICE_ID_FEMALE", "EXAVITQu4vr4xnSDxMaL")
PEXELS_API_KEY = os.getenv("PEXELS_API_KEY", "")
BRAVE_SEARCH_API_KEY = os.getenv("BRAVE_SEARCH_API_KEY", "")

# ── YouTube ───────────────────────────────────────────────
YOUTUBE_CLIENT_ID = os.getenv("YOUTUBE_CLIENT_ID", "")
YOUTUBE_CLIENT_SECRET = os.getenv("YOUTUBE_CLIENT_SECRET", "")
YOUTUBE_REFRESH_TOKEN = os.getenv("YOUTUBE_REFRESH_TOKEN", "")
YOUTUBE_SCOPES = [
    "https://www.googleapis.com/auth/youtube.upload",
    "https://www.googleapis.com/auth/youtube",
]

# ── TikTok ────────────────────────────────────────────────
TIKTOK_ACCESS_TOKEN = os.getenv("TIKTOK_ACCESS_TOKEN", "")
TIKTOK_OPEN_ID = os.getenv("TIKTOK_OPEN_ID", "")

# ── Paths ─────────────────────────────────────────────────
BASE_DIR = Path(__file__).parent
OUTPUT_DIR = Path(os.getenv("OUTPUT_DIR", BASE_DIR / "output"))
DB_PATH = Path(os.getenv("DB_PATH", BASE_DIR / "pipeline.db"))
LOG_DIR = Path(os.getenv("LOG_DIR", BASE_DIR / "logs"))

OUTPUT_DIR.mkdir(parents=True, exist_ok=True)
LOG_DIR.mkdir(parents=True, exist_ok=True)

# ── Schedule — optimal posting times (UTC) ────────────────
# YouTube: weekdays 2–4 PM EST (19–21 UTC); Sat noon EST (17 UTC)
YOUTUBE_POST_TIMES_UTC = ["17:00", "19:00", "21:00"]
# TikTok peaks: 6–10 AM, 7–9 PM EST
TIKTOK_POST_TIMES_UTC = ["11:00", "13:00", "23:00", "01:00"]

# ── Pipeline ──────────────────────────────────────────────
MAX_QUEUE_SIZE = int(os.getenv("MAX_QUEUE_SIZE", 20))
RESEARCH_INTERVAL_HOURS = float(os.getenv("RESEARCH_INTERVAL_HOURS", 6))
AUTO_START = os.getenv("AUTO_START", "false").lower() == "true"

SHORTS_DURATION_TARGET = 45   # seconds
LONGFORM_DURATION_TARGET = 7  # minutes

# ── Dashboard ─────────────────────────────────────────────
DASHBOARD_HOST = os.getenv("DASHBOARD_HOST", "127.0.0.1")
DASHBOARD_PORT = int(os.getenv("DASHBOARD_PORT", 5050))
DASHBOARD_SECRET_KEY = os.getenv("DASHBOARD_SECRET_KEY", "dev-secret-key")

# ── Anthropic model ───────────────────────────────────────
CLAUDE_MODEL = "claude-sonnet-4-6"
