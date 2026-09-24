"""Detect, parse, and clean chat exports into RAG-friendly conversation windows.

Works for common export shapes (WhatsApp, Telegram Desktop, Slack text dumps)
and falls back gracefully when the format is unknown.

Industry default for chat → RAG (unthreaded WhatsApp-style):
1. Normalize noise; keep speaker, time, body.
2. Session on inactivity gaps (~30 minutes), not raw file bytes.
3. Cap message count / chars as a safety valve.
4. Preserve participants + time range on each window (PRD §18.2).
"""

from __future__ import annotations

from dataclasses import dataclass
from datetime import datetime
from enum import StrEnum
import re
from typing import Any


class ExportType(StrEnum):
    WHATSAPP = "whatsapp"
    TELEGRAM = "telegram"
    SLACK = "slack"
    PLAIN = "plain"
    UNKNOWN = "unknown"


@dataclass(frozen=True, slots=True)
class ChatMessage:
    timestamp: str | None
    speaker: str | None
    body: str
    is_system: bool = False


# WhatsApp iOS/Android: [dd/mm/yyyy, HH:MM:SS] Name: body
# Also: [dd/mm/yyyy, HH:MM:SS AM/PM] Name: body
# Bodies stay single-line here; multiline continuations are joined by the parser.
_WA_BRACKET = re.compile(
    r"^\[(\d{1,4}[/\-.]\d{1,2}[/\-.]\d{1,4}),?\s*([^\]]+)\]\s*(~?)([^:]+?):\s*(.*)$"
)
# WhatsApp Android dash form: dd/mm/yyyy, HH:MM - Name: body
_WA_DASH = re.compile(
    r"^(\d{1,4}[/\-.]\d{1,2}[/\-.]\d{1,4}),?\s*(\d{1,2}:\d{2}(?::\d{2})?(?:\s*[APap][Mm])?)\s*-\s*(~?)([^:]+?):\s*(.*)$"
)
# Telegram Desktop text export blocks:
# 04.09.2026 12:22:17 Name Surname
# message body...
_TG_HEADER = re.compile(
    r"^(\d{1,2}[./]\d{1,2}[./]\d{2,4})\s+(\d{1,2}:\d{2}(?::\d{2})?)\s+(.{1,80})$"
)
# Slack export-ish: [Name] HH:MM AM  or  Name [HH:MM]
_SLACK_BRACKET = re.compile(
    r"^\[([^\]]+)\]\s+(\d{1,2}:\d{2}(?::\d{2})?(?:\s*[APap][Mm])?)\s*(.*)$"
)
_SLACK_NAME_TIME = re.compile(
    r"^([A-Za-z0-9._\- ]{2,60})\s+\[(\d{1,2}:\d{2}(?::\d{2})?(?:\s*[APap][Mm])?)\]\s*(.*)$"
)

_SYSTEM_HINTS = re.compile(
    r"("
    r"joined using a group link|"
    r"left(?:\s|$)|"
    r"was added|"
    r"was removed|"
    r"created this group|"
    r"changed (?:the )?(?:subject|group description|this group|"
    r"their phone number|the group icon)|"
    r"Messages and calls are end-to-end encrypted|"
    r"Waiting for this message|"
    r"This message was deleted|"
    r"You deleted this message|"
    r"security code changed|"
    r"turned on disappearing messages|"
    r"turned off disappearing messages|"
    r"started a call|"
    r"missed (?:voice|video) call|"
    r"added you|"
    r"removed you"
    r")",
    re.IGNORECASE,
)

_MEDIA_ONLY = re.compile(
    r"^(?:"
    r"<media omitted>|"
    r"image omitted|"
    r"video omitted|"
    r"audio omitted|"
    r"sticker omitted|"
    r"gif omitted|"
    r"document omitted|"
    r"contact card omitted|"
    r"location:.*|"
    r"null|"
    r"\(file attached\)"
    r")$",
    re.IGNORECASE,
)

_NOISE_CHARS = re.compile(r"[\u200b-\u200f\u202a-\u202e\ufeff\ufffd]+")
_MULTI_SPACE = re.compile(r"[ \t]{2,}")
_EMOJI_RUN = re.compile(r"(?:\s*[\U0001F300-\U0001FAFF\U00002700-\U000027BF]){4,}")


class ChatExportNormalizer:
    """Detect export type, parse messages, drop noise, emit conversation windows."""

    CHAT_SOURCE_HINTS = frozenset(
        {
            "whatsapp",
            "telegram",
            "slack",
            "chat",
            "transcript",
            "txt",
            "text",
        }
    )

    def __init__(
        self,
        *,
        max_messages_per_window: int = 40,
        max_chars_per_window: int = 3500,
        max_gap_minutes: int = 30,
    ) -> None:
        # Industry default for unthreaded chat (WhatsApp-style): session windows
        # by inactivity gap (~30 min), with message/char caps as safety valves.
        # See PRD §18.2 "conversation windows with participants and time".
        self.max_messages_per_window = max_messages_per_window
        self.max_chars_per_window = max_chars_per_window
        self.max_gap_minutes = max_gap_minutes

    @staticmethod
    def _count_line_matches(pattern: re.Pattern[str], text: str) -> int:
        return sum(1 for line in text.splitlines() if pattern.match(line.rstrip()))

    @classmethod
    def looks_like_chat(cls, text: str) -> bool:
        sample = (text or "")[:12000]
        if not sample.strip():
            return False
        wa = cls._count_line_matches(_WA_BRACKET, sample) + cls._count_line_matches(
            _WA_DASH, sample
        )
        tg = cls._count_line_matches(_TG_HEADER, sample)
        slack = cls._count_line_matches(_SLACK_BRACKET, sample) + cls._count_line_matches(
            _SLACK_NAME_TIME, sample
        )
        return (wa + tg + slack) >= 3

    def detect(self, text: str) -> ExportType:
        sample = (text or "")[:20000]
        if not sample.strip():
            return ExportType.UNKNOWN

        wa = self._count_line_matches(_WA_BRACKET, sample) + self._count_line_matches(
            _WA_DASH, sample
        )
        tg = self._count_line_matches(_TG_HEADER, sample)
        slack = self._count_line_matches(_SLACK_BRACKET, sample) + self._count_line_matches(
            _SLACK_NAME_TIME, sample
        )

        scores = {
            ExportType.WHATSAPP: wa,
            ExportType.TELEGRAM: tg,
            ExportType.SLACK: slack,
        }
        best_type, best_score = max(scores.items(), key=lambda item: item[1])
        if best_score >= 3:
            return best_type
        if best_score >= 1:
            return best_type
        if self.looks_like_chat(sample):
            return ExportType.PLAIN
        return ExportType.UNKNOWN

    def parse(self, text: str, export_type: ExportType | None = None) -> list[ChatMessage]:
        cleaned = (text or "").replace("\r\n", "\n").replace("\r", "\n")
        if not cleaned.strip():
            return []

        kind = export_type or self.detect(cleaned)
        if kind == ExportType.WHATSAPP:
            return self._parse_whatsapp(cleaned)
        if kind == ExportType.TELEGRAM:
            return self._parse_telegram(cleaned)
        if kind == ExportType.SLACK:
            return self._parse_slack(cleaned)
        return self._parse_plain(cleaned)

    def clean_messages(self, messages: list[ChatMessage]) -> list[ChatMessage]:
        out: list[ChatMessage] = []
        for msg in messages:
            body = self._clean_body(msg.body)
            speaker = self._clean_speaker(msg.speaker)
            if not body:
                continue
            if msg.is_system or self._is_system(body, speaker):
                continue
            if _MEDIA_ONLY.match(body):
                continue
            out.append(
                ChatMessage(
                    timestamp=msg.timestamp,
                    speaker=speaker,
                    body=body,
                    is_system=False,
                )
            )
        return out

    def to_windows(self, messages: list[ChatMessage]) -> list[dict[str, Any]]:
        """Chunk chat into conversation sessions (industry default for WhatsApp-like RAG).

        Primary boundary: inactivity gap between messages (default 30 minutes).
        Safety valves: max messages / max chars so a busy session cannot blow up
        one embedding. Does not split on raw file size — that is transport-only.
        """
        if not messages:
            return []

        # Merge fragmented same-speaker bursts before sessioning.
        messages = self._coalesce_same_speaker(messages)

        windows: list[dict[str, Any]] = []
        current: list[ChatMessage] = []
        current_chars = 0
        prev_minutes: float | None = None

        def flush() -> None:
            nonlocal current, current_chars, prev_minutes
            if not current:
                return
            windows.append(self._format_window(current))
            current = []
            current_chars = 0
            prev_minutes = None

        for msg in messages:
            minutes = self._timestamp_to_minutes(msg.timestamp)
            line = self._format_line(msg)
            line_len = len(line) + 1

            gap_break = False
            if (
                current
                and minutes is not None
                and prev_minutes is not None
                and self.max_gap_minutes > 0
            ):
                gap = minutes - prev_minutes
                # Guard against clock skew / out-of-order export lines.
                if gap >= self.max_gap_minutes or gap < -self.max_gap_minutes:
                    gap_break = True

            would_overflow = (
                current
                and (
                    len(current) >= self.max_messages_per_window
                    or current_chars + line_len > self.max_chars_per_window
                )
            )
            if gap_break or would_overflow:
                flush()

            current.append(msg)
            current_chars += line_len
            if minutes is not None:
                prev_minutes = minutes

        flush()
        return windows

    def _coalesce_same_speaker(
        self,
        messages: list[ChatMessage],
        *,
        max_merge_gap_minutes: float = 3.0,
        max_merged_chars: int = 1200,
    ) -> list[ChatMessage]:
        """Join consecutive same-speaker fragments within a short time gap."""
        if not messages:
            return []
        merged: list[ChatMessage] = []
        current = messages[0]
        prev_min = self._timestamp_to_minutes(current.timestamp)

        for nxt in messages[1:]:
            nxt_min = self._timestamp_to_minutes(nxt.timestamp)
            same = (
                current.speaker
                and nxt.speaker
                and current.speaker.lower() == nxt.speaker.lower()
            )
            gap_ok = True
            if prev_min is not None and nxt_min is not None:
                gap = nxt_min - prev_min
                gap_ok = 0 <= gap <= max_merge_gap_minutes
            length_ok = len(current.body) + len(nxt.body) + 1 <= max_merged_chars

            if same and gap_ok and length_ok:
                current = ChatMessage(
                    timestamp=current.timestamp,
                    speaker=current.speaker,
                    body=f"{current.body}\n{nxt.body}".strip(),
                    is_system=False,
                )
                if nxt_min is not None:
                    prev_min = nxt_min
            else:
                merged.append(current)
                current = nxt
                prev_min = nxt_min

        merged.append(current)
        return merged

    @staticmethod
    def _timestamp_to_minutes(timestamp: str | None) -> float | None:
        """Parse export timestamps into absolute minutes for gap math."""
        if not timestamp:
            return None
        text = timestamp.strip()
        # dd/mm/yyyy HH:MM[:SS] [AM|PM]  or  dd.mm.yyyy HH:MM[:SS]
        m = re.match(
            r"^(\d{1,4})[/\-.](\d{1,2})[/\-.](\d{1,4})\s+"
            r"(\d{1,2}):(\d{2})(?::(\d{2}))?(?:\s*([APap][Mm]))?",
            text,
        )
        if not m:
            return None
        a, b, c, hour, minute, second, ampm = m.groups()
        # Heuristic: if first token > 31 treat as y/m/d else d/m/y (WA default).
        try:
            n1, n2, n3 = int(a), int(b), int(c)
            if n1 > 31:
                year, month, day = n1, n2, n3
            elif n3 > 31:
                day, month, year = n1, n2, n3
            else:
                day, month, year = n1, n2, n3
                if year < 100:
                    year += 2000
            hour_i = int(hour)
            if ampm:
                ap = ampm.upper()
                if ap == "PM" and hour_i < 12:
                    hour_i += 12
                if ap == "AM" and hour_i == 12:
                    hour_i = 0
            total = (
                year * 525600
                + month * 43800
                + day * 1440
                + hour_i * 60
                + int(minute)
                + (int(second) / 60.0 if second else 0.0)
            )
            return float(total)
        except ValueError:
            return None

    def process(
        self,
        text: str,
        *,
        source_type_hint: str | None = None,
        uri: str | None = None,
    ) -> tuple[ExportType, list[dict[str, Any]]]:
        hint = (source_type_hint or "").strip().lower()
        detected = self.detect(text)
        if detected == ExportType.UNKNOWN and hint in {
            "whatsapp",
            "telegram",
            "slack",
        }:
            detected = ExportType(hint)

        messages = self.clean_messages(self.parse(text, detected))
        if not messages:
            # Unknown / empty after clean — keep a lightly scrubbed plain dump
            # so ingest still indexes something useful.
            scrubbed = self._scrub_plain(text)
            if not scrubbed:
                return detected, []
            locator = {"media_url": uri} if uri else {}
            return detected, [
                {
                    "content": scrubbed,
                    "media_type": "chat_window",
                    "locator": locator,
                    "breadcrumbs": ["chat", detected.value],
                    "export_type": detected.value,
                }
            ]

        windows = self.to_windows(messages)
        locator_base = {"media_url": uri} if uri else {}
        for w in windows:
            w["media_type"] = "chat_window"
            start_iso = self.normalize_export_timestamp(w.get("window_start"))
            end_iso = self.normalize_export_timestamp(w.get("window_end"))
            w["locator"] = {
                **locator_base,
                "export_type": detected.value,
                "window_start": w.get("window_start"),
                "window_end": w.get("window_end"),
                "speakers": w.get("speakers"),
            }
            if start_iso:
                w["locator"]["content_occurred_at"] = start_iso
                w["locator"]["message_at"] = start_iso
            if end_iso:
                w["locator"]["content_occurred_end"] = end_iso
                w["locator"]["message_end"] = end_iso
            w["export_type"] = detected.value
        return detected, windows

    # --- parsers ---------------------------------------------------------

    def _parse_whatsapp(self, text: str) -> list[ChatMessage]:
        messages: list[ChatMessage] = []
        current: ChatMessage | None = None

        for raw_line in text.split("\n"):
            line = raw_line.rstrip()
            match = _WA_BRACKET.match(line) or _WA_DASH.match(line)
            if match:
                if current is not None:
                    messages.append(current)
                groups = match.groups()
                if len(groups) == 5 and _WA_BRACKET.match(line):
                    date, time_part, _tilde, speaker, body = groups
                    ts = f"{date} {time_part}".strip()
                else:
                    date, time_part, _tilde, speaker, body = groups
                    ts = f"{date} {time_part}".strip()
                current = ChatMessage(
                    timestamp=ts,
                    speaker=speaker.strip(),
                    body=body,
                    is_system=False,
                )
                continue

            if current is None:
                if line.strip():
                    current = ChatMessage(
                        timestamp=None,
                        speaker=None,
                        body=line,
                        is_system=False,
                    )
                continue
            current = ChatMessage(
                timestamp=current.timestamp,
                speaker=current.speaker,
                body=f"{current.body}\n{line}" if line else current.body,
                is_system=current.is_system,
            )

        if current is not None:
            messages.append(current)
        return messages

    def _parse_telegram(self, text: str) -> list[ChatMessage]:
        messages: list[ChatMessage] = []
        current: ChatMessage | None = None

        for raw_line in text.split("\n"):
            line = raw_line.rstrip()
            header = _TG_HEADER.match(line)
            if header and not line.endswith(":"):
                # Heuristic: header lines are short and lack a trailing colon body
                date, time_part, name = header.groups()
                # Avoid treating message lines that start with a date as headers
                # when they continue with ": " (WhatsApp-ish).
                if ":" in name and len(name) > 80:
                    pass
                else:
                    if current is not None:
                        messages.append(current)
                    current = ChatMessage(
                        timestamp=f"{date} {time_part}",
                        speaker=name.strip(),
                        body="",
                        is_system=False,
                    )
                    continue

            if current is None:
                if line.strip():
                    current = ChatMessage(
                        timestamp=None,
                        speaker=None,
                        body=line,
                        is_system=False,
                    )
                continue
            if not current.body:
                current = ChatMessage(
                    timestamp=current.timestamp,
                    speaker=current.speaker,
                    body=line,
                    is_system=False,
                )
            else:
                current = ChatMessage(
                    timestamp=current.timestamp,
                    speaker=current.speaker,
                    body=f"{current.body}\n{line}" if line else current.body,
                    is_system=False,
                )

        if current is not None:
            messages.append(current)
        return messages

    def _parse_slack(self, text: str) -> list[ChatMessage]:
        messages: list[ChatMessage] = []
        current: ChatMessage | None = None

        for raw_line in text.split("\n"):
            line = raw_line.rstrip()
            match = _SLACK_BRACKET.match(line) or _SLACK_NAME_TIME.match(line)
            if match:
                if current is not None:
                    messages.append(current)
                a, b, body = match.groups()
                # [Name] time body  vs  Name [time] body
                if _SLACK_BRACKET.match(line):
                    speaker, ts, body = a, b, body
                else:
                    speaker, ts, body = a, b, body
                current = ChatMessage(
                    timestamp=ts.strip(),
                    speaker=speaker.strip(),
                    body=body or "",
                    is_system=False,
                )
                continue

            if current is None:
                if line.strip():
                    current = ChatMessage(
                        timestamp=None,
                        speaker=None,
                        body=line,
                        is_system=False,
                    )
                continue
            current = ChatMessage(
                timestamp=current.timestamp,
                speaker=current.speaker,
                body=f"{current.body}\n{line}" if line else current.body,
                is_system=False,
            )

        if current is not None:
            messages.append(current)
        return messages

    def _parse_plain(self, text: str) -> list[ChatMessage]:
        # Prefer WhatsApp-like lines when mixed; else one message per non-empty block.
        wa_msgs = self._parse_whatsapp(text)
        if len(wa_msgs) >= 3:
            return wa_msgs
        blocks = [b.strip() for b in re.split(r"\n\s*\n", text) if b.strip()]
        return [
            ChatMessage(timestamp=None, speaker=None, body=block, is_system=False)
            for block in blocks
        ]

    # --- cleaning / formatting -------------------------------------------

    @staticmethod
    def _clean_speaker(speaker: str | None) -> str | None:
        if not speaker:
            return None
        s = _NOISE_CHARS.sub("", speaker).strip()
        s = s.lstrip("~").strip()
        s = re.sub(r"^[\W_]+|[\W_]+$", "", s, flags=re.UNICODE).strip()
        s = _MULTI_SPACE.sub(" ", s)
        return s or None

    @staticmethod
    def _clean_body(body: str) -> str:
        if not body:
            return ""
        text = _NOISE_CHARS.sub("", body)
        text = text.replace("\u00a0", " ")
        # Soften long emoji spam without killing legitimate emoji use.
        text = _EMOJI_RUN.sub(" ", text)
        text = _MULTI_SPACE.sub(" ", text)
        # Collapse 3+ blank lines
        text = re.sub(r"\n{3,}", "\n\n", text)
        return text.strip()

    @staticmethod
    def _is_system(body: str, speaker: str | None) -> bool:
        if _SYSTEM_HINTS.search(body):
            return True
        # WhatsApp often duplicates "Name joined..." with speaker == body start
        if speaker and body.startswith(f"~{speaker}"):
            return True
        if speaker and body.lower().startswith(speaker.lower()) and _SYSTEM_HINTS.search(body):
            return True
        return False

    _EXPORT_TS_FORMATS: tuple[str, ...] = (
        "%d/%m/%Y %H:%M:%S",
        "%d/%m/%Y %H:%M",
        "%d/%m/%Y %I:%M:%S %p",
        "%d/%m/%Y %I:%M %p",
        "%d.%m.%Y %H:%M:%S",
        "%d.%m.%Y %H:%M",
        "%m/%d/%Y %H:%M:%S",
        "%m/%d/%Y %H:%M",
    )

    @classmethod
    def normalize_export_timestamp(cls, raw: str | None) -> str | None:
        """Best-effort ISO8601 for export-native timestamps (WhatsApp/Telegram text)."""
        if raw is None:
            return None
        text = " ".join(str(raw).split())
        if not text:
            return None
        for fmt in cls._EXPORT_TS_FORMATS:
            try:
                return datetime.strptime(text, fmt).isoformat(timespec="seconds")
            except ValueError:
                continue
        return None

    @staticmethod
    def _scrub_plain(text: str) -> str:
        scrubbed = ChatExportNormalizer._clean_body(text or "")
        lines = []
        for line in scrubbed.split("\n"):
            if _SYSTEM_HINTS.search(line):
                continue
            if _MEDIA_ONLY.match(line.strip()):
                continue
            lines.append(line)
        return "\n".join(lines).strip()

    @staticmethod
    def _format_line(msg: ChatMessage) -> str:
        speaker = msg.speaker or "Unknown"
        if msg.timestamp:
            return f"[{msg.timestamp}] {speaker}: {msg.body}"
        return f"{speaker}: {msg.body}"

    def _format_window(self, messages: list[ChatMessage]) -> dict[str, Any]:
        lines = [self._format_line(m) for m in messages]
        # Single newlines so ContextualChunker keeps the window intact
        # when it splits on blank paragraphs.
        content = "\n".join(lines).strip()
        speakers = sorted({m.speaker for m in messages if m.speaker})
        start = next((m.timestamp for m in messages if m.timestamp), None)
        end = next((m.timestamp for m in reversed(messages) if m.timestamp), None)
        crumbs = ["chat"]
        if start:
            crumbs.append(f"from:{start}")
        if end and end != start:
            crumbs.append(f"to:{end}")
        if speakers:
            crumbs.append("speakers:" + ",".join(speakers[:8]))
        return {
            "content": content,
            "breadcrumbs": crumbs,
            "window_start": start,
            "window_end": end,
            "speakers": speakers,
        }
