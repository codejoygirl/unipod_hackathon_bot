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


def test_complete_link_answer_fills_missing_urls_with_intro():
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
    )

    assert "https://youtu.be/yVji4ZQECVw" in completed
    assert "https://youtu.be/6q4uPBO_sDc" in completed
    assert "https://youtu.be/-6G7LXiu47o" in completed
    assert "Welcome session + Module 0" in completed
    # Lead intros come from the model when present — never hardcode EN/FR strings here.
    assert set(ids) == {"E1", "E2", "E3"}


def test_complete_link_answer_uses_links_intro_when_not_recordings():
    chunks = [
        _chunk("E1", "Signup form https://example.com/form"),
        _chunk("E2", "Slides https://example.com/slides"),
    ]
    completed, _ids = AnswerSynthesizer._complete_link_answer_from_evidence(
        query="Send me the links",
        answer="Here are the links:\n1. Signup form\nhttps://example.com/form",
        evidence_chunks=chunks,
        link_mode="assets",
    )
    assert "https://example.com/form" in completed
    assert "https://example.com/slides" in completed
    assert "Here are the links:" in completed
    assert "https://example.com/slides" in completed
    assert "session recordings" not in completed.lower()


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
    )
    assert meet_a in completed
    assert meet_b not in completed
    assert meet_c not in completed
    assert "keep them to yourself" not in completed.lower()
    assert completed.count("https://") == 1
    assert set(ids) == {"E1"}


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
        answer=f"1. Join\n{meet}",
        evidence_chunks=chunks,
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
        answer="1. Welcome\nhttps://youtu.be/yVji4ZQECVw",
        evidence_chunks=chunks,
    )
    assert "https://youtu.be/yVji4ZQECVw" in completed
    assert "drive.google.com/file/d/1E5RrwULX8zSjwxHFSxiQzCTtp20ulYQ8/view" in completed
    assert completed.count("1E5RrwULX8zSjwxHFSxiQzCTtp20ulYQ8") == 1
    assert "usp=sharing" not in completed
    assert "meetingrecap" in completed
    assert "spreadsheets" not in completed
    assert "these are all" not in completed.lower()
    assert set(ids) == {"E1", "E2", "E3", "E4"}


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
