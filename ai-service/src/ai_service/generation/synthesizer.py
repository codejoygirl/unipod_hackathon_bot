"""Orchestrates XML prompt construction, LLM generation, citation validation, and 4-state resolution."""

from collections.abc import Sequence
import logging
from ai_service.citations.validator import CitationValidator
from ai_service.generation.prompts import (
    build_evidence_context_xml,
    build_grounded_system_prompt,
    build_user_prompt,
)
from ai_service.providers.base import ChatMessage, ChatModel, ChatRequest
from ai_service.retrieval.conflict import ConflictDetector
from ai_service.retrieval.state_resolver import AnswerStateResolver
from ai_service.schemas.evidence import (
    AnswerState,
    EvidenceChunk,
    ValidatedAnswerPayload,
)
from ai_service.schemas.retrieval import CandidateChunk

logger = logging.getLogger(__name__)


class AnswerSynthesizer:
    """Coordinates the grounded generation and verification pipeline."""

    MIN_CONFIDENCE_FLOOR = 0.75

    def __init__(self, chat_model: ChatModel) -> None:
        self._chat_model = chat_model

    def _normalize_candidates_to_evidence(
        self,
        candidates: Sequence[CandidateChunk],
    ) -> list[EvidenceChunk]:
        """Convert ranked CandidateChunks into sequential EvidenceChunks with E1, E2 labels."""
        evidence_list: list[EvidenceChunk] = []

        for idx, cand in enumerate(candidates, start=1):
            evidence_id = f"E{idx}"
            evidence_list.append(
                EvidenceChunk(
                    evidence_id=evidence_id,
                    chunk_id=cand.chunk_id,
                    source_id=cand.source_id,
                    source_name=f"Source_{cand.source_type}_{cand.source_id.hex[:6]}",
                    source_uri=f"community://sources/{cand.source_id}",
                    source_type=cand.source_type,
                    content=cand.content,
                    breadcrumbs=cand.breadcrumbs,
                    authority_tier=cand.authority_tier,
                    retrieval_score=cand.final_score,
                )
            )

        return evidence_list

    async def synthesize_grounded_answer(
        self,
        query: str,
        candidates: Sequence[CandidateChunk],
        target_language: str | None = None,
        enable_conflict_detection: bool = True,
        temperature: float = 0.0,
    ) -> ValidatedAnswerPayload:
        """Execute end-to-end evidence synthesis and validation.

        1. Fast-path check: If no candidates exceed the confidence floor (0.75),
           bypass LLM generation and immediately return INSUFFICIENT_EVIDENCE.
        2. Normalize candidates to ordered [E1, E2, ...] EvidenceChunks.
        3. Run cross-document conflict detection if enabled.
        4. Assemble XML-fenced prompts and invoke the ChatModel.
        5. Verify all citations against source chunks.
        6. Determine final state (VERIFIED, POSSIBLE, CONFLICT, INSUFFICIENT_EVIDENCE).
        """
        # 1. Fast-Path Pre-Check
        if not candidates or candidates[0].final_score < self.MIN_CONFIDENCE_FLOOR:
            top_score = candidates[0].final_score if candidates else 0.0
            return ValidatedAnswerPayload(
                state=AnswerState.INSUFFICIENT_EVIDENCE,
                answer="",
                confidence_score=round(top_score, 2),
                citations=[],
                conflicts=[],
                needs_escalation=True,
                escalation_reason=f"No authorized evidence met the minimum confidence threshold ({self.MIN_CONFIDENCE_FLOOR}).",
            )

        # 2. Normalize Candidates to Evidence Chunks
        evidence_chunks = self._normalize_candidates_to_evidence(candidates)
        evidence_map = {chunk.evidence_id: chunk for chunk in evidence_chunks}

        # 3. Detect Conflicts Across Top-Tier Evidence
        detected_conflicts = []
        if enable_conflict_detection:
            detected_conflicts = ConflictDetector.detect_conflicts(evidence_chunks)

        # 4. XML Prompt Construction & LLM Inference
        evidence_xml = build_evidence_context_xml(evidence_chunks)
        system_prompt = build_grounded_system_prompt()
        user_prompt = build_user_prompt(
            query=query,
            evidence_xml=evidence_xml,
            target_language=target_language,
        )

        chat_request = ChatRequest(
            messages=[
                ChatMessage(role="system", content=system_prompt),
                ChatMessage(role="user", content=user_prompt),
            ],
            temperature=temperature,
            max_tokens=1024,
        )

        chat_response = await self._chat_model.generate(chat_request)
        raw_answer = chat_response.content

        # 5. Citation Audit & Claim Extraction
        validation_result = CitationValidator.validate_answer(
            answer=raw_answer,
            evidence_map=evidence_map,
            strip_invalid_tags=True,
        )

        # 6. Deterministic 4-State Resolution
        return AnswerStateResolver.resolve_state(
            raw_answer=raw_answer,
            evidence_chunks=evidence_chunks,
            validation_result=validation_result,
            detected_conflicts=detected_conflicts,
        )