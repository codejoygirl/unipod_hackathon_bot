"""Formats verified citations for Web PWA Evidence Drawer and messaging channels."""

from collections.abc import Sequence

from ai_service.schemas.evidence import EnrichedCitation
from ai_service.security.sanitizer import mask_pii

# A "sentence" in a chat export can run to thousands of characters, so the drawer gets a
# readable window rather than the whole block.
MAX_QUOTE_CHARS = 600


def _presentable(text: str) -> str:
    """Citation text as a member should see it: PII-masked and length-capped.

    Chat exports carry members' phone numbers inline with the message body. This is the
    single point where citations leave the service, so cleaning here covers every path
    that produces them (validator and verifier alike).
    """
    cleaned = mask_pii(text or "").strip()

    if len(cleaned) > MAX_QUOTE_CHARS:
        cleaned = cleaned[:MAX_QUOTE_CHARS].rstrip() + "…"

    return cleaned


class EvidenceDrawerFormatter:
    """Converts verified citations into channel-appropriate presentation formats."""

    @staticmethod
    def format_whatsapp_footer(citations: Sequence[EnrichedCitation]) -> str:
        """Format a compact plaintext footnote for WhatsApp or SMS interfaces.

        Example:
        ---
        Sources:
        [E1] Municipal Water Policy (https://city.gov/water)
        [E2] Community Advisory, Page 3
        """
        if not citations:
            return ""

        lines = ["\n---\n*Sources:*"]
        seen_eids: set[str] = set()

        for c in citations:
            if c.evidence_id in seen_eids:
                continue
            seen_eids.add(c.evidence_id)

            line = f"[{c.evidence_id}] {c.source_name}"
            extras = []
            if c.locator.get("page_number") is not None:
                extras.append(f"p. {c.locator.get('page_number')}")
            if c.locator.get("timestamp_seconds") is not None:
                mins = int(c.locator.get("timestamp_seconds") // 60)
                secs = int(c.locator.get("timestamp_seconds") % 60)
                extras.append(f"{mins}:{secs:02d}")

            if extras:
                line += f" ({', '.join(extras)})"

            if c.source_uri and c.source_uri.startswith("http"):
                line += f" - {c.source_uri}"

            lines.append(line)

        return "\n".join(lines)

    @staticmethod
    def format_pwa_drawer_payload(citations: Sequence[EnrichedCitation]) -> list[dict]:
        """Format structured JSON records specifically shaped for the Vue/React PWA Drawer."""
        drawer_entries = []
        for c in citations:
            drawer_entries.append(
                {
                    "evidenceId": c.evidence_id,
                    "sourceName": c.source_name,
                    "sourceUri": c.source_uri,
                    "mediaType": c.media_type,
                    "exactQuote": _presentable(c.evidence_snippet),
                    "contextSnippet": _presentable(c.evidence_snippet),
                    "pageNumber": c.locator.get("page_number"),
                    "timestamp": c.locator.get("timestamp_seconds"),
                }
            )
        return drawer_entries