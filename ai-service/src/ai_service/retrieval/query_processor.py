"""Multi-turn context handling and query decomposition."""

from ai_service.schemas.query import QueryBundle, MultiTurnContext
from typing import Sequence

class QueryProcessor:
    """Processes queries, handling multi-turn history and sub-query rewriting."""
    
    @classmethod
    async def process_query(cls, raw_query: str, history: Sequence[MultiTurnContext] = None) -> QueryBundle:
        """Analyzes the raw query, potentially rewriting it using LLM."""
        # For this refactoring, we'll return a basic structure without a full LLM call
        expanded = [raw_query, raw_query.lower()]
        
        return QueryBundle(
            original_query=raw_query,
            expanded_queries=expanded,
            detected_language="en",
            detected_entities=[]
        )
