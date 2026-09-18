"""Grounding verification, hallucination filtering, and source substring extraction."""

from collections.abc import Mapping
from dataclasses import dataclass
import re
from ai_service.citations.extractor import ExtractedClaim, parse_claims_with_citations
from ai_service.schemas.evidence import CitationDetail, EvidenceChunk


@dataclass(frozen=True)
class CitationValidationResult:
    """Outcome of verifying generated answer text against source chunks."""

    cleaned_answer: str
    verified_citations: list[CitationDetail]
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
        evidence_map: Mapping[str, EvidenceChunk],
        strip_invalid_tags: bool = True,
    ) -> CitationValidationResult:
        """Verify that every citation in the answer exists and is grounded in its chunk.

        Args:
            answer: Raw synthesized LLM answer text.
            evidence_map: Dictionary mapping evidence_id (e.g. 'E1') to EvidenceChunk.
            strip_invalid_tags: If True, remove tags like [E99] from returned text.

        Returns:
            CitationValidationResult with verified citations and diagnostic details.
        """
        if not answer:
            return CitationValidationResult(
                cleaned_answer="",
                verified_citations=[],
                hallucinated_ids=[],
                unanchored_claims=[],
                all_citations_valid=True,
            )

        claims = parse_claims_with_citations(answer)
        verified_citations: list[CitationDetail] = []
        hallucinated_ids: set[str] = set()
        unanchored_claims: list[str] = []
        seen_citations: set[tuple[str, str]] = set()

        for claim in claims:
            if not claim.evidence_ids:
                # Claim has no citations
                continue

            for eid in claim.evidence_ids:
                if eid not in evidence_map:
                    hallucinated_ids.add(eid)
                    continue

                chunk = evidence_map[eid]
                quote, snippet, overlap = cls._extract_best_matching_quote(
                    claim_text=claim.claim_text,
                    chunk_content=chunk.content,
                )

                if overlap < cls.TOKEN_OVERLAP_THRESHOLD:
                    # Claim cited this chunk, but chunk lacks supporting tokens
                    unanchored_claims.append(
                        f"Claim '{claim.claim_text}' cited {eid} but only achieved {overlap:.2f} token overlap."
                    )
                    continue

                dedup_key = (eid, quote)
                if dedup_key not in seen_citations:
                    seen_citations.add(dedup_key)
                    verified_citations.append(
                        CitationDetail(
                            evidence_id=eid,
                            chunk_id=chunk.chunk_id,
                            source_name=chunk.source_name,
                            source_uri=chunk.source_uri,
                            authority_tier=chunk.authority_tier,
                            exact_quote=quote,
                            context_snippet=snippet,
                            page_number=chunk.page_number,
                            timestamp_seconds=chunk.timestamp_seconds,
                            is_verified=True,
                        )
                    )

        # Clean answer text if requested
        cleaned_answer = answer
        if strip_invalid_tags and hallucinated_ids:
            for hid in hallucinated_ids:
                # Remove occurrences of [HID] or [..., HID, ...]
                pattern = re.compile(rf"\[\s*{hid}\s*\]|,\s*{hid}|{hid}\s*,")
                cleaned_answer = pattern.sub("", cleaned_answer)
            # Clean up empty brackets like []
            cleaned_answer = re.sub(r"\[\s*\]", "", cleaned_answer).strip()

        all_valid = len(hallucinated_ids) == 0 and len(unanchored_claims) == 0

        return CitationValidationResult(
            cleaned_answer=cleaned_answer,
            verified_citations=verified_citations,
            hallucinated_ids=sorted(hallucinated_ids),
            unanchored_claims=unanchored_claims,
            all_citations_valid=all_valid,
        )