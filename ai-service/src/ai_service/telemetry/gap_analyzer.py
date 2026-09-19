"""Telemetry engine for detecting knowledge gaps and query conflicts."""

import logging
from ai_service.schemas.evidence import ValidatedAnswerPayload, AnswerState

logger = logging.getLogger(__name__)

class GapAnalyzer:
    """Logs ungrounded queries to help administrators fill content gaps."""
    
    @classmethod
    def analyze_response(cls, query: str, payload: ValidatedAnswerPayload):
        """Asynchronously tracks gaps without blocking response."""
        if payload.state == AnswerState.INSUFFICIENT_EVIDENCE:
            logger.warning(
                f"KNOWLEDGE GAP DETECTED | Query: '{query}' | Reason: {payload.escalation_reason}"
            )
            # In production, this would emit to Prometheus or an observability DB.
            
        elif payload.state == AnswerState.CONFLICT:
            logger.error(
                f"KNOWLEDGE CONFLICT DETECTED | Query: '{query}' | "
                f"Conflicts: {[c.topic for c in payload.conflicts]}"
            )
