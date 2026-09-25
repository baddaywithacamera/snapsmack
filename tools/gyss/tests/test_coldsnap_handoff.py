import os
import sys
import tempfile
import time

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "..", ".."))
SHARED = os.path.join(ROOT, "tools", "_shared")
sys.path.insert(0, SHARED)

import snap_desktop_handoff as handoff


def test_request_is_atomic_and_consumed_once(monkeypatch):
    with tempfile.TemporaryDirectory() as temp:
        monkeypatch.setenv("SNAPSMACK_HOME", temp)
        path = handoff.write_request("coldsnap", {
            "site_url": "https://example.test",
            "site_mode": "smacktalk",
        })
        assert os.path.isfile(path)
        request = handoff.consume_request("coldsnap")
        assert request["site_url"] == "https://example.test"
        assert request["site_mode"] == "smacktalk"
        assert handoff.consume_request("coldsnap") is None


def test_stale_request_is_discarded(monkeypatch):
    with tempfile.TemporaryDirectory() as temp:
        monkeypatch.setenv("SNAPSMACK_HOME", temp)
        path = handoff.write_request("coldsnap", {"site_url": "https://old.test"})
        import json
        with open(path, "r", encoding="utf-8") as handle:
            request = json.load(handle)
        request["requested_at"] = int(time.time()) - 1000
        with open(path, "w", encoding="utf-8") as handle:
            json.dump(request, handle)
        assert handoff.consume_request("coldsnap") is None


def test_both_desktop_apps_are_wired_to_the_shared_request():
    gyss = open(os.path.join(ROOT, "tools", "gyss", "gyss_qt.py"), encoding="utf-8").read()
    main = open(os.path.join(ROOT, "tools", "coldsnap", "coldsnap_qt", "main_window.py"), encoding="utf-8").read()
    connect = open(os.path.join(ROOT, "tools", "coldsnap", "coldsnap_qt", "connect_panel.py"), encoding="utf-8").read()
    assert 'OPEN POST EDITOR IN COLD SNAP' in gyss
    assert 'write_request("coldsnap"' in gyss
    assert 'SEND SELECTED TO COLD TAKE' in gyss
    assert '"selected_images":photos' in gyss
    assert 'consume_request("coldsnap")' in main
    assert 'accept_handoff(selected)' in main
    assert '"photoblog": 0, "carousel": 1, "smacktalk": 2' in main
    assert "def select_site(self, url: str)" in connect

# ===== SNAPSMACK EOF =====
