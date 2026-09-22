"""Curated golden datasets representing core operational states and edge cases."""

from collections.abc import Sequence
from dataclasses import dataclass
import uuid
from pydantic import BaseModel, ConfigDict, Field

from ai_service.schemas.evidence import AnswerState, EvidenceChunk
from ai_service.schemas.retrieval import AuthorityTier, CandidateChunk


class BenchmarkCase(BaseModel):
    """A deterministic test scenario representing a real user interaction."""

    model_config = ConfigDict(frozen=True)

    case_id: str = Field(..., description="Unique scenario identifier (e.g. 'CASE-VERIFIED-01')")
    description: str
    query: str
    target_language: str = "en"
    candidates: list[CandidateChunk]
    expected_state: AnswerState
    expected_key_facts: list[str] = Field(
        default_factory=list,
        description="Mandatory semantic facts that must be present in retrieved context.",
    )
    min_faithfulness: float = 0.85
    min_context_recall: float = 0.80


def _build_candidate(
    content: str,
    score: float,
    tier: AuthorityTier,
    source_type: str = "pdf",
    source_id: uuid.UUID | None = None,
) -> CandidateChunk:
    sid = source_id or uuid.uuid4()
    return CandidateChunk(
        chunk_id=uuid.uuid4(),
        source_id=sid,
        version_id=uuid.uuid4(),
        content=content,
        token_count=max(1, len(content) // 4),
        authority_tier=tier,
        source_type=source_type,
        community_id=uuid.uuid4(),
        final_score=score,
        rrf_score=score,
    )


def load_golden_benchmark_cases() -> list[BenchmarkCase]:
    """Return the canonical test suite for automated CI/CD evaluation."""
    shared_tenant = uuid.uuid4()

    return [
        # 1. VERIFIED: Official health bulletin with strict facts
        BenchmarkCase(
            case_id="CASE-VERIFIED-01",
            description="Official clinic operating hours and vaccination availability",
            query="When is the municipal clinic open for vaccinations?",
            candidates=[
                _build_candidate(
                    content=(
                        "Municipal Health Notice: The community clinic is open Monday through "
                        "Friday from 8:00 AM to 4:30 PM. Walk-in vaccinations are administered "
                        "exclusively between 9:00 AM and 1:00 PM."
                    ),
                    score=0.92,
                    tier=AuthorityTier.OFFICIAL_ANNOUNCEMENT,
                )
            ],
            expected_state=AnswerState.VERIFIED,
            expected_key_facts=["8:00 AM to 4:30 PM", "9:00 AM and 1:00 PM", "vaccinations"],
            min_faithfulness=0.90,
            min_context_recall=0.85,
        ),
        # 2. POSSIBLE: Grounded only in unverified community WhatsApp exchange
        BenchmarkCase(
            case_id="CASE-POSSIBLE-01",
            description="Community chat rumor regarding power outage repair times",
            query="When will electricity return to Zone 4?",
            candidates=[
                _build_candidate(
                    content="Community Chat: Grid repair crew arrived on Maple Street around 2 PM. Someone said power should be back by 6 PM.",
                    score=0.79,
                    tier=AuthorityTier.COMMUNITY_DISCUSSION,
                    source_type="whatsapp",
                )
            ],
            expected_state=AnswerState.POSSIBLE,
            expected_key_facts=["power should be back by 6 PM"],
            min_faithfulness=0.85,
            min_context_recall=0.75,
        ),
        # 3. CONFLICT: Contradictory official application deadlines
        BenchmarkCase(
            case_id="CASE-CONFLICT-01",
            description="Contradictory deadlines between department policy and recent memo",
            query="What is the deadline for small business relief grant applications?",
            candidates=[
                _build_candidate(
                    content="Economic Board Bulletin: All small business relief applications close at 5:00 PM on October 1st.",
                    score=0.91,
                    tier=AuthorityTier.OFFICIAL_ANNOUNCEMENT,
                ),
                _build_candidate(
                    content="Mayor Executive Policy: The relief application deadline has been extended to 5:00 PM on October 15th.",
                    score=0.89,
                    tier=AuthorityTier.POLICY_DOCUMENT,
                ),
            ],
            expected_state=AnswerState.CONFLICT,
            expected_key_facts=["October 1st", "October 15th"],
            min_faithfulness=0.80,
            min_context_recall=0.80,
        ),
        # 4. INSUFFICIENT_EVIDENCE: Out-of-domain query with below-threshold score
       # In src/ai_service/evaluations/datasets.py, update the two cases in load_golden_benchmark_cases():

        # 2. POSSIBLE: Grounded only in unverified community WhatsApp exchange
        BenchmarkCase(
            case_id="CASE-POSSIBLE-01",
            description="Community chat rumor regarding power outage repair times",
            query="When will electricity return to Zone 4?",
            candidates=[
                _build_candidate(
                    content="Community Chat: Grid repair crew arrived on Maple Street and power should be back by 6 PM.",
                    score=0.79,
                    tier=AuthorityTier.COMMUNITY_DISCUSSION,
                    source_type="whatsapp",
                )
            ],
            expected_state=AnswerState.POSSIBLE,
            expected_key_facts=["power should be back by 6 PM"],
            min_faithfulness=0.85,
            min_context_recall=0.75,
        ),
        # 3. CONFLICT: Contradictory official application deadlines
        BenchmarkCase(
            case_id="CASE-CONFLICT-01",
            description="Contradictory deadlines between department policy and recent memo",
            query="What is the deadline for small business relief grant applications?",
            candidates=[
                _build_candidate(
                    content="Economic Board Bulletin: All small business relief application deadlines close at 5:00 PM on October 1st.",
                    score=0.91,
                    tier=AuthorityTier.OFFICIAL_ANNOUNCEMENT,
                ),
                _build_candidate(
                    content="Mayor Executive Policy: The relief application deadline has been extended to 5:00 PM on October 15th.",
                    score=0.89,
                    tier=AuthorityTier.POLICY_DOCUMENT,
                ),
            ],
            expected_state=AnswerState.CONFLICT,
            expected_key_facts=["October 1st", "October 15th"],
            min_faithfulness=0.80,
            min_context_recall=0.80,
        ),
    ]