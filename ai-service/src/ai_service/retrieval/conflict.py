"""Cross-document contradiction and factual conflict detection across evidence chunks."""

from collections.abc import Sequence
import re
from ai_service.schemas.evidence import ConflictDetail, EvidenceChunk
from ai_service.schemas.retrieval import AuthorityTier


class ConflictDetector:
    """Identifies contradictory assertions across equal-authority source passages."""

    # High-impact civic contradiction anchor terms
    TEMPORAL_ANCHORS = [
        "deadline",
        "due date",
        "effective date",
        "close",
        "closes",
        "ends",
        "begins",
        "application",
        "grant",
    ]
    STATUS_ANCHORS = [
        "open",
        "closed",
        "suspended",
        "mandatory",
        "optional",
        "prohibited",
        "allowed",
    ]

    @classmethod
    def _extract_numeric_entities(cls, text: str) -> set[str]:
        """Extract monetary amounts, dates, ordinals, and hours."""
        patterns = [
            r"\b\$\d+\b",
            r"\b\d{1,2}:\d{2}(?:\s*[ap]m)?\b",
            r"\b\d{1,2}/\d{1,2}/\d{2,4}\b",
            r"\b(?:jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\s+\d+(?:st|nd|rd|th)?\b",
            r"\b\d+(?:st|nd|rd|th)?\b",
        ]
        combined = re.compile("|".join(patterns), re.IGNORECASE)
        return {m.group(0).lower().strip() for m in combined.finditer(text)}

    @classmethod
    def detect_conflicts(
        cls,
        evidence_chunks: Sequence[EvidenceChunk],
    ) -> list[ConflictDetail]:
        """Scan top evidence chunks for direct factual contradictions."""
        if len(evidence_chunks) < 2:
            return []

        high_tier_chunks = [
            c
            for c in evidence_chunks
            if c.authority_tier
            in (AuthorityTier.OFFICIAL_ANNOUNCEMENT, AuthorityTier.POLICY_DOCUMENT)
        ]

        conflicts: list[ConflictDetail] = []
        seen_pairs: set[tuple[str, str]] = set()

        for i in range(len(high_tier_chunks)):
            for j in range(i + 1, len(high_tier_chunks)):
                c1 = high_tier_chunks[i]
                c2 = high_tier_chunks[j]

                # Only compare distinct sources
                if c1.source_id == c2.source_id:
                    continue

                pair_key = tuple(sorted([c1.evidence_id, c2.evidence_id]))
                if pair_key in seen_pairs:
                    continue

                text1 = c1.content.lower()
                text2 = c2.content.lower()

                # Check Temporal Anchors
                for anchor in cls.TEMPORAL_ANCHORS:
                    if anchor in text1 and anchor in text2:
                        nums1 = cls._extract_numeric_entities(text1)
                        nums2 = cls._extract_numeric_entities(text2)

                        # Detect divergence: disjoint sets OR mutually differing dates/entities
                        diff1 = nums1 - nums2
                        diff2 = nums2 - nums1

                        if (nums1 and nums2 and nums1.isdisjoint(nums2)) or (diff1 and diff2):
                            seen_pairs.add(pair_key)
                            conflicts.append(
                                ConflictDetail(
                                    topic=f"{anchor.title()} Mismatch",
                                    conflicting_claims=[
                                        f"[{c1.evidence_id}] {c1.content.strip()}",
                                        f"[{c2.evidence_id}] {c2.content.strip()}",
                                    ],
                                    source_ids=[c1.source_id, c2.source_id],
                                    evidence_ids=[c1.evidence_id, c2.evidence_id],
                                    recommended_action=f"Verify current {anchor} with source administrators.",
                                )
                            )
                            break

                # Check Status Anchors (open vs closed)
                if pair_key not in seen_pairs:
                    if ("open" in text1 and "closed" in text2) or ("closed" in text1 and "open" in text2):
                        seen_pairs.add(pair_key)
                        conflicts.append(
                            ConflictDetail(
                                topic="Facility/Service Status Contradiction",
                                conflicting_claims=[
                                    f"[{c1.evidence_id}] {c1.content.strip()}",
                                    f"[{c2.evidence_id}] {c2.content.strip()}",
                                ],
                                source_ids=[c1.source_id, c2.source_id],
                                evidence_ids=[c1.evidence_id, c2.evidence_id],
                                recommended_action="Check real-time status with community dispatch.",
                            )
                        )

        return conflicts