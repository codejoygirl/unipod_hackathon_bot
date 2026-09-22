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
    assert "follow up once I do" in reply
    assert "any language" in reply.lower()
    assert "no need to keep checking" in reply.lower()
    assert "/ask" in reply
    assert "passed it along" not in reply.lower()


def test_system_prompt_out_of_scope_suggests_ask():
    prompt = _system_prompt("out_of_scope", "Demo Community", "schedules")
    assert "/ask" in prompt
    assert "admin" in prompt.lower()
    assert "any language" in prompt.lower()
    assert "keep checking" in prompt.lower()


def test_system_prompt_requires_member_language():
    prompt = _system_prompt("social", "Demo Community", "schedules")
    assert "Demo Community" in prompt
    assert "Zak" in prompt
    assert "REPLY LANGUAGE" in prompt
    assert "English message → English reply" in prompt
    assert "Never answer in English unless" not in prompt


def test_classify_prompt_language_ability_is_conversational():
    prompt = _classify_system_prompt("Demo Community", "UniPods")
    assert "speak a language" in prompt.lower() or "do you speak" in prompt.lower()
    assert "Are you dumb?" in prompt
    assert "conversational|none|no" in prompt
    assert "When is the hackathon ending?" in prompt


def test_social_prompt_keeps_reply_in_question_language():
    prompt = _system_prompt("social", "Demo Community", "schedules")
    assert "English message → English reply" in prompt
    assert "do not switch into French" in prompt.lower() or "must be English" in prompt
    assert "continue in that language" not in prompt.lower()


def test_reply_user_prompt_locks_to_latest_message_language():
    from ai_service.api.routes.conversation import _reply_user_prompt

    auto = _reply_user_prompt("I need help", None)
    assert "member_message" in auto
    assert "I need help" in auto
    assert "untrusted" in auto.lower()
    assert "English message → English reply" in auto
    assert "do not reply in french" in auto.lower()

    forced = _reply_user_prompt("I need help", "fr")
    assert "French" in forced
    assert "ISO fr" in forced
    assert "I need help" in forced

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
    assert "catch-up is ALWAYS knowledge" in prompt or "ALWAYS knowledge" in prompt
    assert "If unsure" in prompt and "knowledge" in prompt
    assert "Fún mi ní àkótán" in prompt
    assert "Who won the World Cup?" in prompt


def test_classify_user_prompt_reminds_catch_up_rule():
    from ai_service.api.routes.conversation import _classify_user_prompt

    text = _classify_user_prompt("Fún mi ní àkótán àwọn ohun tó ṣẹlẹ̀ lónìí.")
    assert "catch-up" in text.lower() or "knowledge|none" in text
    assert "Fún mi ní àkótán" in text
    assert "Swipe-reply to Zak: no" in text


def test_classify_user_prompt_includes_prior_thread_for_follow_ups():
    from ai_service.api.routes.conversation import _classify_user_prompt

    text = _classify_user_prompt(
        "Tu es sûr ?",
        prior_question="How many bots are being tested?",
        prior_answer_excerpt="Only Shadrak's bot is ready.",
        reply_to_bot=True,
    )
    assert "prior_question" in text
    assert "How many bots are being tested?" in text
    assert "prior_answer" in text
    assert "Only Shadrak" in text
    assert "Swipe-reply to Zak: yes" in text
    assert "knowledge|none|yes" in text
    assert "untrusted" in text.lower()


def test_classify_prompt_teaches_multilingual_follow_up():
    prompt = _classify_system_prompt("Demo Community", "UniPods")
    assert "FOLLOW_UP" in prompt
    assert "Tu es sûr" in prompt
    assert "متأكد" in prompt
    assert "Never use clarify when the member is clearly referring" in prompt
    assert "personal_help" in prompt
    assert "Motivate me" in prompt
    assert "When are we going home? → clarify|none|no" in prompt


def test_take_private_and_personal_help_prompts():
    take = _system_prompt("take_private", "Demo Community", "schedules")
    assert "GROUP" in take or "group" in take.lower()
    assert "private" in take.lower()
    assert "Do NOT invent or paste any URL" in take

    help_prompt = _system_prompt("personal_help", "Demo Community", "schedules")
    assert "PRIVATE" in help_prompt or "private" in help_prompt.lower()
    assert "coach" in help_prompt.lower() or "growth" in help_prompt.lower()

    assert "private chat" in _fallback_reply("take_private", "UniPods").lower()
    assert "next step" in _fallback_reply("personal_help", "UniPods").lower()


def test_parse_intent_label_accepts_personal_help():
    assert _parse_intent_label("personal_help|none|no") == "personal_help"
    assert _parse_intent_label("Intent: personal_help") == "personal_help"


def test_clean_reply_strips_emphasis_and_em_dash():
    assert _clean_reply("Hello **there**—friend") == "Hello there-friend"


def test_parse_intent_label_accepts_plain_and_noisy():
    assert _parse_intent_label("knowledge") == "knowledge"
    assert _parse_intent_label("Intent: out_of_scope.") == "out_of_scope"
    assert _parse_intent_label("clarify please") == "clarify"
    assert _parse_intent_label("nope") is None
    assert _parse_intent_label("knowledge|recordings") == "knowledge"
    assert _parse_intent_label("knowledge|none|yes") == "knowledge"


def test_parse_follow_up_flag():
    from ai_service.api.routes.conversation import _parse_follow_up

    assert _parse_follow_up("knowledge|none|yes") is True
    assert _parse_follow_up("knowledge|none|no") is False
    assert _parse_follow_up("clarify|none|yes") is True
    assert _parse_follow_up("knowledge|recordings") is False
    assert _parse_follow_up("follow_up: yes") is True


def test_addressed_prompt_and_label_parser():
    from ai_service.api.routes.conversation import _addressed_system_prompt, _parse_addressed_label

    prompt = _addressed_system_prompt()
    assert "tagged incidentally" in prompt
    assert "yes OR no" in prompt
    assert _parse_addressed_label("yes") is True
    assert _parse_addressed_label("no") is False
    assert _parse_addressed_label("maybe") is None


def test_indexable_prompt_and_label_parser():
    from ai_service.api.routes.conversation import _indexable_system_prompt, _parse_addressed_label

    prompt = _indexable_system_prompt("UniPods", "schedules and sessions")
    assert "durable community facts" in prompt
    assert "slash commands" in prompt
    assert "UniPods" in prompt
    assert _parse_addressed_label("YES") is True
    assert _parse_addressed_label("no thanks") is False


def test_transcribe_request_model_bounds():
    from ai_service.api.routes.conversation import ConversationTranscribeRequest

    req = ConversationTranscribeRequest(
        audio_base64="AAAABBBB",
        mime_type="audio/ogg",
        filename="voice.ogg",
    )
    assert req.audio_base64 == "AAAABBBB"
    assert req.mime_type == "audio/ogg"
