"""Vendor-agnostic typing.Protocol definitions for AI/ML model providers."""

from pathlib import Path
from typing import Protocol, Sequence, runtime_checkable

from ai_service.providers.base import (
    ChatRequest,
    ChatResponse,
    RerankResult,
    TranscriptionResult,
)


@runtime_checkable
class ChatModel(Protocol):
    """Protocol for LLM synthesis and conversational reasoning."""

    async def generate(self, request: ChatRequest) -> ChatResponse:
        """Synthesize a response based on the structured chat request."""
        ...


@runtime_checkable
class VisionModel(Protocol):
    """Protocol for Multimodal LLM synthesis."""

    async def analyze(self, uri: str) -> ChatResponse:
        """Analyze a visual input."""
        ...


@runtime_checkable
class EmbeddingModel(Protocol):
    """Protocol for dense vector representation generation."""

    async def embed(self, texts: Sequence[str]) -> list[list[float]]:
        """Batch-embed passages or documents."""
        ...

    async def embed_query(self, text: str) -> list[float]:
        """Embed a single query string."""
        ...


@runtime_checkable
class RerankingModel(Protocol):
    """Protocol for cross-encoder reranking of candidate passages."""

    async def rerank(
        self,
        query: str,
        documents: Sequence[str],
        top_n: int | None = None,
    ) -> list[RerankResult]:
        """Score candidate documents against a query."""
        ...


@runtime_checkable
class TranscriptionModel(Protocol):
    """Protocol for speech-to-text audio processing."""

    async def transcribe(
        self,
        source: str | Path | bytes,
        language: str | None = None,
    ) -> TranscriptionResult:
        """Transcribe an audio source into text with segment timestamps."""
        ...


@runtime_checkable
class TranslationModel(Protocol):
    """Protocol for neural machine translation."""

    async def translate(
        self,
        text: str,
        target_language: str,
        source_language: str | None = None,
    ) -> str:
        ...


@runtime_checkable
class LanguageDetectionModel(Protocol):
    """Protocol for identifying language code of user queries."""

    async def detect(self, text: str) -> str:
        ...
