# Evolve AI — Motivational Video Pipeline

Automated end-to-end pipeline that researches trending motivational topics, writes scripts with Claude, assembles videos, and publishes to YouTube + TikTok on an optimal schedule.

```
Research → Script → Video Assembly → Upload → Schedule
   Claude    Claude   ElevenLabs       YouTube   APScheduler
              API     + Pexels         TikTok
                      + ffmpeg
```

## Quick Start

### 1. Install dependencies

```bash
cd pipeline
pip install -r requirements.txt
```

> **ffmpeg** must also be installed system-wide:
> - macOS: `brew install ffmpeg`
> - Ubuntu: `sudo apt install ffmpeg`
> - Windows: download from ffmpeg.org and add to PATH

### 2. Configure API keys

```bash
cp .env.example .env
# Edit .env with your keys
```

Minimum required keys to get started:
- `ANTHROPIC_API_KEY` — research + script agents
- `ELEVENLABS_API_KEY` — voiceover (free tier: 10k chars/month)
- `PEXELS_API_KEY` — stock footage (free, unlimited)
- `BRAVE_SEARCH_API_KEY` — trend research (free: 2k queries/month)

Upload keys (optional for testing, required for live publishing):
- YouTube: `YOUTUBE_CLIENT_ID`, `YOUTUBE_CLIENT_SECRET`, `YOUTUBE_REFRESH_TOKEN`
- TikTok: `TIKTOK_ACCESS_TOKEN`, `TIKTOK_OPEN_ID`

### 3. One-time YouTube auth

```bash
python main.py setup-youtube
# Follow the browser prompt, then paste the token into .env
```

### 4. Start the dashboard

```bash
python main.py dashboard
# Open http://127.0.0.1:5050
```

### 5. Run the pipeline

From the dashboard UI — click **▶ Run Full Pipeline**.

Or from the CLI:

```bash
python main.py run                 # full pipeline
python main.py run --research      # find trending topics only
python main.py run --scripts       # generate scripts for saved topics
python main.py run --videos        # assemble videos for pending scripts
python main.py run --uploads       # upload ready videos
python main.py status              # show DB stats
```

---

## Architecture

```
pipeline/
├── main.py                   CLI entry point
├── config.py                 All settings + env vars
├── requirements.txt
├── .env.example
│
├── agents/
│   ├── research_agent.py     Claude + web search + Reddit → trending topics
│   ├── script_agent.py       Claude → short (45s) + long (7min) scripts
│   ├── video_agent.py        ElevenLabs + Pexels + ffmpeg → video files
│   └── upload_agent.py       YouTube Data API v3 + TikTok API → publish
│
├── core/
│   ├── database.py           SQLite schema + all queries
│   ├── scheduler.py          Optimal posting time calculation
│   └── pipeline.py           Stage orchestrator
│
├── dashboard/
│   ├── app.py                Flask app + SSE + APScheduler
│   ├── templates/
│   │   └── dashboard.html    Dashboard UI
│   └── static/
│       ├── dashboard.css
│       └── dashboard.js
│
├── output/                   Generated audio, video, thumbnails
└── logs/                     pipeline.log
```

---

## Agents in Detail

### Research Agent
- Uses **Claude claude-sonnet-4-6** with tool-use
- Tools: `web_search` (Brave API), `reddit_hot` (Reddit JSON API)
- Claude browses trends autonomously and returns the top 5 topics with scores, keywords, and content angles
- Falls back to mock results if no Brave API key is set

### Script Agent
- **Short-form** (Shorts/TikTok, ~45 sec / ~100 words): Hook → insight → CTA
- **Long-form** (YouTube, ~7 min / ~1,000 words): Hook → promise → story → framework → action steps → CTA
- Returns structured JSON via tool-use: hook, body segments with visual notes, SEO title, description, hashtags, thumbnail text

### Video Agent
1. **ElevenLabs TTS** — converts script to MP3 voiceover (Adam or Bella voice)
2. **Pexels API** — downloads royalty-free stock video clips matching topic keywords
3. **ffmpeg** — concatenates clips to match audio duration, applies colour grading, exports portrait (9:16) or landscape (16:9)
4. **Pillow** — generates high-contrast thumbnail with text overlay

### Upload Agent
- **YouTube**: resumable upload via Data API v3 with thumbnail, supports scheduled publishing
- **TikTok**: Content Posting API v2 with caption and hashtags, supports scheduled publishing

### Scheduler
- Calculates optimal UTC posting times weighted by day-of-week engagement patterns
- YouTube peaks: Tue–Thu 12–4 PM ET
- TikTok peaks: Tue–Fri 6–10 AM ET and 7–9 PM ET

---

## Dashboard

Open `http://127.0.0.1:5050` after starting the dashboard.

| Section | What it shows |
|---|---|
| **Stat cards** | Topics found, scripts written, videos made, published, queue size, total views, revenue |
| **Pipeline Controls** | Run full pipeline or individual stages; live progress bar via SSE |
| **Upload Queue** | Upcoming scheduled posts with platform and time |
| **Published Videos** | All uploaded videos with views, likes, revenue, direct link |
| **30-Day Analytics** | Dual-axis chart: views (blue) + revenue (green) |

---

## Auto-scheduling

Set `AUTO_START=true` in `.env` to have the pipeline run automatically on an interval (default: every 6 hours). Or use the `--auto` flag:

```bash
python main.py dashboard --auto
```

Change the interval with `RESEARCH_INTERVAL_HOURS=12`.

---

## API Keys Setup

### Brave Search (Free tier)
1. Sign up at https://brave.com/search/api/
2. Create a Subscription → copy the API key

### ElevenLabs
1. Sign up at https://elevenlabs.io
2. Profile → API key
3. Browse Voice Library for `ELEVENLABS_VOICE_ID`

### Pexels
1. Sign up at https://www.pexels.com/api/
2. Your account → API key

### YouTube Data API v3
1. Go to https://console.cloud.google.com
2. Create project → Enable YouTube Data API v3
3. Credentials → OAuth 2.0 Client ID (Desktop app)
4. Download credentials, add `client_id` and `client_secret` to `.env`
5. Run `python main.py setup-youtube` to get the refresh token

### TikTok for Developers
1. Apply at https://developers.tiktok.com
2. Create app → add "Content Posting API" scope
3. Complete review and get access token
