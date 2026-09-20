"""Token budgeting and semantic context compression."""

import logging
from typing import Sequence
from ai_service.core.config import settings
from ai_service.schemas.evidence import EvidenceChunk

logger = logging.getLogger(__name__)

class TokenBudgeter:
    """Estimates and manages token limits for LLM contexts."""

    @staticmethod
    def estimate_tokens(text: str) -> int:
        """Estimate tokens using a rough 4-character heuristic.
        
        For production accuracy across OpenAI, Anthropic, and Gemini, 
        you would integrate tiktoken, anthropic.count_tokens, etc.
        """
        if not text:
            return 0
        return max(1, len(text) // 4)

class SemanticCompressor:
    """Dynamically prunes chunks to fit within the LLM context window."""

    @classmethod
    def prune_context(
        cls, 
        chunks: Sequence[EvidenceChunk], 
        system_prompt_tokens: int = 500,
        user_query_tokens: int = 50
    ) -> list[EvidenceChunk]:
        """Prunes the lowest-ranked chunks until the remaining fit the budget."""
        
        max_context = settings.MAX_CONTEXT_TOKENS
        max_completion = settings.MAX_COMPLETION_TOKENS
        padding = settings.TOKEN_PADDING
        
        available_budget = max_context - max_completion - padding - system_prompt_tokens - user_query_tokens
        
        if available_budget <= 0:
            logger.warning("Token budget is negative before adding evidence chunks!")
            return []

        pruned_chunks = []
        current_tokens = 0
        
        for chunk in chunks:
            chunk_tokens = TokenBudgeter.estimate_tokens(chunk.content)
            # Add small overhead for XML tags
            chunk_tokens += 20 
            
            if current_tokens + chunk_tokens > available_budget:
                logger.info(f"Context window full. Pruned remaining {len(chunks) - len(pruned_chunks)} chunks.")
                break
                
            pruned_chunks.append(chunk)
            current_tokens += chunk_tokens
            
        return pruned_chunks
