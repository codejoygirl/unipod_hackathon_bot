from unittest.mock import AsyncMock, MagicMock
import pytest
from openai import RateLimitError

from ai_service.providers.base import ChatMessage, ChatModel, ChatRequest, EmbeddingModel
from ai_service.providers.openai import OpenAIProvider


def test_openai_provider_conforms_to_protocols():
    provider = OpenAIProvider(api_key="test-key-mock")
    assert isinstance(provider, ChatModel)
    assert isinstance(provider, EmbeddingModel)


@pytest.mark.asyncio
async def test_openai_chat_generation_success():
    # Setup mock response
    mock_choice = MagicMock()
    mock_choice.message.content = "Community water services resume Friday."
    mock_choice.finish_reason = "stop"

    mock_usage = MagicMock()
    mock_usage.prompt_tokens = 50
    mock_usage.completion_tokens = 8

    mock_resp = MagicMock()
    mock_resp.model = "gpt-4o-mini-2024-07-18"
    mock_resp.choices = [mock_choice]
    mock_resp.usage = mock_usage

    mock_create = AsyncMock(return_value=mock_resp)
    mock_client = MagicMock()
    mock_client.chat.completions.create = mock_create

    # Inject mock client directly into provider
    provider = OpenAIProvider(api_key="test-key-mock", client=mock_client)

    req = ChatRequest(
        messages=[ChatMessage(role="user", content="When will water return?")],
        temperature=0.0,
    )
    response = await provider.generate(req)

    assert response.content == "Community water services resume Friday."
    assert response.finish_reason == "stop"
    assert response.prompt_tokens == 50
    assert mock_create.await_count == 1


@pytest.mark.asyncio
async def test_openai_exponential_backoff_on_rate_limit():
    mock_choice = MagicMock()
    mock_choice.message.content = "Recovered after 429."
    mock_choice.finish_reason = "stop"
    mock_success = MagicMock(choices=[mock_choice], model="gpt-4o-mini", usage=None)

    rate_err = RateLimitError(
        message="Rate limit exceeded",
        response=MagicMock(status_code=429, headers={}),
        body=None,
    )
    mock_create = AsyncMock(side_effect=[rate_err, mock_success])
    mock_client = MagicMock()
    mock_client.chat.completions.create = mock_create

    # Inject client with fast backoff parameters
    provider = OpenAIProvider(
        api_key="test-key-mock",
        client=mock_client,
        max_retries=2,
        base_backoff_seconds=0.01,
        max_backoff_seconds=0.05,
    )

    req = ChatRequest(messages=[ChatMessage(role="user", content="Ping")])
    response = await provider.generate(req)

    assert response.content == "Recovered after 429."
    assert mock_create.await_count == 2


@pytest.mark.asyncio
async def test_openai_embedding_batching():
    texts = ["chunk 1", "chunk 2", "chunk 3"]

    def make_embedding_resp(count):
        items = []
        for _ in range(count):
            item = MagicMock()
            item.embedding = [0.1, 0.2, 0.3, 0.4]
            items.append(item)
        return MagicMock(data=items)

    # 3 items sliced into batches of 2 -> 2 calls
    mock_create = AsyncMock(
        side_effect=[make_embedding_resp(2), make_embedding_resp(1)]
    )
    mock_client = MagicMock()
    mock_client.embeddings.create = mock_create

    provider = OpenAIProvider(
        api_key="test-key-mock",
        client=mock_client,
        embedding_dimension=4,
        batch_size=2,
    )

    vectors = await provider.embed(texts)

    assert len(vectors) == 3
    assert vectors[0] == [0.1, 0.2, 0.3, 0.4]
    assert mock_create.await_count == 2