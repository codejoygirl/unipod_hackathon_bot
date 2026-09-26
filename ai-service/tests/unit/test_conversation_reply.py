"""Smoke test for conversation reply prompt helpers (no live LLM)."""

from ai_service.api.routes.conversation import (
    _classify_system_prompt,
    _clean_document_reply,
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


def test_system_prompt_escalated_reassures_without_inventing():
    prompt = _system_prompt("escalated", "UniPods", "schedules")
    assert "passed to the team" in prompt.lower() or "passed along" in prompt.lower()
    assert "do not invent" in prompt.lower()
    assert "follow up" in prompt.lower()
    assert "UniPods" in prompt
    assert "do not ask them to send /ask" in prompt.lower()


def test_fallback_escalated_reassures_handoff():
    reply = _fallback_reply("escalated", "UniPods")
    assert "passed it along" in reply.lower()
    assert "follow up" in reply.lower()
    assert "solid answer" in reply.lower()


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
    assert "CURRENT TIME" in prompt


def test_reply_user_prompt_locks_to_latest_message_language():
    from ai_service.api.routes.conversation import _reply_user_prompt

    auto = _reply_user_prompt("I need help", None, timezone_name="UTC")
    assert "CURRENT TIME" in auto
    assert "member_message" in auto
    assert "I need help" in auto
    assert "untrusted" in auto.lower()
    assert "English message → English reply" in auto
    assert "do not reply in french" in auto.lower()

    forced = _reply_user_prompt("I need help", "fr", timezone_name="Africa/Lagos")
    assert "CURRENT TIME" in forced
    assert "French" in forced
    assert "ISO fr" in forced
    assert "I need help" in forced


def test_classify_prompt_meeting_today_is_knowledge_not_link_dump():
    prompt = _classify_system_prompt("Demo Community", "METI programme")
    assert "Are we having a meeting today?" in prompt
    assert "knowledge|none|no|na" in prompt


def test_classify_prompt_treats_today_date_as_conversational():
    prompt = _classify_system_prompt("Demo Community", "METI programme")
    assert "What is today's date?" in prompt
    assert "conversational|none|no|na" in prompt
    assert "NOT today's date" in prompt or "NOT today" in prompt
    assert "What's the time in India currently?" in prompt


def test_social_prompt_covers_named_timezone_clock():
    prompt = _system_prompt("social", "Demo Community", "schedules")
    assert "another city/country/timezone" in prompt.lower() or "India" in prompt
    assert "CURRENT TIME" in prompt

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
    assert "8qa4RWev0a0ZdQFrMeSa zak-app → clarify|none|no" in prompt
    assert "(with prior answer) User: asdfjkl → clarify|none|no" in prompt
    assert "opaque" in prompt.lower() or "accidental" in prompt.lower()
    assert "unintelligible" in prompt.lower() or "keyboard smash" in prompt.lower()


def test_social_prompt_asks_to_retype_unintelligible():
    prompt = _system_prompt("social", "Demo Community", "schedules")
    assert "passed it along" in prompt.lower()
    assert "retype" in prompt.lower() or "did not catch" in prompt.lower()
    assert "do NOT say you passed it along" in prompt or "do NOT promise a later follow-up" in prompt


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
    assert _clean_reply("Hello **there**—friend") == "Hello there. friend"


def test_clean_document_reply_keeps_markdown_and_repairs_spacing():
    raw = '## Cover letter\n\nIt seems ready."This is next'
    cleaned = _clean_document_reply(raw)
    assert "## Cover letter" in cleaned
    assert 'ready." This is next' in cleaned
    assert _clean_document_reply("Hello **there**—friend") == "Hello **there**. friend"


def test_clean_document_reply_keeps_research_urls_and_lists():
    raw = (
        "Elon Musk is richest ([bloomberg. com](https://www.bloomberg. com/billionaires/"
        "?utm_source=openai)) 1. Larry Page: $298 billion. 2. Jeff Bezos: $279 billion. "
        "## Highlights: [Who is richest?](https://www.moneyweek.com/a) "
        "[How Musk grew](https://www.theatlantic.com/b)"
    )
    cleaned = _clean_document_reply(raw)
    assert "bloomberg.com" in cleaned
    assert "https://www.bloomberg.com/billionaires/" in cleaned
    assert "utm_source=openai" not in cleaned
    assert "\n1. Larry Page" in cleaned
    assert "\n2. Jeff Bezos" in cleaned
    assert "## Highlights" in cleaned
    assert cleaned.count("\n[") >= 1
    repaired = _clean_document_reply(
        "##\n\n1.\nIdentify a High-Potential Business Idea\nLook for gaps.\n"
        "##\n\n2.\nDevelop a Strong Business Plan\nCreate a detailed plan.\n"
        "## Sources\nForbes\nInvestopedia"
    )
    assert "##\n" not in repaired
    assert "1. Identify a High-Potential Business Idea" in repaired
    assert "2. Develop a Strong Business Plan" in repaired
    assert repaired.index("1. Identify") < repaired.index("Look for gaps")
    jammed = _clean_document_reply(
        "Fill in your aims in your next role. ### Key Skills List your technical skills."
    )
    assert "\n### Key Skills" in jammed
    spaced = _clean_document_reply(
        'Ready."Next ## PitchDeckOutline\n1. TitleSlide\nI\'llneed a file.'
    )
    assert 'Ready." Next' in spaced
    assert "Pitch Deck Outline" in spaced
    assert "Title Slide" in spaced
    assert "I'll need" in spaced
    assert "bloomberg.com" in cleaned
    brief = _clean_document_reply(
        "Yesthe. Thequestionspecificallyaskingforthreeethings:\n"
        "1. Whatproblemareyouaddressing?\n"
        "2. Whoisitfor?\n"
        "3. Howdoesyoursolutionaddresstheproblem?"
    )
    assert "Yes the" in brief or "Yes. The" in brief
    assert "What problem are you addressing" in brief
    assert "Who is it for" in brief
    assert "How does your solution address the problem" in brief


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


def test_understand_image_request_model_bounds():
    from ai_service.api.routes.conversation import (
        ConversationUnderstandImagePart,
        ConversationUnderstandImageRequest,
        _image_understand_prompt,
    )

    req = ConversationUnderstandImageRequest(
        image_base64="AAAABBBB",
        mime_type="image/jpeg",
        filename="photo.jpg",
        caption="When is this?",
    )
    assert req.image_base64 == "AAAABBBB"
    assert req.mime_type == "image/jpeg"
    prompt = _image_understand_prompt("When is this?")
    assert "UNTRUSTED" in prompt
    assert "<untrusted_caption>" in prompt
    assert "When is this?" in prompt

    multi = ConversationUnderstandImageRequest(
        images=[
            ConversationUnderstandImagePart(
                image_base64="AAAABBBB",
                mime_type="image/jpeg",
                filename="a.jpg",
            ),
            ConversationUnderstandImagePart(
                image_base64="CCCCDDDD",
                mime_type="image/png",
                filename="b.png",
            ),
        ],
        caption="Compare these",
    )
    assert multi.images is not None
    assert len(multi.images) == 2
    multi_prompt = _image_understand_prompt("Compare these", image_count=2)
    assert "2 member photos" in multi_prompt


def test_document_reply_keeps_long_files_and_skips_coaching_voice():
    from ai_service.api.routes.conversation import (
        DocumentFileIn,
        _document_system_prompt,
        _document_user_prompt,
    )

    system = _document_system_prompt("ask")
    assert system.startswith("REPLY LANGUAGE")
    assert "full chat assistant" in system.lower()
    assert "not a cage" in system.lower()
    assert "use web search" in system.lower()
    assert "uploaded files only" not in system.lower()
    assert "coaching note" in system.lower()
    assert "normal space between every word" in system.lower()
    assert "80-140" not in system
    assert "same language" in system.lower()
    assert "must not switch" in system.lower()
    assert "do not pick a random language" in system.lower()

    long_text = ("Avocado harvest starts in October. " * 200)
    prompt = _document_user_prompt(
        "When does harvest start?",
        [DocumentFileIn(filename="harvest.txt", text=long_text)],
        timezone_name="UTC",
        prior_question="Resume the last answer",
        prior_answer_excerpt="Harvest is in October.",
    )
    assert "harvest.txt" in prompt
    assert "member_question" in prompt
    assert "prior_question" in prompt
    assert "Resume the last answer" in prompt
    assert "vault_file" in prompt
    assert "Avocado harvest starts in October." in prompt
    assert len(prompt) > 2000
    assert "When does harvest start?" in prompt
    assert "use the web when current or public facts are needed" in prompt.lower()
    assert prompt.rstrip().endswith("End research answers with a ## Sources list of markdown links.")
    assert "do not reply in french" in prompt.lower()
    assert prompt.find("Avocado harvest starts in October.") < prompt.find("When does harvest start?")
    assert prompt.find("When does harvest start?") < prompt.rfind("REPLY LANGUAGE")


def test_document_reply_allows_empty_files_as_full_assistant():
    from ai_service.api.routes.conversation import _document_user_prompt

    prompt = _document_user_prompt("Who are the richest people?", [], timezone_name="UTC")
    assert "(none attached)" in prompt
    assert "library_count: 0" in prompt
    assert "still answer it as a full assistant" in prompt.lower()
    assert "use the web" in prompt.lower()


def test_document_reply_distinguishes_library_from_selected_file():
    from ai_service.api.routes.conversation import (
        DocumentFileIn,
        LibraryItemIn,
        _document_system_prompt,
        _document_user_prompt,
    )

    system = _document_system_prompt("ask")
    assert "personal library" in system.lower()
    assert "not the whole library" in system.lower()

    prompt = _document_user_prompt(
        "How many documents do I have in the library?",
        [
            DocumentFileIn(filename="cv.pdf", text="Senior engineer CV.", selected=True),
            DocumentFileIn(filename="notes.txt", text="Project notes.", selected=False),
        ],
        timezone_name="UTC",
        library=[
            LibraryItemIn(filename="cv.pdf", selected=True, readable=True),
            LibraryItemIn(filename="notes.txt", selected=False, readable=True),
            LibraryItemIn(filename="scan.pdf", selected=False, readable=False),
        ],
    )
    assert "library_count: 3" in prompt
    assert "selected_count: 1" in prompt
    assert "not the whole library" in prompt
    assert "cv.pdf [selected, readable]" in prompt
    assert "scan.pdf [no_extracted_text]" in prompt
    assert "SELECTED: yes" in prompt
    assert "SELECTED: no" in prompt
    assert "notes.txt" in prompt


def test_document_reply_includes_thread_and_export_rules():
    from ai_service.api.routes.conversation import (
        ThreadTurnIn,
        _document_system_prompt,
        _document_user_prompt,
        _split_document_export,
    )

    system = _document_system_prompt("ask")
    assert "ongoing chat" in system.lower()
    assert "EXPORT: pdf" in system
    assert "do not ask what to convert" in system.lower()
    assert "never ask them to clarify" in system.lower()

    letter = "Dear Hiring Manager,\n\nI am writing to apply.\n\nWarm regards,\nAda"
    prompt = _document_user_prompt(
        "Convert it to a PDF",
        [],
        timezone_name="UTC",
        prior_question="Write a cover letter",
        prior_answer_excerpt=letter,
        thread=[
            ThreadTurnIn(role="user", text="Write a cover letter"),
            ThreadTurnIn(role="assistant", text=letter),
        ],
    )
    assert "thread_assistant" in prompt
    assert "Dear Hiring Manager" in prompt
    assert "do not ask them to paste" in prompt.lower()
    assert prompt.find("Dear Hiring Manager") < prompt.find("Convert it to a PDF")

    confirm = _document_user_prompt(
        "yes",
        [],
        timezone_name="UTC",
        prior_answer_excerpt="Would you like this pitch converted to a PDF?",
        last_turn_was_question=True,
    )
    assert "last assistant turn asked a question" in confirm.lower()
    assert "member_question is the member's answer" in confirm.lower()

    export, body = _split_document_export("EXPORT: pdf\n\n" + letter)
    assert export == "pdf"
    assert body.startswith("Dear Hiring Manager")
    assert _split_document_export(letter) == ("none", letter)
