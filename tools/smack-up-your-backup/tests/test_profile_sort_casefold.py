"""Regression test for SS-1402: case-insensitive alphabetical profile sorting."""

import json
import os
import sys

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

import profile_manager


def test_list_profiles_case_insensitive_alphabetical(tmp_path, monkeypatch):
    monkeypatch.setattr(profile_manager, "PROFILES_DIR", str(tmp_path))
    monkeypatch.setattr(profile_manager, "sync_shared_profiles", lambda: None)

    for idx, name in enumerate(["HAL 9000", "a colourless life", "CRAPTASTI", "zed"]):
        with open(tmp_path / f"p{idx}.json", "w", encoding="utf-8") as f:
            json.dump({"name": name, "url": f"https://site{idx}.example"}, f)

    got = profile_manager.list_profiles()
    assert got == ["a colourless life", "CRAPTASTI", "HAL 9000", "zed"]


# ===== SNAPSMACK EOF =====
