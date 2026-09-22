"""Production Anthropic provider for Claude reasoning and grounded generation."""

import asyncio
import logging
import random
from typing import Any

from anthropic import (
    APIConnectionError,
    APIStatusError,
    AsyncAnthropic,
    InternalServerError,
    RateLimitError,
)

from ai_service.core.config import settings
from ai_service.providers.base import (
    ChatMessage,
    ChatModel,
    ChatRequest,
    ChatResponse,
)

logger = logging.getLogger(__name__)


class AnthropicProvider(ChatModel):
    """Anthropic Claude provider implementing ChatModel protocol."""

    def __init__(
        self,
        api_key: str | None = None,
        chat_model: str = "claude-3-5-sonnet-20241022",
        max_retries: int = 4,
        base_backoff_seconds: float = 0.5,
        max_backoff_seconds: float = 8.0,
        max_concurrent_requests: int = 10,
    ) -> None:
        self.api_key = api_key or getattr(settings, "ANTHROPIC_API_KEY", None)
        self.chat_model = chat_model
        self.max_retries = max_retries
        self.base_backoff = base_backoff_seconds
        self.max_backoff = max_backoff_seconds
        self._semaphore = asyncio.Semaphore(max_concurrent_requests)
        self._client: AsyncAnthropic | None = None

    @property
    def client(self) -> AsyncAnthropic:
        """Lazy initialization of AsyncAnthropic client."""
        if self._client is None:
            if not self.api_key:
                raise ValueError(
                    "Anthropic API key is missing. Set ANTHROPIC_API_KEY environment variable or pass api_key."
                )
            self._client = AsyncAnthropic(api_key=self.api_key)
        return self._client

    async def _execute_with_backoff(self, operation_name: str, coroutine_func, *args, **kwargs) -> Any:
        attempt = 0
        while True:
            try:
                async with self._semaphore:
                    return await coroutine_func(*args, **kwargs)
            except (RateLimitError, InternalServerError, APIConnectionError) as exc:
                attempt += 1
                if attempt > self.max_retries:
                    logger.error("Anthropic %s failed after %d retries.", operation_name, attempt)
                    raise

                backoff_limit = min(self.max_backoff, self.base_backoff * (2 ** (attempt - 1)))
                jitter_sleep = random.uniform(0.1, backoff_limit)
                logger.warning(
                    "Anthropic %s encountered %s. Retrying (%d/%d) after %.2fs",
                    operation_name,
                    type(exc).__name__,
                    attempt,
                    self.max_retries,
                    jitter_sleep,
                )
                await asyncio.sleep(jitter_sleep)
            except APIStatusError as exc:
                logger.error("Anthropic %s non-retryable HTTP error: %d", operation_name, exc.status_code)
                raise

    async def generate(self, request: ChatRequest) -> ChatResponse:
        """Synthesize answer using Anthropic Messages API."""
        system_content = ""
        messages = []

        for msg in request.messages:
            if msg.role == "system":
                system_content = msg.content
            else:
                role = "user" if msg.role == "user" else "assistant"
                messages.append({"role": role, "content": msg.content})

        max_tokens = request.max_tokens or 2048

        async def _call():
            params: dict[str, Any] = {
                "model": self.chat_model,
                "messages": messages,
                "max_tokens": max_tokens,
                "temperature": request.temperature,
            }
            if system_content:
                params["system"] = system_content
            if request.stop_sequences:
                params["stop_sequences"] = list(request.stop_sequences)

            return await self.client.messages.create(**params)

        resp = await self._execute_with_backoff("messages.create", _call)

        text_content = ""
        for block in resp.content:
            if getattr(block, "type", "") == "text":
                text_content += block.text

        prompt_tokens = resp.usage.input_tokens if resp.usage else 0
        completion_tokens = resp.usage.output_tokens if resp.usage else 0

        return ChatResponse(
            content=text_content,
            model=resp.model,
            finish_reason=resp.stop_reason,
            prompt_tokens=prompt_tokens,
            completion_tokens=completion_tokens,
        )