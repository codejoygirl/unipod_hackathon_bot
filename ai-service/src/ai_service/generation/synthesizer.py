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
    def _looks_truncated_url(cls, url: str) -> bool:
        """True when a URL looks cut off mid-path/query (common on long Teams joins)."""
        raw = cls._normalize_url_text(url or "").strip()
        if not raw or len(raw) < 12:
            return True
        if re.search(r"%[0-9A-Fa-f]?$", raw):
            return True
        if raw.endswith(("=", "&", "%", ",", '"', "'", ":", "-")):
            return True
        lower = raw.lower()
        if "context=" in lower:
            ctx = lower.split("context=", 1)[1]
            # Complete Teams context JSON ends with } / %7D.
            if "%7d" not in ctx and "}" not in html.unescape(
                raw.split("context=", 1)[-1] if "context=" in raw else ""
            ):
                return True
            # Cut mid-UUID inside Tid/Oid.
            if re.search(
                r"(?:oid|tid)(?:%22%3a%22|\"\s*:\s*\")[0-9a-f-]{1,35}$",
                lower,
            ):
                return True
        return False

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
        cur_t = cls._looks_truncated_url(current)
        cand_t = cls._looks_truncated_url(candidate)
        # Never keep a cut-off paste when a complete twin exists.
        if cur_t and not cand_t:
            return candidate
        if cand_t and not cur_t:
            return current
        if cls._url_query_penalty(candidate) < cls._url_query_penalty(current):
            return candidate
        return current

    @classmethod
    def _scrub_broken_chars(cls, text: str) -> str:
        """Drop encoding damage (U+FFFD / ?? placeholders) left by bad chat exports."""
        if not text:
            return ""
        text = text.replace('\ufffd', '')
        text = re.sub(r'\?{2,}', '', text)
        text = re.sub(r"(^|[\s\u2022\-])['\u2019]s\b", r'\1', text)
        text = re.sub(r'\s{2,}', ' ', text).strip(' \t:-|\u2022')
        return text.strip()

    @classmethod
    def _is_weak_link_label(cls, label: str) -> bool:
        """True when the title is a speaker crumb, date fact, or fluff - not a resource name."""
        text = cls._scrub_broken_chars(label or "")
        if not text:
            return True
        # Strip WhatsApp emphasis for the weakness check.
        plain = re.sub(r"\*+", "", text).strip()
        words = re.findall(r"[\w'’-]+", plain, flags=re.UNICODE)
        if not words:
            return True
        # Schedule/deadline facts used as captions for Drive/files (shape, not language catalog).
        # e.g. "Expected completion date: October 18, 2026" must not title a PDF link.
        if cls._looks_like_date_fact_label(plain):
            return True
        session_re = re.compile(
            r"\b(session|workshop|meeting|ignite|open|course|module|assessment|"
            r"webinar|office|hours|standup|sync|office\s*hours|kickoff|onboarding|"
            r"orientation|training|seminar|forum|clinic|briefing)\b",
            flags=re.I,
        )
        if session_re.search(plain):
            # Still weak when the "title" is clearly a chat message crumb.
            if re.search(
                r"\b(can you|could you|please send|send me|good morning|hope you|"
                r"anyone|don'?t keep|keep them to yourself|ideally to be completed|"
                r"took place|waiting|joined from)\b",
                plain,
                flags=re.I,
            ):
                return True
            if re.match(r"^(reminder\b|today at\b|there (is|are)\b)", plain, flags=re.I):
                return True
            if re.search(r"(^|[\s'’])t keep them\b", plain, flags=re.I):
                return True
            if "@" in plain and re.search(r"@\w*bot\b", plain, flags=re.I):
                return True
            return False
        if len(words) <= 1:
            return True
        if re.match(
            r"^(at\b|your\b|our\b|there is\b|there are\b|here is\b|please\b|kindly\b|"
            r"professor\b|jeovaire\b|reminder\b|today at\b|genial\b)",
            plain,
            flags=re.I,
        ):
            return True
        # Chat-message crumbs pasted as titles.
        if re.search(
            r"\b(can you|could you|send me|good morning|hope you are|"
            r"anyone|don'?t keep|keep them to yourself|took place|"
            r"people in the (teams )?call|joined from the community)\b",
            plain,
            flags=re.I,
        ):
            return True
        if re.search(r"(^|[\s'’])t keep them\b", plain, flags=re.I):
            return True
        if "@" in plain and re.search(r"@\w*bot\b", plain, flags=re.I):
            return True
        # "Speaker: long chat message" export crumbs used as titles.
        if re.match(r"^[^:\n]{1,48}:\s+\S.{24,}$", plain) and plain.count(" ") >= 4:
            return True
        # Long sentence fragments are not link titles.
        if len(plain) > 60 and plain.count(" ") >= 6:
            return True
        if len(plain) > 72 and plain.count(" ") >= 8:
            return True
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
    def _looks_like_date_fact_label(cls, label: str) -> bool:
        """Structural: caption is mainly a calendar/deadline fact, not a document name."""
        plain = (label or "").strip()
        if not plain:
            return True
        if re.fullmatch(r"[\d./\-:\s]+", plain):
            return True
        has_year = re.search(r"\b20\d{2}\b", plain) is not None
        has_day = re.search(r"\b\d{1,2}\b", plain) is not None
        if not (has_year and has_day):
            return False
        # "Label: <date...>" fact lines (completion / due / deadline style).
        if re.search(r":\s*.*\b20\d{2}\b", plain):
            return True
        # Short lines dominated by digits (dates) rather than a resource title.
        digits = len(re.findall(r"\d", plain))
        if digits >= 6 and len(plain) <= 64 and plain.count(" ") <= 7:
            return True
        return False

    @classmethod
    def _is_generic_pack_label(cls, label: str) -> bool:
        """True for pack/source wrappers — not the resource title above a URL."""
        plain = re.sub(r"\*+", "", (label or "")).strip()
        if not plain:
            return True
        if re.match(r"^=+\s*.+\s*=+$", plain):
            return True
        low = plain.lower()
        if re.search(
            r"\b(resource pack|programme resource|program resource|community knowledge|"
            r"programme docs|program docs)\b",
            low,
        ):
            return True
        return False

    @classmethod
    def _prefer_better_label(cls, current: str, candidate: str) -> str:
        cur = (current or "").strip()
        cand = (candidate or "").strip()
        if not cand:
            return cur
        if cls._is_generic_pack_label(cand):
            return cur
        if not cur or cls._is_generic_pack_label(cur):
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
    def restore_urls_in_answer(cls, answer: str) -> str:
        """Decode HTML entities inside http(s) URLs so members get the original link."""

        def _fix(match: re.Match[str]) -> str:
            return cls._normalize_url_text(match.group(0))

        return _URL_RE.sub(_fix, answer or "")

    @classmethod
    def _extract_http_urls(cls, text: str, *, mode: str = "default") -> list[str]:
        by_key: dict[str, str] = {}
        for match in _URL_RE.findall(text or ""):
            url = cls._normalize_url_text(match.rstrip(".,);]}>'\"")).rstrip(".,);]}>'\"")
            if not url.lower().startswith(("http://", "https://")):
                continue
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
            if not url.lower().startswith(("http://", "https://")):
                continue
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
            if cls._is_weak_link_label(label) or cls._is_generic_pack_label(label):
                return True
        return False

    @classmethod
    def _clean_link_label(cls, line: str) -> str:
        line = cls._scrub_broken_chars(line or "")
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
        if cls._is_weak_link_label(line) or cls._is_generic_pack_label(line):
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

        lines = [ln.strip() for ln in before.splitlines() if ln.strip()]
        if not lines:
            return ""

        cleaned_lines: list[str] = []
        for ln in reversed(lines[-4:]):
            ln = re.sub(r"^[\w\s.\-]{2,48}:\s+", "", ln).strip()
            ln = re.sub(
                r"^\[\d{1,2}/\d{1,2}/\d{2,4},?\s*\d{1,2}:\d{2}.*?\]\s*",
                "",
                ln,
            ).strip()
            cleaned = cls._clean_link_label(ln)
            if cleaned and not cls._is_generic_pack_label(cleaned):
                cleaned_lines.append(cleaned)

        if not cleaned_lines:
            return ""
        # Immediate line above the URL wins; earlier lines are fallback only.
        return cleaned_lines[0]

    @classmethod
    def _fallback_label(cls, url: str) -> str:
        """Host-shape fallback only when no meaningful caption exists (not a language catalog)."""
        lower = url.lower()
        if cls._is_meeting_join_url(url):
            return "Microsoft Teams meeting"
        if "linkedin.com/" in lower:
            return "LinkedIn profile"
        if "github.com/" in lower:
            return "GitHub profile"
        if "tiktok.com/" in lower:
            return "TikTok"
        if "chat.whatsapp.com/" in lower or "wa.me/" in lower:
            return "WhatsApp invite"
        if "docs.google.com/spreadsheets" in lower:
            return "Google Sheet"
        if "docs.google.com/forms" in lower or "forms.gle/" in lower:
            return "Google Form"
        if "drive.google.com" in lower:
            return "Google Drive file"
        if "youtu" in lower:
            return "YouTube recording"
        if "meetingrecap" in lower or "recap" in lower:
            return "Teams recording"
        host = urlsplit(url).netloc.lower().removeprefix("www.")
        if host:
            return host
        return "Shared link"

    @classmethod
    def _token_set(cls, text: str) -> set[str]:
        tokens = re.findall(r"[\w'-]+", (text or "").lower(), flags=re.UNICODE)
        return {t for t in tokens if len(t) >= 3}

    @classmethod
    def _ask_content_tokens(cls, query: str) -> set[str]:
        """Ask tokens minus structural chatter (shape only — not a meaning catalog)."""
        stop = {
            "send",
            "give",
            "get",
            "the",
            "and",
            "for",
            "with",
            "from",
            "this",
            "that",
            "have",
            "all",
            "any",
            "can",
            "you",
            "please",
            "need",
            "want",
            "link",
            "links",
            "url",
            "urls",
            "file",
            "files",
            "doc",
            "docs",
            "document",
            "documents",
            "pdf",
            "also",
            "just",
            "only",
            "first",
            "programme",
            "program",
            "community",
        }
        return cls._token_set(query) - stop

    @classmethod
    def _query_url_relevance(
        cls,
        query: str,
        url: str,
        label: str,
        content: str,
    ) -> float:
        """Lexical overlap between the ask and the URL's nearby evidence title/context."""
        q = cls._ask_content_tokens(query)
        if not q:
            q = cls._token_set(query)
        if not q:
            return 0.0

        def _soft_match(a: str, b: str) -> bool:
            if a == b:
                return True
            # Singular/plural only (guideline↔guidelines), not guide↔guideline.
            if a + "s" == b or b + "s" == a:
                return True
            if len(a) > 4 and a.endswith("s") and a[:-1] == b:
                return True
            if len(b) > 4 and b.endswith("s") and b[:-1] == a:
                return True
            return False

        def _overlap(blob: str) -> float:
            t = cls._token_set(blob)
            if not t:
                return 0.0
            exact = q & t
            hits = len(exact)
            for qt in q - exact:
                if any(_soft_match(qt, bt) for bt in t):
                    hits += 1
            return hits / max(len(q), 1)

        local = label or ""
        idx = (content or "").find(url) if url else -1
        if idx < 0 and url:
            short = url.split("?", 1)[0]
            idx = (content or "").find(short) if short else -1
        if idx >= 0:
            local = f"{local} {(content or "")[max(0, idx - 220) : idx + min(len(url), 80) + 40]}"
        path = urlsplit(url).path.replace("/", " ").replace("-", " ").replace("_", " ")
        local = f"{local} {path}"
        # Local title/window dominates; whole chunk is a weak tie-break only.
        return (0.85 * _overlap(local)) + (0.15 * _overlap(content or ""))

    @classmethod
    def _best_focus_one_item(
        cls,
        *,
        query: str,
        evidence_by_key: dict[str, tuple[str, str]],
        evidence_chunks: Sequence[EvidenceChunk],
        mode: str,
        model_items: list[tuple[str, tuple[str, str]]],
    ) -> tuple[str, tuple[str, str]] | None:
        """Pick the single best URL for a singular ask from evidence (+ optional model pick)."""
        if not evidence_by_key and not model_items:
            return None

        evidence_scored: list[tuple[float, str, tuple[str, str]]] = []
        for chunk in evidence_chunks:
            content = chunk.content or ""
            for url in cls._extract_http_urls(content, mode=mode):
                key = cls._url_dedupe_key(url)
                if key not in evidence_by_key:
                    continue
                pair = evidence_by_key[key]
                label = pair[1] or cls._label_for_url(pair[0], content)
                score = cls._query_url_relevance(query, pair[0], label, content)
                evidence_scored.append((score, key, (pair[0], label or pair[1])))

        if evidence_scored:
            evidence_scored.sort(key=lambda row: row[0], reverse=True)
            best_score, best_key, best_pair = evidence_scored[0]
            # Evidence title/context beat a model caption glued onto the wrong URL.
            if best_score > 0:
                return best_key, best_pair

        if not model_items:
            return None

        model_scored: list[tuple[float, str, tuple[str, str]]] = []
        for key, (url, label) in model_items:
            content = ""
            for chunk in evidence_chunks:
                body = chunk.content or ""
                if url.split("?", 1)[0] in body:
                    content = body
                    break
            score = cls._query_url_relevance(query, url, label, content)
            if not content:
                score *= 0.25
            model_scored.append((score, key, (url, label)))
        model_scored.sort(key=lambda row: row[0], reverse=True)
        return model_scored[0][1], model_scored[0][2]

    @classmethod
    def _extract_closing_after_link_list(cls, answer: str) -> str:
        """Keep polite hub/related closing prose (+ optional URL) after a numbered list."""
        lines = (answer or "").replace("\r\n", "\n").splitlines()
        n = len(lines)
        i = 0
        while i < n and not re.match(r"^\d+[\).\:\-]\s+\S", lines[i].strip()):
            i += 1
        if i >= n:
            return ""
        # Consume contiguous numbered caption(+URL) blocks only.
        while i < n:
            trimmed = lines[i].strip()
            if not re.match(r"^\d+[\).\:\-]\s+\S", trimmed):
                break
            i += 1
            while i < n and not lines[i].strip():
                i += 1
            if i < n and lines[i].strip().startswith(("http://", "https://")):
                i += 1
            while i < n and not lines[i].strip():
                i += 1
        closing = "\n".join(lines[i:]).strip()
        if len(closing) < 12:
            return ""
        # Another numbered resource = more list padding, not a hub closing.
        if re.match(r"^\d+[\).\:\-]\s+\S", closing):
            return ""
        return closing

    @classmethod
    def _parse_link_list_from_answer(cls, answer: str) -> list[tuple[str, str]]:
        """Extract (url, caption) pairs from a numbered link list draft."""
        lines = (answer or "").splitlines()
        pairs: list[tuple[str, str]] = []
        i = 0
        while i < len(lines):
            trimmed = lines[i].strip()
            m = re.match(r"^\d+[\).\:\-]\s+(.+)$", trimmed)
            if m:
                rest = m.group(1).strip()
                # Same-line "caption: https://..." (common model slip).
                same = re.match(r"^(.+?)\s*:\s*(https?://\S+)\s*$", rest)
                if same:
                    label = same.group(1).strip()
                    url = cls._normalize_url_text(same.group(2).rstrip(".,);]}>'\"")).rstrip(
                        ".,);]}>'\" "
                    )
                    pairs.append((url, label))
                    i += 1
                    continue
                if rest.startswith("http://") or rest.startswith("https://"):
                    pairs.append((cls._normalize_url_text(rest.rstrip(".,);]}>'\"")), ""))
                    i += 1
                    continue
                j = i + 1
                while j < len(lines) and not lines[j].strip():
                    j += 1
                if j < len(lines):
                    nxt = lines[j].strip()
                    if nxt.startswith("http://") or nxt.startswith("https://"):
                        url = cls._normalize_url_text(nxt.rstrip(".,);]}>'\"")).rstrip(".,);]}>'\"")
                        pairs.append((url, rest))
                        i = j + 1
                        continue
            elif trimmed.startswith("http://") or trimmed.startswith("https://"):
                url = cls._normalize_url_text(trimmed.rstrip(".,);]}>'\"")).rstrip(".,);]}>'\"")
                pairs.append((url, ""))
            i += 1
        return pairs

    @classmethod
    def _complete_link_answer_from_evidence(
        cls,
        query: str,
        answer: str,
        evidence_chunks: Sequence[EvidenceChunk],
        link_mode: str | None = None,
        target_language: str | None = None,
        language_hint: str | None = None,
        link_focus: str | None = None,
    ) -> tuple[str, list[str]]:
        """Polish/fill link lists. The model owns which URLs belong; code does not rank meaning."""
        follow_up_line = cls._current_question(query)
        question = cls._original_question(query)
        q = (follow_up_line or question).lower()
        is_follow_up_envelope = "the member is following up" in (query or "").lower()
        mode_hint = (link_mode or "").strip().lower()
        focus = (link_focus or "").strip().lower()
        if focus not in {"one", "many", "na"}:
            focus = "na"
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

        # Keep captions + URLs the model already listed (e.g. LinkedIn on an assets ask
        # where social hosts are filtered from evidence extraction).
        for url, model_label in cls._parse_link_list_from_answer(answer or ""):
            if mode == "meetings" and not cls._is_meeting_join_url(url):
                continue
            if mode == "recordings" and (
                cls._is_meeting_join_url(url) or not cls._is_likely_recording_url(url)
            ):
                continue
            key = cls._url_dedupe_key(url)
            clean_model = (
                ""
                if not model_label or cls._is_weak_link_label(model_label)
                else model_label.strip()
            )
            if key not in evidence_by_key:
                evidence_by_key[key] = (url, clean_model)
                url_evidence_ids.setdefault(key, [])
            else:
                prev_url, prev_label = evidence_by_key[key]
                chosen = cls._prefer_cleaner_stored_url(prev_url, url)
                best_label = cls._prefer_better_label(prev_label, clean_model)
                # Prefer a strong model caption over a chat-crumb evidence label.
                if clean_model and (
                    not prev_label or cls._is_weak_link_label(prev_label)
                ):
                    best_label = clean_model
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
        answer_has_truncated = any(cls._looks_truncated_url(u) for u in answer_urls)
        # Rebuild for duplicate shapes / weak titles. Never invent a bigger corpus dump
        # than the model already chose — the model owns which links match the ask.
        needs_rebuild = wants_any and (
            raw_answer_url_count > len(answer_keys)
            or cls._answer_has_weak_link_titles(answer or "")
            or (focus == "one" and raw_answer_url_count > 1)
            or focus == "one"
            or (not answer_keys and bool(evidence_by_key))
            or answer_has_truncated
        )

        # Never turn a person/identity answer into a dump of unrelated URLs.
        if re.search(r"\bwho(?:'s|’s|\s+is|\s+are)?\b", q) and not wants_any:
            return answer, []
        if not wants_any and not answer_keys:
            return answer, []
        if not missing and answer_keys and wants_any and not needs_rebuild:
            if focus != "one" or raw_answer_url_count <= 1:
                # Still normalize &amp; etc. in the model reply; expand cut-off twins.
                fixed = cls.restore_urls_in_answer(answer or "")
                for url in answer_urls:
                    if not cls._looks_truncated_url(url):
                        continue
                    key = cls._url_dedupe_key(url)
                    full = evidence_by_key.get(key, (None, None))[0]
                    if full and full != url:
                        fixed = fixed.replace(url, full)
                return fixed, []

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
            # Keep lead punctuation (e.g. trailing ":"); scrub is for titles/labels.
            intro = re.sub(r"\?{2,}", "", (trimmed or "").replace("\ufffd", "")).strip()
            break

        used_ids: list[str] = []
        link_lines: list[str] = []
        # Trust the model's selection order. Only fill from evidence when the model
        # listed no openable URL for a typed link ask.
        emit_items: list[tuple[str, tuple[str, str]]] = []
        # Prefer the numbered list order (keeps social URLs on assets asks that
        # evidence extraction filters as noise).
        model_pairs = cls._parse_link_list_from_answer(answer or "")
        seed_urls: list[str] = []
        seed_labels: dict[str, str] = {}
        for url, label in model_pairs:
            if mode == "meetings" and not cls._is_meeting_join_url(url):
                continue
            if mode == "recordings" and (
                cls._is_meeting_join_url(url) or not cls._is_likely_recording_url(url)
            ):
                continue
            key = cls._url_dedupe_key(url)
            if key in seed_labels:
                continue
            seed_urls.append(url)
            seed_labels[key] = label
        if not seed_urls:
            seed_urls = list(answer_urls)

        if seed_urls:
            seen: set[str] = set()
            for url in seed_urls:
                key = cls._url_dedupe_key(url)
                if key in seen:
                    continue
                seen.add(key)
                if key in evidence_by_key:
                    emit_items.append((key, evidence_by_key[key]))
                else:
                    label = seed_labels.get(key, "")
                    if not label:
                        pairs = cls._parse_link_list_from_answer(answer or "")
                        label = next(
                            (lab for u, lab in pairs if cls._url_dedupe_key(u) == key),
                            "",
                        )
                    emit_items.append((key, (url, label or "")))
            if focus == "one":
                # Prefer the evidence URL whose nearby title best matches the ask.
                best = cls._best_focus_one_item(
                    query=follow_up_line or question,
                    evidence_by_key=evidence_by_key,
                    evidence_chunks=evidence_chunks,
                    mode=mode,
                    model_items=emit_items,
                )
                emit_items = [best] if best is not None else emit_items[:1]
            # Never pad a model-chosen list with the whole evidence corpus.
        elif wants_any and evidence_by_key:
            # Model failed to list URLs — light fill only (retrieval already ranked chunks).
            emit_items = list(evidence_by_key.items())
            if focus == "one":
                best = cls._best_focus_one_item(
                    query=follow_up_line or question,
                    evidence_by_key=evidence_by_key,
                    evidence_chunks=evidence_chunks,
                    mode=mode,
                    model_items=[],
                )
                emit_items = [best] if best is not None else emit_items[:1]
            elif mode == "assets":
                emit_items = emit_items[:5]
            elif mode in {"recordings", "meetings"}:
                emit_items = emit_items[:6]

        for i, (key, (url, label)) in enumerate(emit_items, start=1):
            # Prefer the line immediately above the URL in evidence when the model
            # caption is missing, weak, or a pack/source wrapper.
            evidence_label = ""
            for chunk in evidence_chunks:
                evidence_label = cls._label_for_url(url, chunk.content or "")
                if evidence_label:
                    break
            model_ok = bool(
                label
                and not cls._is_weak_link_label(label)
                and not cls._is_generic_pack_label(label)
            )
            evidence_ok = bool(
                evidence_label
                and not cls._is_weak_link_label(evidence_label)
                and not cls._is_generic_pack_label(evidence_label)
            )
            if evidence_ok and (
                not model_ok
                or cls._is_generic_pack_label(label or "")
                or cls._is_weak_link_label(label or "")
            ):
                label = evidence_label
            elif evidence_ok and model_ok and cls._is_generic_pack_label(label or ""):
                label = evidence_label
            if label and not cls._is_weak_link_label(label) and not cls._is_generic_pack_label(label):
                display_label = label.strip()
            elif evidence_ok:
                display_label = evidence_label.strip()
            elif label and len(re.findall(r"[\w'’-]+", label, flags=re.UNICODE)) >= 5:
                display_label = label.strip()
            else:
                display_label = cls._fallback_label(url)
            display_label = cls._scrub_broken_chars(display_label) or cls._fallback_label(url)
            link_lines.append(f"{i}. {display_label}")
            link_lines.append(cls._normalize_url_text(url))
            link_lines.append("")
            for eid in url_evidence_ids.get(key, []):
                if eid not in used_ids:
                    used_ids.append(eid)
        link_block = "\n".join(link_lines).strip()

        # Follow-ups must not re-glue the prior summary onto a second link dump
        # (that produced duplicate "here's more detail" blocks in chat).
        # When the classifier says focus=one, still use the trimmed rebuild.
        if is_follow_up_envelope and (answer or "").strip() and focus != "one":
            if len((answer or "").strip()) >= 40:
                return cls.restore_urls_in_answer(cls._ensure_section_spacing(answer)), []
            if link_block:
                return cls._ensure_section_spacing(f"{(answer or '').rstrip()}\n\n{link_block}"), used_ids

        rebuilt = f"{intro}\n\n{link_block}".strip() if intro else link_block

        # focus=one trims the numbered list, but keep a polite related-hub closing
        # the model already wrote after the list (URL only if it was in the draft).
        if focus == "one":
            closing = cls._extract_closing_after_link_list(answer or "")
            if closing:
                primary_urls = {
                    cls._normalize_url_text(url) for _, (url, _) in emit_items
                }
                # Drop closing if it only repeats the primary URL (no new hub).
                closing_urls = [
                    cls._normalize_url_text(m.rstrip(".,);]}>'\"")).rstrip(".,);]}>'\"")
                    for m in _URL_RE.findall(closing)
                ]
                if not closing_urls or any(u not in primary_urls for u in closing_urls):
                    rebuilt = f"{rebuilt}\n\n{closing}".strip()

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
        timezone_name: str | None = None,
        reference_time_iso: str | None = None,
        link_focus: str | None = None,
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
            timezone_name=timezone_name,
            reference_time_iso=reference_time_iso,
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
            link_focus=link_focus,
        )
        if completed_answer:
            answer_text = completed_answer
            for eid in link_evidence_ids:
                if eid not in evidence_ids_used:
                    evidence_ids_used.append(eid)
            if link_evidence_ids:
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
        # Evidence XML escapes & as &amp;; never leave that in member-facing URLs.
        cleaned_answer = self.restore_urls_in_answer(cleaned_answer)

        # 6. Deterministic 4-State Resolution
        return AnswerVerifier.resolve_state(
            raw_answer=cleaned_answer,
            evidence_chunks=evidence_chunks,
            verified_citations=verified_citations,
            all_citations_valid=all_valid,
            detected_conflicts=detected_conflicts,
        )
