"""Deterministic 4-state resolution matrix for grounded generation payloads."""

from collections.abc import Sequence
from ai_service.citations.validator import CitationValidationResult
from ai_service.generation.prompts import INSUFFICIENT_EVIDENCE_SENTINEL
from ai_service.schemas.evidence import (
    AnswerState,
    ConflictDetail,
    EvidenceChunk,
    ValidatedAnswerPayload,
)
from ai_service.schemas.retrieval import AuthorityTier


class AnswerStateResolver:
    """Classifies answer states based on retrieval confidence, authority, and verification."""

    VERIFIED_CONFIDENCE_THRESHOLD = 0.82
    POSSIBLE_CONFIDENCE_THRESHOLD = 0.75

    @classmethod
    def resolve_state(
        cls,
        raw_answer: str,
        evidence_chunks: Sequence[EvidenceChunk],
        validation_result: CitationValidationResult,
        detected_conflicts: Sequence[ConflictDetail],
    ) -> ValidatedAnswerPayload:
        """Evaluate generation outputs and return a strictly validated answer package."""
        # 1. Check for Contradictions
        if detected_conflicts:
            top_score = evidence_chunks[0].retrieval_score if evidence_chunks else 0.0
            return ValidatedAnswerPayload(
                state=AnswerState.CONFLICT,
                answer=validation_result.cleaned_answer,
                confidence_score=round(top_score, 2),
                citations=validation_result.verified_citations,
                conflicts=list(detected_conflicts),
                needs_escalation=True,
                escalation_reason="Conflicting official guidance detected across retrieved sources.",
            )

        # 2. Check Insufficient Evidence & Hard Failure
        has_no_evidence = len(evidence_chunks) == 0
        top_chunk_score = evidence_chunks[0].retrieval_score if evidence_chunks else 0.0
        is_below_floor = top_chunk_score < cls.POSSIBLE_CONFIDENCE_THRESHOLD

        if has_no_evidence or is_below_floor or not validation_result.all_citations_valid:
            reason = "No authorized evidence found meeting confidence threshold 0.75."
            if not validation_result.all_citations_valid:
                reason = "Generation failed due to hallucinated citations or unanchored claims."

            return ValidatedAnswerPayload(
                state=AnswerState.INSUFFICIENT_EVIDENCE,
                answer="",  # Strictly empty per invariant
                confidence_score=round(top_chunk_score, 2),
                citations=[],
                conflicts=[],
                needs_escalation=True,
                escalation_reason=reason,
            )

        # 3. Check High-Confidence Verification (VERIFIED)
        primary_chunk = evidence_chunks[0]
        is_high_authority = primary_chunk.authority_tier in (
            AuthorityTier.OFFICIAL_ANNOUNCEMENT,
            AuthorityTier.POLICY_DOCUMENT,
        )
        is_high_confidence = top_chunk_score >= cls.VERIFIED_CONFIDENCE_THRESHOLD

        if is_high_confidence and is_high_authority and validation_result.all_citations_valid:
            return ValidatedAnswerPayload(
                state=AnswerState.VERIFIED,
                answer=validation_result.cleaned_answer,
                confidence_score=round(top_chunk_score, 2),
                citations=validation_result.verified_citations,
                conflicts=[],
                needs_escalation=False,
            )

        # 4. Fallback: POSSIBLE State
        return ValidatedAnswerPayload(
            state=AnswerState.POSSIBLE,
            answer=validation_result.cleaned_answer,
            confidence_score=round(top_chunk_score, 2),
            citations=validation_result.verified_citations,
            conflicts=[],
            needs_escalation=False,
            escalation_reason="Information grounded in secondary or community-level sources.",
        )