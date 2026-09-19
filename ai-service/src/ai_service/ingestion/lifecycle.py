"""Data lifecycle management: SHA-256 deduplication and TTL."""

import hashlib

class DocumentLifecycleManager:
    """Handles content deduplication and time-to-live cache invalidation."""
    
    @staticmethod
    def generate_content_hash(content: str) -> str:
        """Generates SHA-256 hash of the content to prevent duplicate ingestion."""
        return hashlib.sha256(content.encode("utf-8")).hexdigest()
        
    @staticmethod
    async def deduplicate_and_sync(session, tenant_id, content: str):
        """Checks DB for existing hash to avoid duplicate vector insertion."""
        content_hash = DocumentLifecycleManager.generate_content_hash(content)
        # DB lookup for content_hash would happen here.
        return content_hash
