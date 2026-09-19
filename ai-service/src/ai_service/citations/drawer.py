"""Formats verified citations for Web PWA Evidence Drawer and messaging channels."""

from collections.abc import Sequence
from ai_service.schemas.evidence import EnrichedCitation


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
                    "exactQuote": c.evidence_snippet,
                    "contextSnippet": c.evidence_snippet,
                    "pageNumber": c.locator.get("page_number"),
                    "timestamp": c.locator.get("timestamp_seconds"),
                }
            )
        return drawer_entries