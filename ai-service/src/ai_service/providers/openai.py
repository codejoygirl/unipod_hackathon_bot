"""Production OpenAI provider for embeddings and chat generation with exponential backoff and jitter."""

import asyncio
from collections.abc import Sequence
import logging
import random
import time
from typing import Any

from openai import (
    APIConnectionError,
    APIStatusError,
    AsyncOpenAI,
    InternalServerError,
    RateLimitError,
)

from ai_service.core.config import settings
from ai_service.providers.base import (
    ChatMessage,
    ChatModel,
    ChatRequest,
    ChatResponse,
    EmbeddingModel,
)

logger = logging.getLogger(__name__)


# In src/ai_service/providers/openai.py

class OpenAIProvider(ChatModel, EmbeddingModel):
    """Integrated OpenAI client implementing ChatModel and EmbeddingModel protocols."""

    def __init__(
        self,
        api_key: str | None = None,
        chat_model: str = "gpt-4o-mini",
        embedding_model: str = "text-embedding-3-small",
        embedding_dimension: int = 1536,
        max_retries: int = 4,
        base_backoff_seconds: float = 0.5,
        max_backoff_seconds: float = 8.0,
        max_concurrent_requests: int = 10,
        batch_size: int = 100,
        client: AsyncOpenAI | None = None,  # Dependency injection support
    ) -> None:
        self.api_key = api_key or settings.OPENAI_API_KEY
        self.chat_model = chat_model
        self.embedding_model = embedding_model
        self.embedding_dimension = embedding_dimension
        self.max_retries = max_retries
        self.base_backoff = base_backoff_seconds
        self.max_backoff = max_backoff_seconds
        self.batch_size = batch_size
        self._semaphore = asyncio.Semaphore(max_concurrent_requests)

        # Injected or lazily-created client
        self._client: AsyncOpenAI | None = client

    @property
    def client(self) -> AsyncOpenAI:
        """Lazy initialization of AsyncOpenAI client singleton."""
        if self._client is None:
            if not self.api_key:
                raise ValueError(
                    "OpenAI API key is missing. Set OPENAI_API_KEY environment variable or pass api_key."
                )
            self._client = AsyncOpenAI(api_key=self.api_key)
        return self._client

    @client.setter
    def client(self, value: AsyncOpenAI | None) -> None:
        """Allow explicit client injection at runtime."""
        self._client = value
        
    async def _execute_with_backoff(self, operation_name: str, coroutine_func, *args, **kwargs) -> Any:
        """Execute an async API operation with full-jitter exponential backoff."""
        attempt = 0
        while True:
            try:
                async with self._semaphore:
                    return await coroutine_func(*args, **kwargs)
            except (RateLimitError, InternalServerError, APIConnectionError) as exc:
                attempt += 1
                if attempt > self.max_retries:
                    logger.error(
                        "OpenAI %s permanently failed after %d attempts. Error: %s",
                        operation_name,
                        attempt,
                        str(exc),
                        exc_info=True,
                    )
                    raise

                # Full jitter calculation
                backoff_limit = min(self.max_backoff, self.base_backoff * (2 ** (attempt - 1)))
                jitter_sleep = random.uniform(0.1, backoff_limit)

                logger.warning(
                    "OpenAI %s encountered transient error: %s. Retrying attempt %d/%d after %.2fs jitter sleep.",
                    operation_name,
                    type(exc).__name__,
                    attempt,
                    self.max_retries,
                    jitter_sleep,
                )
                await asyncio.sleep(jitter_sleep)

            except APIStatusError as exc:
                # 4xx client errors (400, 401, 403, 404) are non-retryable
                logger.error(
                    "OpenAI %s failed with non-retryable status %d: %s",
                    operation_name,
                    exc.status_code,
                    exc.message,
                )
                raise
            except Exception as exc:
                logger.error("OpenAI %s raised unexpected exception: %s", operation_name, str(exc), exc_info=True)
                raise

    # ========================================================================
    # ChatModel Protocol Implementation
    # ========================================================================

    async def generate(self, request: ChatRequest) -> ChatResponse:
        """Synthesize answer using OpenAI Chat Completions endpoint."""
        formatted_messages = []
        for msg in request.messages:
            msg_dict: dict[str, str] = {"role": msg.role, "content": msg.content}
            if msg.name:
                msg_dict["name"] = msg.name
            formatted_messages.append(msg_dict)

        async def _call():
            params: dict[str, Any] = {
                "model": self.chat_model,
                "messages": formatted_messages,
                "temperature": request.temperature,
            }
            if request.max_tokens is not None:
                params["max_tokens"] = request.max_tokens
            if request.stop_sequences:
                params["stop"] = list(request.stop_sequences)

            return await self.client.chat.completions.create(**params)

        response = await self._execute_with_backoff("chat.completions", _call)
        choice = response.choices[0]
        content = choice.message.content or ""

        usage = response.usage
        prompt_tokens = usage.prompt_tokens if usage else 0
        completion_tokens = usage.completion_tokens if usage else 0

        return ChatResponse(
            content=content,
            model=response.model,
            finish_reason=choice.finish_reason,
            prompt_tokens=prompt_tokens,
            completion_tokens=completion_tokens,
        )

    # ========================================================================
    # EmbeddingModel Protocol Implementation
    # ========================================================================

    async def embed(self, texts: Sequence[str]) -> list[list[float]]:
        """Batch-generate dense embeddings, chunking large lists into bounded HTTP calls."""
        if not texts:
            return []

        cleaned_texts = [t.strip() if t.strip() else " " for t in texts]
        results: list[list[float]] = []

        # Slice into chunks of max self.batch_size
        for i in range(0, len(cleaned_texts), self.batch_size):
            batch = cleaned_texts[i : i + self.batch_size]

            async def _call(sub_batch=batch):
                return await self.client.embeddings.create(
                    model=self.embedding_model,
                    input=sub_batch,
                    dimensions=self.embedding_dimension,
                )

            resp = await self._execute_with_backoff("embeddings.create", _call)
            # OpenAI preserves array input order in data list
            for item in resp.data:
                results.append(item.embedding)

        return results

    async def embed_query(self, text: str) -> list[float]:
        """Embed a single query string."""
        cleaned = text.strip() or " "
        embeddings = await self.embed([cleaned])
        return embeddings[0]