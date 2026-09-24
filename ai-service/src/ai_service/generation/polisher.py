"""Response writer: turn grounded drafts into member-ready copy.

Industry pattern after RAG: retrieve → draft/verify → dedicated writer pass.
The writer does not invent facts; it only rewrites for clarity, grammar, and
phone-friendly structure (no typos, punctuation junk, or chat-export crumbs).
"""

from __future__ import annotations

import logging
import re

from ai_service.generation.response_quality_judge import ResponseQualityJudge
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
  draft used Yoruba/French/etc. English member_question → English reply ONLY (never open with
  French/Yoruba/etc. like "Voici les liens" / "Eyi ni" when the ask is English). If the draft
  lead is in the wrong language, rewrite the lead and captions into the ask language.
  Link captions ARE part of the reply: write every caption in that same reply language.
  Do not leave English-only captions (or host placeholders like "LinkedIn profile") on a
  non-English reply — rewrite them into the member's language while keeping URLs and proper
  nouns exact.
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
- Never mix an English greeting with a non-English body; one language for the whole reply.
- Strip internal citation markers like [E1] from the visible text (the channel handles evidence separately).
- Never paste chat-export crumbs as titles (raw timestamps, "~ Name:", mid-word fragments).
- No English footer / source line (e.g. "From the community chat") on non-English replies.

WRITING QUALITY (this is why you exist):
- Correct grammar, spelling, and punctuation. No typos or nonsense garbles.
- Clear, complete sentences a careful human would send.
- Easy to skim on a phone: short lead line, blank line, then numbered items when listing.
- For link lists: "1. Clean meaningful caption" then the URL on the next line (never "caption: https://..." on one line).
- Captions must help a member know what the link is, everywhere a caption appears (social, forms, meetings, recordings, sites). Read the draft and the URL: if a chat intro, greeting, self-intro, OR a promo/CTA line sits as the title, rewrite into a short caption naming who or what the account/page/file is. Use meaning already in the draft and clues in the URL path/handle when helpful. Never paste the CTA or greeting as the title. Never invent people or roles that are not in the draft. Captions must be in the SAME language as the rest of the reply.
- Session/event lists still use full session names (e.g. "MIT Universal AI Welcome", "Needs Assessment Workshop"), never a lone person name ("Diane", "Saidu"), a chat message crumb ("Can you send me…", "Good morning everyone…"), or a sentence fragment ("Your contribution will help…"). If a draft title is a raw chat paste or CTA, rewrite it to a meaningful caption from the same draft; only if nothing useful remains, use a short host-based label rewritten into the reply language - never leave "Shared link" when the draft has enough context.
- Match the member's ask: if they asked for a TikTok / LinkedIn / form / meeting link, keep the reply focused on that kind of link; do not leave unrelated dumps.
- When the member asks for one specific link or document (e.g. the only guidelines PDF, a named Video Demo Guide), keep exactly one best matching item — never a corpus dump.
- If the draft mentions a community resources pack/folder/hub, keep one polite closing line about it (in the ask language) after the exact match; do not expand it into a full link dump.
- Keep every https URL exact (decode &amp; to &, never truncate Drive/YouTube ids).
- Stay polite and warm.
- Deduplicate: never list the same meeting twice. If two URLs are clearly the same join (e.g. Teams /meet/ and light-meetings for one session), keep one cleaner link and one title.
- Warm and human, not stiff or robotic. One light emoji only if the draft already used one or it clearly fits; never spam.
- Catch-up / activity summaries: tight bullet or numbered points, no filler.
- No filler closings like "From the community chat" or "Let me know if you need anything else" unless the draft already had a necessary question.

Return ONLY the final member-facing reply. No JSON, no preamble, no "Here is the polished version"."""


class AnswerPolisher:
    """Dedicated writer pass after grounded synthesis (plus deterministic cleanup)."""

    def __init__(self, chat_model: ChatModel | None = None) -> None:
        self._chat = chat_model
        self._judge = ResponseQualityJudge(chat_model)

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
                    written = self.deterministic_cleanup(written)
                    written = await self._maybe_repair_after_judge(
                        written,
                        question=question,
                    )
                    return written
            except Exception:
                logger.exception("response_writer_failed; using deterministic cleanup")
        return text

    async def _maybe_repair_after_judge(
        self,
        answer: str,
        *,
        question: str | None,
    ) -> str:
        q = (question or "").strip()
        if not q or self._chat is None:
            return answer
        verdict = await self._judge.evaluate(question=q, answer=answer)
        if verdict is None or verdict.passed:
            return answer
        issues = verdict.issues or [
            "Reply must fully address the member question with clean spelling and grammar.",
        ]
        try:
            repaired = await self._model_write(
                answer,
                question=question,
                revision_notes=issues,
            )
            if repaired and len(repaired) >= 20:
                return self.deterministic_cleanup(repaired)
        except Exception:
            logger.exception("response_writer_repair_failed")
        return answer

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
        # Long "caption: https://..." on one line → caption then URL (CTA/intro pastes).
        # Keep short "Link: https://..." key-value lines intact.
        text = re.sub(
            r"(?m)^(\d+[\).\:\-]\s+)((?:\S+\s+){3,}\S.*?)\s*:\s*(https?://\S+)\s*$",
            r"\1\2\n\3",
            text,
        )
        # Markdown **bold** → WhatsApp *bold* (never leave double asterisks).
        text = _MD_BOLD.sub(r"*\1*", text)
        text = re.sub(r"__([^_\n]+?)__", r"*\1*", text)
        # Collapse accidental double/nested stars: ** leftovers, * *x* *, ****.
        text = text.replace("****", "")
        text = re.sub(r"\*(\s*)\*+", r"*\1", text)
        text = re.sub(r"\*{3,}", "*", text)
        text = _MD_ITALIC_UNDERSCORE.sub(r"\1", text)

        fixed_lines: list[str] = []
        for line in text.split("\n"):
            if _MIDWORD_TITLE.match(line):
                m = re.match(r"^(\d+[\).\:\-]\s+)", line)
                prefix = m.group(1) if m else ""
                fixed_lines.append(f"{prefix}Shared link")
                continue
            if _CRUMB_TITLE.match(line):
                # Timestamp / "~ Name" crumbs only — leave caption-like lines alone.
                m = re.match(r"^(\d+[\).\:\-]\s+)(.+)$", line.strip())
                body = (m.group(2) if m else "").strip()
                if re.match(r"^\[?\d{1,2}[/\-.]\d{1,2}", body) or re.match(r"^~\s*\S", body):
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
        # Evidence fencing escapes & → &amp;; restore real URLs for members.
        from ai_service.generation.synthesizer import AnswerSynthesizer

        text = AnswerSynthesizer.restore_urls_in_answer(text)
        return text.strip()

    async def _model_write(
        self,
        draft: str,
        *,
        question: str | None,
        revision_notes: list[str] | None = None,
    ) -> str:
        assert self._chat is not None
        parts = [
            "Rewrite this grounded draft into the final member-facing reply.",
            "Use WhatsApp *single-asterisk* bold on list values (Label: *value*). "
            "Never use **double asterisks**.",
        ]
        if revision_notes:
            notes = "\n".join(f"- {n}" for n in revision_notes[:5])
            parts.append(
                "QUALITY REVISION (mandatory): An independent evaluator flagged issues:\n"
                f"{notes}\n"
                "Fix them while keeping every fact and https URL from draft_reply."
            )
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
        parts.append(
            "Final pass: every numbered link caption must be a short meaningful label "
            "(who/what the link is: document, session, form, account), never a greeting, "
            "self-intro, promo/CTA, or a standalone deadline/date fact. "
            "Captions must be in the same language as the rest of the reply "
            "(match member_question when present). "
            "Put each https URL on its own line under its caption. "
            "Copy each URL exactly as in the draft - never HTML-escape (&amp;), "
            "never shorten or truncate Drive/YouTube/path ids, never wrap URLs in markdown."
        )
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
