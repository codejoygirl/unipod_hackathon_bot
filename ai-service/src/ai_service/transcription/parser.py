"""Robust parser for meeting transcripts, WebVTT, SRT, and conversational logs."""

from collections.abc import Sequence
import re
from ai_service.providers.base import TranscriptSegment, TranscriptionResult


class TranscriptParser:
    """Parses raw meeting transcripts into structured, timestamped segments."""

    # Matches VTT/SRT timestamp arrows: 00:01:23.456 --> 00:01:28.910 or 00:01:23,456 --> 00:01:28,910
    ARROW_TIMESTAMP_PATTERN = re.compile(
        r"(?:(\d{1,2}):)?(\d{2}):(\d{2})[.,](\d{3})\s*-->\s*(?:(\d{1,2}):)?(\d{2}):(\d{2})[.,](\d{3})"
    )

    # Matches inline bracketed timestamps: [01:23], [01:23:45], (12:34), 12:34:56
    INLINE_TIMESTAMP_PATTERN = re.compile(
        r"(?:\[|\()?(?:(\d{1,2}):)?(\d{2}):(\d{2})(?:[.,](\d{1,3}))?(?:\]|\))?"
    )

    # Matches WebVTT <v VoiceName>Dialogue</v> tags (no colon required)
    VTT_VOICE_PATTERN = re.compile(
        r"^<v(?:\.[\w-]+)?\s+([^>]+)>(.*?)(?:</v>)?$", re.DOTALL
    )

    # Matches standard speaker labels: "Alice:", "Speaker 1:", "[Mayor Jane]:", "[Mayor Jane] -"
    SPEAKER_PATTERN = re.compile(
        r"^(?:\[([^\]]+)\](?::|\s*-)?|([A-Za-z0-9\u1200-\u137F\s._-]+?)(?::|\s+-))\s*(.*)$",
        re.DOTALL,
    )

    @classmethod
    def _timestamp_to_seconds(
        cls,
        hours: str | None,
        minutes: str,
        seconds: str,
        fraction: str | None = None,
    ) -> float:
        """Convert timestamp units into total float seconds."""
        h = float(hours) if hours else 0.0
        m = float(minutes)
        s = float(seconds)
        frac = float(f"0.{fraction}") if fraction else 0.0
        return (h * 3600.0) + (m * 60.0) + s + frac

    @classmethod
    def _extract_speaker_and_text(cls, raw_content: str) -> tuple[str | None, str]:
        """Extract speaker name and clean dialogue text from raw transcript line."""
        cleaned = raw_content.strip()
        if not cleaned:
            return None, ""

        # 1. Check WebVTT <v VoiceName> tag
        vtt_match = cls.VTT_VOICE_PATTERN.match(cleaned)
        if vtt_match:
            speaker = vtt_match.group(1).strip()
            text = vtt_match.group(2).strip()
            return speaker, text

        # 2. Check standard colon or bracketed speaker labels
        spk_match = cls.SPEAKER_PATTERN.match(cleaned)
        if spk_match:
            spk_candidates = [g for g in spk_match.groups()[:2] if g is not None]
            speaker = spk_candidates[0].strip() if spk_candidates else None
            text = spk_match.group(3).strip()
            return speaker, text

        return None, cleaned

    @classmethod
    def parse_vtt_or_srt(cls, text: str) -> list[TranscriptSegment]:
        """Parse WebVTT or SubRip (SRT) format text into TranscriptSegments."""
        lines = [line.strip() for line in text.splitlines()]
        segments: list[TranscriptSegment] = []

        idx = 0
        total_lines = len(lines)

        while idx < total_lines:
            line = lines[idx]

            # Skip empty lines, 'WEBVTT' headers, and numeric cue indices
            if not line or line.upper().startswith("WEBVTT") or line.isdigit():
                idx += 1
                continue

            # Check for timestamp range line
            match = cls.ARROW_TIMESTAMP_PATTERN.search(line)
            if match:
                start_h, start_m, start_s, start_ms, end_h, end_m, end_s, end_ms = (
                    match.groups()
                )
                start_sec = cls._timestamp_to_seconds(
                    start_h, start_m, start_s, start_ms
                )
                end_sec = cls._timestamp_to_seconds(end_h, end_m, end_s, end_ms)

                # Collect subsequent content lines until empty line or next cue
                idx += 1
                text_lines: list[str] = []
                while idx < total_lines and lines[idx]:
                    if cls.ARROW_TIMESTAMP_PATTERN.search(lines[idx]):
                        break
                    text_lines.append(lines[idx])
                    idx += 1

                raw_content = " ".join(text_lines).strip()
                if raw_content:
                    speaker, cleaned_content = cls._extract_speaker_and_text(raw_content)
                    if cleaned_content:
                        segments.append(
                            TranscriptSegment(
                                start_seconds=round(start_sec, 3),
                                end_seconds=round(end_sec, 3),
                                text=cleaned_content,
                                speaker=speaker,
                            )
                        )
                continue

            idx += 1

        return segments

    @classmethod
    def parse_plain_log(cls, text: str) -> list[TranscriptSegment]:
        """Parse plain-text meeting notes formatted with inline timestamps and speaker labels.

        Example:
            [00:12] Alice: Welcome everyone to the water safety briefing.
            [00:45] Bob: Thanks Alice. Testing reports are ready.
        """
        lines = [line.strip() for line in text.splitlines() if line.strip()]
        segments: list[TranscriptSegment] = []

        for line in lines:
            timestamp_match = cls.INLINE_TIMESTAMP_PATTERN.match(line)
            start_sec = 0.0
            content = line

            if timestamp_match:
                h, m, s, frac = timestamp_match.groups()
                start_sec = cls._timestamp_to_seconds(h, m, s, frac)
                content = line[timestamp_match.end() :].strip()

            if content.startswith("-") or content.startswith(":"):
                content = content.lstrip("-: ").strip()

            speaker, cleaned_content = cls._extract_speaker_and_text(content)

            if cleaned_content:
                # Default duration estimate: 1 second per 15 characters, min 2.0s
                duration = max(2.0, len(cleaned_content) / 15.0)
                segments.append(
                    TranscriptSegment(
                        start_seconds=round(start_sec, 3),
                        end_seconds=round(start_sec + duration, 3),
                        text=cleaned_content,
                        speaker=speaker,
                    )
                )

        return segments

    @classmethod
    def coalesce_segments(
        cls,
        segments: Sequence[TranscriptSegment],
        max_gap_seconds: float = 3.0,
        max_char_length: int = 1200,
    ) -> list[TranscriptSegment]:
        """Merge consecutive utterances by the same speaker within a temporal proximity window."""
        if not segments:
            return []

        merged: list[TranscriptSegment] = []
        current = segments[0]

        for nxt in segments[1:]:
            same_speaker = current.speaker == nxt.speaker
            gap = nxt.start_seconds - current.end_seconds
            length_ok = (len(current.text) + len(nxt.text)) <= max_char_length

            if same_speaker and 0 <= gap <= max_gap_seconds and length_ok:
                current = TranscriptSegment(
                    start_seconds=current.start_seconds,
                    end_seconds=max(current.end_seconds, nxt.end_seconds),
                    text=f"{current.text} {nxt.text}".strip(),
                    speaker=current.speaker,
                )
            else:
                merged.append(current)
                current = nxt

        merged.append(current)
        return merged

    @classmethod
    def parse(
        cls,
        raw_text: str,
        language: str = "en",
        coalesce: bool = True,
    ) -> TranscriptionResult:
        """Autodetect transcript structure, parse segments, and return TranscriptionResult."""
        cleaned = raw_text.strip()
        if not cleaned:
            return TranscriptionResult(text="", language=language, duration_seconds=0.0)

        if "-->" in cleaned:
            segments = cls.parse_vtt_or_srt(cleaned)
        else:
            segments = cls.parse_plain_log(cleaned)

        if coalesce:
            segments = cls.coalesce_segments(segments)

        total_duration = segments[-1].end_seconds if segments else 0.0
        full_text = "\n".join(
            f"[{s.speaker or 'Unknown'}]: {s.text}" if s.speaker else s.text
            for s in segments
        )

        return TranscriptionResult(
            text=full_text,
            language=language,
            duration_seconds=round(total_duration, 2),
            segments=tuple(segments),
        )