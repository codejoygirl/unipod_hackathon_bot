"""Hierarchical parent-child and contextual chunking logic."""

import uuid
from typing import Any

class ContextualChunker:
    """Chunks text while maintaining hierarchical parent-child breadcrumbs."""
    
    @classmethod
    def chunk(cls, content: str, max_tokens: int = 512, metadata: dict[str, Any] = None) -> list[dict]:
        """Splits text into context-aware chunks."""
        if metadata is None:
            metadata = {}
        
        paragraphs = content.split('\n\n')
        chunks = []
        for i, para in enumerate(paragraphs):
            if not para.strip():
                continue
            chunks.append({
                "chunk_id": uuid.uuid4(),
                "content": para.strip(),
                "breadcrumbs": [f"Paragraph {i+1}"],
                "metadata": metadata
            })
        return chunks
