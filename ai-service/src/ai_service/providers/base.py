from typing import Protocol


class ChatModel(Protocol):
    async def generate(self, request: object) -> object: ...


class EmbeddingModel(Protocol):
    async def embed(self, texts: list[str]) -> list[list[float]]: ...


class RerankingModel(Protocol):
    async def rerank(self, query: str, documents: list[str]) -> list[float]: ...


class TranscriptionModel(Protocol):
    async def transcribe(self, source: str) -> object: ...


class TranslationModel(Protocol):
    async def translate(self, text: str, target_language: str) -> str: ...
