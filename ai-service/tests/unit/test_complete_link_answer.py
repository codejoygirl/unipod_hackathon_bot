import re
import uuid

import pytest

from ai_service.generation.synthesizer import AnswerSynthesizer
from ai_service.providers.base import ChatRequest, ChatResponse
from ai_service.schemas.evidence import EvidenceChunk
from ai_service.schemas.retrieval import AuthorityTier, CandidateChunk


class _GroundedChat:
    async def generate(self, request: ChatRequest) -> ChatResponse:
        if request.extra_params.get("response_format") == {"type": "json_object"}:
            return ChatResponse(
                content=(
                    '{"state":"POSSIBLE",'
                    '"answer":"بدأ الهاكاثون في 18 سبتمبر 2026 [E1].",'
                    '"evidence_ids_used":["E1"]}'
                ),
                model="test",
            )

        draft = str(request.messages[-1].content)
        marker = "<draft_reply>"
        if marker in draft:
            draft = draft.split(marker, 1)[1].split("</draft_reply>", 1)[0].strip()
        return ChatResponse(content=draft, model="test")


def _chunk(eid: str, content: str) -> EvidenceChunk:
    uid = uuid.uuid4()
    return EvidenceChunk(
        evidence_id=eid,
        chunk_id=uid,
        source_id=uid,
        source_name="UniPods",
        source_uri="whatsapp://export/fake",
        source_type="whatsapp",
        content=content,
        authority_tier=AuthorityTier.COMMUNITY_DISCUSSION,
        retrieval_score=0.9,
    )


def _candidate(content: str, score: float) -> CandidateChunk:
    uid = uuid.uuid4()
    return CandidateChunk(
        chunk_id=uid,
        source_id=uid,
        version_id=uid,
        source_name="UniPods",
        source_uri="whatsapp://export/fake",
        source_type="whatsapp",
        content=content,
        token_count=20,
        authority_tier=AuthorityTier.COMMUNITY_DISCUSSION,
        community_id=str(uid),
        rrf_score=score,
        final_score=score,
    )


@pytest.mark.asyncio
async def test_cross_language_evidence_below_english_floor_still_synthesizes():
    synthesizer = AnswerSynthesizer(chat_model=_GroundedChat())

    result = await synthesizer.synthesize_grounded_answer(
        query="متى بدأ الهاكاثون؟",
        candidates=[
            _candidate(
                "The hackathon started on September 18, 2026 and runs until September 24, 2026.",
                0.48,
            )
        ],
        language_hint="ar",
    )

    assert result.answer
    assert "18 سبتمبر 2026" in result.answer
    assert result.citations


def test_complete_link_answer_fills_when_model_lists_no_urls():
    chunks = [
        _chunk("E1", "Welcome session + Module 0 (10 September) https://youtu.be/yVji4ZQECVw"),
        _chunk("E2", "Module 1 class session (15 September) https://youtu.be/6q4uPBO_sDc"),
        _chunk("E3", "Module 1 Problem Statement coaching https://youtu.be/-6G7LXiu47o"),
    ]

    completed, ids = AnswerSynthesizer._complete_link_answer_from_evidence(
        query="Send me all the recording links of the sessions",
        answer="Here are the session recordings:",
        evidence_chunks=chunks,
        link_mode="recordings",
        link_focus="many",
    )

    assert "https://youtu.be/yVji4ZQECVw" in completed
    assert "https://youtu.be/6q4uPBO_sDc" in completed
    assert "https://youtu.be/-6G7LXiu47o" in completed
    assert "Welcome session + Module 0" in completed
    assert set(ids) == {"E1", "E2", "E3"}


def test_complete_link_answer_many_keeps_model_list_without_corpus_pad():
    chunks = [
        _chunk("E1", "Welcome session + Module 0 (10 September) https://youtu.be/yVji4ZQECVw"),
        _chunk("E2", "Module 1 class session (15 September) https://youtu.be/6q4uPBO_sDc"),
        _chunk("E3", "Module 1 Problem Statement coaching https://youtu.be/-6G7LXiu47o"),
    ]
    partial = "1. Wadhwani Module 1 coaching\nhttps://youtu.be/-6G7LXiu47o"

    completed, ids = AnswerSynthesizer._complete_link_answer_from_evidence(
        query="Send me all the recording links of the sessions",
        answer=partial,
        evidence_chunks=chunks,
        link_mode="recordings",
        link_focus="many",
    )

    assert "https://youtu.be/-6G7LXiu47o" in completed
    assert "https://youtu.be/yVji4ZQECVw" not in completed
    assert "https://youtu.be/6q4uPBO_sDc" not in completed
    assert ids == ["E3"]


def test_complete_link_answer_uses_links_intro_when_not_recordings():
    chunks = [
        _chunk("E1", "Signup form https://example.com/form"),
        _chunk("E2", "Slides https://example.com/slides"),
    ]
    completed, _ids = AnswerSynthesizer._complete_link_answer_from_evidence(
        query="Send me the links",
        answer=(
            "Here are the links:\n"
            "1. Signup form\nhttps://example.com/form\n"
            "2. Slides\nhttps://example.com/slides"
        ),
        evidence_chunks=chunks,
        link_mode="assets",
        link_focus="many",
    )
    assert "https://example.com/form" in completed
    assert "https://example.com/slides" in completed
    assert "Here are the links:" in completed
    assert "session recordings" not in completed.lower()


def test_complete_link_answer_only_document_ask_keeps_guidelines_drive():
    guidelines = (
        "https://drive.google.com/file/d/1Pcf4ZwhHWcdQXO8gMV27de3OoZjuLi_p/view?usp=sharing"
    )
    other = "https://learn.mit.edu/universal-learning/ai"
    signup = (
        "https://web.nen.wfglobal.org/en/login?mode=createAccount&amp;source=student"
    )
    chunks = [
        _chunk(
            "E1",
            "UniPods Hackathon Guidelines (PDF)\n" + guidelines,
        ),
        _chunk("E2", "General course information page\n" + other),
        _chunk("E3", "Wadhwani Ignite signup\n" + signup),
        _chunk("E4", "WhatsApp invite\nhttps://chat.whatsapp.com/KqId6NMKUDxKQUstvjTHPE"),
    ]
    dump = (
        "Here are the hackathon guidelines documents:\n\n"
        f"1. General course information page\n{other}\n\n"
        f"2. Wadhwani Ignite signup\n{signup}\n\n"
        f"3. Hackathon guidelines document (English)\n{guidelines}\n\n"
        "4. WhatsApp invite\nhttps://chat.whatsapp.com/KqId6NMKUDxKQUstvjTHPE"
    )
    completed, ids = AnswerSynthesizer._complete_link_answer_from_evidence(
        query="Send me the hackathon guideline document",
        answer=dump,
        evidence_chunks=chunks,
        link_mode="assets",
        link_focus="one",
    )
    assert guidelines in completed
    assert other not in completed
    assert completed.count("https://") == 1
    assert "chat.whatsapp.com" not in completed
    assert "&amp;" not in completed
    assert "E1" in ids


def test_complete_link_answer_named_guide_link_keeps_best_drive():
    demo = (
        "https://drive.google.com/file/d/1YZvsMxcbqEvWZk-Zx3IBHYxwdhXRs5O/view?usp=drivelink"
    )
    other = (
        "https://drive.google.com/file/d/1jkYKc8xmP1Msh7jPGaC0lPsZceGGkFh/view?usp=sharing"
    )
    chunks = [
        _chunk("E1", "UniPods Video Demo Guide\n" + demo),
        _chunk("E2", "Wadhwani Ignite Module 1 and 2 slides\n" + other),
        _chunk("E3", "YouTube\nhttps://youtu.be/yVji4ZQECVw"),
    ]
    # Model already chose the right first URL; focus=one preserves that choice.
    dump = (
        "Here is the UniPods Video Demo Guide:\n\n"
        f"1. UniPods Video Demo Guide\n{demo}\n\n"
        f"2. Google Drive file\n{other}\n\n"
        "3. YouTube recording\nhttps://youtu.be/yVji4ZQECVw"
    )
    completed, ids = AnswerSynthesizer._complete_link_answer_from_evidence(
        query="Give me the UniPods Video Demo Guide link",
        answer=dump,
        evidence_chunks=chunks,
        link_mode="assets",
        link_focus="one",
    )
    assert demo in completed
    assert other not in completed
    assert "youtu.be" not in completed
    assert completed.count("https://") == 1
    assert set(ids) <= {"E1"}


def test_complete_link_answer_does_not_expand_beyond_model_list():
    a = "https://drive.google.com/file/d/aaa/view"
    b = "https://drive.google.com/file/d/bbb/view"
    c = "https://learn.mit.edu/extra"
    chunks = [
        _chunk("E1", "Guide A\n" + a),
        _chunk("E2", "Guide B\n" + b),
        _chunk("E3", "Extra\n" + c),
    ]
    completed, _ids = AnswerSynthesizer._complete_link_answer_from_evidence(
        query="Send the guide link",
        answer=f"1. Guide A\n{a}",
        evidence_chunks=chunks,
        link_mode="assets",
        link_focus="one",
    )
    assert a in completed
    assert b not in completed
    assert c not in completed


def test_complete_link_answer_singular_asset_does_not_dump_corpus():
    chunks = [
        _chunk("E1", "MIT Universal AI course https://learn.mit.edu/universal-learning/ai"),
        _chunk("E2", "Team declaration https://docs.google.com/spreadsheets/d/abc"),
        _chunk("E3", "Open hour https://teams.microsoft.com/meet/111?p=x"),
        _chunk(
            "E4",
            "Professor: @meti_bot Can you send me the team member declaration "
            "https://docs.google.com/spreadsheets/d/xyz",
        ),
    ]
    completed, ids = AnswerSynthesizer._complete_link_answer_from_evidence(
        query="Send the first onboarding link for the programme",
        answer=(
            "Here is the onboarding link:\n\n"
            "1. MIT Universal AI course\n"
            "https://learn.mit.edu/universal-learning/ai"
        ),
        evidence_chunks=chunks,
        link_mode="assets",
        link_focus="one",
    )
    assert "https://learn.mit.edu/universal-learning/ai" in completed
    assert "docs.google.com" not in completed
    assert "teams.microsoft.com" not in completed
    assert "Can you send me" not in completed
    assert set(ids) == {"E1"}


def test_complete_link_answer_singular_meeting_keeps_one():
    meet_a = "https://teams.microsoft.com/meet/419860837373470?p=aaaa"
    meet_b = "https://teams.microsoft.com/meet/369123389215172?p=bbbb"
    meet_c = "https://teams.microsoft.com/l/meetup-join/19%3ameeting_xxx%40thread.v2/0"
    chunks = [
        _chunk("E1", f"Reminder! Today at 3pm CAT we start\n{meet_a}"),
        _chunk("E2", f"Don't keep them to yourself! ask anything\n{meet_b}"),
        _chunk("E3", f"MIT Universal AI Welcome and onboarding\n{meet_c}"),
    ]
    draft = (
        f"1. Reminder! Today at 3pm\n{meet_a}\n"
        f"2. keep them to yourself\n{meet_b}\n"
        f"3. Microsoft Teams meeting\n{meet_c}"
    )
    completed, ids = AnswerSynthesizer._complete_link_answer_from_evidence(
        query="Send the first onboarding meeting link for the programme",
        answer=draft,
        evidence_chunks=chunks,
        link_mode="meetings",
        link_focus="one",
    )
    # focus=one picks the onboarding-titled meeting via evidence overlap with the ask.
    assert meet_c in completed
    assert meet_a not in completed
    assert meet_b not in completed
    assert completed.count("https://") == 1
    assert set(ids) == {"E3"}


def test_weak_link_label_rejects_date_fact_captions():
    assert AnswerSynthesizer._is_weak_link_label(
        "Expected completion date: October 18, 2026"
    )
    assert AnswerSynthesizer._is_weak_link_label("18/10/2026")
    assert not AnswerSynthesizer._is_weak_link_label(
        "Hackathon guidelines document (English)"
    )


def test_restore_urls_unescapes_html_entities_without_truncating():
    raw = (
        "1. Signup\n"
        "https://web.nen.wfglobal.org/en/login?mode=createAccount&amp;source=student\n\n"
        "2. Guidelines\n"
        "https://drive.google.com/file/d/1Pcf4ZwhHWcdQXO8gMV27de3OoZjuLip/view?usp=sharing"
    )
    fixed = AnswerSynthesizer.restore_urls_in_answer(raw)
    assert "&amp;" not in fixed
    assert "mode=createAccount&source=student" in fixed
    assert "1Pcf4ZwhHWcdQXO8gMV27de3OoZjuLip" in fixed


def test_prefer_document_title_over_date_fact_near_drive_url():
    drive = "https://drive.google.com/file/d/1Pcf4ZwhHWcdQXO8gMV27de3OoZjuLip/view?usp=sharing"
    chunks = [
        _chunk(
            "E1",
            "Hackathon guidelines document (English)\n"
            "Expected completion date: October 18, 2026\n"
            + drive,
        ),
    ]
    completed, ids = AnswerSynthesizer._complete_link_answer_from_evidence(
        query="Just the document for the hackathon guidelines",
        answer=(
            "Here are the hackathon guidelines documents:\n\n"
            "1. Expected completion date: October 18, 2026\n"
            f"{drive}"
        ),
        evidence_chunks=chunks,
        link_mode="assets",
    )
    assert "Expected completion date" not in completed
    assert "Hackathon guidelines" in completed or "guidelines" in completed.lower()
    assert drive in completed
    assert "&amp;" not in completed
    assert set(ids) == {"E1"}


def test_weak_link_label_rejects_speaker_chat_paste():
    assert AnswerSynthesizer._is_weak_link_label(
        "Jackson: Hello everyone, am Ssekyanzi Jackson a Software Engineer"
    )
    assert AnswerSynthesizer._is_weak_link_label(
        "Fleva: Hey. I am Yemima and I am from Togo. I am"
    )
    assert not AnswerSynthesizer._is_weak_link_label(
        "Jackson (software engineer) - LinkedIn"
    )
    assert not AnswerSynthesizer._is_weak_link_label("MIT Universal AI onboarding call")


def test_weak_link_label_rejects_meeting_chat_crumbs():
    assert AnswerSynthesizer._is_weak_link_label(
        "Reminder! Today at *3pm CAT (2pm WA / 4pm EA local time) we"
    )
    assert AnswerSynthesizer._is_weak_link_label(
        "'t keep them to yourself! This is a great opportunity to ask anything"
    )
    assert AnswerSynthesizer._is_weak_link_label(
        "There are *15* people in the Teams call just waiting."
    )
    assert AnswerSynthesizer._is_weak_link_label(
        "Genial: *Genial joined from the community*"
    )
    assert not AnswerSynthesizer._is_weak_link_label("MIT Universal AI onboarding call")


def test_complete_link_answer_keeps_social_urls_from_answer_on_assets_ask():
    linkedin = "https://www.linkedin.com/in/ssekyanzi-jackson"
    chunks = [
        _chunk(
            "E1",
            "Jackson: Hello everyone, am Ssekyanzi Jackson a Software Engineer\n" + linkedin,
        ),
        _chunk("E2", "Signup https://example.com/form"),
    ]
    completed, _ids = AnswerSynthesizer._complete_link_answer_from_evidence(
        query="Give me the social media handles of the platform",
        answer=(
            "Here are the social handles shared in the community:\n\n"
            "1. Jackson (software engineer) - LinkedIn\n"
            f"{linkedin}\n\n"
            "2. Signup form\n"
            "https://example.com/form"
        ),
        evidence_chunks=chunks,
        link_mode="assets",
    )
    assert linkedin in completed
    assert "Jackson (software engineer) - LinkedIn" in completed
    assert "Hello everyone, am Ssekyanzi" not in completed


def test_fallback_label_uses_host_shape_for_profiles():
    assert (
        AnswerSynthesizer._fallback_label("https://www.linkedin.com/in/someone")
        == "LinkedIn profile"
    )
    assert AnswerSynthesizer._fallback_label("https://github.com/kiongosss") == "GitHub profile"


def test_parse_link_list_same_line_caption_url():
    pairs = AnswerSynthesizer._parse_link_list_from_answer(
        "1. Follow, like, repost and share our videos so we can reach even more: "
        "https://www.tiktok.com/@timbuktoounipods?r=1&_t=ZS-99cbj4oT5lc"
    )
    assert len(pairs) == 1
    url, label = pairs[0]
    assert "tiktok.com/@timbuktoounipods" in url
    assert "Follow, like, repost" in label
    assert AnswerSynthesizer._is_weak_link_label(label)


def test_polisher_splits_same_line_caption_url():
    from ai_service.generation.polisher import AnswerPolisher

    raw = (
        "The TikTok handle shared for the UniPods platform is:\n\n"
        "1. Follow, like, repost and share our videos so we can reach even more: "
        "https://www.tiktok.com/@timbuktoounipods?r=1&_t=ZS-99cbj4oT5lc\n"
    )
    out = AnswerPolisher.deterministic_cleanup(raw)
    assert ":\nhttps://www.tiktok.com/@timbuktoounipods" not in out.replace(" ", "")
    assert re.search(
        r"1\.\s+Follow, like, repost[^\n]+\nhttps://www\.tiktok\.com/@timbuktoounipods",
        out,
    )


def test_complete_link_answer_skips_teams_meet_joins_for_recordings():
    meet = "https://teams.microsoft.com/meet/419860837373470?p=fake"
    chunks = [
        _chunk(
            "E1",
            "Live coaching session with Q&A on Module 1, Problem Statement via this link - Join "
            + meet,
        ),
        _chunk("E2", "Welcome session + Module 0 https://youtu.be/yVji4ZQECVw"),
        _chunk("E3", "Wadhwani Module 1 coaching/Q&A - 17 September https://youtu.be/-6G7LXiu47o"),
    ]
    completed, ids = AnswerSynthesizer._complete_link_answer_from_evidence(
        query="Send me all the recording links",
        answer="Here are the session recordings:",
        evidence_chunks=chunks,
        link_mode="recordings",
        link_focus="many",
    )
    assert meet not in completed
    assert "https://youtu.be/yVji4ZQECVw" in completed
    assert "https://youtu.be/-6G7LXiu47o" in completed
    assert "via this link" not in completed
    assert "Welcome session + Module 0" in completed
    assert set(ids) == {"E2", "E3"}


def test_complete_link_answer_includes_drive_and_dedupes_variants():
    chunks = [
        _chunk("E1", "Welcome https://youtu.be/yVji4ZQECVw"),
        _chunk(
            "E2",
            "Drive copy https://drive.google.com/file/d/1E5RrwULX8zSjwxHFSxiQzCTtp20ulYQ8/view?usp=sharing",
        ),
        _chunk(
            "E3",
            "Same drive https://drive.google.com/file/d/1E5RrwULX8zSjwxHFSxiQzCTtp20ulYQ8/view",
        ),
        _chunk(
            "E4",
            "Teams recap https://teams.microsoft.com/l/meetingrecap?driveItemId=abc",
        ),
        _chunk(
            "E5",
            "Not a recording https://docs.google.com/spreadsheets/d/15sAD53FA9LZXJ7EzOIzWLALTViaPz2e/edit",
        ),
    ]
    completed, ids = AnswerSynthesizer._complete_link_answer_from_evidence(
        query="Send me the recording links",
        answer=(
            "1. Welcome\nhttps://youtu.be/yVji4ZQECVw\n"
            "2. Drive copy\n"
            "https://drive.google.com/file/d/1E5RrwULX8zSjwxHFSxiQzCTtp20ulYQ8/view?usp=sharing\n"
            "3. Teams recap\n"
            "https://teams.microsoft.com/l/meetingrecap?driveItemId=abc"
        ),
        evidence_chunks=chunks,
        link_mode="recordings",
        link_focus="many",
    )
    assert "https://youtu.be/yVji4ZQECVw" in completed
    assert "drive.google.com/file/d/1E5RrwULX8zSjwxHFSxiQzCTtp20ulYQ8/view" in completed
    assert completed.count("1E5RrwULX8zSjwxHFSxiQzCTtp20ulYQ8") == 1
    assert "usp=sharing" not in completed
    assert "meetingrecap" in completed
    assert "spreadsheets" not in completed
    assert "these are all" not in completed.lower()
    assert {"E1", "E2", "E4"} <= set(ids)


def test_complete_link_answer_skips_person_questions():
    prose = (
        "Diane is an active member of the community who has shared meeting links "
        "and support notes."
    )
    chunks = [
        _chunk(
            "E1",
            "Diane shared https://teams.microsoft.com/l/meetingrecap?driveItemId=abc",
        ),
        _chunk(
            "E2",
            "Drive https://drive.google.com/file/d/1E5RrwULX8zSjwxHFSxiQzCTtp20ulYQ8/view",
        ),
    ]
    completed, ids = AnswerSynthesizer._complete_link_answer_from_evidence(
        query="Who's Diane?",
        answer=prose,
        evidence_chunks=chunks,
    )
    assert completed == prose
    assert ids == []
    assert "Here they are" not in completed


def test_meeting_today_schedule_ask_skips_misclassified_meetings_dump():
    """Schedule yes/no must not append every historical Teams URL when link_mode=meetings."""
    meet = "https://teams.microsoft.com/meet/419860837373470?p=abc"
    recap = "https://teams.microsoft.com/l/meetingrecap?driveId=abc"
    chunks = [
        _chunk(
            "E1",
            "[9/22/2026, 8:15 PM] Diane: Open Hour session this Friday at 3:00 PM CAT\n" + meet,
        ),
        _chunk("E2", f"Recap from onboarding {recap}"),
    ]
    prose = (
        "There is no programme meeting on Thursday 24 September 2026. "
        "Open Hour is Friday 26 September at 3:00 PM CAT."
    )
    completed, ids = AnswerSynthesizer._complete_link_answer_from_evidence(
        query="Are we having a meeting today?",
        answer=prose,
        evidence_chunks=chunks,
        link_mode="meetings",
    )
    assert completed.strip() == prose.strip()
    assert meet not in completed
    assert recap not in completed
    assert ids == []


def test_complete_link_answer_meeting_links_only_keeps_joins():
    meet = "https://teams.microsoft.com/meet/419860837373470?p=abc"
    light = (
        "https://teams.microsoft.com/light-meetings/launch?p=cM5gEphg2N9i9d0w7G&anon=true"
    )
    linkedin = "https://www.linkedin.com/in/matsididi"
    yt = "https://youtu.be/yVji4ZQECVw"
    chunks = [
        _chunk("E1", f"Join coaching {meet}"),
        _chunk("E2", f"Alt join {light}"),
        _chunk("E3", f"Profile {linkedin}"),
        _chunk("E4", f"Recording {yt}"),
    ]
    completed, ids = AnswerSynthesizer._complete_link_answer_from_evidence(
        query="Send me all meeting links ever sent. Also any meeting today?",
        answer="I don't see a meeting scheduled for today in the notes.",
        evidence_chunks=chunks,
    )
    assert meet in completed
    assert light in completed
    assert linkedin not in completed
    assert yt not in completed
    assert "don't see a meeting scheduled for today" in completed
    assert set(ids) == {"E1", "E2"}


def test_teams_meet_and_light_meetings_same_session_deduped():
    """ /meet/{id}?p= and light-meetings with the same meetingCode are one join. """
    meet = "https://teams.microsoft.com/meet/360293151621628?p=cM5gEphg2N9i9d0w7G"
    # coords payload: {"meetingCode":"360293151621628","passcode":"cM5gEphg2N9i9d0w7G"}
    import base64
    import json

    coords = base64.urlsafe_b64encode(
        json.dumps(
            {
                "meetingUrl": meet + "&anon=true",
                "meetingCode": "360293151621628",
                "passcode": "cM5gEphg2N9i9d0w7G",
            }
        ).encode()
    ).decode().rstrip("=")
    light = (
        "https://teams.microsoft.com/light-meetings/launch"
        f"?p=cM5gEphg2N9i9d0w7G&anon=true&coords={coords}"
    )
    chunks = [
        _chunk("E1", f"Saidu\n{light}"),
        _chunk("E2", f"Mamadou Lamine Diallo\n{meet}"),
    ]
    draft = (
        "Here are the meeting links:\n\n"
        f"1. Saidu\n{light}\n\n"
        f"2. Mamadou Lamine Diallo\n{meet}\n"
    )
    completed, ids = AnswerSynthesizer._complete_link_answer_from_evidence(
        query="meeting links for tomorrow",
        answer=draft,
        evidence_chunks=chunks,
        link_mode="meetings",
    )
    assert completed.count("teams.microsoft.com") == 1
    assert meet in completed
    assert "light-meetings" not in completed
    assert "Saidu" not in completed
    assert "Mamadou" not in completed
    assert "Microsoft Teams meeting" in completed or "Teams" in completed
    assert set(ids) == {"E1", "E2"}


def test_url_dedupe_key_unifies_teams_shapes():
    meet = "https://teams.microsoft.com/meet/360293151621628?p=cM5gEphg2N9i9d0w7G"
    import base64
    import json

    coords = base64.urlsafe_b64encode(
        json.dumps(
            {
                "meetingCode": "360293151621628",
                "passcode": "cM5gEphg2N9i9d0w7G",
            }
        ).encode()
    ).decode().rstrip("=")
    light = f"https://teams.microsoft.com/light-meetings/launch?p=cM5gEphg2N9i9d0w7G&coords={coords}"
    assert AnswerSynthesizer._url_dedupe_key(meet) == AnswerSynthesizer._url_dedupe_key(light)
    assert AnswerSynthesizer._url_dedupe_key(meet).startswith("teams-meet:")


def test_clean_link_label_rejects_weak_and_time_fluff():
    assert AnswerSynthesizer._clean_link_label("Diane") == ""
    assert AnswerSynthesizer._clean_link_label("Saidu") == ""
    assert AnswerSynthesizer._clean_link_label(
        "Your contribution will help the UniPods team better support you at"
    ) == ""
    cleaned = AnswerSynthesizer._clean_link_label(
        "At *3:00 PM CAT* (2:00 PM WAT), there is the first optional METI Open"
    )
    assert cleaned
    assert "METI Open" in cleaned
    assert cleaned.lower().startswith("meti") or "METI" in cleaned
    assert "Wadhwani Ignite session" in AnswerSynthesizer._clean_link_label(
        "Wadhwani Ignite session"
    )


def test_complete_link_answer_rebuilds_weak_titles():
    meet_a = "https://teams.microsoft.com/meet/111111111111111?p=aaaaaaa1"
    meet_b = "https://teams.microsoft.com/l/meetup-join/19%3ameeting_MjlkNWYyMjYtMGNhMi00NDM1LTlkNmYtOTZhYTU2MDU4MDc2%40thread.v2/0?context=%7B%22Tid%22%3A%22x%22%7D"
    chunks = [
        _chunk("E1", f"Wadhwani Ignite session\n{meet_a}"),
        _chunk("E2", f"Diane\n{meet_b}"),
    ]
    draft = f"1. Diane\n{meet_a}\n2. Diane\n{meet_b}"
    completed, ids = AnswerSynthesizer._complete_link_answer_from_evidence(
        query="meeting links",
        answer=draft,
        evidence_chunks=chunks,
        link_mode="meetings",
    )
    assert "Wadhwani Ignite session" in completed
    assert meet_a in completed
    assert "meetup-join" in completed or meet_b.split("?", 1)[0] in completed
    # Lone "Diane" must not remain as a numbered title.
    assert not re.search(r"^\d+\.\s*Diane\s*$", completed, flags=re.M)
    assert set(ids) == {"E1", "E2"}


def test_parse_intent_and_link_mode_from_classify_line():
    # Keep this in synthesizer-adjacent tests without importing FastAPI routes.
    import re

    def parse_intent(raw: str) -> str | None:
        text = (raw or "").strip().lower()
        text = re.sub(r"[^a-z_|]+", " ", text).strip()
        if "|" in text:
            left = text.split("|", 1)[0].strip()
            first = (left.split() or [""])[0]
            if first in {"conversational", "knowledge", "out_of_scope", "clarify"}:
                return first
        first = (text.split() or [""])[0]
        return first if first in {"conversational", "knowledge", "out_of_scope", "clarify"} else None

    def parse_link(raw: str) -> str:
        text = (raw or "").strip().lower()
        if "|" in text:
            right = text.split("|", 1)[1].strip()
            token = (re.sub(r"[^a-z_]+", " ", right).strip().split() or [""])[0]
            if token in {"none", "recordings", "meetings", "assets"}:
                return token
        return "none"

    assert parse_intent("knowledge|recordings") == "knowledge"
    assert parse_link("knowledge|recordings") == "recordings"
    assert parse_link("knowledge|meetings") == "meetings"
    assert parse_link("conversational|none") == "none"
    assert parse_intent("knowledge") == "knowledge"
    assert parse_link("knowledge") == "none"


def test_complete_link_answer_french_enregistrements_skips_linkedin():
    yt = "https://youtu.be/-6G7LXiu47o"
    linkedin = "https://www.linkedin.com/in/ismailaseck/"
    lecture = "https://weblab.t.u-tokyo.ac.jp/en/lecture/gci/"
    chunks = [
        _chunk("E1", f"Module 1 recording {yt}"),
        _chunk("E2", f"Noise {linkedin} {lecture}"),
    ]
    completed, ids = AnswerSynthesizer._complete_link_answer_from_evidence(
        query="Envoyez-moi les liens vers les enregistrements, s'il vous plaît",
        answer=f"Voici les enregistrements des sessions :\n1. junk\n{linkedin}\n2. home\n{lecture}",
        evidence_chunks=chunks,
        link_mode="recordings",
        target_language="fr",
    )
    # Keep the model's lead (any language); do not invent a hardcoded FR string.
    assert "Voici les enregistrements des sessions :" in completed
    assert yt in completed
    assert linkedin not in completed
    assert lecture not in completed
    assert set(ids) == {"E1"}


def test_follow_up_envelope_does_not_duplicate_summary_with_link_dump():
    chunks = [
        _chunk("E1", "Welcome session https://youtu.be/yVji4ZQECVw"),
        _chunk("E2", "Module 1 https://youtu.be/6q4uPBO_sDc"),
    ]
    prior = (
        "Here's a bit more detail on today's discussions:\n\n"
        "1. Request for a summary\n"
        "2. Official UniPods updates"
    )
    query = (
        "The member is following up on a previous community answer.\n\n"
        "Original question: What happened today and share available links\n\n"
        f"Previous answer already shown to the member:\n{prior}\n\n"
        "Follow-up: Is that all?"
    )
    completed, ids = AnswerSynthesizer._complete_link_answer_from_evidence(
        query=query,
        answer=prior,
        evidence_chunks=chunks,
    )
    # Must keep the follow-up model answer as-is — not glue a second link dump.
    assert completed == prior or completed.count("Here's a bit more detail") <= 1
    assert ids == []


def test_current_question_reads_follow_up_marker():
    q = (
        "The member is following up on a previous community answer.\n\n"
        "Original question: How many bots are being tested?\n\n"
        "Follow-up: Is that all?"
    )
    assert AnswerSynthesizer._current_question(q) == "Is that all?"
    assert "How many bots" in AnswerSynthesizer._original_question(q)


def test_ensure_section_spacing_adds_blank_after_lead():
    raw = "Here are the updates:\n1. First item\n2. Second item"
    out = AnswerSynthesizer._ensure_section_spacing(raw)
    assert "Here are the updates:\n\n1. First item" in out


def test_clean_link_label_rejects_truncated_export_crumbs():
    assert AnswerSynthesizer._clean_link_label("versal AI course is self-paced") == ""
    assert AnswerSynthesizer._clean_link_label("es ressources. Votre contribution") == ""
    assert "Welcome session" in AnswerSynthesizer._clean_link_label(
        "Welcome session + Module 0 (10 September)"
    )


def test_complete_link_answer_recordings_empty_when_only_noise_urls():
    linkedin = "https://www.linkedin.com/in/ismailaseck/"
    chunks = [_chunk("E1", f"Profile {linkedin}")]
    completed, ids = AnswerSynthesizer._complete_link_answer_from_evidence(
        query="Send me the recording links",
        answer=f"1. Profile\n{linkedin}",
        evidence_chunks=chunks,
        link_mode="recordings",
    )
    assert completed == ""
    assert ids == []


def test_prefer_cleaner_keeps_full_teams_meetup_over_truncated():
    full = (
        "https://teams.microsoft.com/l/meetup-join/"
        "19%3ameeting_MjgxNmY4NGItZTZlMi00OTNmLTk2YzEtMjg0ZTdmYWJjM2Q4%40thread.v2/0"
        "?context=%7B%22Tid%22%3A%22b3e5db5e-2944-4837-99f5-7488ace54319%22"
        "%2C%22Oid%22%3A%2225f213f2-0e2f-4763-83fa-0d909a0e9701%22%7D"
    )
    truncated = full[: full.index("0e2f") + 3]  # cut mid-Oid UUID
    assert AnswerSynthesizer._looks_truncated_url(truncated)
    assert not AnswerSynthesizer._looks_truncated_url(full)
    assert AnswerSynthesizer._url_dedupe_key(full) == AnswerSynthesizer._url_dedupe_key(
        truncated
    )
    assert AnswerSynthesizer._prefer_cleaner_stored_url(truncated, full) == full
    assert AnswerSynthesizer._prefer_cleaner_stored_url(full, truncated) == full


def test_complete_link_answer_expands_truncated_teams_meetup_join():
    full = (
        "https://teams.microsoft.com/l/meetup-join/"
        "19%3ameeting_MjgxNmY4NGItZTZlMi00OTNmLTk2YzEtMjg0ZTdmYWJjM2Q4%40thread.v2/0"
        "?context=%7B%22Tid%22%3A%22b3e5db5e-2944-4837-99f5-7488ace54319%22"
        "%2C%22Oid%22%3A%2225f213f2-0e2f-4763-83fa-0d909a0e9701%22%7D"
    )
    truncated = (
        "https://teams.microsoft.com/l/meetup-join/"
        "19%3ameeting_MjgxNmY4NGItZTZlMi00OTNmLTk2YzEtMjg0ZTdmYWJjM2Q4%40thread.v2/0"
        "?context=%7b%22Tid%22%3a%22b3e5db5e-2944-4837-99f5-7488ace54319%22"
        "%2c%22Oid%22%3a%2225f213f2-0e2"
    )
    chunks = [
        _chunk(
            "E1",
            "METI Cohort 1 Needs Assessment Workshop\n"
            f"Microsoft Teams join link\n{full}",
        ),
        _chunk(
            "E2",
            "Wadhwani Module 1 coaching / Q&A recording\nhttps://youtu.be/-6G7LXiu47o",
        ),
    ]
    draft = (
        "Here is the Teams join link:\n\n"
        f"1. METI Needs Assessment Workshop\n{truncated}\n\n"
        "2. Wadhwani Module 1 coaching/Q&A\nhttps://youtu.be/-6G7LXiu47o"
    )
    completed, ids = AnswerSynthesizer._complete_link_answer_from_evidence(
        query="METI Needs Assessment Workshop Teams join link",
        answer=draft,
        evidence_chunks=chunks,
        link_mode="meetings",
        link_focus="one",
    )
    assert full in completed
    assert truncated not in completed
    assert "youtu.be" not in completed
    assert "E1" in ids


def test_complete_link_answer_focus_one_keeps_related_hub_closing():
    guide = "https://drive.google.com/file/d/1YZvsMxcbq_EvWZk-Zx3IBHYxwdhXRs5O/view"
    hub = "https://drive.google.com/drive/folders/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs"
    chunks = [
        _chunk("E1", f"UniPods Video Demo Guide\n{guide}"),
        _chunk("E2", f"UNIPOD COMMUNITY RESOURCES\nProgram files pack\n{hub}"),
    ]
    draft = (
        "Here is the UniPods Video Demo Guide:\n\n"
        f"1. UniPods Video Demo Guide\n{guide}\n\n"
        "You can also browse the community resources folder for more materials:\n"
        f"{hub}"
    )
    completed, ids = AnswerSynthesizer._complete_link_answer_from_evidence(
        query="UniPods Video Demo Guide link",
        answer=draft,
        evidence_chunks=chunks,
        link_mode="assets",
        link_focus="one",
    )
    assert guide in completed
    assert hub in completed
    assert completed.index(guide) < completed.index(hub)
    # Hub must not become a second numbered list item.
    assert not re.search(r"^2\.\s+", completed, flags=re.M)
    assert "community resources" in completed.lower()
    assert "E1" in ids


def test_label_for_url_prefers_immediate_title_not_pack_name():
    slides = "https://drive.google.com/file/d/1jkYKc8xmP1Msh7jPGaC0lPsZceG_GkFh/view?usp=sharing"
    content = (
        "UniPods / Wadhwani programme resource pack\n"
        "Community knowledge: schedules, slides, Teams joins.\n"
        "=== Wadhwani slides ===\n"
        "Wadhwani Ignite Module 1 and 2 slides\n"
        f"{slides}\n"
    )
    label = AnswerSynthesizer._label_for_url(slides, content)
    assert "Module 1 and 2" in label
    assert "resource pack" not in label.lower()
    assert "community knowledge" not in label.lower()


def test_complete_link_answer_multi_uses_resource_titles():
    slides = "https://drive.google.com/file/d/1jkYKc8xmP1Msh7jPGaC0lPsZceG_GkFh/view?usp=sharing"
    rec = "https://www.youtube.com/watch?v=C9gaW26GfWw"
    chunks = [
        _chunk(
            "E1",
            "UniPods / Wadhwani programme resource pack\n"
            "=== Wadhwani slides ===\n"
            f"Wadhwani Ignite Module 1 and 2 slides\n{slides}\n"
            "=== Wadhwani session recordings (YouTube) ===\n"
            f"Wadhwani Module 2 Part 1 class session recording — 22 September 2026\n{rec}\n",
        ),
    ]
    draft = (
        "Here are the links:\n\n"
        f"1. Wadhwani programme resource pack\n{slides}\n\n"
        f"2. Module 2 Part 1 class session recording\n{rec}"
    )
    completed, _ids = AnswerSynthesizer._complete_link_answer_from_evidence(
        query="Send me the Wadhwani slides and the Module 2 recording",
        answer=draft,
        evidence_chunks=chunks,
        link_mode="recordings",
        link_focus="many",
    )
    assert slides in completed
    assert rec in completed
    assert "Wadhwani Ignite Module 1 and 2 slides" in completed
    assert "resource pack" not in completed.lower()
