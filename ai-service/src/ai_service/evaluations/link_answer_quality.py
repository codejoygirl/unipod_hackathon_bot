"""Deterministic checks that a member-facing reply answered the link/caption task well.

These are shape + quality gates (not a language keyword catalog). The model still
owns multilingual captions and focus; this fails obvious paste/CTA/title/dump bugs.
"""

from __future__ import annotations

import re
from dataclasses import dataclass, field

from ai_service.generation.synthesizer import AnswerSynthesizer

_URL_RE = re.compile(r"https?://[^\s<>\"']+", re.IGNORECASE)
_NUMBERED = re.compile(r"^(\d+)[\).\:\-]\s+(.+)$")


@dataclass
class LinkAnswerQualityReport:
    """Pass/fail report for link-list answers (captions + URLs + focus)."""

    passed: bool = True
    score: float = 1.0
    url_count: int = 0
    caption_count: int = 0
    weak_caption_count: int = 0
    same_line_url_count: int = 0
    missing_caption_count: int = 0
    issues: list[str] = field(default_factory=list)

    def add(self, issue: str) -> None:
        self.issues.append(issue)
        self.passed = False


def evaluate_link_answer_quality(
    answer: str,
    *,
    question: str | None = None,
    link_focus: str | None = None,
) -> LinkAnswerQualityReport:
    """Evaluate whether a reply's link list is readable and task-shaped.

    Checks (language-neutral):
    - Numbered captions sit on their own line above https URLs
    - Captions are not weak chat/CTA paste
    - No long "caption: https://..." same-line slips
    - URLs are not HTML-escaped (&amp;)
    - When classifier focus is ``one``, refuse multi-URL dumps
    """
    report = LinkAnswerQualityReport()
    text = (answer or "").replace("\r\n", "\n").strip()
    if not text:
        report.add("empty_answer")
        report.score = 0.0
        report.passed = False
        return report

    if "&amp;" in text and _URL_RE.search(text):
        report.add("html_escaped_url")

    lines = text.splitlines()
    i = 0
    while i < len(lines):
        trimmed = lines[i].strip()
        m = _NUMBERED.match(trimmed)
        if not m:
            i += 1
            continue
        rest = m.group(2).strip()
        same = re.match(r"^(.+?)\s*:\s*(https?://\S+)\s*$", rest)
        if same and len(same.group(1).split()) >= 3:
            report.same_line_url_count += 1
            report.add(f"same_line_caption_url:{m.group(1)}")
            i += 1
            continue
        if rest.startswith("http://") or rest.startswith("https://"):
            report.url_count += 1
            report.missing_caption_count += 1
            report.add(f"missing_caption:{m.group(1)}")
            i += 1
            continue

        caption = rest
        j = i + 1
        while j < len(lines) and not lines[j].strip():
            j += 1
        if j >= len(lines) or not _URL_RE.match(lines[j].strip()):
            i += 1
            continue

        report.url_count += 1
        report.caption_count += 1
        if AnswerSynthesizer._is_weak_link_label(caption):
            report.weak_caption_count += 1
            report.add(f"weak_caption:{caption[:80]}")
        i = j + 1

    # Classifier-owned focus (preferred). Fallback: structural dump gate only.
    focus = (link_focus or "").strip().lower()
    if focus == "one" and report.url_count > 1:
        report.add(f"singular_ask_url_dump:{report.url_count}")
    elif focus not in {"one", "many", "na"} and report.url_count > 8:
        # Phone-unfriendly corpus dump regardless of ask wording.
        report.add(f"url_dump:{report.url_count}")

    penalties = (
        report.weak_caption_count
        + report.same_line_url_count
        + report.missing_caption_count
        + (1 if any(i.startswith("singular_ask") or i.startswith("url_dump") for i in report.issues) else 0)
        + (1 if any(i.startswith("html_escaped") for i in report.issues) else 0)
    )
    denom = max(report.caption_count, report.url_count, 1)
    report.score = max(0.0, round(1.0 - (penalties / denom), 3))
    report.passed = penalties == 0 and report.score >= 0.85
    return report
