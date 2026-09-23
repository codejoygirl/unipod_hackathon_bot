"""Prompt-injection defense: fence untrusted text; never treat it as instructions.

Industry approach (not keyword blocklists alone):
1. Keep system instructions in the system role.
2. Put member/document text in delimited fences after escaping.
3. Cap length / strip control characters.
4. Tell the model that fenced content is data only.
"""

from __future__ import annotations

import html
import logging
import re

logger = logging.getLogger(__name__)

# Soft markers: neutralize (do not hard-fail — false positives break legitimate asks).
_ROLE_SPOOF_PATTERNS: list[tuple[re.Pattern[str], str]] = [
    (re.compile(r"(?im)^\s*(system|assistant|developer)\s*:\s*", re.MULTILINE), "[role]: "),
    (re.compile(r"(?i)```(?:system|assistant|prompt)\b"), "```"),
    (re.compile(r"(?i)</?\s*(?:system|instructions?|prompt)\s*>"), ""),
]

# Log-only heuristics (never raise — incomplete lists create bypass + false blocks).
_INJECTION_LOG_PATTERNS = [
    re.compile(r"(?i)ignore\s+(all\s+)?(previous|prior|above)\s+instructions?"),
    re.compile(r"(?i)disregard\s+(all\s+)?(previous|prior|above)"),
    re.compile(r"(?i)reveal\s+(your\s+)?(system\s+)?prompt"),
    re.compile(r"(?i)jailbreak"),
]

_PII_PATTERNS = [
    (re.compile(r"\b\d{3}-\d{2}-\d{4}\b"), "[SSN REDACTED]"),
    (
        re.compile(r"\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b"),
        "[EMAIL REDACTED]",
    ),
]

# Chat exports are full of phone numbers (`+250 783 188 655`, `223 74 42 77 59`), which
# identify members. The character class is deliberately broad so it catches the many
# spacing conventions, which also makes it eager — so the digit count is checked before
# masking, keeping dates, times, prices and quantities intact.
_PHONE_CANDIDATE = re.compile(r"(?<![\d])\+?\d[\d\s().\-]{6,}\d(?![\d])")
_MIN_PHONE_DIGITS = 9


def _mask_phone(match: re.Match[str]) -> str:
    candidate = match.group(0)
    if len(re.sub(r"\D", "", candidate)) < _MIN_PHONE_DIGITS:
        return candidate
    return "[PHONE REDACTED]"


def strip_control_chars(text: str) -> str:
    if not text:
        return ""
    return re.sub(r"[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\x9F]", "", text)


def sanitize_untrusted_text(text: str, *, max_chars: int = 8000) -> str:
    """Normalize untrusted member/document text before it enters a prompt."""
    cleaned = strip_control_chars(text or "").strip()
    if max_chars > 0 and len(cleaned) > max_chars:
        cleaned = cleaned[:max_chars]
    for pattern, replacement in _ROLE_SPOOF_PATTERNS:
        cleaned = pattern.sub(replacement, cleaned)
    for pattern in _INJECTION_LOG_PATTERNS:
        if pattern.search(cleaned):
            logger.warning("prompt_injection_heuristic_matched chars=%s", len(cleaned))
            break
    return cleaned


def mask_pii(text: str) -> str:
    out = text
    for pattern, mask in _PII_PATTERNS:
        out = pattern.sub(mask, out)
    return _PHONE_CANDIDATE.sub(_mask_phone, out)


def fence_untrusted(label: str, text: str, *, max_chars: int = 8000, escape_xml: bool = True) -> str:
    """Wrap untrusted content so models treat it as data, not instructions."""
    safe_label = re.sub(r"[^a-z0-9_-]+", "", (label or "untrusted").lower()) or "untrusted"
    body = sanitize_untrusted_text(text, max_chars=max_chars)
    if escape_xml:
        body = html.escape(body, quote=False)
    return (
        f"<{safe_label} untrusted=\"true\">\n"
        f"{body}\n"
        f"</{safe_label}>"
    )


def sanitize_query(query: str) -> str:
    """Sanitize a retrieval / chat query (compat entrypoint).

    Historically raised on a tiny English blocklist; that caused false positives
    and was easy to bypass. We neutralize + fence upstream instead of raising.
    """
    return mask_pii(sanitize_untrusted_text(query, max_chars=4000))
