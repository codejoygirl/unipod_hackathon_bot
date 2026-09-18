import pytest
from ai_service.transcription.parser import TranscriptParser


def test_parse_vtt_with_speakers():
    vtt_content = """WEBVTT

00:00:01.000 --> 00:00:04.500
<v Chairperson>Good morning everyone. Let us begin the town hall meeting.

00:00:05.000 --> 00:00:08.200
Chairperson: Today we address the new water filtration initiative.

00:00:10.000 --> 00:00:14.000
Dr. Smith: The clinical lab samples from Zone 3 are completely clear.
"""
    result = TranscriptParser.parse(vtt_content, coalesce=False)

    assert len(result.segments) == 3
    assert result.segments[0].speaker == "Chairperson"
    assert result.segments[0].start_seconds == 1.0
    assert result.segments[0].end_seconds == 4.5
    assert "town hall meeting" in result.segments[0].text

    assert result.segments[1].speaker == "Chairperson"
    assert result.segments[2].speaker == "Dr. Smith"
    assert result.duration_seconds == 14.0


def test_parse_plain_meeting_notes():
    log_content = """
    [01:15] Mayor Rivera: Emergency shelter capacity has doubled.
    [01:45] Dispatcher: Buses are departing on the hour.
    """
    result = TranscriptParser.parse(log_content, coalesce=False)

    assert len(result.segments) == 2
    assert result.segments[0].speaker == "Mayor Rivera"
    assert result.segments[0].start_seconds == 75.0  # 1m 15s = 75s
    assert "shelter capacity" in result.segments[0].text

    assert result.segments[1].speaker == "Dispatcher"
    assert result.segments[1].start_seconds == 105.0  # 1m 45s = 105s


def test_coalesce_segments_merges_rapid_same_speaker():
    from ai_service.providers.base import TranscriptSegment

    raw_segments = [
        TranscriptSegment(start_seconds=0.0, end_seconds=2.0, text="Hello.", speaker="Alice"),
        TranscriptSegment(start_seconds=3.0, end_seconds=5.0, text="Can everyone hear me?", speaker="Alice"),
        TranscriptSegment(start_seconds=6.0, end_seconds=8.0, text="Yes, loud and clear.", speaker="Bob"),
    ]

    coalesced = TranscriptParser.coalesce_segments(raw_segments, max_gap_seconds=3.0)

    # The first two utterances by Alice (gap = 1.0s) should merge into one
    assert len(coalesced) == 2
    assert coalesced[0].speaker == "Alice"
    assert coalesced[0].text == "Hello. Can everyone hear me?"
    assert coalesced[0].start_seconds == 0.0
    assert coalesced[0].end_seconds == 5.0

    assert coalesced[1].speaker == "Bob"
    assert coalesced[1].text == "Yes, loud and clear."