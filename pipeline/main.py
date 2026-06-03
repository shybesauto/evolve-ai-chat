#!/usr/bin/env python3
"""
Motivational Video Pipeline — main entry point.

Usage:
  python main.py dashboard        # Start the web dashboard (default)
  python main.py run              # Run the full pipeline once
  python main.py run --research   # Research stage only
  python main.py run --scripts    # Script stage only (needs existing topics)
  python main.py run --videos     # Video stage only (needs pending scripts)
  python main.py run --uploads    # Upload stage only (needs ready videos)
  python main.py setup-youtube    # Interactive OAuth setup for YouTube
  python main.py status           # Print pipeline status and DB summary
"""
import argparse
import logging
import os
import sys
from pathlib import Path

# Make sure sibling modules are importable regardless of cwd
sys.path.insert(0, str(Path(__file__).parent))

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(name)s: %(message)s",
    handlers=[
        logging.StreamHandler(),
        logging.FileHandler("pipeline/logs/pipeline.log", encoding="utf-8"),
    ],
)
log = logging.getLogger("main")


def cmd_dashboard(args):
    from core import database as db
    from config import DASHBOARD_HOST, DASHBOARD_PORT, AUTO_START, RESEARCH_INTERVAL_HOURS
    db.init_db()
    log.info("Starting dashboard on http://%s:%d", DASHBOARD_HOST, DASHBOARD_PORT)

    from dashboard.app import app, start_scheduler
    if AUTO_START or args.auto:
        start_scheduler(RESEARCH_INTERVAL_HOURS)
        log.info("Auto-scheduler enabled — pipeline runs every %.1fh", RESEARCH_INTERVAL_HOURS)

    app.run(host=DASHBOARD_HOST, port=DASHBOARD_PORT, debug=False, threaded=True)


def cmd_run(args):
    from core import database as db
    from core.pipeline import run_pipeline
    db.init_db()

    kwargs = {}
    if args.research:
        kwargs = {"skip_video": True, "skip_upload": True}
    elif args.scripts:
        kwargs = {"skip_research": True, "skip_video": True, "skip_upload": True}
    elif args.videos:
        kwargs = {"skip_research": True, "skip_upload": True}
    elif args.uploads:
        kwargs = {"skip_research": True, "skip_video": True}

    if args.topics:
        kwargs["num_topics"] = args.topics

    result = run_pipeline(**kwargs)
    if "error" in result:
        log.error("Pipeline failed: %s", result["error"])
        sys.exit(1)
    print("\n=== Pipeline Result ===")
    for k, v in result.items():
        print(f"  {k:20s}: {v}")


def cmd_setup_youtube(args):
    """Walk the user through getting a YouTube refresh token."""
    from config import YOUTUBE_CLIENT_ID, YOUTUBE_CLIENT_SECRET, YOUTUBE_SCOPES
    if not YOUTUBE_CLIENT_ID or not YOUTUBE_CLIENT_SECRET:
        print("ERROR: Set YOUTUBE_CLIENT_ID and YOUTUBE_CLIENT_SECRET in .env first.")
        sys.exit(1)

    try:
        from google_auth_oauthlib.flow import InstalledAppFlow
    except ImportError:
        print("Run: pip install google-auth-oauthlib")
        sys.exit(1)

    client_config = {
        "installed": {
            "client_id": YOUTUBE_CLIENT_ID,
            "client_secret": YOUTUBE_CLIENT_SECRET,
            "redirect_uris": ["urn:ietf:wg:oauth:2.0:oob", "http://localhost"],
            "auth_uri": "https://accounts.google.com/o/oauth2/auth",
            "token_uri": "https://oauth2.googleapis.com/token",
        }
    }
    flow = InstalledAppFlow.from_client_config(client_config, YOUTUBE_SCOPES)
    creds = flow.run_local_server(port=0)
    print("\n✅ YouTube authorised!\n")
    print(f"Add this to your .env:\n  YOUTUBE_REFRESH_TOKEN={creds.refresh_token}\n")


def cmd_status(args):
    from core import database as db
    db.init_db()
    summary = db.dashboard_summary()
    print("\n=== Pipeline Status ===")
    keys = [
        ("Topics (total/unused)", f"{summary['topics_total']} / {summary['topics_unused']}"),
        ("Scripts",               summary["scripts_total"]),
        ("Videos",                summary["videos_total"]),
        ("Published uploads",     summary["uploads_published"]),
        ("Queued uploads",        summary["uploads_queued"]),
        ("Total views",           f"{summary['total_views']:,}"),
        ("Est. revenue",          f"${summary['total_revenue']:.2f}"),
    ]
    for label, val in keys:
        print(f"  {label:30s}: {val}")

    if summary["last_run"]:
        r = summary["last_run"]
        print(f"\n  Last run [{r['status']}] at {r['started_at']}")
        print(f"    Topics={r['topics_found']} Scripts={r['scripts_written']} "
              f"Videos={r['videos_made']} Uploads={r['uploads_done']}")
    print()


# ── CLI ───────────────────────────────────────────────────

def main():
    parser = argparse.ArgumentParser(
        prog="pipeline",
        description="Motivational Video Pipeline CLI",
    )
    subs = parser.add_subparsers(dest="command")

    # dashboard
    p_dash = subs.add_parser("dashboard", help="Start the web dashboard")
    p_dash.add_argument("--auto", action="store_true",
                        help="Enable auto-scheduled pipeline runs")

    # run
    p_run = subs.add_parser("run", help="Execute the pipeline")
    p_run.add_argument("--research", action="store_true", help="Research stage only")
    p_run.add_argument("--scripts",  action="store_true", help="Script stage only")
    p_run.add_argument("--videos",   action="store_true", help="Video stage only")
    p_run.add_argument("--uploads",  action="store_true", help="Upload stage only")
    p_run.add_argument("--topics",   type=int, default=None, help="Number of topics")

    # setup-youtube
    subs.add_parser("setup-youtube", help="Get a YouTube refresh token via OAuth")

    # status
    subs.add_parser("status", help="Print current DB stats")

    args = parser.parse_args()

    dispatch = {
        "dashboard":     cmd_dashboard,
        "run":           cmd_run,
        "setup-youtube": cmd_setup_youtube,
        "status":        cmd_status,
    }

    cmd = args.command or "dashboard"
    fn = dispatch.get(cmd)
    if not fn:
        parser.print_help()
        sys.exit(1)

    fn(args)


if __name__ == "__main__":
    main()
