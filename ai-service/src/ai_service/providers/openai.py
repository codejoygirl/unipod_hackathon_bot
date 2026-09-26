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
    TranscriptionModel,
    TranscriptionResult,
    TranscriptSegment,
)

logger = logging.getLogger(__name__)


# In src/ai_service/providers/openai.py

class OpenAIProvider(ChatModel, EmbeddingModel, TranscriptionModel):
    """Integrated OpenAI client implementing ChatModel, EmbeddingModel, and TranscriptionModel protocols."""

    def __init__(
        self,
        api_key: str | None = None,
        chat_model: str = "gpt-4o-mini",
        embedding_model: str = "text-embedding-3-small",
        transcription_model: str = "whisper-1",
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
        self.transcription_model = transcription_model
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

    def _format_messages(self, request: ChatRequest) -> list[dict[str, Any]]:
        formatted_messages = []
        for msg in request.messages:
            msg_dict: dict[str, Any] = {"role": msg.role}
            if msg.name:
                msg_dict["name"] = msg.name

            if isinstance(msg.content, str):
                msg_dict["content"] = msg.content
            elif msg.content:
                parts = []
                for p in msg.content:
                    if p.type == "text" and p.text:
                        parts.append({"type": "text", "text": p.text})
                    elif p.type == "image_url" and p.media_url:
                        parts.append({"type": "image_url", "image_url": {"url": p.media_url}})
                    elif p.type == "media" and p.media_data and p.media_mime_type:
                        import base64
                        b64_data = base64.b64encode(p.media_data).decode("utf-8")
                        data_uri = f"data:{p.media_mime_type};base64,{b64_data}"
                        parts.append({"type": "image_url", "image_url": {"url": data_uri}})
                msg_dict["content"] = parts

            formatted_messages.append(msg_dict)
        return formatted_messages

    def _message_text(self, msg: ChatMessage) -> str:
        if isinstance(msg.content, str):
            return msg.content
        if not msg.content:
            return ""
        return " ".join(part.text for part in msg.content if part.text)

    def _content_from_choice(self, raw_content: Any) -> str:
        if isinstance(raw_content, list):
            parts: list[str] = []
            for block in raw_content:
                if isinstance(block, dict):
                    if block.get("type") == "text":
                        parts.append(str(block.get("text") or ""))
                else:
                    text = getattr(block, "text", None)
                    if text:
                        parts.append(str(text))
            return "".join(parts).strip()
        return str(raw_content or "").strip()

    async def generate(self, request: ChatRequest) -> ChatResponse:
        """Synthesize answer, using live web search when the caller enables it."""
        if request.extra_params.get("web_search"):
            try:
                return await self._generate_hosted_web(request)
            except Exception as exc:  # noqa: BLE001
                logger.warning("OpenAI hosted web search failed: %s", exc)
            try:
                return await self._generate_tool_web(request)
            except Exception as exc:  # noqa: BLE001
                logger.warning("OpenAI tool web search failed: %s", exc)
        return await self._generate_chat(request)

    async def _generate_hosted_web(self, request: ChatRequest) -> ChatResponse:
        instructions: list[str] = []
        input_items: list[dict[str, str]] = []
        for msg in request.messages:
            text = self._message_text(msg)
            if not text:
                continue
            if msg.role == "system":
                instructions.append(text)
            else:
                role = "assistant" if msg.role == "assistant" else "user"
                input_items.append({"role": role, "content": text})

        async def _call(tool_type: str = "web_search"):
            params: dict[str, Any] = {
                "model": self.chat_model,
                "input": input_items or "Hello",
                "tools": [{"type": tool_type}],
                "temperature": request.temperature,
            }
            if instructions:
                params["instructions"] = "\n\n".join(instructions)
            if request.max_tokens is not None:
                params["max_output_tokens"] = request.max_tokens
            return await self.client.responses.create(**params)

        try:
            response = await self._execute_with_backoff("responses.web_search", _call)
        except APIStatusError:
            response = await self._execute_with_backoff(
                "responses.web_search_preview",
                lambda: _call("web_search_preview"),
            )

        content = str(getattr(response, "output_text", None) or "").strip()
        if not content:
            raise RuntimeError("hosted web search returned empty text")
        usage = getattr(response, "usage", None)
        return ChatResponse(
            content=content,
            model=getattr(response, "model", self.chat_model) or self.chat_model,
            finish_reason="stop",
            prompt_tokens=getattr(usage, "input_tokens", 0) or 0,
            completion_tokens=getattr(usage, "output_tokens", 0) or 0,
        )

    async def _generate_tool_web(self, request: ChatRequest) -> ChatResponse:
        import json

        from ai_service.research.web_search import search_web

        formatted_messages = self._format_messages(request)
        tools = [
            {
                "type": "function",
                "function": {
                    "name": "web_search",
                    "description": (
                        "Search the public web when the question needs current or "
                        "external facts not already in the member files."
                    ),
                    "parameters": {
                        "type": "object",
                        "properties": {
                            "query": {
                                "type": "string",
                                "description": "Search query to run on the public web.",
                            }
                        },
                        "required": ["query"],
                    },
                },
            }
        ]

        async def _first():
            params: dict[str, Any] = {
                "model": self.chat_model,
                "messages": formatted_messages,
                "temperature": request.temperature,
                "tools": tools,
            }
            if request.max_tokens is not None:
                params["max_tokens"] = request.max_tokens
            return await self.client.chat.completions.create(**params)

        first = await self._execute_with_backoff("chat.completions.tools", _first)
        message = first.choices[0].message
        tool_calls = getattr(message, "tool_calls", None) or []
        if not tool_calls:
            usage = first.usage
            return ChatResponse(
                content=self._content_from_choice(message.content),
                model=first.model,
                finish_reason=first.choices[0].finish_reason,
                prompt_tokens=usage.prompt_tokens if usage else 0,
                completion_tokens=usage.completion_tokens if usage else 0,
            )

        follow_messages = list(formatted_messages)
        follow_messages.append(message.model_dump(exclude_none=True))
        for call in tool_calls[:3]:
            raw_args = getattr(getattr(call, "function", None), "arguments", "") or "{}"
            try:
                query = str(json.loads(raw_args).get("query") or "")
            except json.JSONDecodeError:
                query = ""
            brief = await search_web(query) if query else ""
            follow_messages.append({
                "role": "tool",
                "tool_call_id": call.id,
                "content": brief or "No web results.",
            })

        async def _second():
            params: dict[str, Any] = {
                "model": self.chat_model,
                "messages": follow_messages,
                "temperature": request.temperature,
            }
            if request.max_tokens is not None:
                params["max_tokens"] = request.max_tokens
            return await self.client.chat.completions.create(**params)

        second = await self._execute_with_backoff("chat.completions.tool_result", _second)
        choice = second.choices[0]
        usage = second.usage
        return ChatResponse(
            content=self._content_from_choice(choice.message.content),
            model=second.model,
            finish_reason=choice.finish_reason,
            prompt_tokens=usage.prompt_tokens if usage else 0,
            completion_tokens=usage.completion_tokens if usage else 0,
        )

    async def _generate_chat(self, request: ChatRequest) -> ChatResponse:
        """Synthesize answer using OpenAI Chat Completions endpoint."""
        formatted_messages = self._format_messages(request)

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
        usage = response.usage
        return ChatResponse(
            content=self._content_from_choice(choice.message.content),
            model=response.model,
            finish_reason=choice.finish_reason,
            prompt_tokens=usage.prompt_tokens if usage else 0,
            completion_tokens=usage.completion_tokens if usage else 0,
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

    # ========================================================================
    # TranscriptionModel Protocol Implementation
    # ========================================================================

    async def transcribe(
        self,
        source: str | bytes,
        language: str | None = None,
    ) -> TranscriptionResult:
        """Transcribe an audio source using OpenAI Whisper."""
        try:
            import io
            from pathlib import Path
            
            if isinstance(source, (str, Path)):
                with open(source, "rb") as f:
                    file_obj = io.BytesIO(f.read())
                    file_obj.name = Path(source).name
            else:
                file_obj = io.BytesIO(source)
                file_obj.name = "audio.mp3"  # Fallback name
                
            kwargs = {}
            if language:
                kwargs["language"] = language

            async def _call():
                return await self.client.audio.transcriptions.create(
                    model=self.transcription_model,
                    file=file_obj,
                    response_format="verbose_json",
                    timestamp_granularities=["segment"],
                    **kwargs
                )

            resp = await self._execute_with_backoff("audio.transcriptions", _call)
            
            segments = []
            if hasattr(resp, "segments") and resp.segments:
                for seg in resp.segments:
                    segments.append(
                        TranscriptSegment(
                            start_seconds=seg["start"] if isinstance(seg, dict) else seg.start,
                            end_seconds=seg["end"] if isinstance(seg, dict) else seg.end,
                            text=seg["text"].strip() if isinstance(seg, dict) else seg.text.strip(),
                        )
                    )
            
            return TranscriptionResult(
                text=resp.text,
                language=resp.language if hasattr(resp, "language") else (language or "en"),
                duration_seconds=resp.duration if hasattr(resp, "duration") else 0.0,
                segments=segments
            )
        except Exception as e:
            logger.error("OpenAI transcription failed: %s", str(e))
            raise