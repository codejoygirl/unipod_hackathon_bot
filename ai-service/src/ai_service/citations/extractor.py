"""Regular expression extraction and sentence-level claim boundary mapping."""

from dataclasses import dataclass
import re


@dataclass(frozen=True)
class ExtractedClaim:
    """A single sentence or assertion mapped to its cited evidence labels."""

    claim_text: str
    evidence_ids: list[str]
    raw_span: str


# Matches single or grouped bracketed citations: [E1], [E1, E2], [E1,E3], [E1][E2]
CITATION_TAG_PATTERN = re.compile(r"\[E\d+(?:\s*,\s*E\d+)*\]")
INDIVIDUAL_EVIDENCE_ID_PATTERN = re.compile(r"E\d+")

# Splits text into sentences while respecting trailing punctuation
SENTENCE_SPLIT_PATTERN = re.compile(r"(?<=[.!?])\s+(?=[A-Z0-9\u1200-\u137F¿¡])")


def extract_all_evidence_ids(text: str) -> list[str]:
    """Extract a deduplicated, order-preserved list of all evidence IDs cited in text."""
    if not text:
        return []

    found_ids: list[str] = []
    seen: set[str] = set()

    for match in CITATION_TAG_PATTERN.finditer(text):
        tag_content = match.group(0)
        for eid in INDIVIDUAL_EVIDENCE_ID_PATTERN.findall(tag_content):
            if eid not in seen:
                seen.add(eid)
                found_ids.append(eid)

    return found_ids


def parse_claims_with_citations(text: str) -> list[ExtractedClaim]:
    """Parse text into distinct claim statements with associated evidence IDs.

    Groups sentences and assertions with their immediate terminal or inline citations.
    """
    if not text:
        return []

    # Clean redundant whitespace
    normalized_text = re.sub(r"\s+", " ", text).strip()
    sentences = SENTENCE_SPLIT_PATTERN.split(normalized_text)

    claims: list[ExtractedClaim] = []

    for sentence in sentences:
        sentence = sentence.strip()
        if not sentence:
            continue

        # Extract all evidence tags in this sentence
        evidence_ids = extract_all_evidence_ids(sentence)

        # Clean citations out to get the raw semantic claim text
        clean_claim = CITATION_TAG_PATTERN.sub("", sentence)
        # Collapse whitespace before punctuation marks (e.g., "water ." -> "water.")
        clean_claim = re.sub(r"\s+([.,!?;:])", r"\1", clean_claim)
        # Strip trailing sentence punctuation
        clean_claim = re.sub(r"[.,!?;:]+$", "", clean_claim).strip()
        clean_claim = re.sub(r"\s+", " ", clean_claim).strip()

        claims.append(
            ExtractedClaim(
                claim_text=clean_claim,
                evidence_ids=evidence_ids,
                raw_span=sentence,
            )
        )

    return claims