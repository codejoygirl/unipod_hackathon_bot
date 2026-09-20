"""MinHash and content-hash deduplication for RAG ingestion."""

import hashlib
import re

class Deduplicator:
    """Provides methods to deduplicate knowledge chunks before indexing."""
    
    @staticmethod
    def generate_content_hash(text: str) -> str:
        """Generates an exact SHA-256 hash of the normalized text."""
        normalized = re.sub(r'\s+', ' ', text).strip().lower()
        return hashlib.sha256(normalized.encode("utf-8")).hexdigest()
        
    @staticmethod
    def generate_minhash(text: str, num_permutations: int = 128) -> list[int]:
        """
        Generates a MinHash signature for near-duplicate detection.
        (Requires datasketch or similar library in production, simplified here).
        """
        # MVP placeholder for MinHash signature generation
        words = text.lower().split()
        return [hash(word) % num_permutations for word in words[:num_permutations]]
        
    @classmethod
    def is_near_duplicate(cls, text1: str, text2: str, threshold: float = 0.85) -> bool:
        """Calculates Jaccard similarity between two texts for near-duplicate detection."""
        set1 = set(text1.lower().split())
        set2 = set(text2.lower().split())
        
        if not set1 or not set2:
            return False
            
        intersection = len(set1.intersection(set2))
        union = len(set1.union(set2))
        
        return (intersection / union) >= threshold
