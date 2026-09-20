"""Lightweight conversational replies (no retrieval). Called only by Laravel."""

from __future__ import annotations

import logging
import re

from fastapi import APIRouter, Depends, status
from pydantic import BaseModel, ConfigDict, Field

from ai_service.api.dependencies import verify_hmac
from ai_service.providers.base import ChatMessage, ChatRequest
from ai_service.providers.factory import ModelFactory

logger = logging.getLogger(__name__)

router = APIRouter(prefix="/conversation", tags=["conversation"])

_chat_model = ModelFactory.get_chat_model()


class ConversationReplyRequest(BaseModel):
    model_config = ConfigDict(frozen=True, str_strip_whitespace=True)

    message: str = Field(..., min_length=1, max_length=2000)
    mode: str = Field(
        default="social",
        description="'social' for greetings/tone, 'out_of_scope' for polite redirect.",
    )
    community_name: str | None = Field(default=None, max_length=200)
    community_scope: str | None = Field(
        default=None,
        max_length=1000,
        description="Short blurb of what Zak covers in this community.",
    )
    target_language: str | None = Field(
        default=None,
        max_length=10,
        description="Optional ISO reply language. Omit/auto to match the member's message.",
    )


class ConversationReplyResponse(BaseModel):
    model_config = ConfigDict(frozen=True)

    reply: str


class ConversationClassifyRequest(BaseModel):
    model_config = ConfigDict(frozen=True, str_strip_whitespace=True)

    message: str = Field(..., min_length=1, max_length=2000)
    community_name: str | None = Field(default=None, max_length=200)
    community_scope: str | None = Field(default=None, max_length=1000)


class ConversationClassifyResponse(BaseModel):
    model_config = ConfigDict(frozen=True)

    intent: str = Field(description="conversational | knowledge | out_of_scope | clarify")
    link_mode: str = Field(
        default="none",
        description="none | recordings | meetings | assets (URL list intent; any language)",
    )


_ALLOWED_INTENTS = frozenset({"conversational", "knowledge", "out_of_scope", "clarify"})
_ALLOWED_LINK_MODES = frozenset({"none", "recordings", "meetings", "assets"})


def _classify_system_prompt(community_name: str | None, community_scope: str | None) -> str:
    label = (community_name or "this community").strip() or "this community"
    scope = (community_scope or "").strip()
    scope_line = (
        f"Community identity / coverage:\n{scope}"
        if scope
        else "No detailed coverage blurb; treat schedules, sessions, links, people, and shared notes as in-scope."
    )
    return (
        "You route messages for Zak, a private community chat assistant.\n"
        "Return EXACTLY one line: INTENT|LINK_MODE\n"
        "INTENT is one of: conversational, knowledge, out_of_scope, clarify\n"
        "LINK_MODE is one of: none, recordings, meetings, assets\n"
        "No other words. Works for any language the member writes in.\n\n"
        f"Community display name: {label}\n"
        f"{scope_line}\n\n"
        "Use the identity / coverage text as the source of truth for what this community is. "
        "The display name alone may be generic (e.g. Demo Community). "
        "Programme names listed in coverage (UniPods, Wadhwani, METI, hackathon, etc.) refer to "
        "THIS community and are knowledge, not out_of_scope.\n\n"
        "INTENT labels:\n"
        "- conversational: hello, thanks, ok, tone feedback, who are you (about Zak), "
        "or whether Zak speaks a language (e.g. 'do you speak French'). "
        "Short acks only. Not programme questions.\n"
        "- knowledge: asks about community people, bots in the group, schedules, sessions, "
        "deadlines, recordings, links, announcements, hackathons, programme rules, "
        "or anything shared in the group. "
        "Include typo-ridden person asks (e.g. 'Who dianee', 'whonis meti_bot').\n"
        "- out_of_scope: math homework, romance aimed at the bot, theology with no community angle, "
        "world trivia (e.g. who won the World Cup), weather, jokes on demand. "
        "Do NOT use out_of_scope for topics covered in the identity / coverage text.\n"
        "- clarify: genuinely too vague to choose (rare).\n\n"
        "LINK_MODE (independent of language):\n"
        "- recordings: member wants session recordings / replays / recorded videos "
        "(e.g. English 'recording links', French 'liens vers les enregistrements', "
        "Spanish 'enlaces de las grabaciones').\n"
        "- meetings: member wants live meeting join / call links (Teams/Zoom/Meet join URLs).\n"
        "- assets: member wants other shared links (forms, docs, slides, general 'send the links') "
        "but not specifically recordings or meeting joins.\n"
        "- none: not asking for a list of URLs.\n\n"
        "When unsure between knowledge and out_of_scope, prefer knowledge if it could be about "
        "this community's programme or shared material.\n"
        "Example outputs: knowledge|recordings   conversational|none   knowledge|meetings"
    )


def _parse_intent_label(raw: str) -> str | None:
    text = (raw or "").strip().lower()
    text = text.replace("\u2014", "-").replace("\u2013", "-")
    text = re.sub(r"[^a-z_|]+", " ", text).strip()
    # Prefer INTENT|LINK_MODE
    if "|" in text:
        left = text.split("|", 1)[0].strip()
        first = (left.split() or [""])[0]
        if first in _ALLOWED_INTENTS:
            return first
    first = (text.split() or [""])[0]
    if first in _ALLOWED_INTENTS:
        return first
    for label in _ALLOWED_INTENTS:
        if label in text:
            return label
    return None


def _parse_link_mode(raw: str) -> str:
    text = (raw or "").strip().lower()
    text = text.replace("\u2014", "-").replace("\u2013", "-")
    if "|" in text:
        right = text.split("|", 1)[1].strip()
        token = (re.sub(r"[^a-z_]+", " ", right).strip().split() or [""])[0]
        if token in _ALLOWED_LINK_MODES:
            return token
    text = re.sub(r"[^a-z_]+", " ", text).strip()
    for mode in ("recordings", "meetings", "assets"):
        if mode in text.split():
            return mode
    return "none"


def _system_prompt(mode: str, community_name: str | None, community_scope: str | None) -> str:
    label = (community_name or "this community").strip() or "this community"
    scope = (community_scope or "").strip()
    focus = (
        "schedules, sessions, updates, links, and what has been shared in the group"
        if not scope
        else "schedules, sessions, updates, links, programme details, and what has been shared in the group"
    )

    base = (
        "You are Zak, a warm community assistant for a private group chat. "
        "Write like a helpful person in the group: polite, clear, easy to skim on a phone. "
        "Do not use markdown emphasis (no *asterisks* or **bold**). "
        "Do not use em dashes. "
        "Do not open with Hey, Hi, Hello, or Hi there. "
        "The channel already tags the member; start with the useful content. "
        "Vary your wording so you do not sound like a template. "
        "Keep replies short (2 to 4 short paragraphs or fewer). "
        "Always reply in the same language the member used in their latest message "
        "(any language; do not switch to English just because scope notes are English). "
        "Scope notes may be English; still answer in the member's language. "
        "Never invent community facts, deadlines, or links. "
        "Never escalate or say you passed something to an admin unless asked about a real community gap "
        "in a knowledge answer (this endpoint is not for that)."
    )

    scope_note = ""
    if scope:
        scope_note = (
            f" Scope notes for this community: {scope}. "
            "Topics named there (for example UniPods, Wadhwani, hackathons, sessions) ARE in scope. "
            f"Do not say those topics are outside {label}."
        )

    if mode == "out_of_scope":
        return (
            f"{base}\n\n"
            f"The member asked something outside what you cover for {label}.{scope_note} "
            f"Apologize briefly, say you can't help with that one, and clearly explain you help with "
            f"{focus} in {label}. "
            "Invite them to ask about the community anytime. "
            "Promise that if you don't have an answer right now, you'll say so and come back once you do. "
            "Do not dump long admin blurbs. Do not lecture. "
            "Do not refuse UniPods, Wadhwani, or hackathon questions if those are in the scope notes."
        )

    return (
        f"{base}\n\n"
        f"This is a social / tone turn (hello, thanks, who are you, feedback like "
        f"'why aren't you friendly', short acks like 'ok', etc.) for {label}.{scope_note} "
        f"Respond naturally and warmly. If useful, mention you help with {focus}. "
        "If they ask whether you speak another language (e.g. 'Do you speak French?' in English), "
        "say yes briefly in the SAME language as their question (English here), and invite them to continue. "
        "Do not switch into the language they named unless they wrote the question in that language. "
        "If they say you seem unfriendly, apologize briefly and reset warmly. "
        "If the message is just 'ok' / 'thanks' / 'cool', keep it to one short friendly line. "
        "Do NOT say 'thanks for joining' or welcome them as if they just arrived, unless they said hello/hi. "
        "If the member pastes programme info or asks about the hackathon / UniPods / schedules, "
        "do not pretend that is out of scope; keep a short warm ack or ask how you can help with it. "
        "If you don't have an answer to a community question later, you'll say so and come back once you do. "
        "Do not search or invent facts."
    )


def _fallback_reply(mode: str, community_name: str | None) -> str:
    label = (community_name or "this community").strip() or "this community"
    if mode == "out_of_scope":
        return (
            "Sorry, I can't help with that one.\n\n"
            f"In {label}, I can help with schedules, sessions, updates, links, "
            "and what has been shared in the group.\n\n"
            "Ask me anything about the community anytime. "
            "If I don't have an answer right now, I'll say so and come back once I do."
        )
    return (
        "Happy to help.\n\n"
        f"Ask me anything about {label}: schedules, updates, links, "
        "and what has been shared in the group.\n\n"
        "If I don't have an answer right now, I'll say so and come back once I do."
    )


def _clean_reply(text: str) -> str:
    text = (text or "").strip()
    text = text.replace("\u2014", "-").replace("\u2013", "-")
    text = re.sub(r"\*\*(.+?)\*\*", r"\1", text, flags=re.DOTALL)
    text = re.sub(r"\*(.+?)\*", r"\1", text, flags=re.DOTALL)
    return text.strip()


@router.post(
    "/reply",
    response_model=ConversationReplyResponse,
    status_code=status.HTTP_200_OK,
    dependencies=[Depends(verify_hmac)],
    summary="Warm social or out-of-scope reply without retrieval",
)
async def conversation_reply(request: ConversationReplyRequest) -> ConversationReplyResponse:
    mode = (request.mode or "social").strip().lower()
    if mode not in {"social", "out_of_scope"}:
        mode = "social"

    system = _system_prompt(mode, request.community_name, request.community_scope)
    lang = (request.target_language or "").strip().lower()
    if lang in {"", "auto"}:
        user_content = (
            f"{request.message}\n\n"
            "(Reply in the same language as the member message above.)"
        )
    else:
        user_content = (
            f"{request.message}\n\n"
            f"(Reply in language code: {lang}.)"
        )
    try:
        response = await _chat_model.generate(
            ChatRequest(
                messages=[
                    ChatMessage(role="system", content=system),
                    ChatMessage(role="user", content=user_content),
                ],
                temperature=0.4,
                max_tokens=280,
            )
        )
        reply = _clean_reply(response.content)
        if not reply:
            reply = _fallback_reply(mode, request.community_name)
        return ConversationReplyResponse(reply=reply)
    except Exception as exc:  # noqa: BLE001
        logger.warning("conversation reply failed, using fallback: %s", exc, exc_info=True)
        return ConversationReplyResponse(
            reply=_fallback_reply(mode, request.community_name),
        )


@router.post(
    "/classify",
    response_model=ConversationClassifyResponse,
    status_code=status.HTTP_200_OK,
    dependencies=[Depends(verify_hmac)],
    summary="Classify ambiguous chat turns into routing intents",
)
async def conversation_classify(
    request: ConversationClassifyRequest,
) -> ConversationClassifyResponse:
    system = _classify_system_prompt(request.community_name, request.community_scope)
    try:
        response = await _chat_model.generate(
            ChatRequest(
                messages=[
                    ChatMessage(role="system", content=system),
                    ChatMessage(role="user", content=request.message),
                ],
                temperature=0.0,
                max_tokens=24,
            )
        )
        intent = _parse_intent_label(response.content)
        link_mode = _parse_link_mode(response.content)
        if intent is None:
            logger.warning(
                "conversation classify returned unusable label (chars=%s)",
                len(response.content or ""),
            )
            return ConversationClassifyResponse(intent="clarify", link_mode="none")
        if intent != "knowledge":
            link_mode = "none"
        return ConversationClassifyResponse(intent=intent, link_mode=link_mode)
    except Exception as exc:  # noqa: BLE001
        logger.warning("conversation classify failed: %s", exc, exc_info=True)
        return ConversationClassifyResponse(intent="clarify", link_mode="none")
