"""Orchestrates XML prompt construction, LLM generation, citation validation, and 4-state resolution."""

from collections.abc import Sequence
import logging
import json
from ai_service.generation.verifier import AnswerVerifier
from ai_service.generation.prompts import (
    build_evidence_context_xml,
    build_grounded_system_prompt,
    build_user_prompt,
)
from ai_service.providers.base import ChatMessage, ChatModel, ChatRequest
from ai_service.retrieval.conflict import ConflictDetector
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
                    media_type=cand.media_type,
                    locator={
                        "media_url": cand.media_url,
                        "timestamp_seconds": cand.timestamp_seconds,
                        "bounding_box": cand.bounding_box
                    }
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
        """Execute end-to-end evidence synthesis and validation."""
        
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
            extra_params={"response_format": {"type": "json_object"}},
        )

        chat_response = await self._chat_model.generate(chat_request)
        raw_answer_text = chat_response.content
        
        try:
            if raw_answer_text.startswith("```json"):
                raw_answer_text = raw_answer_text[7:-3]
            parsed_response = json.loads(raw_answer_text.strip())
            answer_text = parsed_response.get("answer", "")
            evidence_ids_used = parsed_response.get("evidence_ids_used", [])
        except json.JSONDecodeError:
            logger.error(f"Failed to parse structured JSON output from LLM: {raw_answer_text}")
            answer_text = ""
            evidence_ids_used = []

        # 5. Citation Audit & Claim Extraction via Verifier
        cleaned_answer, verified_citations, all_valid = AnswerVerifier.verify_citations(
            answer=answer_text,
            evidence_ids_used=evidence_ids_used,
            evidence_map=evidence_map,
        )

        # 6. Deterministic 4-State Resolution
        return AnswerVerifier.resolve_state(
            raw_answer=cleaned_answer,
            evidence_chunks=evidence_chunks,
            verified_citations=verified_citations,
            all_citations_valid=all_valid,
            detected_conflicts=detected_conflicts,
        )