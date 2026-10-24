"""A preview develops at preview size; only a master is full sensor.

Developing the whole sensor to fill a few hundred on-screen pixels, then
writing, hashing, verifying and decoding a 270 MB TIFF to discard almost all
of it, was most of the wait after releasing a RAW DEVELOP slider. These tests
hold the two halves of the fix in place: previews are bounded, and nothing
bounded is ever allowed to pass as a developed master.
"""

import hashlib
import json
import os
from pathlib import Path
import sys

HUB = Path(__file__).resolve().parents[1]
SHARED = HUB.parent / "_shared"
for folder in (HUB, SHARED):
    if str(folder) not in sys.path:
        sys.path.insert(0, str(folder))

import editor_engine
import raw_preview


ADJUSTMENTS = {"exposure": 1.85, "contrast": 47, "saturation": -41}


# --- the profile ---------------------------------------------------------

def test_master_profile_carries_no_resize():
    assert "[Resize]" not in raw_preview._pp3_text(ADJUSTMENTS)


def test_preview_profile_bounds_the_long_edge():
    text = raw_preview._pp3_text(ADJUSTMENTS, 1600)
    assert "[Resize]" in text
    assert "Enabled=true" in text.split("[Resize]")[1]
    assert "Width=1600" in text and "Height=1600" in text
    # A budget at or above the sensor must not enlarge the photograph.
    assert "AllowUpscaling=false" in text


def test_preview_profile_only_adds_resize():
    master = raw_preview._pp3_text(ADJUSTMENTS)
    preview = raw_preview._pp3_text(ADJUSTMENTS, 1600)
    assert preview.startswith(master), "a preview changed a development control"


# --- the cache key -------------------------------------------------------

def test_master_cache_key_is_unchanged_by_the_new_parameter(tmp_path):
    raw = tmp_path / "camera.dng"
    raw.write_bytes(b"raw")
    details = os.stat(raw)
    legacy = json.dumps({"path": os.path.abspath(raw), "size": details.st_size,
                         "mtime": details.st_mtime_ns, "adjustments": ADJUSTMENTS},
                        sort_keys=True, separators=(",", ":"))
    expected = hashlib.sha256(legacy.encode("utf-8")).hexdigest()
    current = raw_preview._development_cache_path(str(raw), ADJUSTMENTS)
    assert Path(current).stem == expected, "masters already on disk would be re-developed"


def test_a_preview_never_collides_with_a_master(tmp_path):
    raw = tmp_path / "camera.dng"
    raw.write_bytes(b"raw")
    master = raw_preview._development_cache_path(str(raw), ADJUSTMENTS)
    small = raw_preview._development_cache_path(str(raw), ADJUSTMENTS, 1600)
    large = raw_preview._development_cache_path(str(raw), ADJUSTMENTS, 3200)
    assert len({master, small, large}) == 3


# --- the budget ----------------------------------------------------------

def test_no_size_means_the_full_master():
    assert editor_engine.raw_preview_budget(None) is None


def test_a_small_viewport_still_gets_the_floor():
    assert editor_engine.raw_preview_budget((480, 320)) == editor_engine.RAW_PREVIEW_FLOOR


def test_a_large_viewport_asks_for_what_it_shows():
    assert editor_engine.raw_preview_budget((3000, 2000)) == 3000


def test_a_crop_asks_for_proportionally_more():
    # Showing a quarter of the frame at 2000 px needs an 8000 px development
    # for the viewer to see the same detail.
    budget = editor_engine.raw_preview_budget(
        (2000, 2000), {"crop": (0.25, 0.25, 0.5, 0.5)})
    assert budget == 8000


def test_an_empty_crop_is_ignored():
    assert editor_engine.raw_preview_budget((2000, 2000), {"crop": None}) == 2000


# --- the invariant that matters -----------------------------------------

class _Document(editor_engine.EditorDocument):
    def __init__(self):            # no file, no decode: only the RAW branch
        self.adjustments = dict(editor_engine.DEFAULT_ADJUSTMENTS)
        self.geometry = {}
        self.raw_source_path = "camera.dng"


def test_a_preview_development_never_enters_the_provenance_chain(monkeypatch):
    chain = []
    monkeypatch.setattr(raw_preview, "cached_master", lambda *a, **k: "")
    monkeypatch.setattr(raw_preview, "develop",
                        lambda *a, **k: "preview.tif")
    monkeypatch.setattr(editor_engine.EditorDocument, "_use_raw_development",
                        lambda self, artifacts: chain.append(artifacts))
    master, _ = _Document()._raw_development_master((1200, 800))
    assert master == "preview.tif"
    assert chain == [], "a bounded preview was recorded as the edit source"


def test_a_full_render_takes_the_master_and_records_it(monkeypatch):
    chain = []
    artifacts = {"original": "camera.dng", "profile": "p.pp3",
                 "master": "master.tif", "producer": "rt"}
    monkeypatch.setattr(raw_preview, "development_artifacts",
                        lambda *a, **k: artifacts)
    monkeypatch.setattr(editor_engine.EditorDocument, "_use_raw_development",
                        lambda self, value: chain.append(value))
    master, _ = _Document()._raw_development_master(None)
    assert master == "master.tif"
    assert chain == [artifacts]


def test_an_existing_master_is_read_rather_than_redeveloped(monkeypatch):
    developed = []
    monkeypatch.setattr(raw_preview, "cached_master", lambda *a, **k: "master.tif")
    monkeypatch.setattr(raw_preview, "develop",
                        lambda *a, **k: developed.append(k) or "preview.tif")
    master, _ = _Document()._raw_development_master((1200, 800))
    assert master == "master.tif"
    assert developed == [], "a smaller copy was developed of a photograph already on disk"


def test_raw_controls_are_left_to_rawtherapee_either_way(monkeypatch):
    monkeypatch.setattr(raw_preview, "cached_master", lambda *a, **k: "")
    monkeypatch.setattr(raw_preview, "develop", lambda *a, **k: "preview.tif")
    document = _Document()
    document.adjustments["exposure"] = 1.85
    document.adjustments["clarity"] = 36
    _, base = document._raw_development_master((1200, 800))
    assert base["exposure"] == editor_engine.DEFAULT_ADJUSTMENTS["exposure"]
    assert base["clarity"] == 36, "a SNAP SLAPPER control was dropped"

# ===== SNAPSMACK EOF =====
