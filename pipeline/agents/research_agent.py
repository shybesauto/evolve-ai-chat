"""
Research Agent — finds trending motivational topics using Claude + web search.

Uses the Anthropic tool-use API. Claude calls `web_search` and `reddit_hot`
tools to gather real-time signals, then synthesises the top trending topics.
"""
import json
import logging
import time
from typing import Any

import anthropic
import requests

import sys, os
sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))
from config import ANTHROPIC_API_KEY, BRAVE_SEARCH_API_KEY, CLAUDE_MODEL

log = logging.getLogger(__name__)

client = anthropic.Anthropic(api_key=ANTHROPIC_API_KEY)

# ── Tool definitions ──────────────────────────────────────

TOOLS: list[dict] = [
    {
        "name": "web_search",
        "description": (
            "Search the web for trending motivational content. "
            "Use it to find trending YouTube/TikTok motivational videos, "
            "viral quotes, popular themes, and what's getting the most engagement."
        ),
        "input_schema": {
            "type": "object",
            "properties": {
                "query": {
                    "type": "string",
                    "description": "The search query to run.",
                },
                "count": {
                    "type": "integer",
                    "description": "Number of results to return (max 10).",
                    "default": 5,
                },
            },
            "required": ["query"],
        },
    },
    {
        "name": "reddit_hot",
        "description": (
            "Fetch the hottest posts from motivational subreddits "
            "(r/getmotivated, r/motivation, r/selfimprovement, r/entrepreneur)."
        ),
        "input_schema": {
            "type": "object",
            "properties": {
                "subreddit": {
                    "type": "string",
                    "description": "The subreddit name (without r/).",
                },
                "limit": {
                    "type": "integer",
                    "description": "Number of posts to return (max 25).",
                    "default": 10,
                },
            },
            "required": ["subreddit"],
        },
    },
    {
        "name": "finish_research",
        "description": "Return the finalised list of trending topics you've identified.",
        "input_schema": {
            "type": "object",
            "properties": {
                "topics": {
                    "type": "array",
                    "description": "List of trending motivational topics.",
                    "items": {
                        "type": "object",
                        "properties": {
                            "title": {
                                "type": "string",
                                "description": "Short, punchy topic title (e.g. 'Discipline over motivation').",
                            },
                            "source": {
                                "type": "string",
                                "enum": ["youtube", "tiktok", "reddit", "ai"],
                            },
                            "trend_score": {
                                "type": "number",
                                "description": "Estimated trending strength 0-10.",
                            },
                            "keywords": {
                                "type": "array",
                                "items": {"type": "string"},
                                "description": "SEO keywords for this topic.",
                            },
                            "notes": {
                                "type": "string",
                                "description": "Why this is trending and content angle to take.",
                            },
                        },
                        "required": ["title", "source", "trend_score", "keywords", "notes"],
                    },
                }
            },
            "required": ["topics"],
        },
    },
]


# ── Tool implementations ──────────────────────────────────

def _brave_search(query: str, count: int = 5) -> list[dict]:
    if not BRAVE_SEARCH_API_KEY:
        log.warning("BRAVE_SEARCH_API_KEY not set — returning mock results")
        return _mock_search(query)
    headers = {
        "Accept": "application/json",
        "Accept-Encoding": "gzip",
        "X-Subscription-Token": BRAVE_SEARCH_API_KEY,
    }
    params = {"q": query, "count": min(count, 10), "safesearch": "off"}
    try:
        r = requests.get(
            "https://api.search.brave.com/res/v1/web/search",
            headers=headers,
            params=params,
            timeout=10,
        )
        r.raise_for_status()
        data = r.json()
        results = data.get("web", {}).get("results", [])
        return [
            {
                "title": item.get("title", ""),
                "url": item.get("url", ""),
                "description": item.get("description", ""),
            }
            for item in results[:count]
        ]
    except Exception as e:
        log.error("Brave search error: %s", e)
        return _mock_search(query)


def _mock_search(query: str) -> list[dict]:
    """Fallback when no search API key is configured."""
    return [
        {"title": f"Trending: {query}", "url": "", "description": "No API key — mock result."},
    ]


def _reddit_hot(subreddit: str, limit: int = 10) -> list[dict]:
    headers = {"User-Agent": "MotivationalPipelineBot/1.0"}
    try:
        r = requests.get(
            f"https://www.reddit.com/r/{subreddit}/hot.json",
            headers=headers,
            params={"limit": min(limit, 25)},
            timeout=10,
        )
        r.raise_for_status()
        children = r.json()["data"]["children"]
        return [
            {
                "title": c["data"]["title"],
                "score": c["data"]["score"],
                "url": c["data"]["url"],
                "flair": c["data"].get("link_flair_text", ""),
                "upvote_ratio": c["data"].get("upvote_ratio", 0),
            }
            for c in children
            if not c["data"].get("stickied")
        ][:limit]
    except Exception as e:
        log.error("Reddit fetch error for r/%s: %s", subreddit, e)
        return []


def _dispatch_tool(name: str, tool_input: dict[str, Any]) -> str:
    if name == "web_search":
        results = _brave_search(tool_input["query"], tool_input.get("count", 5))
        return json.dumps(results, ensure_ascii=False)
    if name == "reddit_hot":
        results = _reddit_hot(tool_input["subreddit"], tool_input.get("limit", 10))
        return json.dumps(results, ensure_ascii=False)
    return json.dumps({"error": f"Unknown tool: {name}"})


# ── Main agent ────────────────────────────────────────────

SYSTEM_PROMPT = """You are a social media trend analyst specialising in motivational content.
Your job is to identify the 5 most compelling, currently trending motivational topics
that would perform well as short-form (Shorts/TikTok) and long-form (YouTube) video content.

Research approach:
1. Search YouTube/TikTok trends for motivational content (engagement, views, virality signals)
2. Check Reddit's top motivational subreddits for high-scoring posts
3. Look for recurring themes, breakout quotes, and underserved angles

Topic criteria — prioritise:
- Emotional resonance (struggle, comeback, discipline, purpose)
- Broad audience appeal (entrepreneurship, fitness, mindset, success)
- Freshness: avoid overused clichés unless you have a unique angle
- Virality signals: high upvotes, comment engagement, share-worthy

Return exactly 5 topics via the `finish_research` tool. Make each one actionable and specific.
"""


def run(num_topics: int = 5) -> list[dict]:
    """
    Run the research agent. Returns a list of trending topic dicts.
    Each dict: {title, source, trend_score, keywords, notes}
    """
    log.info("Research agent starting — target %d topics", num_topics)
    messages: list[dict] = [
        {
            "role": "user",
            "content": (
                f"Research the web and Reddit right now to find the top {num_topics} "
                "trending motivational topics. Use the tools to gather real data, "
                "then call finish_research with your findings."
            ),
        }
    ]

    max_rounds = 10
    for round_num in range(max_rounds):
        response = client.messages.create(
            model=CLAUDE_MODEL,
            max_tokens=4096,
            system=SYSTEM_PROMPT,
            tools=TOOLS,
            messages=messages,
        )
        log.debug("Research round %d — stop_reason=%s", round_num, response.stop_reason)

        # Collect assistant message
        messages.append({"role": "assistant", "content": response.content})

        if response.stop_reason == "end_turn":
            log.warning("Agent ended without calling finish_research")
            break

        if response.stop_reason != "tool_use":
            break

        # Dispatch tool calls
        tool_results = []
        finished_topics = None

        for block in response.content:
            if block.type != "tool_use":
                continue
            if block.name == "finish_research":
                finished_topics = block.input.get("topics", [])
                tool_results.append({
                    "type": "tool_result",
                    "tool_use_id": block.id,
                    "content": json.dumps({"status": "ok"}),
                })
            else:
                result = _dispatch_tool(block.name, block.input)
                tool_results.append({
                    "type": "tool_result",
                    "tool_use_id": block.id,
                    "content": result,
                })

        messages.append({"role": "user", "content": tool_results})

        if finished_topics is not None:
            log.info("Research complete — %d topics found", len(finished_topics))
            return finished_topics[:num_topics]

        time.sleep(0.3)  # be polite to APIs

    log.error("Research agent exceeded max rounds without finishing")
    return []
