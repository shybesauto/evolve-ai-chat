"""
Script Agent — generates short-form and long-form motivational video scripts.

Uses the Anthropic API with structured output via tool_use to return
fully parsed scripts including hook, body segments, CTA, and SEO metadata.
"""
import json
import logging

import anthropic

import sys, os
sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))
from config import ANTHROPIC_API_KEY, CLAUDE_MODEL, SHORTS_DURATION_TARGET, LONGFORM_DURATION_TARGET

log = logging.getLogger(__name__)
client = anthropic.Anthropic(api_key=ANTHROPIC_API_KEY)

# ── Tool schema for structured script output ──────────────

_SCRIPT_TOOL = {
    "name": "deliver_script",
    "description": "Deliver the completed video script with all metadata.",
    "input_schema": {
        "type": "object",
        "properties": {
            "hook": {
                "type": "string",
                "description": (
                    "The opening 3-5 seconds — one punchy line that stops the scroll. "
                    "Must create immediate curiosity or emotional impact."
                ),
            },
            "body_segments": {
                "type": "array",
                "description": "Ordered content segments.",
                "items": {
                    "type": "object",
                    "properties": {
                        "segment_title": {"type": "string"},
                        "script_text": {
                            "type": "string",
                            "description": "The spoken script for this segment.",
                        },
                        "visual_note": {
                            "type": "string",
                            "description": "Director note: what footage/graphic to show.",
                        },
                        "duration_secs": {"type": "integer"},
                    },
                    "required": ["segment_title", "script_text", "visual_note", "duration_secs"],
                },
            },
            "cta": {
                "type": "string",
                "description": "The closing call-to-action (subscribe, comment, share).",
            },
            "full_script_text": {
                "type": "string",
                "description": "The entire script concatenated, ready for TTS.",
            },
            "word_count": {"type": "integer"},
            "estimated_duration_secs": {"type": "integer"},
            "seo_title": {
                "type": "string",
                "description": "YouTube/TikTok title — under 70 chars, high-CTR.",
            },
            "seo_description": {
                "type": "string",
                "description": "Video description with keywords, timestamps (for long-form), and links.",
            },
            "hashtags": {
                "type": "array",
                "items": {"type": "string"},
                "description": "10-20 hashtags without the # symbol.",
            },
            "thumbnail_text": {
                "type": "string",
                "description": "Bold 3-5 word text to overlay on the thumbnail.",
            },
        },
        "required": [
            "hook", "body_segments", "cta", "full_script_text",
            "word_count", "estimated_duration_secs",
            "seo_title", "seo_description", "hashtags", "thumbnail_text",
        ],
    },
}


# ── System prompts ────────────────────────────────────────

_SYSTEM_SHORT = f"""You are an elite short-form content writer for motivational video creators.
You write scripts for YouTube Shorts and TikTok ({SHORTS_DURATION_TARGET}-second target).

Style rules:
- HOOK in the first sentence — shock, question, or bold claim. No warm-up.
- Speak to ONE person ("you", not "people"). Direct, personal, present tense.
- Conversational but punchy. Short sentences. Active voice.
- Energy: like David Goggins meets a TED talk — raw, real, no fluff.
- End with one sharp CTA (e.g. "Follow for more" / "Comment your #1 goal below").
- No filler ("In today's video...", "Don't forget to..."). Respect the viewer's time.

Format the script using the deliver_script tool.
Speaking pace: ~140 words per minute. Target {SHORTS_DURATION_TARGET}s = ~{SHORTS_DURATION_TARGET * 140 // 60} words.
"""

_SYSTEM_LONG = f"""You are a long-form motivational video scriptwriter for YouTube.
Target: {LONGFORM_DURATION_TARGET} minutes of compelling, story-driven content.

Structure every video as:
1. HOOK (0:00-0:30) — bold claim or question that earns attention
2. PROMISE (0:30-1:00) — what they'll get from watching
3. STORY/EVIDENCE (1:00-5:00) — research, real examples, your narrative arc
4. THE SHIFT (5:00-6:00) — the insight or framework
5. ACTION STEPS (6:00-6:45) — 3 concrete things to do right now
6. CLOSE (6:45-7:00) — CTA + subscribe ask

Style: Direct, energetic, authentic. Think Andrew Huberman meets Jocko Willink.
Back claims with specifics (names, studies, numbers) — no vague platitudes.
Use the deliver_script tool. Speaking pace ~140 wpm. Target {LONGFORM_DURATION_TARGET}min ≈ {LONGFORM_DURATION_TARGET * 140} words.
"""


# ── Core function ─────────────────────────────────────────

def _generate(topic: dict, fmt: str) -> dict | None:
    """
    Generate a script for a topic.
    fmt: "short" | "long"
    Returns the deliver_script tool input dict, or None on failure.
    """
    system = _SYSTEM_SHORT if fmt == "short" else _SYSTEM_LONG
    user_msg = (
        f"Write a {fmt}-form motivational video script about:\n\n"
        f"**Topic:** {topic['title']}\n"
        f"**Trend insight:** {topic.get('notes', '')}\n"
        f"**Keywords to weave in:** {', '.join(topic.get('keywords', []))}\n\n"
        "Use the deliver_script tool to return the complete script."
    )

    response = client.messages.create(
        model=CLAUDE_MODEL,
        max_tokens=8192,
        system=system,
        tools=[_SCRIPT_TOOL],
        tool_choice={"type": "tool", "name": "deliver_script"},
        messages=[{"role": "user", "content": user_msg}],
    )

    for block in response.content:
        if getattr(block, "type", None) == "tool_use" and block.name == "deliver_script":
            return block.input

    log.error("Script agent returned no tool call for topic: %s", topic["title"])
    return None


def generate_short(topic: dict) -> dict | None:
    """Generate a ~45-second Shorts/TikTok script."""
    log.info("Generating SHORT script for: %s", topic["title"])
    return _generate(topic, "short")


def generate_long(topic: dict) -> dict | None:
    """Generate a ~7-minute YouTube long-form script."""
    log.info("Generating LONG script for: %s", topic["title"])
    return _generate(topic, "long")


def run(topic: dict, formats: list[str] = ("short", "long")) -> list[dict]:
    """
    Generate scripts in the requested formats.
    Returns list of {format, script_data} dicts.
    """
    results = []
    for fmt in formats:
        fn = generate_short if fmt == "short" else generate_long
        data = fn(topic)
        if data:
            results.append({"format": fmt, "script_data": data})
    return results
