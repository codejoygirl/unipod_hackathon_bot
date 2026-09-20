import pytest
import asyncio
import uuid
from ai_service.providers.factory import ModelFactory
from ai_service.router.query_classifier import QueryClassifier

@pytest.mark.asyncio
async def test_provider_factory_resolution():
    """Verify that the factory correctly resolves the abstraction based on overrides."""
    openai_model = ModelFactory.get_chat_model(provider="openai")
    assert openai_model.__class__.__name__ == "FallbackChatModel"
    assert openai_model.primary_provider_name == "openai"
    
    anthropic_model = ModelFactory.get_chat_model(provider="anthropic")
    assert anthropic_model.__class__.__name__ == "FallbackChatModel"
    assert anthropic_model.primary_provider_name == "anthropic"

def test_query_classifier_routing():
    """Verify semantic routing to proper model tiers."""
    simple_query = "What is the capital of France?"
    complex_query = "Can you compare and analyze the economic differences between France and Germany?"
    
    assert QueryClassifier.classify_complexity(simple_query) == "simple"
    assert QueryClassifier.classify_complexity(complex_query) == "complex"
    
    simple_model = QueryClassifier.get_routed_chat_model(simple_query)
    assert simple_model.__class__.__name__ == "FallbackChatModel"
    assert simple_model.primary_provider_name == "openai"
    
    # Complex defaults to gemini usually (from .env or default)
    complex_model = QueryClassifier.get_routed_chat_model(complex_query)
    assert complex_model.__class__.__name__ == "FallbackChatModel"
    assert complex_model.primary_provider_name == "gemini"
