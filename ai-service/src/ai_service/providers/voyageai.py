"""Voyage AI provider for embeddings using httpx."""

import asyncio
from collections.abc import Sequence
import logging
import random
import httpx
from typing import Any

from ai_service.core.config import settings
from ai_service.providers.base import EmbeddingModel

logger = logging.getLogger(__name__)

class VoyageAIProvider(EmbeddingModel):
    """Voyage AI client implementing the EmbeddingModel protocol via httpx."""

    def __init__(
        self,
        api_key: str | None = None,
        embedding_model: str = "voyage-large-2-instruct",
        max_retries: int = 4,
        base_backoff_seconds: float = 0.5,
        max_backoff_seconds: float = 8.0,
        max_concurrent_requests: int = 10,
        batch_size: int = 100,
        http_client: httpx.AsyncClient | None = None,
    ) -> None:
        # Default to checking settings for VOYAGE_API_KEY
        self.api_key = api_key or getattr(settings, "VOYAGE_API_KEY", None)
        self.embedding_model = embedding_model
        self.max_retries = max_retries
        self.base_backoff = base_backoff_seconds
        self.max_backoff = max_backoff_seconds
        self.batch_size = batch_size
        self._semaphore = asyncio.Semaphore(max_concurrent_requests)
        
        self._client: httpx.AsyncClient | None = http_client

    @property
    def client(self) -> httpx.AsyncClient:
        """Lazy initialization of httpx.AsyncClient."""
        if self._client is None:
            if not self.api_key:
                raise ValueError(
                    "Voyage API key is missing. Set VOYAGE_API_KEY environment variable or pass api_key."
                )
            self._client = httpx.AsyncClient(
                headers={"Authorization": f"Bearer {self.api_key}", "Content-Type": "application/json"},
                timeout=30.0,
            )
        return self._client
        
    async def _execute_with_backoff(self, operation_name: str, url: str, payload: dict) -> Any:
        """Execute an async API operation with full-jitter exponential backoff."""
        attempt = 0
        while True:
            try:
                async with self._semaphore:
                    response = await self.client.post(url, json=payload)
                    response.raise_for_status()
                    return response.json()
            except httpx.HTTPStatusError as exc:
                if exc.response.status_code in (429, 500, 502, 503, 504):
                    attempt += 1
                    if attempt > self.max_retries:
                        logger.error(
                            "Voyage AI %s permanently failed after %d attempts. Status: %s",
                            operation_name, attempt, exc.response.status_code
                        )
                        raise
                    backoff_limit = min(self.max_backoff, self.base_backoff * (2 ** (attempt - 1)))
                    jitter_sleep = random.uniform(0.1, backoff_limit)
                    logger.warning(
                        "Voyage AI %s encountered status %d. Retrying %d/%d after %.2fs jitter.",
                        operation_name, exc.response.status_code, attempt, self.max_retries, jitter_sleep
                    )
                    await asyncio.sleep(jitter_sleep)
                else:
                    logger.error(
                        "Voyage AI %s failed with non-retryable status %d: %s",
                        operation_name, exc.response.status_code, exc.response.text
                    )
                    raise
            except httpx.RequestError as exc:
                attempt += 1
                if attempt > self.max_retries:
                    logger.error("Voyage AI request failed: %s", str(exc))
                    raise
                backoff_limit = min(self.max_backoff, self.base_backoff * (2 ** (attempt - 1)))
                jitter_sleep = random.uniform(0.1, backoff_limit)
                await asyncio.sleep(jitter_sleep)

    async def embed(self, texts: Sequence[str]) -> list[list[float]]:
        """Batch-generate dense embeddings."""
        if not texts:
            return []

        cleaned_texts = [t.strip() if t.strip() else " " for t in texts]
        results: list[list[float]] = []

        url = "https://api.voyageai.com/v1/embeddings"

        # Slice into chunks of max self.batch_size
        for i in range(0, len(cleaned_texts), self.batch_size):
            batch = cleaned_texts[i : i + self.batch_size]
            payload = {
                "input": batch,
                "model": self.embedding_model,
            }

            resp_data = await self._execute_with_backoff("embeddings", url, payload)
            
            # Sort data by index to ensure order matches input
            sorted_data = sorted(resp_data.get("data", []), key=lambda x: x.get("index", 0))
            for item in sorted_data:
                results.append(item["embedding"])

        return results

    async def embed_query(self, text: str) -> list[float]:
        """Embed a single query string."""
        cleaned = text.strip() or " "
        embeddings = await self.embed([cleaned])
        return embeddings[0]
