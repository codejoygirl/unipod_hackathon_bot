"""Public web search used only when the model asks for it."""

from __future__ import annotations

import logging
import re
from html import unescape
from urllib.parse import unquote

import httpx

logger = logging.getLogger(__name__)

_HEADERS = {
    "User-Agent": (
        "Mozilla/5.0 (compatible; ZakResearch/1.0; +https://localhost)"
    )
}


def _clean(text: str, limit: int) -> str:
    return re.sub(r"\s+", " ", unescape(text or "")).strip()[:limit]


async def search_web(query: str, *, max_results: int = 5) -> str:
    """Return a short research brief. Empty string if nothing usable."""
    q = (query or "").strip()[:300]
    if not q:
        return ""

    chunks: list[str] = []
    try:
        async with httpx.AsyncClient(
            timeout=12.0,
            follow_redirects=True,
            headers=_HEADERS,
        ) as client:
            instant = await client.get(
                "https://api.duckduckgo.com/",
                params={
                    "q": q,
                    "format": "json",
                    "no_html": "1",
                    "skip_disambig": "1",
                },
            )
            if instant.is_success:
                data = instant.json()
                abstract = _clean(str(data.get("AbstractText") or ""), 600)
                source = _clean(str(data.get("AbstractURL") or ""), 200)
                if abstract:
                    chunks.append(f"{abstract} {source}".strip())
                for item in (data.get("RelatedTopics") or [])[:max_results]:
                    if not isinstance(item, dict):
                        continue
                    text = _clean(str(item.get("Text") or ""), 280)
                    url = _clean(str(item.get("FirstURL") or ""), 200)
                    if text:
                        chunks.append(f"{text} {url}".strip())

            html = await client.get(
                "https://html.duckduckgo.com/html/",
                params={"q": q},
            )
            if html.is_success:
                titles = re.findall(
                    r'class="result__a"[^>]*href="([^"]+)"[^>]*>(.*?)</a>',
                    html.text,
                    flags=re.I | re.S,
                )
                if not titles:
                    titles = re.findall(
                        r'href="([^"]+)"[^>]*class="result__a"[^>]*>(.*?)</a>',
                        html.text,
                        flags=re.I | re.S,
                    )
                for href, title in titles[:max_results]:
                    url = unquote(href)
                    if "uddg=" in url:
                        match = re.search(r"uddg=([^&]+)", url)
                        if match:
                            url = unquote(match.group(1))
                    label = _clean(re.sub(r"<[^>]+>", "", title), 160)
                    if label:
                        chunks.append(f"{label} {url}".strip())
    except Exception as exc:  # noqa: BLE001
        logger.warning("web search failed: %s", exc)
        return ""

    seen: set[str] = set()
    unique: list[str] = []
    for item in chunks:
        key = item.lower()
        if key in seen:
            continue
        seen.add(key)
        unique.append(f"- {item}")
        if len(unique) >= max_results:
            break
    return "\n".join(unique)
