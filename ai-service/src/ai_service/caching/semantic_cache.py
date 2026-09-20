"""Dual-layer semantic cache for exact-match and high-similarity queries."""

import hashlib
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import text
from ai_service.caching.embedding_cache import EmbeddingCache
from ai_service.core.config import settings

class SemanticCache:
    """Provides exact and semantic caching to bypass LLM generation."""
    
    @classmethod
    async def get_cached_response(
        cls, 
        session: AsyncSession, 
        query: str, 
        tenant_id: str
    ) -> str | None:
        """
        Check for an existing identical or highly similar query response.
        Returns the cached answer text, or None if no cache hit.
        """
        query_hash = hashlib.sha256(query.encode("utf-8")).hexdigest()
        
        # Layer 1: Exact Hash Match
        exact_stmt = text("""
            SELECT response FROM semantic_cache 
            WHERE tenant_id = :tenant_id AND query_hash = :hash 
            AND created_at > NOW() - INTERVAL '1 second' * :ttl
            LIMIT 1
        """)
        
        result = await session.execute(
            exact_stmt, 
            {"tenant_id": tenant_id, "hash": query_hash, "ttl": settings.SEMANTIC_CACHE_TTL_SECONDS}
        )
        row = result.fetchone()
        if row:
            return row[0]
            
        # Layer 2: Semantic Similarity Match
        query_vector = await EmbeddingCache.get_or_embed_query(query)
        formatted_vector = "[" + ",".join(map(str, query_vector)) + "]"
        
        semantic_stmt = text("""
            SELECT response, 1 - (embedding <=> :vector::vector) as similarity
            FROM semantic_cache
            WHERE tenant_id = :tenant_id
              AND created_at > NOW() - INTERVAL '1 second' * :ttl
            ORDER BY embedding <=> :vector::vector
            LIMIT 1
        """)
        
        try:
            result = await session.execute(
                semantic_stmt,
                {"tenant_id": tenant_id, "vector": formatted_vector, "ttl": settings.SEMANTIC_CACHE_TTL_SECONDS}
            )
            row = result.fetchone()
            
            if row and row[1] >= settings.SEMANTIC_CACHE_SIMILARITY_THRESHOLD:
                return row[0]
        except Exception:
            # Semantic cache table might not exist yet if not migrated
            pass
            
        return None

    @classmethod
    async def set_cached_response(
        cls, 
        session: AsyncSession, 
        query: str, 
        response: str, 
        tenant_id: str
    ):
        """Store a new query response in the semantic cache."""
        query_hash = hashlib.sha256(query.encode("utf-8")).hexdigest()
        query_vector = await EmbeddingCache.get_or_embed_query(query)
        formatted_vector = "[" + ",".join(map(str, query_vector)) + "]"
        
        insert_stmt = text("""
            INSERT INTO semantic_cache (tenant_id, query_hash, query, response, embedding, created_at)
            VALUES (:tenant_id, :hash, :query, :response, :vector::vector, NOW())
            ON CONFLICT (tenant_id, query_hash) 
            DO UPDATE SET response = EXCLUDED.response, created_at = NOW()
        """)
        
        try:
            await session.execute(
                insert_stmt,
                {
                    "tenant_id": tenant_id, 
                    "hash": query_hash,
                    "query": query,
                    "response": response,
                    "vector": formatted_vector
                }
            )
            await session.commit()
        except Exception:
            await session.rollback()
