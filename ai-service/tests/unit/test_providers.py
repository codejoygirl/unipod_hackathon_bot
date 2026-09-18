from ai_service.providers.anthropic import AnthropicProvider
from ai_service.providers.base import ChatModel, EmbeddingModel
from ai_service.providers.gemini import GeminiProvider
from ai_service.providers.openai import OpenAIProvider


def test_provider_protocol_conformance():
    openai_p = OpenAIProvider(api_key="mock-key")
    gemini_p = GeminiProvider(api_key="mock-key")
    anthropic_p = AnthropicProvider(api_key="mock-key")

    # OpenAI implements both
    assert isinstance(openai_p, ChatModel)
    assert isinstance(openai_p, EmbeddingModel)

    # Gemini implements both
    assert isinstance(gemini_p, ChatModel)
    assert isinstance(gemini_p, EmbeddingModel)

    # Anthropic implements ChatModel only
    assert isinstance(anthropic_p, ChatModel)