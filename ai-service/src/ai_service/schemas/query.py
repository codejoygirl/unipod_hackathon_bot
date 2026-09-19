"""Pydantic v2 schemas for query processing, multi-turn contexts, and internal retrieval requests."""

import uuid
from typing import Any
from pydantic import BaseModel, ConfigDict, Field, field_validator


class MetadataFilter(BaseModel):
    """Filter parameters mapped directly to pgvector JSONB metadata querying."""
    model_config = ConfigDict(frozen=True)
    
    key: str
    operator: str = Field(description="'eq', 'in', 'contains', 'gte', 'lte'")
    value: Any


class MultiTurnContext(BaseModel):
    """Chat history representation for conversational query rewriting."""
    model_config = ConfigDict(frozen=True)
    
    role: str = Field(description="'user' or 'assistant'")
    content: str


class QueryBundle(BaseModel):
    """The deeply processed representation of a user query."""
    model_config = ConfigDict(frozen=True)
    
    original_query: str
    expanded_queries: list[str] = Field(default_factory=list, description="Sub-queries and synonyms for sparse retrieval.")
    dense_vector: list[float] | None = Field(default=None, description="The primary embedding for the query.")
    sparse_vector: dict[str, float] | None = Field(default=None, description="BM25/SPLADE sparse representation.")
    detected_language: str = "en"
    detected_entities: list[str] = Field(default_factory=list)


class RetrievalRequest(BaseModel):
    """Internal contract passed to the Retrieval/Hybrid Engine."""
    model_config = ConfigDict(frozen=True)
    
    query_bundle: QueryBundle
    tenant_id: uuid.UUID
    community_ids: list[uuid.UUID]
    metadata_filters: list[MetadataFilter] = Field(default_factory=list)
    top_k: int = 25
    rerank_top_n: int = 5
    min_authority_threshold: float = 0.0
    allowed_roles: list[str] | None = Field(default=None, description="RBAC allowed roles to view source documents.")

