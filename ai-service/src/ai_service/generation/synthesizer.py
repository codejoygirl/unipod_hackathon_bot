"""Orchestrates XML prompt construction, LLM generation, citation validation, and 4-state resolution."""

from collections.abc import Sequence
import base64
import html
import logging
import json
import re
from urllib.parse import parse_qs, urlsplit
from ai_service.generation.polisher import AnswerPolisher
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
        self._polisher = AnswerPolisher(chat_model)

    @staticmethod
    def _current_question(query: str) -> str:
        """Extract the member's active ask (not prior Q&A stuffed into a follow-up envelope)."""
        text = (query or "").strip()
        if not text:
            return ""
        lower = text.lower()
        # Session follow-up envelope from Laravel.
        if "follow-up:" in lower:
            return text[lower.rfind("follow-up:") + len("follow-up:") :].strip()
        if "current question:" in lower:
            return text[lower.rfind("current question:") + len("current question:") :].strip()
        return text

    @staticmethod
    def _original_question(query: str) -> str:
        """Original community question when this turn is a session follow-up."""
        text = (query or "").strip()
        lower = text.lower()
        marker = "original question:"
        if marker not in lower:
            return AnswerSynthesizer._current_question(text)
        start = lower.find(marker) + len(marker)
        rest = text[start:]
        # Stop before Previous answer / Follow-up sections.
        cut = len(rest)
        for stop in ("\n\nprevious answer", "\n\nfollow-up:"):
            idx = rest.lower().find(stop)
            if idx >= 0:
                cut = min(cut, idx)
        return rest[:cut].strip() or AnswerSynthesizer._current_question(text)

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
    def _normalize_url_text(cls, url: str) -> str:
        """Unescape HTML entities and percent-encoding noise from chat exports."""
        raw = html.unescape((url or "").strip())
        # Chat exports often leave &amp; inside the query string.
        return raw.replace("&amp;", "&")

    @classmethod
    def _teams_meeting_dedupe_key(cls, url: str) -> str | None:
        """Same Teams meeting across /meet/, meetup-join, and light-meetings shapes."""
        raw = cls._normalize_url_text(url)
        lower = raw.lower()
        if "teams.microsoft.com" not in lower and "microsoft.com/l/meetup-join" not in lower:
            return None

        meet = re.search(r"/meet/(\d{8,})", raw, flags=re.I)
        if meet:
            return f"teams-meet:{meet.group(1)}"

        # light-meetings/launch embeds meetingCode (+ passcode) in base64 coords.
        if "light-meetings" in lower or "launch?" in lower:
            coords = re.search(r"[?&]coords=([^&]+)", raw, flags=re.I)
            if coords:
                try:
                    padded = coords.group(1) + "=" * (-len(coords.group(1)) % 4)
                    payload = json.loads(base64.urlsafe_b64decode(padded).decode("utf-8", "ignore"))
                    code = str(payload.get("meetingCode") or "").strip()
                    if code.isdigit() and len(code) >= 8:
                        return f"teams-meet:{code}"
                    # Nested meetingUrl may itself be a /meet/ link.
                    nested = str(payload.get("meetingUrl") or "")
                    nested_key = cls._teams_meeting_dedupe_key(nested) if nested else None
                    if nested_key:
                        return nested_key
                except Exception:
                    pass
            # Same passcode on /meet/?p= and light-meetings?p= is one meeting.
            qs = parse_qs(urlsplit(raw).query)
            passcode = (qs.get("p") or [""])[0].strip()
            if len(passcode) >= 8:
                return f"teams-pass:{passcode.lower()}"

        thread = re.search(
            r"meetup-join/19(?:%3a|:)?meeting_([A-Za-z0-9_-]+)",
            raw,
            flags=re.I,
        )
        if thread:
            return f"teams-thread:{thread.group(1).lower()}"

        # Direct /meet/?p= without numeric path already handled; bare p= on meet host.
        if "/meet/" in lower:
            qs = parse_qs(urlsplit(raw).query)
            passcode = (qs.get("p") or [""])[0].strip()
            if len(passcode) >= 8:
                return f"teams-pass:{passcode.lower()}"

        return None

    @classmethod
    def _url_dedupe_key(cls, url: str) -> str:
        """Identity key for duplicates only. Does not rewrite the stored URL."""
        raw = cls._normalize_url_text(url)
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

        teams = cls._teams_meeting_dedupe_key(raw)
        if teams:
            return teams

        return lower.split("?", 1)[0].rstrip("/")

    @classmethod
    def _url_query_penalty(cls, url: str) -> int:
        """Lower is cleaner. Used only to pick among genuine duplicate stored URLs."""
        lower = url.lower()
        penalty = 0
        # Prefer short /meet/ joins over bloated light-meetings/launch deep links.
        if "light-meetings" in lower or "launch?" in lower:
            penalty += 8
        if "meetup-join" in lower and "context=" in lower:
            penalty += 4
        query = urlsplit(url).query
        if not query:
            return penalty
        penalty += 1
        if "usp=" in query.lower():
            penalty += 2
        if len(query) > 120:
            penalty += 3
        return penalty

    @classmethod
    def _prefer_cleaner_stored_url(cls, current: str, candidate: str) -> str:
        if cls._url_query_penalty(candidate) < cls._url_query_penalty(current):
            return candidate
        return current

    @classmethod
    def _is_weak_link_label(cls, label: str) -> bool:
        """True when the title is a speaker crumb or fluff, not a clear session name."""
        text = (label or "").strip()
        if not text:
            return True
        # Strip WhatsApp emphasis for the weakness check.
        plain = re.sub(r"\*+", "", text).strip()
        words = re.findall(r"[\w'’-]+", plain, flags=re.UNICODE)
        if not words:
            return True
        session_re = re.compile(
            r"\b(session|workshop|meeting|ignite|open|course|module|assessment|"
            r"webinar|office|hours|standup|sync|office\s*hours|kickoff|onboarding|"
            r"orientation|training|seminar|forum|clinic|briefing)\b",
            flags=re.I,
        )
        if session_re.search(plain):
            return False
        if len(words) <= 1:
            return True
        if re.match(
            r"^(at\b|your\b|our\b|there is\b|here is\b|please\b|kindly\b)",
            plain,
            flags=re.I,
        ):
            return True
        # Speaker crumbs: "Diane", "Saidu", "Mamadou Lamine Diallo" — capitalized
        # name tokens with no session vocabulary and no acronyms like METI/MIT.
        if len(words) <= 3 and len(plain) <= 48:
            name_like = True
            for w in words:
                if any(ch.isdigit() for ch in w):
                    name_like = False
                    break
                if w.isupper() and 2 <= len(w) <= 5:
                    name_like = False  # acronym (MIT, METI, AI)
                    break
                if not (w[:1].isupper() and (len(w) == 1 or w[1:].islower() or w[1:].isalpha())):
                    name_like = False
                    break
            if name_like:
                return True
        return False

    @classmethod
    def _prefer_better_label(cls, current: str, candidate: str) -> str:
        cur = (current or "").strip()
        cand = (candidate or "").strip()
        if not cand:
            return cur
        if not cur:
            return cand
        cur_weak = cls._is_weak_link_label(cur)
        cand_weak = cls._is_weak_link_label(cand)
        if cur_weak and not cand_weak:
            return cand
        if cand_weak and not cur_weak:
            return cur
        return cand if len(cand) > len(cur) else cur

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
            url = cls._normalize_url_text(match.rstrip(".,);]}>'\"")).rstrip(".,);]}>'\"")
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
    def _raw_http_url_count(cls, text: str, *, mode: str = "default") -> int:
        """Count matching URLs before dedupe — used to force rebuild on duplicates."""
        count = 0
        for match in _URL_RE.findall(text or ""):
            url = cls._normalize_url_text(match.rstrip(".,);]}>'\"")).rstrip(".,);]}>'\"")
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
            count += 1
        return count

    @classmethod
    def _answer_has_weak_link_titles(cls, answer: str) -> bool:
        for line in (answer or "").splitlines():
            trimmed = line.strip()
            m = re.match(r"^\d+[\).\:\-]\s+(.+)$", trimmed)
            if not m:
                continue
            label = m.group(1).strip()
            if label.startswith("http://") or label.startswith("https://"):
                continue
            if cls._is_weak_link_label(label):
                return True
        return False

    @classmethod
    def _clean_link_label(cls, line: str) -> str:
        line = re.sub(r"^\d+\.\s*", "", line).strip(" \t:-–—|")
        line = re.sub(r"\s*via this link\b.*$", "", line, flags=re.I)
        line = re.sub(r"\s*[-–—|]\s*join\s*$", "", line, flags=re.I)
        # Drop leading time fluff: "At *3:00 PM CAT* (2:00 PM WAT), there is the first optional …"
        line = re.sub(
            r"^at\s+\*?[^,)]{0,40}\*?(\s*\([^)]*\))?\s*,?\s*"
            r"(there is\s+(the\s+)?)?(first\s+)?(optional\s+)?",
            "",
            line,
            flags=re.I,
        ).strip()
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
            r"^(you can|here are|these links|find all|join|click here|microsoft teams|"
            r"your contribution|our contribution|please join|kindly join)\b",
            line,
            flags=re.I,
        ):
            return ""
        # Prefer a clean head title, never chop mid-word from the tail.
        if len(line) > 70:
            line = line[:70].rsplit(" ", 1)[0].strip() or line[:70].strip()
        # Incomplete export crumbs (trailing conjunctions / cut-off phrases).
        if line.endswith((" the", " a", " an", " and", " or", " to", " for", " with", " —", " -")):
            return ""
        # Mid-word truncation at the start (export chopped the title), e.g. "versal AI…".
        if re.match(r"^[a-z]", line) and not re.match(
            r"^(the|a|an|le|la|les|un|une|des|el|los|las)\b",
            line,
            flags=re.I,
        ):
            return ""
        # Speaker/timestamp crumbs that slipped through.
        if re.match(r"^\d{1,2}/\d{2,4}", line) or ":" in line[:24] and re.search(
            r"\bBot\b|\bNexus\b", line
        ):
            if re.match(r"^\[?\d", line) or re.search(r"\d{1,2}:\d{2}", line[:30]):
                return ""
        if cls._is_weak_link_label(line):
            return ""
        return line

    @classmethod
    def _label_for_url(cls, url: str, content: str) -> str:
        idx = content.find(url)
        # Also try normalized / truncated variants for long light-meeting URLs.
        if idx < 0:
            short = url.split("?", 1)[0]
            idx = content.find(short) if short else -1
        if idx < 0:
            return ""

        window_start = max(0, idx - 280)
        before = content[window_start:idx]
        # Prefer a clean line start — a wide window often begins mid-word.
        if before and before[0].isalnum() and not before[0].isupper():
            cut = re.search(r"[\n.!?]\s+", before)
            if cut:
                before = before[cut.end() :]
            else:
                before = re.sub(r"^\S*\s+", "", before, count=1)

        candidates: list[str] = []
        lines = [ln.strip() for ln in before.splitlines() if ln.strip()]
        if lines:
            # Immediate line before the URL, then earlier lines (session titles often sit above).
            for ln in reversed(lines[-4:]):
                ln = re.sub(r"^[\w\s.\-]{2,48}:\s+", "", ln).strip()
                ln = re.sub(
                    r"^\[\d{1,2}/\d{1,2}/\d{2,4},?\s*\d{1,2}:\d{2}.*?\]\s*",
                    "",
                    ln,
                ).strip()
                cleaned = cls._clean_link_label(ln)
                if cleaned:
                    candidates.append(cleaned)

        # Prefer the most specific (longest non-weak) candidate.
        best = ""
        for cand in candidates:
            best = cls._prefer_better_label(best, cand)
        return best

    @classmethod
    def _fallback_label(cls, url: str) -> str:
        lower = url.lower()
        if cls._is_meeting_join_url(url):
            return "Microsoft Teams meeting"
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
        follow_up_line = cls._current_question(query)
        question = cls._original_question(query)
        q = question.lower()
        is_follow_up_envelope = "the member is following up" in (query or "").lower()
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
        # Short follow-ups must not invent a link-dump intent from prior answer text
        # stuffed into the envelope — only the original ask counts.
        if is_follow_up_envelope and not (
            wants_meeting_links or wants_recordings or wants_generic_links
        ):
            return answer, []

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
                        raw = cls._normalize_url_text(raw.rstrip(".,);]}>'\"")).rstrip(".,);]}>'\"")
                        if cls._url_dedupe_key(raw) == key:
                            label = cls._label_for_url(raw, chunk.content)
                            if label:
                                break
                if label and cls._is_weak_link_label(label):
                    label = ""
                if key not in evidence_by_key:
                    evidence_by_key[key] = (url, label)
                else:
                    prev_url, prev_label = evidence_by_key[key]
                    chosen = cls._prefer_cleaner_stored_url(prev_url, url)
                    best_label = cls._prefer_better_label(prev_label, label)
                    evidence_by_key[key] = (chosen, best_label)

        # Collapse light-meetings?p=… into /meet/{id}?p=… when both share a passcode.
        pass_to_meet: dict[str, str] = {}
        for key, (url, _label) in evidence_by_key.items():
            if not key.startswith("teams-meet:"):
                continue
            qs = parse_qs(urlsplit(cls._normalize_url_text(url)).query)
            passcode = (qs.get("p") or [""])[0].strip()
            if len(passcode) >= 8:
                pass_to_meet[f"teams-pass:{passcode.lower()}"] = key
        for pass_key, meet_key in pass_to_meet.items():
            if pass_key not in evidence_by_key or meet_key not in evidence_by_key:
                continue
            pass_url, pass_label = evidence_by_key.pop(pass_key)
            meet_url, meet_label = evidence_by_key[meet_key]
            evidence_by_key[meet_key] = (
                cls._prefer_cleaner_stored_url(meet_url, pass_url),
                cls._prefer_better_label(meet_label, pass_label),
            )
            for eid in url_evidence_ids.pop(pass_key, []):
                url_evidence_ids.setdefault(meet_key, [])
                if eid not in url_evidence_ids[meet_key]:
                    url_evidence_ids[meet_key].append(eid)

        if not evidence_by_key:
            if wants_recordings or wants_meeting_links:
                # Never pass through LinkedIn / random sites for typed link asks.
                kept = cls._extract_http_urls(answer or "", mode=mode)
                if not kept:
                    return "", []
            return answer, []

        answer_urls = cls._extract_http_urls(answer or "", mode=mode)
        answer_keys = {cls._url_dedupe_key(u) for u in answer_urls}
        missing = [u for key, (u, _) in evidence_by_key.items() if key not in answer_keys]
        raw_answer_url_count = cls._raw_http_url_count(answer or "", mode=mode)
        # Rebuild when the draft still has duplicate Teams shapes or weak "Diane"-style titles.
        needs_rebuild = wants_any and (
            raw_answer_url_count > len(answer_keys)
            or cls._answer_has_weak_link_titles(answer or "")
            or len(answer_keys) > len(evidence_by_key)
        )

        # Never turn a person/identity answer into a dump of unrelated URLs.
        if re.search(r"\bwho(?:'s|’s|\s+is|\s+are)?\b", q) and not wants_any:
            return answer, []
        if not wants_any and not answer_keys:
            return answer, []
        if not missing and answer_keys and wants_any and not needs_rebuild:
            if len(answer_keys) >= len(evidence_by_key):
                return answer, []

        if not wants_any and not missing:
            return answer, []

        # Prefer the model's lead sentence (any language). Never hardcode EN/FR/AR intros.
        intro = ""
        for line in (answer or "").splitlines():
            trimmed = line.strip()
            if not trimmed or _URL_RE.search(trimmed):
                continue
            if re.match(r"^\d+[\).\:\-]\s*", trimmed):
                continue
            intro = trimmed
            break

        used_ids: list[str] = []
        link_lines: list[str] = []
        for i, (key, (url, label)) in enumerate(evidence_by_key.items(), start=1):
            display_label = (
                label
                if label and not cls._is_weak_link_label(label)
                else cls._fallback_label(url)
            )
            link_lines.append(f"{i}. {display_label}")
            link_lines.append(url)
            link_lines.append("")
            for eid in url_evidence_ids.get(key, []):
                if eid not in used_ids:
                    used_ids.append(eid)
        link_block = "\n".join(link_lines).strip()

        # Follow-ups must not re-glue the prior summary onto a second link dump
        # (that produced duplicate "here's more detail" blocks in chat).
        if is_follow_up_envelope and (answer or "").strip():
            if len((answer or "").strip()) >= 40:
                return cls._ensure_section_spacing(answer), []
            if link_block:
                return cls._ensure_section_spacing(f"{(answer or '').rstrip()}\n\n{link_block}"), used_ids

        rebuilt = f"{intro}\n\n{link_block}".strip() if intro else link_block

        # Keep prose for multi-part asks ("… also any meeting today?").
        # Use original question only — never the whole follow-up envelope.
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
                # Numbered link titles are replaced by the rebuilt link_block.
                if re.match(r"^\d+[\).\:\-]\s+\S", trimmed):
                    continue
                if re.match(r"^[•\-]\s+\S", trimmed) and len(trimmed) < 80:
                    continue
                prose_lines.append(trimmed)
            prose = "\n".join(prose_lines).strip()
            if len(prose) >= 24:
                # Append links without a second lead sentence (avoids duplicate intros).
                return cls._ensure_section_spacing(f"{prose}\n\n{link_block}"), used_ids

        return cls._ensure_section_spacing(rebuilt), used_ids

    @staticmethod
    def _ensure_section_spacing(answer: str) -> str:
        """Keep replies readable: blank line after lead, no jam-packed blocks."""
        text = (answer or "").replace("\r\n", "\n").strip()
        if not text:
            return ""
        # Collapse 3+ newlines to a double break; ensure lead → list has a blank line.
        text = re.sub(r"\n{3,}", "\n\n", text)
        text = re.sub(
            r"(?m)^([^\n\d][^\n]{0,120})\n(\d+[\).\:\-]\s+)",
            r"\1\n\n\2",
            text,
        )
        return text.strip()

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
                    source_uri=cand.source_uri or "",
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
        # Typed link asks (recordings/meetings/assets) may score soft cross-language
        # (query in AR/FR vs English chunks) even when URL evidence is present — still synthesize.
        link_mode_norm = (link_mode or "").strip().lower()
        typed_link_ask = link_mode_norm in {"recordings", "meetings", "assets"}
        has_http_evidence = any("http" in (c.content or "").lower() for c in candidates)
        top_score = candidates[0].final_score if candidates else 0.0
        detected_lang = (language_hint or "").strip().lower()
        cross_language_evidence = detected_lang not in {"", "en"} and top_score >= 0.35
        if not candidates or (
            top_score < self.MIN_CONFIDENCE_FLOOR
            and not (typed_link_ask and has_http_evidence)
            and not cross_language_evidence
        ):
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
        cleaned_answer = self._ensure_section_spacing(cleaned_answer)

        # 5b. Dedicated response writer: grounded draft → professional member copy.
        # Language lock = latest ask (Follow-up:), never the older Original question.
        cleaned_answer = await self._polisher.polish(
            cleaned_answer,
            question=self._current_question(query),
            use_model=True,
        )
        cleaned_answer = self._ensure_section_spacing(cleaned_answer)

        # 6. Deterministic 4-State Resolution
        return AnswerVerifier.resolve_state(
            raw_answer=cleaned_answer,
            evidence_chunks=evidence_chunks,
            verified_citations=verified_citations,
            all_citations_valid=all_valid,
            detected_conflicts=detected_conflicts,
        )
