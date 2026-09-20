import uuid

from ai_service.generation.synthesizer import AnswerSynthesizer
from ai_service.schemas.evidence import EvidenceChunk
from ai_service.schemas.retrieval import AuthorityTier


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

    assert "Here are the session recordings:" in completed
    assert "https://youtu.be/yVji4ZQECVw" in completed
    assert "https://youtu.be/6q4uPBO_sDc" in completed
    assert "https://youtu.be/-6G7LXiu47o" in completed
    assert "Welcome session + Module 0" in completed
    assert set(ids) == {"E1", "E2", "E3"}


def test_complete_link_answer_uses_links_intro_when_not_recordings():
    chunks = [
        _chunk("E1", "Signup form https://example.com/form"),
        _chunk("E2", "Slides https://example.com/slides"),
    ]
    completed, _ids = AnswerSynthesizer._complete_link_answer_from_evidence(
        query="Send me the links",
        answer="1. Signup form\nhttps://example.com/form",
        evidence_chunks=chunks,
        link_mode="assets",
    )
    assert completed.startswith("Here are the links:")
    assert "https://example.com/slides" in completed
    assert "session recordings" not in completed.lower()


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
    assert "meeting join links" in completed.lower()
    assert meet in completed
    assert light in completed
    assert linkedin not in completed
    assert yt not in completed
    assert "don't see a meeting scheduled for today" in completed
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
        answer=f"Here they are:\n1. junk\n{linkedin}\n2. home\n{lecture}",
        evidence_chunks=chunks,
        link_mode="recordings",
        target_language="fr",
    )
    assert "Voici les enregistrements des sessions :" in completed
    assert yt in completed
    assert linkedin not in completed
    assert lecture not in completed
    assert "Here they are" not in completed
    assert set(ids) == {"E1"}


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
