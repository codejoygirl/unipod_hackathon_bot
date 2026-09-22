"""Response writer: turn grounded drafts into member-ready copy.

Industry pattern after RAG: retrieve → draft/verify → dedicated writer pass.
The writer does not invent facts; it only rewrites for clarity, grammar, and
phone-friendly structure (no typos, punctuation junk, or chat-export crumbs).
"""

from __future__ import annotations

import logging
import re

from ai_service.providers.base import ChatMessage, ChatModel, ChatRequest
from ai_service.security.sanitizer import fence_untrusted

logger = logging.getLogger(__name__)

_MD_BOLD = re.compile(r"\*\*(.+?)\*\*")
_MD_ITALIC_UNDERSCORE = re.compile(r"(?<!\w)_(.+?)_(?!\w)")
_MD_LINK = re.compile(r"\[([^\]]+)\]\((https?://[^)]+)\)")
_MIDWORD_TITLE = re.compile(
    r"(?m)^(\d+[\).\:\-]\s+)([a-z][\w'’-]{0,12}\b.*)$"
)
_CRUMB_TITLE = re.compile(
    r"(?m)^(\d+[\).\:\-]\s+)("
    r"\[?\d{1,2}[/\-.]\d{1,2}|"
    r"~\s*\S|"
    r".{0,40}:\s*$"
    r").*$"
)
# Numbered / bulleted "Label: value" lines (not bare URLs).
_LIST_KEY_VALUE = re.compile(
    r"^(?P<prefix>(?:\d+[\).\:\-]|[•\-])\s+)"
    r"(?P<label>[^:\n]{1,80}?)\s*:\s+"
    r"(?P<value>\S.*)$"
)

_WRITER_SYSTEM = """You are Zak's response writer — the last step before a community member sees the reply.

You receive a DRAFT that was already grounded in retrieved community notes. Your job is to rewrite it so it reads like a sharp, professional human community assistant on WhatsApp/Telegram.

HARD RULES:
- Do NOT invent facts, names, dates, URLs, or links. Use only what is in the draft.
- Keep every https:// and http:// URL character-for-character identical.
- LANGUAGE (highest priority): If member_question is provided, write the ENTIRE reply in that
  question's language — even when the draft and the community notes are English. Translate the
  draft's facts into the member's language (Yoruba question → Yoruba reply; French → French;
  Arabic → Arabic; any other language → that language). Do not leave an English reply for a
  non-English question. If there is no member_question, keep the draft's language.
  member_question is the LATEST ask only — never switch language because an older turn or
  draft used Yoruba/French/etc. English member_question → keep or translate the draft to English.
- WHATSAPP FORMATTING (not Markdown):
  - Bold uses a SINGLE asterisk on each side: *like this*
  - NEVER use double asterisks **like this** (WhatsApp shows the stars literally).
  - Never nest or stack asterisks (* *text* * or ****).
  - Do not use Markdown links [label](url), # headings, or backticks for styling.
- LIST ITEMS with a label and value (e.g. "1. Starts: September 18, 2026"):
  keep the label plain and bold ONLY the value with WhatsApp single asterisks:
  1. Starts: *September 18, 2026*
  Same for Prize, Deadline, Duration, Host, etc. Do NOT bold the whole line.
  Do NOT wrap URLs in asterisks. Link-list rows stay: "1. Title" then URL on the next line.
- Do NOT open with Hi/Hey/Hello.
- Strip internal citation markers like [E1] from the visible text (the channel handles evidence separately).
- Never paste chat-export crumbs as titles (raw timestamps, "~ Name:", mid-word fragments).
- No English footer / source line (e.g. "From the community chat") on non-English replies.

WRITING QUALITY (this is why you exist):
- Correct grammar, spelling, and punctuation. No typos.
- Clear, complete sentences a careful human would send.
- Easy to skim on a phone: short lead line, blank line, then numbered items when listing.
- For link lists: "1. Clean professional title" then the URL on the next line.
- Titles must be full session/event names (e.g. "MIT Universal AI Welcome", "Needs Assessment Workshop"), never a lone person name ("Diane", "Saidu") or a sentence fragment ("Your contribution will help…"). If the draft title is weak, rewrite it to the clearest session name present in the draft; if none, use "Microsoft Teams meeting".
- Deduplicate: never list the same meeting twice. If two URLs are clearly the same join (e.g. Teams /meet/ and light-meetings for one session), keep one cleaner link and one title.
- Warm and human, not stiff or robotic. One light emoji only if the draft already used one or it clearly fits; never spam.
- Catch-up / activity summaries: tight bullet or numbered points, no filler.
- No filler closings like "From the community chat" or "Let me know if you need anything else" unless the draft already had a necessary question.

Return ONLY the final member-facing reply. No JSON, no preamble, no "Here is the polished version"."""


class AnswerPolisher:
    """Dedicated writer pass after grounded synthesis (plus deterministic cleanup)."""

    def __init__(self, chat_model: ChatModel | None = None) -> None:
        self._chat = chat_model

    async def polish(
        self,
        answer: str,
        *,
        question: str | None = None,
        use_model: bool = True,
    ) -> str:
        text = self.deterministic_cleanup(answer or "")
        if not text:
            return ""
        # Always run the writer for real answers — quality is the point.
        if use_model and self._chat is not None and len(text) >= 24:
            try:
                written = await self._model_write(text, question=question)
                if written and len(written) >= 20:
                    return self.deterministic_cleanup(written)
            except Exception:
                logger.exception("response_writer_failed; using deterministic cleanup")
        return text

    @classmethod
    def _strip_emphasis(cls, text: str) -> str:
        t = (text or "").strip()
        t = _MD_BOLD.sub(r"\1", t)
        t = re.sub(r"^\*([^*]+)\*$", r"\1", t)
        return t.strip()

    @classmethod
    def _bold_whatsapp(cls, text: str) -> str:
        """Wrap with single * for WhatsApp; never produce **."""
        inner = cls._strip_emphasis(text)
        if not inner or inner.startswith("http://") or inner.startswith("https://"):
            return inner
        return f"*{inner}*"

    @classmethod
    def _format_list_key_values(cls, text: str) -> str:
        """Turn '1. Key: value' into '1. Key: *value*' (WhatsApp bold on value only)."""
        out: list[str] = []
        for line in text.split("\n"):
            m = _LIST_KEY_VALUE.match(line.strip()) if line.strip() else None
            if not m:
                out.append(line)
                continue
            label = cls._strip_emphasis(m.group("label"))
            value = m.group("value").strip()
            if value.startswith("http://") or value.startswith("https://"):
                out.append(f"{m.group('prefix')}{label}: {value}")
                continue
            # Already correctly bolded value only.
            if re.fullmatch(r"\*[^*\n]+\*", value) and "**" not in value:
                out.append(f"{m.group('prefix')}{label}: {value}")
                continue
            out.append(f"{m.group('prefix')}{label}: {cls._bold_whatsapp(value)}")
        return "\n".join(out)

    @classmethod
    def deterministic_cleanup(cls, answer: str) -> str:
        text = (answer or "").replace("\r\n", "\n").strip()
        if not text:
            return ""

        # Drop leftover evidence tags the writer might miss.
        text = re.sub(
            r"\s*\[\s*E\d+(?:\s*\([^)]*\))?(?:\s*,\s*E\d+(?:\s*\([^)]*\))?)*\s*\]",
            "",
            text,
        )

        text = _MD_LINK.sub(r"\1\n\2", text)
        # Markdown **bold** → WhatsApp *bold* (never leave double asterisks).
        text = _MD_BOLD.sub(r"*\1*", text)
        text = re.sub(r"__([^_\n]+?)__", r"*\1*", text)
        # Collapse accidental double/nested stars: ** leftovers, * *x* *, ****.
        text = text.replace("****", "")
        text = re.sub(r"\*(\s*)\*+", r"*\1", text)
        text = re.sub(r"\*{3,}", "*", text)
        text = _MD_ITALIC_UNDERSCORE.sub(r"\1", text)

        fixed_lines: list[str] = []
        skip_next_url = False
        for line in text.split("\n"):
            if skip_next_url and re.match(r"^https?://", line.strip()):
                skip_next_url = False
                fixed_lines.append("Shared link")
                fixed_lines.append(line.strip())
                continue
            skip_next_url = False
            if _MIDWORD_TITLE.match(line) or _CRUMB_TITLE.match(line):
                m = re.match(r"^(\d+[\).\:\-]\s+)", line)
                prefix = m.group(1) if m else ""
                fixed_lines.append(f"{prefix}Shared link")
                continue
            fixed_lines.append(line)
        text = "\n".join(fixed_lines)

        text = cls._format_list_key_values(text)

        paras = [p for p in re.split(r"\n{2,}", text) if p.strip() != ""]
        i = 0
        while i < len(paras):
            collapsed = False
            for length in range(len(paras) - i, 0, -1):
                run = paras[i : i + length]
                nxt = paras[i + length : i + 2 * length]
                if len(nxt) == length and [p.strip() for p in nxt] == [
                    p.strip() for p in run
                ]:
                    paras = paras[: i + length] + paras[i + 2 * length :]
                    collapsed = True
                    break
            if not collapsed:
                i += 1
        text = "\n\n".join(paras)

        text = re.sub(r"\n{3,}", "\n\n", text)
        text = re.sub(
            r"(?m)^([^\n\d][^\n]{0,140})\n(\d+[\).\:\-]\s+)",
            r"\1\n\n\2",
            text,
        )
        text = re.sub(r"[ \t]+([.,;:!?])", r"\1", text)
        # Final guard: no Markdown double-asterisk bold left for WhatsApp.
        text = text.replace("**", "")
        return text.strip()

    async def _model_write(self, draft: str, *, question: str | None) -> str:
        assert self._chat is not None
        parts = [
            "Rewrite this grounded draft into the final member-facing reply.",
            "Use WhatsApp *single-asterisk* bold on list values (Label: *value*). "
            "Never use **double asterisks**.",
        ]
        if question:
            parts.append(fence_untrusted("member_question", question, max_chars=1500))
            parts.append(
                "CRITICAL - REPLY LANGUAGE: Match member_question exactly (the latest ask). "
                "English member_question → English reply. "
                "If member_question is not English, translate the whole draft into that "
                "language. Do not return Yoruba/French/etc. for an English question, "
                "and do not return English just because draft_reply is English. "
                "If a proper noun looks like voice-STT gibberish but community notes "
                "show the clear official name, use the notes' spelling."
            )
        parts.append(fence_untrusted("draft_reply", draft, max_chars=6000))
        response = await self._chat.generate(
            ChatRequest(
                messages=[
                    ChatMessage(role="system", content=_WRITER_SYSTEM),
                    ChatMessage(role="user", content="\n\n".join(parts)),
                ],
                temperature=0.2,
                max_tokens=2000,
            )
        )
        return (response.content or "").strip()
