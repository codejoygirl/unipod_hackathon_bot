"""LRU and DB-backed caching for query and chunk embeddings."""

import hashlib
from cachetools import LRUCache
from ai_service.providers.factory import ModelFactory
from ai_service.core.config import settings

class EmbeddingCache:
    """In-memory cache to prevent re-embedding identical text."""
    
    _cache = LRUCache(maxsize=10000)
    
    @classmethod
    async def get_or_embed_query(cls, text: str) -> list[float]:
        text_hash = hashlib.sha256(text.encode("utf-8")).hexdigest()
        
        if text_hash in cls._cache:
            return cls._cache[text_hash]
            
        embedder = ModelFactory.get_embedding_model()
        vector = await embedder.embed_query(text)
        
        cls._cache[text_hash] = vector
        return vector
        
    @classmethod
    async def get_or_embed_batch(cls, texts: list[str]) -> list[list[float]]:
        embedder = ModelFactory.get_embedding_model()
        results = []
        texts_to_embed = []
        indices_to_embed = []
        
        for i, text in enumerate(texts):
            text_hash = hashlib.sha256(text.encode("utf-8")).hexdigest()
            if text_hash in cls._cache:
                results.append(cls._cache[text_hash])
            else:
                results.append(None)
                texts_to_embed.append(text)
                indices_to_embed.append(i)
                
        if texts_to_embed:
            new_vectors = await embedder.embed(texts_to_embed)
            for i, text, vector in zip(indices_to_embed, texts_to_embed, new_vectors, strict=True):
                text_hash = hashlib.sha256(text.encode("utf-8")).hexdigest()
                cls._cache[text_hash] = vector
                results[i] = vector
                
        return results
