"""LLM-as-judge for member-facing replies (reference-free, question vs answer).

Industry pattern (RAGAS answer relevance + separate presentation check): an independent
judge scores whether the reply addresses the ask and reads cleanly — spelling, grammar,
language match, obvious garbles — without keyword lists or per-term hardcoding.
"""

from __future__ import annotations

import json
import logging
import re
from dataclasses import dataclass, field

from ai_service.providers.base import ChatMessage, ChatModel, ChatRequest
from ai_service.security.sanitizer import fence_untrusted

logger = logging.getLogger(__name__)

_JSON_FENCE = re.compile(r"```(?:json)?\s*([\s\S]*?)```", re.IGNORECASE)

_JUDGE_SYSTEM = """You are an independent quality evaluator for a community assistant.

You receive the member's question and the assistant's candidate reply. The reply was
already grounded in community notes; you do NOT verify facts against the outside world.

Score two axes (industry RAG triad style — answer relevance separate from presentation):

1. addresses_question: Does the reply directly answer what the member asked, at the
   right level of detail, without obvious sidetracks or missing the point?

2. presentation_ok: Is the reply in the same language as the member's question? Are
   grammar, spelling, and wording clean on a phone? Flag obvious nonsense garbles,
   mangled words, or terms that clearly refer to the same thing the member named but
   are spelled wrong in the reply. Do NOT require specific words from the question —
   judge holistically.

pass is true only when BOTH axes are true.

Return JSON only:
{
  "pass": true | false,
  "addresses_question": true | false,
  "presentation_ok": true | false,
  "issues": ["short English repair hints for a rewriter, max 3 items"]
}

issues must be empty when pass is true. No preamble outside JSON."""


@dataclass
class ResponseQualityVerdict:
    """Result of a reference-free question↔answer quality check."""

    passed: bool = True
    addresses_question: bool = True
    presentation_ok: bool = True
    issues: list[str] = field(default_factory=list)


def parse_judge_json(raw: str) -> ResponseQualityVerdict | None:
    text = (raw or "").strip()
    if not text:
        return None
    m = _JSON_FENCE.search(text)
    if m:
        text = m.group(1).strip()
    try:
        data = json.loads(text)
    except json.JSONDecodeError:
        start = text.find("{")
        end = text.rfind("}")
        if start < 0 or end <= start:
            return None
        try:
            data = json.loads(text[start : end + 1])
        except json.JSONDecodeError:
            return None
    if not isinstance(data, dict):
        return None

    addresses = bool(data.get("addresses_question", False))
    presentation = bool(data.get("presentation_ok", False))
    passed = bool(data.get("pass", addresses and presentation))
    issues_raw = data.get("issues") or []
    issues: list[str] = []
    if isinstance(issues_raw, list):
        for item in issues_raw[:5]:
            if isinstance(item, str) and item.strip():
                issues.append(item.strip())

    return ResponseQualityVerdict(
        passed=passed and addresses and presentation,
        addresses_question=addresses,
        presentation_ok=presentation,
        issues=issues,
    )


class ResponseQualityJudge:
    """Reference-free LLM judge: member question vs candidate answer."""

    def __init__(self, chat_model: ChatModel | None = None) -> None:
        self._chat = chat_model

    async def evaluate(
        self,
        *,
        question: str,
        answer: str,
    ) -> ResponseQualityVerdict | None:
        q = (question or "").strip()
        a = (answer or "").strip()
        if not q or not a or self._chat is None:
            return None
        if len(a) < 20:
            return ResponseQualityVerdict(passed=True)

        user = "\n\n".join(
            [
                fence_untrusted("member_question", q, max_chars=1500),
                fence_untrusted("candidate_answer", a, max_chars=6000),
                "Evaluate candidate_answer against member_question using the rubric.",
            ]
        )
        try:
            response = await self._chat.generate(
                ChatRequest(
                    messages=[
                        ChatMessage(role="system", content=_JUDGE_SYSTEM),
                        ChatMessage(role="user", content=user),
                    ],
                    temperature=0.0,
                    max_tokens=400,
                )
            )
        except Exception:
            logger.exception("response_quality_judge_failed")
            return None

        verdict = parse_judge_json(response.content or "")
        if verdict is None:
            logger.warning(
                "response_quality_judge_unparseable chars=%s",
                len(response.content or ""),
            )
        return verdict
