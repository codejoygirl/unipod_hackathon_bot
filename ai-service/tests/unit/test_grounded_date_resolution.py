"""Grounded synthesis must pass trusted clock into the model (no live LLM)."""

import json
import uuid

import pytest

from ai_service.generation.synthesizer import AnswerSynthesizer
from ai_service.providers.base import ChatRequest, ChatResponse
from ai_service.schemas.retrieval import AuthorityTier, CandidateChunk


class _DateAwareGroundedChat:
    """Returns a schedule answer that respects CURRENT TIME in the user prompt."""

    async def generate(self, request: ChatRequest) -> ChatResponse:
        user = str(request.messages[-1].content)
        if request.extra_params.get("response_format") == {"type": "json_object"}:
            if "CURRENT TIME" not in user:
                return ChatResponse(
                    content=json.dumps(
                        {
                            "state": "INSUFFICIENT_EVIDENCE",
                            "answer": "",
                            "evidence_ids_used": [],
                        }
                    ),
                    model="test",
                )
            if "Open Hour session this Friday" not in user:
                return ChatResponse(
                    content=json.dumps(
                        {
                            "state": "INSUFFICIENT_EVIDENCE",
                            "answer": "",
                            "evidence_ids_used": [],
                        }
                    ),
                    model="test",
                )
            return ChatResponse(
                content=json.dumps(
                    {
                        "state": "GROUNDED",
                        "answer": (
                            "There is no programme meeting on Thursday 24 September 2026. "
                            "Open Hour is Friday 26 September at 3:00 PM CAT [E1]."
                        ),
                        "evidence_ids_used": ["E1"],
                    }
                ),
                model="test",
            )
        # Response writer (polisher): echo cleaned draft without citations
        if "draft_reply" in user and "Thursday 24 September 2026" in user:
            return ChatResponse(
                content=(
                    "There is no programme meeting on Thursday 24 September 2026. "
                    "Open Hour is Friday 26 September at 3:00 PM CAT."
                ),
                model="test",
            )
        return ChatResponse(content="", model="test")


def _candidate(content: str) -> CandidateChunk:
    uid = uuid.uuid4()
    return CandidateChunk(
        chunk_id=uid,
        source_id=uid,
        version_id=uid,
        source_name="UniPods",
        source_uri="whatsapp://export/fake",
        source_type="whatsapp",
        content=content,
        token_count=40,
        authority_tier=AuthorityTier.COMMUNITY_DISCUSSION,
        community_id=str(uid),
        rrf_score=0.92,
        final_score=0.92,
    )


@pytest.mark.asyncio
async def test_grounded_meeting_today_prompt_includes_trusted_clock_and_no_link_dump():
    synthesizer = AnswerSynthesizer(_DateAwareGroundedChat())
    meet = "https://teams.microsoft.com/meet/419860837373470?p=abc"
    candidates = [
        _candidate(
            "[9/22/2026, 8:15 PM] Diane: Open Hour session this Friday at 3:00 PM CAT\n"
            + meet,
        ),
    ]
    payload = await synthesizer.synthesize_grounded_answer(
        query="Are we having a meeting today?",
        candidates=candidates,
        link_mode="meetings",
        timezone_name="Africa/Lagos",
        reference_time_iso="2026-09-24T13:00:00+01:00",
    )
    assert "Thursday 24 September 2026" in payload.answer
    assert "Friday 26 September" in payload.answer
    assert meet not in payload.answer
    assert "Clock missing" not in payload.answer
