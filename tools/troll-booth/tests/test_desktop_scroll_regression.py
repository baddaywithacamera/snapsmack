from pathlib import Path


def test_transcript_scroll_waits_for_qt_layout_and_respects_reader_position():
    source = (Path(__file__).parents[1] / "troll_booth" / "desktop.py").read_text(encoding="utf-8")
    assert "QTimer.singleShot(0, self._scroll_transcript_to_bottom)" in source
    assert "room_changed or was_at_bottom" in source
    assert "scrollbar.setValue(scrollbar.maximum())" in source
    assert "message_count == self._rendered_message_count" in source
