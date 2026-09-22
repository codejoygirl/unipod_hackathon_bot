import pytest

from ai_service.generation.prompts import build_user_prompt
from ai_service.providers.mock import MockLanguageDetector


@pytest.mark.asyncio
async def test_mock_detector_yoruba_orthography_without_keyword_catalog():
    detector = MockLanguageDetector()

    text = "N\u00edgb\u00e0 wo ni hackathon n\u00e1\u00e0 b\u1eb9\u0300r\u1eb9\u0300?"

    assert await detector.detect(text) == "non-en"


def test_non_english_reply_language_lock_reaches_grounded_prompt():
    prompt = build_user_prompt(
        "N\u00edgb\u00e0 wo ni hackathon n\u00e1\u00e0 b\u1eb9\u0300r\u1eb9\u0300?",
        "<context></context>",
        target_language="non-en",
    )

    assert "detected as non-English" in prompt
    assert "same language as member_question" in prompt
    assert "Do not answer in English" in prompt
    assert "<context> is English" in prompt


def test_follow_up_envelope_locks_language_to_latest_ask_not_original():
    """English follow-up after a Yoruba prior must not fence Yoruba as member_question."""
    from ai_service.generation.prompts import _active_member_ask
    from ai_service.generation.synthesizer import AnswerSynthesizer

    envelope = (
        "The member is following up on a previous community answer.\n\n"
        "Original question: Nígbà wo ni hackathon náà bẹ̀rẹ̀?\n\n"
        "Previous answer already shown to the member:\n"
        "Hackathon bẹ̀rẹ̀ ní September 18.\n\n"
        "Follow-up: What do we have next tomorrow?"
    )
    assert _active_member_ask(envelope) == "What do we have next tomorrow?"
    assert AnswerSynthesizer._current_question(envelope) == "What do we have next tomorrow?"
    assert "hackathon" in AnswerSynthesizer._original_question(envelope).lower()

    prompt = build_user_prompt(envelope, "<context></context>", target_language=None)
    # Latest ask is what language locks on.
    assert "What do we have next tomorrow?" in prompt
    assert "member_question" in prompt
    import re

    m = re.search(
        r"<member_question[^>]*>\s*(.*?)\s*</member_question>",
        prompt,
        flags=re.S,
    )
    assert m is not None
    member_q = m.group(1)
    assert "What do we have next tomorrow?" in member_q
    assert "Nígbà" not in member_q
    assert "Never reply in Yoruba to an English question" in prompt
