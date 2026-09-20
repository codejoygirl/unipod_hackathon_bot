"""Universal OpenAI-compatible provider (Ollama, Groq, vLLM)."""

import os
from openai import AsyncOpenAI
from ai_service.providers.base import ChatRequest, ChatResponse
from ai_service.providers.protocols import ChatModel
from ai_service.core.config import settings

class OpenAICompatProvider(ChatModel):
    def __init__(self, base_url: str | None = None, api_key: str | None = None):
        self.client = AsyncOpenAI(
            base_url=base_url or settings.OLLAMA_BASE_URL,
            api_key=api_key or "sk-dummy"
        )

    async def generate(self, request: ChatRequest) -> ChatResponse:
        msgs = [{"role": msg.role, "content": str(msg.content)} for msg in request.messages]
        
        response = await self.client.chat.completions.create(
            model=settings.CHAT_MODEL_NAME,
            messages=msgs,
            temperature=request.temperature,
            max_tokens=request.max_tokens,
        )
        
        return ChatResponse(
            content=response.choices[0].message.content or "",
            model=settings.CHAT_MODEL_NAME,
            finish_reason=response.choices[0].finish_reason,
            prompt_tokens=response.usage.prompt_tokens if response.usage else 0,
            completion_tokens=response.usage.completion_tokens if response.usage else 0,
        )
