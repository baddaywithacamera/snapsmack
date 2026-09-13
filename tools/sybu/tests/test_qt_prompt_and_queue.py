# SNAPSMACK_EOF_HEADER: this file must end with the canonical Python EOF marker.

import os
import sys
from types import SimpleNamespace


TOOL = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
if TOOL not in sys.path:
    sys.path.insert(0, TOOL)

import sybu_core


def test_prompt_save_updates_selected_shared_profile(monkeypatch):
    saved = {}
    profile = {
        "name": "My site", "url": "https://photos.example",
        "portable": {"prompt": "old", "jpeg_quality": 90},
    }
    monkeypatch.setattr(sybu_core.profile_manager, "load_profile", lambda _name: profile)
    monkeypatch.setattr(sybu_core.profile_manager, "save_profile",
                        lambda value: saved.update(value))
    pool = {}
    monkeypatch.setitem(sys.modules, "snap_home",
                        SimpleNamespace(site_key=lambda _url: "photos.example"))
    monkeypatch.setitem(sys.modules, "snap_prompts",
                        SimpleNamespace(load=lambda: pool, save=lambda value: pool.update(value)))

    result = sybu_core.Engine.__new__(sybu_core.Engine).profile_save_prompt(
        "My site", "  Describe plainly.  ")

    assert result["prompt"] == "Describe plainly."
    assert saved["portable"]["prompt"] == "Describe plainly."
    assert saved["portable"]["jpeg_quality"] == 90
    assert pool["photos.example"] == "Describe plainly."


def test_qt_queue_exposes_review_fields():
    source = open(os.path.join(TOOL, "sybu_qt.py"), encoding="utf-8").read()
    for heading in ("PREVIEW", "CAPTION", "ALT TEXT", "COLOUR / B&W", "ORIENTATION"):
        assert heading in source
    assert "SAVE TO SNAP HQ" in source
    assert "USE FOR THIS RUN" in source

# ===== SNAPSMACK EOF =====
