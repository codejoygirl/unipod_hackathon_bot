"""Dynamic provider factory for instantiating AI models."""

from ai_service.core.config import settings
from ai_service.providers.base import (
    ChatModel,
    EmbeddingModel,
    TranscriptionModel,
)
from ai_service.providers.gemini import GeminiProvider
from ai_service.providers.openai import OpenAIProvider


class ModelFactory:
    """Factory for resolving and instantiating AI providers."""

    @staticmethod
    def _resolve_provider(
        provider: str | None = None,
        *,
        setting_name: str,
    ) -> str:
        """Resolve provider from an explicit argument or Settings (.env)."""

        if provider:
            return provider.strip().lower()

        configured = getattr(settings, setting_name, None) or "openai"
        return str(configured).strip().lower()

    @staticmethod
    def _create_provider(provider_name: str):
        """Instantiate the requested provider."""

        if provider_name == "openai":
            return OpenAIProvider()

        if provider_name == "gemini":
            return GeminiProvider()

        raise ValueError(
            f"Unsupported AI provider: '{provider_name}'. "
            "Supported providers: gemini, openai."
        )

    @classmethod
    def get_chat_model(cls, provider: str | None = None) -> ChatModel:
        """Get the configured chat generation model."""

        provider_name = cls._resolve_provider(provider, setting_name="LLM_PROVIDER")
        return cls._create_provider(provider_name)

    @classmethod
    def get_embedding_model(
        cls,
        provider: str | None = None,
    ) -> EmbeddingModel:
        """Get the configured dense embedding model."""

        provider_name = cls._resolve_provider(
            provider,
            setting_name="EMBEDDING_PROVIDER",
        )
        return cls._create_provider(provider_name)

    @classmethod
    def get_transcription_model(
        cls,
        provider: str | None = None,
    ) -> TranscriptionModel:
        """Get the configured audio transcription model."""

        provider_name = cls._resolve_provider(
            provider,
            setting_name="TRANSCRIPTION_PROVIDER",
        )
        return cls._create_provider(provider_name)

    @classmethod
    def get_vision_model(
        cls,
        provider: str | None = None,
    ) -> ChatModel:
        """Get the configured vision-capable chat model."""

        provider_name = cls._resolve_provider(provider, setting_name="VISION_PROVIDER")
        return cls._create_provider(provider_name)
