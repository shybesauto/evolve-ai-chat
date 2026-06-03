"""
Scheduler — determines optimal posting times and manages the upload queue.
"""
import logging
from datetime import datetime, timedelta, timezone
from typing import Optional

import pytz

import sys, os
sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))
from config import YOUTUBE_POST_TIMES_UTC, TIKTOK_POST_TIMES_UTC

log = logging.getLogger(__name__)

_EASTERN = pytz.timezone("America/New_York")

# Days: 0=Mon … 6=Sun.  YouTube weights — higher = better day to post.
_YOUTUBE_DAY_WEIGHTS = {0: 0.8, 1: 0.9, 2: 1.0, 3: 1.0, 4: 0.9, 5: 0.7, 6: 0.6}
_TIKTOK_DAY_WEIGHTS  = {0: 0.9, 1: 0.9, 2: 1.0, 3: 1.0, 4: 1.0, 5: 0.8, 6: 0.7}


def _next_slot(time_slots_utc: list[str], day_weights: dict[int, float],
               after: Optional[datetime] = None) -> datetime:
    """
    Find the next posting slot after `after` (defaults to now).
    Slots are strings like "17:00".  Returns a UTC datetime.
    """
    now = after or datetime.now(timezone.utc)
    # Search up to 14 days ahead
    for day_offset in range(14):
        candidate_day = now + timedelta(days=day_offset)
        weight = day_weights.get(candidate_day.weekday(), 0.5)
        if weight < 0.6:  # skip low-weight days
            continue
        for slot_str in sorted(time_slots_utc):
            h, m = map(int, slot_str.split(":"))
            slot = candidate_day.replace(hour=h, minute=m, second=0, microsecond=0,
                                         tzinfo=timezone.utc)
            if slot > now + timedelta(minutes=5):
                return slot

    # Fallback: 24 hours from now
    return now + timedelta(hours=24)


def next_youtube_slot(after: Optional[datetime] = None) -> datetime:
    return _next_slot(YOUTUBE_POST_TIMES_UTC, _YOUTUBE_DAY_WEIGHTS, after)


def next_tiktok_slot(after: Optional[datetime] = None) -> datetime:
    return _next_slot(TIKTOK_POST_TIMES_UTC, _TIKTOK_DAY_WEIGHTS, after)


def schedule_upload(platform: str,
                    after: Optional[datetime] = None) -> str:
    """Return the next posting slot as an ISO-8601 UTC string."""
    fn = next_youtube_slot if platform == "youtube" else next_tiktok_slot
    dt = fn(after)
    log.info("Scheduled %s upload for %s UTC", platform, dt.strftime("%Y-%m-%d %H:%M"))
    return dt.isoformat()


def slots_today(platform: str) -> list[str]:
    """Return all posting slots for the current day in local ET."""
    slots = YOUTUBE_POST_TIMES_UTC if platform == "youtube" else TIKTOK_POST_TIMES_UTC
    now_et = datetime.now(_EASTERN)
    result = []
    for s in slots:
        h, m = map(int, s.split(":"))
        utc_slot = now_et.replace(hour=h, minute=m, second=0, microsecond=0,
                                   tzinfo=timezone.utc)
        et_slot = utc_slot.astimezone(_EASTERN)
        result.append(et_slot.strftime("%I:%M %p ET"))
    return result
