"""Multi-turn ambiguity resolution, contextual query rewriting, sub-query decomposition, and classification."""

import re
import json
from datetime import datetime, timedelta, timezone
from ai_service.providers.factory import ModelFactory
from ai_service.providers.base import ChatRequest, ChatMessage


class QueryProcessor:
    """Processes queries to improve retrieval recall and resolve ambiguities."""
    
    @classmethod
    async def classify_query(cls, query: str) -> tuple[str, dict[str, str] | None]:
        """Classify a query as point_lookup or summary_aggregation, returning temporal bounds if applicable."""
        query_lower = query.lower()
        now = datetime.now(timezone.utc)
        
        # Rule-based temporal matching
        if "today" in query_lower:
            start_of_today = now.replace(hour=0, minute=0, second=0, microsecond=0)
            return "summary_aggregation", {"gte": start_of_today.isoformat()}
        
        if "this week" in query_lower:
            start_of_week = (now - timedelta(days=now.weekday())).replace(hour=0, minute=0, second=0, microsecond=0)
            return "summary_aggregation", {"gte": start_of_week.isoformat()}
            
        if "last week" in query_lower:
            start_of_last_week = (now - timedelta(days=now.weekday() + 7)).replace(hour=0, minute=0, second=0, microsecond=0)
            return "summary_aggregation", {"gte": start_of_last_week.isoformat()}
            
        if re.search(r'last (\d+) days?', query_lower):
            match = re.search(r'last (\d+) days?', query_lower)
            days = int(match.group(1))
            start_time = (now - timedelta(days=days)).replace(hour=0, minute=0, second=0, microsecond=0)
            return "summary_aggregation", {"gte": start_time.isoformat()}
            
        # Non-date summary heuristics
        summary_keywords = ["summarize", "what's new", "latest", "since"]
        if any(kw in query_lower for kw in summary_keywords):
            # Try LLM fallback to see if there's a specific entity event date or just a general summary
            system_prompt = (
                "You are a query classifier. The user wants a summary or aggregation. "
                "If they specify a clear entity-based temporal bound (e.g. 'since the finance policy update'), "
                "extract it. Otherwise just return null. Output strictly JSON:\n"
                '{"needs_entity_resolution": true, "entity": "finance policy update"} OR {"needs_entity_resolution": false}'
            )
            chat_model = ModelFactory.get_chat_model()
            req = ChatRequest(
                messages=[
                    ChatMessage(role="system", content=system_prompt),
                    ChatMessage(role="user", content=query)
                ],
                max_tokens=100,
                temperature=0.0
            )
            try:
                resp = await chat_model.generate(req)
                cleaned = resp.content.replace('```json', '').replace('```', '').strip()
                data = json.loads(cleaned)
                if data.get("needs_entity_resolution") and data.get("entity"):
                    return "summary_aggregation", {"entity_after": data["entity"]}
            except Exception:
                pass
            return "summary_aggregation", {"unresolved": "true"}
            
        return "point_lookup", None

    @classmethod
    async def decompose_query(cls, query: str) -> list[str]:
        """Decomposes a complex query into simpler sub-queries for hybrid search."""
        
        system_prompt = (
            "You are an expert search architect. Decompose the following complex query into 1-3 simple, "
            "independent search queries that will maximize retrieval recall from a vector database. "
            "Output each query on a new line, with no extra text or numbering."
        )
        
        chat_model = ModelFactory.get_chat_model()
        req = ChatRequest(
            messages=[
                ChatMessage(role="system", content=system_prompt),
                ChatMessage(role="user", content=query)
            ],
            max_tokens=150,
            temperature=0.2
        )
        
        resp = await chat_model.generate(req)
        
        sub_queries = [line.strip("- *").strip() for line in resp.content.split("\n") if line.strip()]
        
        if not sub_queries:
            return [query]
            
        return sub_queries
        
    @classmethod
    async def rewrite_contextual_query(cls, current_query: str, chat_history: list[dict[str, str]] | None) -> str:
        """Rewrites a query to be fully context-independent based on chat history."""
        
        if not chat_history:
            return current_query
            
        history_text = "\n".join([f"{msg['role']}: {msg['content']}" for msg in chat_history])
        
        system_prompt = (
            "Given the following chat history and the user's latest query, rewrite the user's query "
            "to be fully self-contained and context-independent. Do not answer the query, just rewrite it."
        )
        
        user_prompt = f"Chat History:\n{history_text}\n\nLatest Query: {current_query}\n\nRewritten Query:"
        
        chat_model = ModelFactory.get_chat_model()
        req = ChatRequest(
            messages=[
                ChatMessage(role="system", content=system_prompt),
                ChatMessage(role="user", content=user_prompt)
            ],
            max_tokens=150,
            temperature=0.0
        )
        
        resp = await chat_model.generate(req)
        rewritten = resp.content.strip()
        return rewritten if rewritten else current_query
