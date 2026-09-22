"""Vendor-agnostic typing.Protocol definitions for all AI/ML model providers.

Defines explicit input and output contracts for chat generation, embeddings,
reranking, transcription, translation, and language detection.
"""

from collections.abc import Sequence
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any, Protocol, runtime_checkable


# ============================================================================
# Value Objects / Data Transfer Objects
# ============================================================================


@dataclass(frozen=True)
class MessagePart:
    """A part of a multimodal message (text, image, audio, or video)."""

    type: str  # "text" | "image_url" | "media"
    text: str | None = None
    media_url: str | None = None
    media_mime_type: str | None = None
    media_data: bytes | None = None


@dataclass(frozen=True)
class ChatMessage:
    """Standardized chat message payload."""

    role: str  # "system" | "user" | "assistant"
    content: str | Sequence[MessagePart] | None = None
    name: str | None = None


@dataclass(frozen=True)
class ChatRequest:
    """Input payload for text and conversational generation."""

    messages: Sequence[ChatMessage]
    temperature: float = 0.0
    max_tokens: int | None = 2048
    stop_sequences: Sequence[str] = field(default_factory=tuple)
    extra_params: dict[str, Any] = field(default_factory=dict)


@dataclass(frozen=True)
class ChatResponse:
    """Output payload from text and conversational generation."""

    content: str
    model: str
    finish_reason: str | None = None
    prompt_tokens: int = 0
    completion_tokens: int = 0


@dataclass(frozen=True)
class RerankResult:
    """Individual reranked item preserving original document lineage."""

    index: int
    score: float
    document: str | None = None


@dataclass(frozen=True)
class TranscriptSegment:
    """Timestamped segment from an audio/meeting transcription."""

    start_seconds: float
    end_seconds: float
    text: str
    speaker: str | None = None


@dataclass(frozen=True)
class TranscriptionResult:
    """Output payload from speech-to-text engines."""

    text: str
    language: str
    duration_seconds: float = 0.0
    segments: Sequence[TranscriptSegment] = field(default_factory=tuple)


# ============================================================================
# Protocol Definitions
# ============================================================================


@runtime_checkable
class ChatModel(Protocol):
    """Protocol for LLM synthesis and conversational reasoning."""

    async def generate(self, request: ChatRequest) -> ChatResponse:
        """Synthesize a response based on the structured chat request."""
        ...


@runtime_checkable
class EmbeddingModel(Protocol):
    """Protocol for dense vector representation generation."""

    async def embed(self, texts: Sequence[str]) -> list[list[float]]:
        """Batch-embed passages or documents."""
        ...

    async def embed_query(self, text: str) -> list[float]:
        """Embed a single query string, applying asymmetric prefixing if required."""
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
        """Score candidate documents against a query.

        Returns:
            List of RerankResult (index, score) sorted descending by relevance.
        """
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
    """Protocol for neural machine translation with sentinel preservation."""

    async def translate(
        self,
        text: str,
        target_language: str,
        source_language: str | None = None,
    ) -> str:
        """Translate text into the target language code (e.g., 'en', 'es', 'am')."""
        ...


@runtime_checkable
class LanguageDetectionModel(Protocol):
    """Protocol for identifying language code of user queries."""

    async def detect(self, text: str) -> str:
        """Return the primary ISO language code (e.g., 'en', 'es', 'am', 'sw')."""
        ...