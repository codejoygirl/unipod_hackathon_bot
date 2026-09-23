"""Tests for answer polish (markdown strip, mid-word titles, duplicates)."""

from ai_service.generation.polisher import AnswerPolisher


def test_converts_markdown_bold_to_whatsapp_and_links():
    raw = (
        "Here are the details:\n\n"
        "1. **Duration**: Sept 19–24\n"
        "2. [MIT course](https://learn.mit.edu/ai)\n"
    )
    out = AnswerPolisher.deterministic_cleanup(raw)
    assert "**" not in out
    assert "[MIT course]" not in out
    assert "https://learn.mit.edu/ai" in out
    assert "1. Duration: *Sept 19–24*" in out


def test_bolds_list_key_values_with_single_asterisks():
    raw = (
        "Hackathon details:\n\n"
        "1. Starts: September 18, 2026\n"
        "2. Ends: September 24, 2026\n"
        "3. Prize: $5,000\n"
    )
    out = AnswerPolisher.deterministic_cleanup(raw)
    assert "1. Starts: *September 18, 2026*" in out
    assert "2. Ends: *September 24, 2026*" in out
    assert "3. Prize: *$5,000*" in out
    assert "**" not in out


def test_does_not_bold_url_values_or_double_wrap():
    raw = "1. Link: https://example.com/x\n2. Host: *Joy*"
    out = AnswerPolisher.deterministic_cleanup(raw)
    assert "1. Link: https://example.com/x" in out
    assert "2. Host: *Joy*" in out
    assert "**" not in out
    assert "* *Joy* *" not in out


def test_fixes_midword_link_titles():
    raw = (
        "Here are links:\n\n"
        "1. versal AI course is self-paced; Wadhwani\n"
        "https://learn.mit.edu/universal-learning/ai\n\n"
        "2. 9/2026, 15:Nexus Bot: The Wadhwani\n"
        "https://example.com/x\n"
    )
    out = AnswerPolisher.deterministic_cleanup(raw)
    assert "versal" not in out.lower() or "Shared link" in out
    assert "Nexus Bot" not in out or "Shared link" in out
    assert "https://learn.mit.edu/universal-learning/ai" in out


def test_collapses_duplicate_lead():
    raw = (
        "The hackathon encourages practical AI skills.\n\n"
        "1. Submit by Sept 24\n\n"
        "The hackathon encourages practical AI skills.\n\n"
        "1. Submit by Sept 24\n"
    )
    out = AnswerPolisher.deterministic_cleanup(raw)
    assert out.lower().count("the hackathon encourages") == 1


def test_strips_evidence_tags():
    raw = "Joy helps with onboarding [E1] and hosts clinics [E2, E3]."
    out = AnswerPolisher.deterministic_cleanup(raw)
    assert "[E1]" not in out
    assert "[E2" not in out
    assert "Joy helps with onboarding" in out


def test_writer_system_prioritizes_member_question_language():
    from ai_service.generation.polisher import _WRITER_SYSTEM

    lower = _WRITER_SYSTEM.lower()
    assert "member_question" in lower
    assert "translate" in lower
    assert "caption" in lower
    assert "same language" in lower or "same reply language" in lower
    assert "yoruba" in lower
    assert "highest priority" in lower
    assert "whatsapp" in lower
    assert "single asterisk" in lower or "*like this*" in _WRITER_SYSTEM
