import pytest
from ai_service.providers.base import (
    ChatModel,
    EmbeddingModel,
    LanguageDetectionModel,
    RerankingModel,
    TranscriptionModel,
    TranslationModel,
)
from ai_service.providers.mock import (
    MockChatModel,
    MockEmbedder,
    MockLanguageDetector,
    MockReranker,
    MockTranscriptionModel,
    MockTranslator,
)


def test_mock_conformance():
    assert isinstance(MockChatModel(), ChatModel)
    assert isinstance(MockEmbedder(), EmbeddingModel)
    assert isinstance(MockReranker(), RerankingModel)
    assert isinstance(MockTranscriptionModel(), TranscriptionModel)
    assert isinstance(MockTranslator(), TranslationModel)
    assert isinstance(MockLanguageDetector(), LanguageDetectionModel)