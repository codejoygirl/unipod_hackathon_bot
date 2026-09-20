
"""Dynamic provider factory for instantiating AI models."""

import os

from ai_service.providers.protocols import (
    ChatModel,
    EmbeddingModel,
    TranscriptionModel,
    VisionModel,
)
from ai_service.providers.gemini import GeminiProvider
from ai_service.providers.openai import OpenAIProvider
from ai_service.providers.anthropic import AnthropicProvider
from ai_service.providers.openai_compat import OpenAICompatProvider
from ai_service.core.config import settings


class ModelFactory:
    """Factory for resolving and instantiating AI providers."""

    @staticmethod
    def _resolve_provider(
        provider: str | None = None,
        env_var: str = "LLM_PROVIDER",
    ) -> str:
        """Resolve provider from an explicit argument or environment config."""
        provider_name = provider or os.getenv(env_var) or getattr(settings, env_var, "gemini")
        return provider_name.strip().lower()

    @staticmethod
    def _create_provider(provider_name: str):
        """Instantiate the requested provider."""

        if provider_name == "mock":
            from ai_service.providers.mock import MockChatModel
            return MockChatModel()
        if provider_name == "openai":
            return OpenAIProvider()
        if provider_name == "gemini":
            return GeminiProvider()
        if provider_name == "anthropic":
            return AnthropicProvider()
        if provider_name in ("ollama", "vllm", "groq"):
            # Setup specific base URLs depending on provider
            base_url = settings.OLLAMA_BASE_URL if provider_name == "ollama" else settings.VLLM_BASE_URL
            api_key = settings.GROQ_API_KEY if provider_name == "groq" else None
            if provider_name == "groq":
                base_url = "https://api.groq.com/openai/v1"
            return OpenAICompatProvider(base_url=base_url, api_key=api_key)

        raise ValueError(
            f"Unsupported AI provider: '{provider_name}'. "
            "Supported providers: gemini, openai, anthropic, ollama, vllm, groq, mock."
        )

    @classmethod
    def get_chat_model(cls, provider: str | None = None) -> ChatModel:
        primary_provider = cls._resolve_provider(provider, "LLM_PROVIDER")
        if primary_provider == "mock":
            from ai_service.providers.mock import MockChatModel
            return MockChatModel()
        return FallbackChatModel(primary_provider)

    @classmethod
    def get_embedding_model(cls, provider: str | None = None) -> EmbeddingModel:
        provider_name = cls._resolve_provider(provider, "EMBEDDING_PROVIDER")
        if provider_name == "mock":
            from ai_service.providers.mock import MockEmbedder
            return MockEmbedder(dimension=settings.EMBEDDING_DIMENSION)
        return cls._create_provider(provider_name)

    @classmethod
    def get_transcription_model(cls, provider: str | None = None) -> TranscriptionModel:
        provider_name = cls._resolve_provider(provider, "TRANSCRIPTION_PROVIDER")
        if provider_name == "mock":
            from ai_service.providers.mock import MockTranscriptionModel
            return MockTranscriptionModel()
        return cls._create_provider(provider_name)

    @classmethod
    def get_vision_model(cls, provider: str | None = None) -> VisionModel:
        provider_name = cls._resolve_provider(provider, "VISION_PROVIDER")
        if provider_name == "mock":
            # Just return a mock chat model as it implements necessary methods or bypass it
            from ai_service.providers.mock import MockChatModel
            return MockChatModel()
        return cls._create_provider(provider_name)

import logging
from typing import Any
logger = logging.getLogger(__name__)

class FallbackChatModel(ChatModel):
    """Cascades through a ring of providers if the primary fails."""
    
    def __init__(self, primary_provider_name: str):
        self.primary_provider_name = primary_provider_name
        self.fallback_ring = ["gemini", "openai", "vllm", "ollama"]
        
    async def generate(self, request: Any) -> Any:
        last_exception = None
        providers_to_try = [self.primary_provider_name] + [p for p in self.fallback_ring if p != self.primary_provider_name]
        
        for p_name in providers_to_try:
            try:
                model = ModelFactory._create_provider(p_name)
                # In production, wrapped by CircuitBreaker
                return await model.generate(request)
            except Exception as e:
                logger.warning("Provider %s failed with %s, cascading to next...", p_name, type(e).__name__)
                last_exception = e
                
        raise RuntimeError(f"All providers in fallback ring failed. Last error: {str(last_exception)}")
