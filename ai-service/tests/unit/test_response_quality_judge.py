"""Tests for LLM-as-judge response quality (parse + rubric shape)."""

import json

from ai_service.generation.response_quality_judge import (
    _JUDGE_SYSTEM,
    parse_judge_json,
)


def test_parse_judge_json_accepts_fenced_payload():
    payload = {
        "pass": False,
        "addresses_question": True,
        "presentation_ok": False,
        "issues": ["Fix garbled event name to match the member ask."],
    }
    raw = f"```json\n{json.dumps(payload)}\n```"
    verdict = parse_judge_json(raw)
    assert verdict is not None
    assert verdict.passed is False
    assert verdict.presentation_ok is False
    assert len(verdict.issues) == 1


def test_parse_judge_json_pass_requires_both_axes():
    verdict = parse_judge_json(
        json.dumps(
            {
                "pass": True,
                "addresses_question": True,
                "presentation_ok": True,
                "issues": [],
            }
        )
    )
    assert verdict is not None
    assert verdict.passed is True


def test_judge_rubric_is_holistic_not_keyword_based():
    lower = _JUDGE_SYSTEM.lower()
    assert "addresses_question" in lower
    assert "presentation_ok" in lower
    assert "holistically" in lower or "do not require specific words" in lower
    assert "hackathon" not in lower
    assert "akhatin" not in lower
