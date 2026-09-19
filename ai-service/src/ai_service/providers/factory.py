"""Dynamic provider factory for instantiating LLM, embedding, and transcription models."""

import os
from typing import Literal

from ai_service.core.config import settings
from ai_service.providers.base import ChatModel, EmbeddingModel, TranscriptionModel
from ai_service.providers.gemini import GeminiProvider
from ai_service.providers.openai import OpenAIProvider


class ModelFactory:
    """Factory for resolving and instantiating AI providers based on environment config."""

    @staticmethod
    def get_chat_model(provider: str | None = None) -> ChatModel:
        """Get the configured chat generation model."""
        provider_name = (provider or os.getenv("LLM_PROVIDER", "gemini")).lower()
        if provider_name == "openai":
            return OpenAIProvider()
        return GeminiProvider()

    @staticmethod
    def get_embedding_model(provider: str | None = None) -> EmbeddingModel:
        """Get the configured dense embedding model."""
        provider_name = (provider or os.getenv("EMBEDDING_PROVIDER", "openai")).lower()
        if provider_name == "openai":
            return OpenAIProvider()
        return GeminiProvider()

    @staticmethod
    def get_transcription_model(provider: str | None = None) -> TranscriptionModel:
        """Get the configured audio transcription model."""
        provider_name = (provider or os.getenv("LLM_PROVIDER", "openai")).lower()
        if provider_name == "gemini":
            return GeminiProvider()
        return OpenAIProvider()

    @staticmethod
    def get_vision_model(provider: str | None = None) -> ChatModel:
        """Get the configured vision-capable chat model."""
        provider_name = (provider or os.getenv("VISION_PROVIDER", "gemini")).lower()
        if provider_name == "openai":
            return OpenAIProvider()
        return GeminiProvider()
