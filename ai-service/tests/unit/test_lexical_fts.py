"""Unit tests for FTS lexical term extraction."""

from ai_service.retrieval.service import lexical_terms_for_fts


def test_french_question_keeps_hackathon_drops_stopwords() -> None:
    assert lexical_terms_for_fts("Y a-t-il un hackathon ?") == "hackathon"


def test_english_question_keeps_content_words() -> None:
    terms = lexical_terms_for_fts("Is there a hackathon going on?")
    assert "hackathon" in terms
    assert "there" not in terms.split()
    assert "is" not in terms.split()


def test_conversational_question_keeps_topic_token() -> None:
    assert lexical_terms_for_fts("What do you know about the hackathon?") == "hackathon"
    assert lexical_terms_for_fts("Who's Diane?") == "diane"
