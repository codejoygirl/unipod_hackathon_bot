"""Fast query classifier to route to appropriate model tiers."""

import re
from ai_service.core.config import settings
from ai_service.providers.factory import ModelFactory
from ai_service.providers.protocols import ChatModel

class QueryClassifier:
    """Routes queries to model tiers based on complexity."""
    
    COMPLEXITY_TRIGGERS = [
        "compare", "analyze", "synthesize", "why", "how",
        "conflict", "discrepancy", "evaluate", "detailed"
    ]
    
    @classmethod
    def classify_complexity(cls, query: str) -> str:
        """Return 'simple' or 'complex' based on heuristic."""
        query_lower = query.lower()
        word_count = len(query.split())
        
        if word_count > 15:
            return "complex"
            
        if any(trigger in query_lower for trigger in cls.COMPLEXITY_TRIGGERS):
            return "complex"
            
        return "simple"
        
    @classmethod
    def get_routed_chat_model(cls, query: str) -> ChatModel:
        """Returns the appropriate model based on query complexity."""
        complexity = cls.classify_complexity(query)
        
        if complexity == "simple":
            # Usually simple queries can be routed to a cheaper mini model
            return ModelFactory.get_chat_model(provider="openai") # Default to openai which might be mapped to gpt-4o-mini
        else:
            return ModelFactory.get_chat_model() # Default frontier model
