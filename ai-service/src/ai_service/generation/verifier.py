"""Strict claim-checking, conflict detection, and 4-state resolution."""

import re
from typing import Mapping, Sequence
from ai_service.schemas.evidence import (
    AnswerState,
    ConflictDetail,
    EvidenceChunk,
    ValidatedAnswerPayload,
    EnrichedCitation,
)
from ai_service.schemas.retrieval import AuthorityTier

class AnswerVerifier:
    """Consolidated verifier for citations and state resolution."""
    
    TOKEN_OVERLAP_THRESHOLD = 0.25
    VERIFIED_CONFIDENCE_THRESHOLD = 0.82
    POSSIBLE_CONFIDENCE_THRESHOLD = 0.75

    @classmethod
    def _extract_best_matching_quote(cls, claim_text: str, chunk_content: str) -> tuple[str, float]:
        """Find the most relevant sentence from chunk_content supporting claim_text."""
        claim_tokens = set(re.findall(r"\w+", claim_text.lower()))
        if not claim_tokens:
            return "", 0.0

        chunk_sentences = re.split(r"(?<=[.!?])\s+", chunk_content)
        best_sentence = ""
        best_overlap = 0.0

        for sentence in chunk_sentences:
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

        if best_overlap == 0.0:
            content_tokens = set(re.findall(r"\w+", chunk_content.lower()))
            overlap = len(claim_tokens.intersection(content_tokens)) / len(claim_tokens) if claim_tokens else 0
            return chunk_content[:150], overlap

        return best_sentence, best_overlap

    @classmethod
    def verify_citations(
        cls, answer: str, evidence_ids_used: list[str], evidence_map: Mapping[str, EvidenceChunk]
    ) -> tuple[str, list[EnrichedCitation], bool]:
        """Verify citations against authorized evidence chunks."""
        verified_citations: list[EnrichedCitation] = []
        all_valid = True

        for eid in evidence_ids_used:
            if eid not in evidence_map:
                all_valid = False
                continue
                
            chunk = evidence_map[eid]
            quote, overlap = cls._extract_best_matching_quote(claim_text=answer, chunk_content=chunk.content)
            
            if overlap < cls.TOKEN_OVERLAP_THRESHOLD:
                all_valid = False
                continue

            # Populate locator with chunk metadata
            locator_data = chunk.locator.model_dump(exclude_none=True) if chunk.locator else {}
            
            verified_citations.append(
                EnrichedCitation(
                    evidence_id=eid,
                    source_name=chunk.source_name,
                    source_uri=chunk.source_uri,
                    media_type=chunk.media_type,
                    evidence_snippet=chunk.content, # Exact verbatim snippet per instructions
                    locator=locator_data,
                    relevance_score=chunk.retrieval_score
                )
            )

        return (answer if all_valid else ""), verified_citations, all_valid

    @classmethod
    def resolve_state(
        cls,
        raw_answer: str,
        evidence_chunks: Sequence[EvidenceChunk],
        verified_citations: list[EnrichedCitation],
        all_citations_valid: bool,
        detected_conflicts: Sequence[ConflictDetail] = ()
    ) -> ValidatedAnswerPayload:
        """Evaluate generation outputs and return a strictly validated answer package."""
        if detected_conflicts:
            top_score = evidence_chunks[0].retrieval_score if evidence_chunks else 0.0
            return ValidatedAnswerPayload(
                state=AnswerState.CONFLICT,
                answer=raw_answer,
                confidence_score=round(top_score, 2),
                citations=verified_citations,
                conflicts=list(detected_conflicts),
                needs_escalation=True,
                escalation_reason="Conflicting official guidance detected."
            )

        has_no_evidence = len(evidence_chunks) == 0
        top_chunk_score = evidence_chunks[0].retrieval_score if evidence_chunks else 0.0
        is_below_floor = top_chunk_score < cls.POSSIBLE_CONFIDENCE_THRESHOLD

        if has_no_evidence or is_below_floor or not all_citations_valid:
            reason = "No authorized evidence found meeting confidence threshold 0.75."
            if not all_citations_valid:
                reason = "Generation failed due to hallucinated citations or unanchored claims."

            return ValidatedAnswerPayload(
                state=AnswerState.INSUFFICIENT_EVIDENCE,
                answer="",
                confidence_score=round(top_chunk_score, 2),
                citations=[],
                conflicts=[],
                needs_escalation=True,
                escalation_reason=reason,
            )

        primary_chunk = evidence_chunks[0]
        is_high_authority = primary_chunk.authority_tier in (
            AuthorityTier.OFFICIAL_ANNOUNCEMENT,
            AuthorityTier.POLICY_DOCUMENT,
        )
        is_high_confidence = top_chunk_score >= cls.VERIFIED_CONFIDENCE_THRESHOLD

        if is_high_confidence and is_high_authority and all_citations_valid:
            return ValidatedAnswerPayload(
                state=AnswerState.VERIFIED,
                answer=raw_answer,
                confidence_score=round(top_chunk_score, 2),
                citations=verified_citations,
                conflicts=[],
                needs_escalation=False,
            )

        return ValidatedAnswerPayload(
            state=AnswerState.POSSIBLE,
            answer=raw_answer,
            confidence_score=round(top_chunk_score, 2),
            citations=verified_citations,
            conflicts=[],
            needs_escalation=False,
            escalation_reason="Information grounded in secondary sources.",
        )
