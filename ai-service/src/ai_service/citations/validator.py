"""Grounding verification, hallucination filtering, and source substring extraction."""

from collections.abc import Mapping
from dataclasses import dataclass
import re
from ai_service.citations.extractor import ExtractedClaim, parse_claims_with_citations
from ai_service.schemas.evidence import EnrichedCitation, EvidenceChunk


@dataclass(frozen=True)
class CitationValidationResult:
    """Outcome of verifying generated answer text against source chunks."""

    cleaned_answer: str
    verified_citations: list[EnrichedCitation]
    hallucinated_ids: list[str]
    unanchored_claims: list[str]
    all_citations_valid: bool


class CitationValidator:
    """Validates citations against authorized evidence chunks and verifies grounding."""

    TOKEN_OVERLAP_THRESHOLD = 0.25  # Minimum shared content token ratio

    @classmethod
    def _extract_best_matching_quote(
        cls,
        claim_text: str,
        chunk_content: str,
    ) -> tuple[str, str, float]:
        """Find the most relevant sentence from chunk_content supporting claim_text.

        Returns:
            Tuple of (exact_quote, context_snippet, overlap_ratio).
        """
        # Tokenize claim
        claim_tokens = set(re.findall(r"\w+", claim_text.lower()))
        if not claim_tokens:
            return "", "", 0.0

        # Split chunk into sentences
        chunk_sentences = re.split(r"(?<=[.!?])\s+", chunk_content)
        best_sentence = ""
        best_overlap = 0.0
        best_idx = 0

        for idx, sentence in enumerate(chunk_sentences):
            sentence_clean = sentence.strip()
            if not sentence_clean:
                continue

            sentence_tokens = set(re.findall(r"\w+", sentence_clean.lower()))
            if not sentence_tokens:
                continue

            intersection = claim_tokens.intersection(sentence_tokens)
            overlap = len(intersection) / len(claim_tokens)

            if overlap > best_overlap:
                best_overlap = overlap
                best_sentence = sentence_clean
                best_idx = idx

        if best_overlap == 0.0:
            # Fallback: check whole content if sentences are irregular
            content_tokens = set(re.findall(r"\w+", chunk_content.lower()))
            overlap = len(claim_tokens.intersection(content_tokens)) / len(claim_tokens)
            return chunk_content[:150], chunk_content[:250], overlap

        # Build surrounding context window (previous sentence + current + next sentence)
        prev_s = chunk_sentences[best_idx - 1].strip() if best_idx > 0 else ""
        next_s = chunk_sentences[best_idx + 1].strip() if best_idx + 1 < len(chunk_sentences) else ""

        snippet_parts = [p for p in [prev_s, best_sentence, next_s] if p]
        context_snippet = " ".join(snippet_parts)

        return best_sentence, context_snippet, best_overlap

    @classmethod
    def validate_answer(
        cls,
        answer: str,
        evidence_ids_used: list[str],
        evidence_map: Mapping[str, EvidenceChunk],
    ) -> CitationValidationResult:
        """Verify that every claimed citation exists and is grounded in its chunk.
        
        Args:
            answer: Raw synthesized LLM answer text.
            evidence_ids_used: List of evidence IDs the model claims to have used.
            evidence_map: Dictionary mapping evidence_id (e.g. 'E1') to EvidenceChunk.
            
        Returns:
            CitationValidationResult with verified citations and diagnostic details.
        """
        if not answer and not evidence_ids_used:
            return CitationValidationResult(
                cleaned_answer="",
                verified_citations=[],
                hallucinated_ids=[],
                unanchored_claims=[],
                all_citations_valid=True,
            )

        verified_citations: list[EnrichedCitation] = []
        hallucinated_ids: set[str] = set()
        unanchored_claims: list[str] = []

        # 1. Hard Failure Check for Hallucinated IDs
        for eid in evidence_ids_used:
            if eid not in evidence_map:
                hallucinated_ids.add(eid)
                
        # If any hallucination occurred, fail early.
        if hallucinated_ids:
            return CitationValidationResult(
                cleaned_answer="",
                verified_citations=[],
                hallucinated_ids=sorted(hallucinated_ids),
                unanchored_claims=[],
                all_citations_valid=False,
            )

        # 2. Token Overlap Check for valid IDs
        for eid in evidence_ids_used:
            chunk = evidence_map[eid]
            quote, snippet, overlap = cls._extract_best_matching_quote(
                claim_text=answer,
                chunk_content=chunk.content,
            )

            if overlap < cls.TOKEN_OVERLAP_THRESHOLD:
                unanchored_claims.append(
                    f"Answer cited {eid} but only achieved {overlap:.2f} token overlap."
                )
                continue

            verified_citations.append(
                EnrichedCitation(
                    evidence_id=eid,
                    source_name=chunk.source_name,
                    source_uri=chunk.source_uri,
                    media_type=chunk.media_type,
                    evidence_snippet=quote,
                    locator=chunk.locator.model_dump() if chunk.locator else {},
                    relevance_score=chunk.retrieval_score,
                )
            )

        all_valid = len(hallucinated_ids) == 0 and len(unanchored_claims) == 0

        return CitationValidationResult(
            cleaned_answer=answer if all_valid else "",
            verified_citations=verified_citations,
            hallucinated_ids=sorted(hallucinated_ids),
            unanchored_claims=unanchored_claims,
            all_citations_valid=all_valid,
        )