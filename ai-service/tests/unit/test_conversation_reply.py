"""Smoke test for conversation reply prompt helpers (no live LLM)."""

from ai_service.api.routes.conversation import (
    _classify_system_prompt,
    _clean_reply,
    _fallback_reply,
    _parse_intent_label,
    _system_prompt,
)


def test_fallback_out_of_scope_is_clear():
    reply = _fallback_reply("out_of_scope", "UniPods")
    assert "UniPods" in reply
    assert "can't help" in reply.lower() or "cannot help" in reply.lower()
    assert "come back once I do" in reply
    assert "passed it along" not in reply.lower()


def test_system_prompt_requires_member_language():
    prompt = _system_prompt("social", "Demo Community", "schedules")
    assert "Demo Community" in prompt
    assert "Zak" in prompt
    assert "same language" in prompt.lower()


def test_classify_prompt_language_ability_is_conversational():
    prompt = _classify_system_prompt("Demo Community", "UniPods")
    assert "speak a language" in prompt.lower() or "do you speak" in prompt.lower()


def test_social_prompt_keeps_reply_in_question_language():
    prompt = _system_prompt("social", "Demo Community", "schedules")
    assert "SAME language as their question" in prompt or "same language" in prompt.lower()
    assert "continue in that language" not in prompt.lower()


def test_system_prompt_keeps_scope_topics_in_bounds():
    prompt = _system_prompt(
        "out_of_scope",
        "Demo Community",
        "UniPods Wadhwani programme: schedules, sessions, hackathon",
    )
    assert "UniPods" in prompt
    assert "ARE in scope" in prompt
    assert "Do not refuse UniPods" in prompt


def test_classify_prompt_prefers_programme_knowledge():
    prompt = _classify_system_prompt(
        "Demo Community",
        "Display name: Demo Community. This community's programme / coverage: UniPods hackathon.",
    )
    assert "Demo Community" in prompt
    assert "identity / coverage" in prompt.lower() or "Community identity" in prompt
    assert "knowledge, not out_of_scope" in prompt.lower() or "not out_of_scope" in prompt.lower()


def test_clean_reply_strips_emphasis_and_em_dash():
    assert _clean_reply("Hello **there**—friend") == "Hello there-friend"


def test_parse_intent_label_accepts_plain_and_noisy():
    assert _parse_intent_label("knowledge") == "knowledge"
    assert _parse_intent_label("Intent: out_of_scope.") == "out_of_scope"
    assert _parse_intent_label("clarify please") == "clarify"
    assert _parse_intent_label("nope") is None
    assert _parse_intent_label("knowledge|recordings") == "knowledge"
