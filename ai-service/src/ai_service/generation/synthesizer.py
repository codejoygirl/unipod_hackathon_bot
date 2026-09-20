"""Orchestrates XML prompt construction, LLM generation, citation validation, and 4-state resolution."""

from collections.abc import Sequence
import logging
import json
import re
from urllib.parse import urlsplit
from ai_service.generation.verifier import AnswerVerifier
from ai_service.generation.prompts import (
    build_evidence_context_xml,
    build_grounded_system_prompt,
    build_user_prompt,
)
from ai_service.providers.base import ChatMessage, ChatModel, ChatRequest
from ai_service.retrieval.conflict import ConflictDetector
from ai_service.schemas.evidence import (
    AnswerState,
    EvidenceChunk,
    MediaLocator,
    ValidatedAnswerPayload,
)
from ai_service.schemas.retrieval import CandidateChunk

logger = logging.getLogger(__name__)

_URL_RE = re.compile(r"https?://[^\s<>\"']+", re.IGNORECASE)
# English-only offline fallback. Multilingual asks pass link_mode from the classifier.
_LINK_ASK_RE = re.compile(
    r"\b(recording|recordings|youtube|youtu|link|links|slides|video|videos|replay|recap|url|urls|meeting|meet|join)\b",
    re.IGNORECASE,
)
_MEETING_LINK_ASK_RE = re.compile(
    r"\b((?:meeting|meet|join|call)\s+links?|links?\s+(?:for|to)\s+(?:the\s+)?(?:meeting|meet|call|session)|join\s+(?:the\s+)?(?:meeting|call|session))\b",
    re.IGNORECASE,
)
_RECORDING_ASK_RE = re.compile(
    r"\b(recording|recordings|replay|recap|video|videos|youtube|youtu)\b",
    re.IGNORECASE,
)


class AnswerSynthesizer:
    """Coordinates the grounded generation and verification pipeline."""

    MIN_CONFIDENCE_FLOOR = 0.65

    def __init__(self, chat_model: ChatModel) -> None:
        self._chat_model = chat_model

    @staticmethod
    def _current_question(query: str) -> str:
        marker = "current question:"
        lower = query.lower()
        if marker in lower:
            return query[lower.rfind(marker) + len(marker) :].strip()
        return query.strip()

    @classmethod
    def _is_meeting_join_url(cls, url: str) -> bool:
        lower = url.lower()
        return (
            "meetup-join" in lower
            or "light-meetings/launch" in lower
            or "/calendar/" in lower
            or "outlook.office" in lower
            or "teams.microsoft.com/meet/" in lower
            or "teams.live.com/meet" in lower
            or "zoom.us/j/" in lower
            or "meet.google.com/" in lower
            or re.search(r"teams\.microsoft\.com/.*/meet(?:/|\?|$)", lower) is not None
        )

    @classmethod
    def _is_likely_recording_url(cls, url: str) -> bool:
        """Allowlist replay/recording hosts; never treat live meet joins as recordings."""
        if cls._is_meeting_join_url(url):
            return False
        lower = url.lower()
        if "youtu.be/" in lower or "youtube.com/" in lower:
            return True
        if "meetingrecap" in lower:
            return True
        if "stream.microsoft.com" in lower:
            return True
        if "drive.google.com" in lower and "/file/" in lower:
            return True
        # Sheets / Docs / Forms are not session recordings.
        if "docs.google.com" in lower:
            return False
        if "sharepoint.com" in lower and (".mp4" in lower or "recording" in lower):
            return True
        if "vimeo.com" in lower:
            return True
        if "facebook.com" in lower and "video" in lower:
            return True
        return False

    @classmethod
    def _is_likely_community_asset_url(cls, url: str) -> bool:
        if cls._is_meeting_join_url(url) or cls._is_likely_recording_url(url):
            return True
        lower = url.lower()
        return (
            "docs.google.com" in lower
            or "forms.gle" in lower
            or "forms.office.com" in lower
            or "sharepoint.com" in lower
            or "notion.so" in lower
            or "notion.site" in lower
        )

    @classmethod
    def _url_dedupe_key(cls, url: str) -> str:
        """Identity key for duplicates only. Does not rewrite the stored URL."""
        raw = url.strip()
        lower = raw.lower()

        drive = re.search(r"drive\.google\.com/file/d/([^/]+)", raw, flags=re.I)
        if drive:
            return f"gdrive:{drive.group(1).lower()}"

        yt = re.search(r"youtu\.be/([\w-]+)", raw, flags=re.I)
        if yt:
            return f"yt:{yt.group(1).lower()}"
        yt = re.search(r"[?&]v=([\w-]+)", raw, flags=re.I)
        if yt and "youtube." in lower:
            return f"yt:{yt.group(1).lower()}"

        item = re.search(r"driveItemId=([^&]+)", raw, flags=re.I)
        if item and "meetingrecap" in lower:
            return f"teams-recap:{item.group(1).lower()}"

        return lower.split("?", 1)[0].rstrip("/")

    @classmethod
    def _url_query_penalty(cls, url: str) -> int:
        """Lower is cleaner. Used only to pick among genuine duplicate stored URLs."""
        query = urlsplit(url).query
        if not query:
            return 0
        penalty = 1
        if "usp=" in query.lower():
            penalty += 2
        return penalty

    @classmethod
    def _prefer_cleaner_stored_url(cls, current: str, candidate: str) -> str:
        if cls._url_query_penalty(candidate) < cls._url_query_penalty(current):
            return candidate
        return current

    @classmethod
    def _is_social_or_profile_noise_url(cls, url: str) -> bool:
        lower = url.lower()
        return (
            "linkedin.com/" in lower
            or "github.com/" in lower
            or "facebook.com/" in lower
            or "instagram.com/" in lower
            or "twitter.com/" in lower
            or "x.com/" in lower
            or "wa.me/" in lower
            or "chat.whatsapp.com/" in lower
        )

    @classmethod
    def _extract_http_urls(cls, text: str, *, mode: str = "default") -> list[str]:
        by_key: dict[str, str] = {}
        for match in _URL_RE.findall(text or ""):
            url = match.rstrip(".,);]}>'\"")
            if mode == "meetings":
                if not cls._is_meeting_join_url(url):
                    continue
            elif mode == "recordings":
                if cls._is_meeting_join_url(url) or not cls._is_likely_recording_url(url):
                    continue
            elif mode == "assets":
                if cls._is_social_or_profile_noise_url(url):
                    continue
            elif cls._is_meeting_join_url(url):
                continue
            key = cls._url_dedupe_key(url)
            if key not in by_key:
                by_key[key] = url
            else:
                by_key[key] = cls._prefer_cleaner_stored_url(by_key[key], url)
        return list(by_key.values())

    @classmethod
    def _clean_link_label(cls, line: str) -> str:
        line = re.sub(r"^\d+\.\s*", "", line).strip(" \t:-–—|")
        line = re.sub(r"\s*via this link\b.*$", "", line, flags=re.I)
        line = re.sub(r"\s*[-–—|]\s*join\s*$", "", line, flags=re.I)
        line = re.sub(
            r"\b(link|recording|video|here|see|watch|available|join)\s*$",
            "",
            line,
            flags=re.I,
        )
        line = line.strip(" \t:-–—|")
        if len(line) < 3:
            return ""
        # WhatsApp export timestamps / speaker prefixes are not titles.
        if re.match(r"^\[?\d{1,2}/\d{1,2}/\d{2,4}", line):
            return ""
        if re.match(r"^~\s*\S+$", line):
            return ""
        if re.match(
            r"^(you can|here are|these links|find all|join|click here|microsoft teams)\b",
            line,
            flags=re.I,
        ):
            return ""
        # Prefer a clean head title, never chop mid-word from the tail.
        if len(line) > 70:
            line = line[:70].rsplit(" ", 1)[0].strip() or line[:70].strip()
        return line

    @classmethod
    def _label_for_url(cls, url: str, content: str) -> str:
        idx = content.find(url)
        if idx <= 0:
            return ""
        before = content[max(0, idx - 160) : idx]
        line = before.splitlines()[-1].strip() if before else ""
        return cls._clean_link_label(line)

    @classmethod
    def _fallback_label(cls, url: str) -> str:
        lower = url.lower()
        if cls._is_meeting_join_url(url):
            return "Meeting join link"
        if "youtu" in lower:
            return "YouTube recording"
        if "drive.google.com" in lower:
            return "Google Drive recording"
        if "meetingrecap" in lower or "recap" in lower:
            return "Teams recording"
        return "Shared link"

    @classmethod
    def _complete_link_answer_from_evidence(
        cls,
        query: str,
        answer: str,
        evidence_chunks: Sequence[EvidenceChunk],
        link_mode: str | None = None,
        target_language: str | None = None,
        language_hint: str | None = None,
    ) -> tuple[str, list[str]]:
        """If evidence has more openable URLs than the model listed, rebuild a full titled list."""
        question = cls._current_question(query)
        q = question.lower()
        mode_hint = (link_mode or "").strip().lower()
        if mode_hint in {"recordings", "meetings", "assets"}:
            wants_meeting_links = mode_hint == "meetings"
            wants_recordings = mode_hint == "recordings"
            wants_generic_links = mode_hint == "assets"
        else:
            wants_meeting_links = bool(_MEETING_LINK_ASK_RE.search(question))
            wants_recordings = bool(_RECORDING_ASK_RE.search(question)) and not wants_meeting_links
            wants_generic_links = (
                bool(_LINK_ASK_RE.search(question)) and not wants_meeting_links and not wants_recordings
            )
        wants_any = wants_meeting_links or wants_recordings or wants_generic_links

        if wants_meeting_links:
            mode = "meetings"
        elif wants_recordings:
            mode = "recordings"
        elif wants_generic_links:
            mode = "assets"
        else:
            mode = "default"

        # key -> (preferred_url, label)
        evidence_by_key: dict[str, tuple[str, str]] = {}
        url_evidence_ids: dict[str, list[str]] = {}

        for chunk in evidence_chunks:
            for url in cls._extract_http_urls(chunk.content, mode=mode):
                key = cls._url_dedupe_key(url)
                url_evidence_ids.setdefault(key, [])
                if chunk.evidence_id not in url_evidence_ids[key]:
                    url_evidence_ids[key].append(chunk.evidence_id)
                label = cls._label_for_url(url, chunk.content)
                if not label:
                    for raw in _URL_RE.findall(chunk.content or ""):
                        raw = raw.rstrip(".,);]}>'\"")
                        if cls._url_dedupe_key(raw) == key:
                            label = cls._label_for_url(raw, chunk.content)
                            if label:
                                break
                if key not in evidence_by_key:
                    evidence_by_key[key] = (url, label)
                else:
                    prev_url, prev_label = evidence_by_key[key]
                    chosen = cls._prefer_cleaner_stored_url(prev_url, url)
                    best_label = label if label and len(label) > len(prev_label) else prev_label
                    evidence_by_key[key] = (chosen, best_label)

        if not evidence_by_key:
            if wants_recordings or wants_meeting_links:
                # Never pass through LinkedIn / random sites for typed link asks.
                kept = cls._extract_http_urls(answer or "", mode=mode)
                if not kept:
                    return "", []
            return answer, []

        answer_keys = {
            cls._url_dedupe_key(u) for u in cls._extract_http_urls(answer or "", mode=mode)
        }
        missing = [u for key, (u, _) in evidence_by_key.items() if key not in answer_keys]

        # Never turn a person/identity answer into a dump of unrelated URLs.
        if re.search(r"\bwho(?:'s|’s|\s+is|\s+are)?\b", q) and not wants_any:
            return answer, []
        if not wants_any and not answer_keys:
            return answer, []
        if not missing and answer_keys and wants_any:
            if len(answer_keys) >= len(evidence_by_key):
                return answer, []

        if not wants_any and not missing:
            return answer, []

        fr = (target_language or language_hint or "").strip().lower() == "fr" or bool(
            re.search(
                r"\b(enregistrement|enregistrements|lien|liens|r[eé]union|s'il vous|envoyez|quand|est-ce)\b",
                q,
                flags=re.I,
            )
        )
        if wants_meeting_links:
            intro = "Voici les liens de réunion :" if fr else "Here are the meeting join links:"
        elif wants_recordings and (
            mode_hint == "recordings"
            or re.search(r"\b(recording|recordings|replay|recap)\b", q, flags=re.I)
        ):
            intro = "Voici les enregistrements des sessions :" if fr else "Here are the session recordings:"
        elif wants_recordings:
            intro = "Voici les vidéos :" if fr else "Here are the videos:"
        elif wants_generic_links:
            intro = "Voici les liens :" if fr else "Here are the links:"
        else:
            intro = "Les voici :" if fr else "Here they are:"
        lines = [intro, ""]
        used_ids: list[str] = []
        for i, (key, (url, label)) in enumerate(evidence_by_key.items(), start=1):
            display_label = label or cls._fallback_label(url)
            lines.append(f"{i}. {display_label}")
            lines.append(url)
            lines.append("")
            for eid in url_evidence_ids.get(key, []):
                if eid not in used_ids:
                    used_ids.append(eid)

        rebuilt = "\n".join(lines).strip()

        # Keep prose for multi-part asks ("… also any meeting today?").
        has_extra = bool(
            re.search(r"\balso\b", q)
            or question.count("?") >= 2
            or (
                wants_any
                and re.search(r"\b(today|tomorrow|this week|schedule|when|any meeting)\b", q)
            )
        )
        if has_extra:
            prose_lines: list[str] = []
            for line in (answer or "").splitlines():
                trimmed = line.strip()
                if not trimmed or _URL_RE.search(trimmed):
                    continue
                if re.match(r"^(?:\d+\.|[•\-])\s*$", trimmed):
                    continue
                prose_lines.append(trimmed)
            prose = "\n".join(prose_lines).strip()
            if len(prose) >= 24 and not re.match(
                r"^(here are the .+|here they are):?$", prose, flags=re.I
            ):
                return f"{prose}\n\n{rebuilt}", used_ids

        return rebuilt, used_ids
    def _normalize_candidates_to_evidence(
        self,
        candidates: Sequence[CandidateChunk],
    ) -> list[EvidenceChunk]:
        """Convert ranked CandidateChunks into sequential EvidenceChunks with E1, E2 labels."""
        evidence_list: list[EvidenceChunk] = []

        for idx, cand in enumerate(candidates, start=1):
            evidence_id = f"E{idx}"
            evidence_list.append(
                EvidenceChunk(
                    evidence_id=evidence_id,
                    chunk_id=cand.chunk_id,
                    source_id=cand.source_id,
                    source_name=cand.source_name or f"Source_{cand.source_type}_{cand.source_id.hex[:6]}",
                    source_uri=cand.source_uri or f"community://sources/{cand.source_id}",
                    source_type=cand.source_type,
                    content=cand.content,
                    breadcrumbs=cand.breadcrumbs,
                    authority_tier=cand.authority_tier,
                    retrieval_score=cand.final_score,
                    media_type=cand.media_type,
                    locator=MediaLocator(
                        media_url=cand.media_url,
                        timestamp_seconds=cand.timestamp_seconds,
                        bounding_box=cand.bounding_box,
                    ),
                )
            )

        return evidence_list

    async def synthesize_grounded_answer(
        self,
        query: str,
        candidates: Sequence[CandidateChunk],
        target_language: str | None = None,
        enable_conflict_detection: bool = True,
        temperature: float = 0.0,
        link_mode: str | None = None,
        language_hint: str | None = None,
    ) -> ValidatedAnswerPayload:
        """Execute end-to-end evidence synthesis and validation."""
        
        # 1. Fast-Path Pre-Check
        if not candidates or candidates[0].final_score < self.MIN_CONFIDENCE_FLOOR:
            top_score = candidates[0].final_score if candidates else 0.0
            return ValidatedAnswerPayload(
                state=AnswerState.INSUFFICIENT_EVIDENCE,
                answer="",
                confidence_score=round(top_score, 2),
                citations=[],
                conflicts=[],
                needs_escalation=True,
                escalation_reason=f"No authorized evidence met the minimum confidence threshold ({self.MIN_CONFIDENCE_FLOOR}).",
            )

        # 2. Normalize Candidates to Evidence Chunks
        evidence_chunks = self._normalize_candidates_to_evidence(candidates)
        evidence_map = {chunk.evidence_id: chunk for chunk in evidence_chunks}

        # 3. Detect Conflicts Across Top-Tier Evidence
        detected_conflicts = []
        if enable_conflict_detection:
            detected_conflicts = ConflictDetector.detect_conflicts(evidence_chunks)

        # 4. XML Prompt Construction & LLM Inference
        evidence_xml = build_evidence_context_xml(evidence_chunks)
        system_prompt = build_grounded_system_prompt()
        user_prompt = build_user_prompt(
            query=query,
            evidence_xml=evidence_xml,
            target_language=target_language,
        )

        chat_request = ChatRequest(
            messages=[
                ChatMessage(role="system", content=system_prompt),
                ChatMessage(role="user", content=user_prompt),
            ],
            temperature=temperature,
            max_tokens=1024,
            extra_params={"response_format": {"type": "json_object"}},
        )

        chat_response = await self._chat_model.generate(chat_request)
        raw_answer_text = chat_response.content
        logger.info(
            "Grounded LLM response received (chars=%s)",
            len(raw_answer_text or ""),
        )
        logger.debug(
            "Grounded LLM raw response preview: %s",
            (raw_answer_text or "")[:800],
        )

        try:
            cleaned_json = (raw_answer_text or "").strip()
            if cleaned_json.startswith("```json"):
                cleaned_json = cleaned_json[7:]
                if cleaned_json.endswith("```"):
                    cleaned_json = cleaned_json[:-3]
                cleaned_json = cleaned_json.strip()
            elif cleaned_json.startswith("```"):
                cleaned_json = cleaned_json[3:]
                if cleaned_json.endswith("```"):
                    cleaned_json = cleaned_json[:-3]
                cleaned_json = cleaned_json.strip()
            parsed_response = json.loads(cleaned_json)
            answer_text = parsed_response.get("answer") or ""
            evidence_ids_used = parsed_response.get("evidence_ids_used") or []
            if not isinstance(evidence_ids_used, list):
                evidence_ids_used = []
            logger.info(
                "Parsed grounded answer: state=%s answer_len=%s evidence_ids=%s",
                parsed_response.get("state"),
                len(answer_text),
                evidence_ids_used,
            )
        except json.JSONDecodeError:
            logger.error(
                "Failed to parse structured JSON output from LLM (chars=%s)",
                len(raw_answer_text or ""),
            )
            logger.debug(
                "Unparseable grounded LLM preview: %s",
                (raw_answer_text or "")[:400],
            )
            answer_text = ""
            evidence_ids_used = []

        # 4b. Complete partial link lists from all retrieved evidence (not only cited chunks).
        completed_answer, link_evidence_ids = self._complete_link_answer_from_evidence(
            query=query,
            answer=answer_text,
            evidence_chunks=evidence_chunks,
            link_mode=link_mode,
            target_language=target_language,
            language_hint=language_hint,
        )
        if link_evidence_ids:
            answer_text = completed_answer
            for eid in link_evidence_ids:
                if eid not in evidence_ids_used:
                    evidence_ids_used.append(eid)
            logger.info(
                "Completed link answer from evidence: urls_via_ids=%s",
                link_evidence_ids,
            )

        # 5. Citation Audit & Claim Extraction via Verifier
        cleaned_answer, verified_citations, all_valid = AnswerVerifier.verify_citations(
            answer=answer_text,
            evidence_ids_used=evidence_ids_used,
            evidence_map=evidence_map,
        )

        # 6. Deterministic 4-State Resolution
        return AnswerVerifier.resolve_state(
            raw_answer=cleaned_answer,
            evidence_chunks=evidence_chunks,
            verified_citations=verified_citations,
            all_citations_valid=all_valid,
            detected_conflicts=detected_conflicts,
        )