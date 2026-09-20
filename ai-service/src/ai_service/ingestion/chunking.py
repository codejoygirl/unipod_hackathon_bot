"""Hierarchical parent-child and contextual chunking logic."""

import uuid
from typing import Any
import re

class ContextualChunker:
    """Chunks text while maintaining hierarchical parent-child relationships."""
    
    @classmethod
    def chunk_document(cls, content: str, title: str = "", max_parent_tokens: int = 800, max_child_tokens: int = 150, metadata: dict[str, Any] = None) -> list[dict]:
        """Splits text into parent chunks and associated child chunks."""
        if metadata is None:
            metadata = {}
            
        # Simplified split heuristic (assumes 4 chars ~ 1 token)
        parent_char_limit = max_parent_tokens * 4
        child_char_limit = max_child_tokens * 4
        
        paragraphs = re.split(r'\n\n+', content)
        chunks = []
        
        current_parent_text = ""
        current_parent_children = []
        
        # We will build parents and their children
        for i, para in enumerate(paragraphs):
            para = para.strip()
            if not para:
                continue
                
            breadcrumb_text = f"Document: {title}" if title else "Document"
            
            # Simple child chunking logic
            if len(current_parent_text) + len(para) > parent_char_limit and current_parent_text:
                chunks.append({
                    "chunk_id": uuid.uuid4(),
                    "content": current_parent_text.strip(),
                    "breadcrumbs": [breadcrumb_text],
                    "metadata": metadata,
                    "children": current_parent_children
                })
                current_parent_text = ""
                current_parent_children = []
                
            current_parent_text += para + "\n\n"
            
            # Create child chunks
            child_text = f"Context: {breadcrumb_text}\n" + para
            # Truncate child if too large (simplified)
            if len(child_text) > child_char_limit:
                child_text = child_text[:child_char_limit] + "..."
                
            current_parent_children.append({
                "chunk_id": uuid.uuid4(),
                "content": child_text,
                "breadcrumbs": [breadcrumb_text],
                "metadata": metadata
            })
            
        if current_parent_text:
            chunks.append({
                "chunk_id": uuid.uuid4(),
                "content": current_parent_text.strip(),
                "breadcrumbs": [breadcrumb_text if title else "Document"],
                "metadata": metadata,
                "children": current_parent_children
            })
            
        return chunks

    @classmethod
    def chunk(cls, content: str, max_tokens: int = 512, metadata: dict[str, Any] = None) -> list[dict]:
        """Legacy compatibility wrapper."""
        return cls.chunk_document(content, max_parent_tokens=max_tokens, metadata=metadata)
        
    @classmethod
    def chunk_content(cls, content: str, **kwargs) -> list[dict]:
        """Legacy alias required for backwards compatibility."""
        return cls.chunk_document(content, **kwargs)
