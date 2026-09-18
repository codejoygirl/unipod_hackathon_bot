import pytest
from ai_service.providers.mock import MockLanguageDetector, MockTranslator
from ai_service.translation.detector import LanguageDetector
from ai_service.translation.expander import QueryExpander
from ai_service.translation.glossary import GlossaryMasker
from ai_service.translation.translator import ProtectedTranslator


def test_glossary_masking_greedy_length_order():
    """Ensure longer phrases take priority over substrings to prevent broken tokens."""
    text = "We need a Kebele ID renewal at the Kebele office."
    protected = ["Kebele", "Kebele ID"]

    res = GlossaryMasker.mask(text, protected)

    # Kebele ID must be token 0
    assert "__GLOSSARY_TOKEN_0__" in res.masked_text
    assert "__GLOSSARY_TOKEN_1__" in res.masked_text
    assert res.token_to_term["__GLOSSARY_TOKEN_0__"] == "Kebele ID"
    assert res.token_to_term["__GLOSSARY_TOKEN_1__"] == "Kebele"

    # Verify unmasking returns exact original string
    restored = GlossaryMasker.unmask(res.masked_text, res.token_to_term)
    assert restored == text


def test_glossary_unmasking_handles_nmt_whitespace_drift():
    """NMT models often introduce spaces: '__ GLOSSARY_TOKEN_0 __'."""
    text_with_drift = "Request for __ GLOSSARY_TOKEN_0 __ was submitted."
    mapping = {"__GLOSSARY_TOKEN_0__": "Emergency Relief Fund"}

    restored = GlossaryMasker.unmask(text_with_drift, mapping)
    assert restored == "Request for Emergency Relief Fund was submitted."


@pytest.mark.asyncio
async def test_protected_translator_preserves_terms():
    mock_trans = MockTranslator()
    translator = ProtectedTranslator(mock_trans)

    result = await translator.translate_with_glossary(
        text="Application for Woreda Pass",
        target_language="am",
        source_language="en",
        protected_terms=["Woreda Pass"],
    )

    # MockTranslator wraps text with [AM] ... but Woreda Pass must remain unchanged
    assert "[AM]" in result
    assert "Woreda Pass" in result


@pytest.mark.asyncio
async def test_query_expander_dual_query_generation():
    detector = LanguageDetector(MockLanguageDetector())
    translator = ProtectedTranslator(MockTranslator())
    expander = QueryExpander(detector, translator)

    # Spanish query expanded into English target
    expansion = await expander.expand_query(
        query="¿Dónde está el centro médico?",
        target_language="en",
        protected_terms=["centro médico"],
    )

    assert expansion.detected_language == "es"
    assert len(expansion.queries) == 2
    assert expansion.queries[0] == "¿Dónde está el centro médico?"
    assert "[EN]" in expansion.queries[1]
    assert "centro médico" in expansion.queries[1]