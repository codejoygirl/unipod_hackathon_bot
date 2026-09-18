"""Production Google Gemini provider implementing ChatModel and EmbeddingModel protocols."""

import asyncio
from collections.abc import Sequence
import logging
import random
from typing import Any

from google import genai
from google.genai import errors, types

from ai_service.core.config import settings
from ai_service.providers.base import (
    ChatMessage,
    ChatModel,
    ChatRequest,
    ChatResponse,
    EmbeddingModel,
)

logger = logging.getLogger(__name__)


class GeminiProvider(ChatModel, EmbeddingModel):
    """Google GenAI provider for Gemini chat and text embeddings."""

    def __init__(
        self,
        api_key: str | None = None,
        chat_model: str = "gemini-2.5-flash",
        embedding_model: str = "text-embedding-004",
        max_retries: int = 4,
        base_backoff_seconds: float = 0.5,
        max_backoff_seconds: float = 8.0,
        max_concurrent_requests: int = 10,
    ) -> None:
        self.api_key = api_key or settings.GEMINI_API_KEY
        self.chat_model = chat_model
        self.embedding_model = embedding_model
        self.max_retries = max_retries
        self.base_backoff = base_backoff_seconds
        self.max_backoff = max_backoff_seconds
        self._semaphore = asyncio.Semaphore(max_concurrent_requests)
        self._client: genai.Client | None = None

    @property
    def client(self) -> genai.Client:
        """Lazy initialization of Google GenAI Client."""
        if self._client is None:
            if not self.api_key:
                raise ValueError(
                    "Gemini API key is missing. Set GEMINI_API_KEY environment variable or pass api_key."
                )
            self._client = genai.Client(api_key=self.api_key)
        return self._client

    async def _execute_with_backoff(self, operation_name: str, coroutine_func, *args, **kwargs) -> Any:
        attempt = 0
        while True:
            try:
                async with self._semaphore:
                    return await coroutine_func(*args, **kwargs)
            except errors.APIError as exc:
                # 429 (ResourceExhausted) and 503 (Unavailable) are retryable
                if exc.code not in (429, 503, 500):
                    logger.error("Gemini %s failed with non-retryable error: %s", operation_name, str(exc))
                    raise

                attempt += 1
                if attempt > self.max_retries:
                    logger.error("Gemini %s failed after %d retries.", operation_name, attempt)
                    raise

                backoff_limit = min(self.max_backoff, self.base_backoff * (2 ** (attempt - 1)))
                jitter_sleep = random.uniform(0.1, backoff_limit)
                logger.warning(
                    "Gemini %s hit status %s. Retrying (%d/%d) in %.2fs",
                    operation_name,
                    exc.code,
                    attempt,
                    self.max_retries,
                    jitter_sleep,
                )
                await asyncio.sleep(jitter_sleep)

    # ========================================================================
    # ChatModel Protocol Implementation
    # ========================================================================

    async def generate(self, request: ChatRequest) -> ChatResponse:
        """Synthesize answer using Gemini asynchronous generate_content endpoint."""
        contents = []
        system_instruction = None

        for msg in request.messages:
            if msg.role == "system":
                system_instruction = msg.content
            else:
                role = "user" if msg.role == "user" else "model"
                contents.append(types.Content(role=role, parts=[types.Part.from_text(text=msg.content)]))

        config = types.GenerateContentConfig(
            temperature=request.temperature,
            system_instruction=system_instruction,
            max_output_tokens=request.max_tokens,
            stop_sequences=list(request.stop_sequences) if request.stop_sequences else None,
        )

        async def _call():
            # genai.Client exposes client.aio for non-blocking operations
            return await self.client.aio.models.generate_content(
                model=self.chat_model,
                contents=contents,
                config=config,
            )

        resp = await self._execute_with_backoff("generate_content", _call)
        content_text = resp.text or ""

        prompt_tokens = resp.usage_metadata.prompt_token_count if resp.usage_metadata else 0
        completion_tokens = resp.usage_metadata.candidates_token_count if resp.usage_metadata else 0

        return ChatResponse(
            content=content_text,
            model=self.chat_model,
            finish_reason="stop",
            prompt_tokens=prompt_tokens,
            completion_tokens=completion_tokens,
        )

    # ========================================================================
    # EmbeddingModel Protocol Implementation
    # ========================================================================

    async def embed(self, texts: Sequence[str]) -> list[list[float]]:
        """Batch embeddings using Google GenAI text-embedding models."""
        if not texts:
            return []

        cleaned_texts = [t.strip() if t.strip() else " " for t in texts]

        async def _call():
            resp = await self.client.aio.models.embed_content(
                model=self.embedding_model,
                contents=cleaned_texts,
            )
            return [e.values for e in resp.embeddings]

        return await self._execute_with_backoff("embed_content", _call)

    async def embed_query(self, text: str) -> list[float]:
        cleaned = text.strip() or " "
        res = await self.embed([cleaned])
        return res[0]