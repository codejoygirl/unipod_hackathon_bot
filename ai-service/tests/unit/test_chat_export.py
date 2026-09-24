"""Unit tests for chat-export detection, cleaning, and windowing."""

from ai_service.ingestion.chat_export import ChatExportNormalizer, ExportType


WA_SAMPLE = """\
[04/09/2026, 09:27:15] UniPods METI AI Program 2026 Cohort: \u200eMessages and calls are end-to-end encrypted. Only people in this chat can read, listen to, or share them.
[04/09/2026, 09:27:15] ~\u200eDiane: \u200e~\u200eDiane created this group
[04/09/2026, 12:12:14] UniPods METI AI Program 2026 Cohort: \u200eYou joined using a group link
[04/09/2026, 12:22:17] ~\u200eDiane: Hello everyone!

Congratulations again on making it to this stage.
Meeting link: https://meet.google.com/abc-defg-hij
[04/09/2026, 12:23:45] ~\u200eEmmanuel_aanuajayi: Thank you so much Diane,

As part of what we want to write online, are we using the whole 14weeks in Ethiopia?
[04/09/2026, 12:52:10] ~\u200eDebby: Hello everyone
my name is Deborah
[04/09/2026, 13:00:00] ~\u200eSam: <Media omitted>
"""


TG_SAMPLE = """\
04.09.2026 12:22:17 Diane
Hello everyone! Welcome to the programme.
Meeting: https://meet.google.com/abc-defg-hij

04.09.2026 12:23:45 Emmanuel
Thank you Diane. Are the 14 weeks in Ethiopia?

04.09.2026 12:52:10 Debby
Hello, Deborah here from Rwanda.
"""


def test_detect_whatsapp_export():
    n = ChatExportNormalizer()
    assert n.detect(WA_SAMPLE) == ExportType.WHATSAPP


def test_detect_telegram_export():
    n = ChatExportNormalizer()
    assert n.detect(TG_SAMPLE) == ExportType.TELEGRAM


def test_whatsapp_clean_drops_system_and_media():
    n = ChatExportNormalizer()
    export_type, windows = n.process(WA_SAMPLE, source_type_hint="whatsapp")
    assert export_type == ExportType.WHATSAPP
    assert windows
    joined = "\n".join(w["content"] for w in windows)
    assert "end-to-end encrypted" not in joined
    assert "created this group" not in joined
    assert "joined using a group link" not in joined
    assert "Media omitted" not in joined
    assert "https://meet.google.com/abc-defg-hij" in joined
    assert "Diane:" in joined
    assert "Emmanuel_aanuajayi:" in joined
    assert "\u200e" not in joined
    assert "~Diane" not in joined


def test_looks_like_chat_for_unknown_source_type():
    assert ChatExportNormalizer.looks_like_chat(WA_SAMPLE)
    assert not ChatExportNormalizer.looks_like_chat("# Just a markdown doc\n\nHello.")


def test_windows_keep_speaker_and_time():
    n = ChatExportNormalizer(max_messages_per_window=2, max_chars_per_window=500)
    _, windows = n.process(WA_SAMPLE, source_type_hint="whatsapp")
    assert len(windows) >= 2
    first = windows[0]
    assert first["media_type"] == "chat_window"
    assert "speakers" in first
    assert any("Diane" in (s or "") for s in first["speakers"])
    assert first["locator"].get("message_at", "").startswith("2026-09-04")


def test_windows_split_on_inactivity_gap():
    """Industry default: flush when idle gap exceeds ~30 minutes."""
    mixed = """\
[04/09/2026, 12:22:17] ~Diane: Welcome — session starting
[04/09/2026, 12:23:45] ~Emmanuel: Thanks Diane
[04/09/2026, 14:00:00] ~Diane: Back after a long break with the recording
https://youtu.be/abc123
[04/09/2026, 14:05:00] ~Sam: Got it
"""
    n = ChatExportNormalizer(
        max_messages_per_window=40,
        max_chars_per_window=8000,
        max_gap_minutes=30,
    )
    _, windows = n.process(mixed, source_type_hint="whatsapp")
    assert len(windows) >= 2
    assert "session starting" in windows[0]["content"]
    assert "long break" in windows[1]["content"]
    assert "https://youtu.be/abc123" in windows[1]["content"]


def test_windows_stay_together_within_gap():
    close = """\
[04/09/2026, 12:22:17] ~Diane: Point one
[04/09/2026, 12:25:00] ~Emmanuel: Point two
[04/09/2026, 12:40:00] ~Sam: Point three still same session
"""
    n = ChatExportNormalizer(max_gap_minutes=30)
    _, windows = n.process(close, source_type_hint="whatsapp")
    assert len(windows) == 1
    assert "Point one" in windows[0]["content"]
    assert "Point three" in windows[0]["content"]
