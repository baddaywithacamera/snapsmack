from pathlib import Path


def test_transcript_scroll_waits_for_qt_layout_and_respects_reader_position():
    source = (Path(__file__).parents[1] / "troll_booth" / "desktop.py").read_text(encoding="utf-8")
    assert "QTimer.singleShot(0, self._scroll_transcript_to_bottom)" in source
    assert "room_changed or was_at_bottom" in source
    assert "scrollbar.setValue(scrollbar.maximum())" in source
    assert "message_count == self._rendered_message_count" in source
    assert "last_message_id == self._rendered_last_message_id" in source


def test_refresh_messages_updates_when_room_exceeds_500_cap(monkeypatch):
    import os
    os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")
    from PySide6.QtWidgets import QApplication
    from troll_booth import desktop

    _app = QApplication.instance() or QApplication([])

    class FakeStore:
        def __init__(self):
            self.seq = 500

        def rooms(self, **_kw):
            return [{"id": "archive-recovery", "title": "Archive recovery", "message_count": self.seq, "created_at": 1000}]

        def messages(self, room_id, limit=500, before=None):
            start = max(1, self.seq - limit + 1)
            return [
                {"id": f"msg-{i}", "room_id": room_id, "sender": "claude", "body": f"Body #{i}", "created_at": 1000 + i}
                for i in range(start, self.seq + 1)
            ]

    monkeypatch.setattr(desktop, "open_conversation_store", lambda: (FakeStore(), "MARIADB"))
    monkeypatch.setattr(desktop.BoothWindow, "_build_tray", lambda self: setattr(self, "tray", None))
    win = desktop.BoothWindow()
    assert "Body #500" in win.transcript.toPlainText()
    assert win._rendered_message_count == 500

    # Now message #501 arrives; len(messages) is STILL 500 because of the 500-message cap!
    win.store.seq = 501
    win.refresh_messages()
    assert "Body #501" in win.transcript.toPlainText()
    assert win._rendered_last_message_id == "msg-501"
    win.timer.stop()
    win.pair_timer.stop()
    win.close()
