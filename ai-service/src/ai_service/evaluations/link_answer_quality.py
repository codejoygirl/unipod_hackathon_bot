"""Deterministic checks that a member-facing reply answered the link/caption task well.

These are shape + quality gates (not a language keyword catalog). The model still
owns multilingual captions; this only fails obvious paste/CTA/title bugs.
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


def evaluate_link_answer_quality(answer: str, *, question: str | None = None) -> LinkAnswerQualityReport:
    """Evaluate whether a reply's link list is readable and task-shaped.

    Checks (language-neutral):
    - Numbered captions sit on their own line above https URLs
    - Captions are not chat crumbs / CTAs / speaker pastes
    - No long "caption: https://..." same-line slips
    - When the ask looks singular, avoid dumping many unrelated URLs
    """
    report = LinkAnswerQualityReport()
    text = (answer or "").replace("\r\n", "\n").strip()
    if not text:
        report.add("empty_answer")
        report.score = 0.0
        return report

    urls = _URL_RE.findall(text)
    report.url_count = len(urls)
    if report.url_count == 0:
        # Not a link-list answer — nothing to score here.
        return report

    lines = [ln.strip() for ln in text.splitlines()]
    i = 0
    while i < len(lines):
        line = lines[i]
        m = _NUMBERED.match(line)
        if not m:
            i += 1
            continue
        rest = m.group(2).strip()
        same = re.match(r"^(.+?)\s*:\s*(https?://\S+)\s*$", rest)
        if same and len(re.findall(r"[\w'’-]+", same.group(1), flags=re.UNICODE)) >= 4:
            report.same_line_url_count += 1
            report.add(f"same_line_caption_url:{m.group(1)}")
            i += 1
            continue
        if rest.startswith("http://") or rest.startswith("https://"):
            report.missing_caption_count += 1
            report.add(f"missing_caption:{m.group(1)}")
            i += 1
            continue

        caption = rest
        j = i + 1
        while j < len(lines) and not lines[j]:
            j += 1
        if j >= len(lines) or not (
            lines[j].startswith("http://") or lines[j].startswith("https://")
        ):
            i += 1
            continue

        report.caption_count += 1
        if AnswerSynthesizer._is_weak_link_label(caption):
            report.weak_caption_count += 1
            report.add(f"weak_caption:{caption[:80]}")
        i = j + 1

    q = (question or "").strip().lower()
    singular = bool(
        re.search(r"\b(first|initial|main|primary)\b", q)
        or (re.search(r"\blink\b", q) and not re.search(r"\blinks\b", q))
    ) and not bool(re.search(r"\b(handles|profiles|meetings|recordings)\b", q))
    if singular and report.url_count > 3:
        report.add(f"singular_ask_url_dump:{report.url_count}")

    penalties = (
        report.weak_caption_count
        + report.same_line_url_count
        + report.missing_caption_count
        + (1 if any(i.startswith("singular_ask") for i in report.issues) else 0)
    )
    denom = max(report.caption_count, report.url_count, 1)
    report.score = max(0.0, round(1.0 - (penalties / denom), 3))
    report.passed = penalties == 0 and report.score >= 0.85
    return report
