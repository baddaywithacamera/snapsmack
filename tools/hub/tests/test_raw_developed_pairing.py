"""`_raw_developed_adjustments` describes the master at `source_path`.

They are only meaningful as a pair. `_raw_developed_adjustments` says which
RAW controls are already baked into the file at `source_path`, and
`_use_raw_development` is what moves `source_path` to a newly developed
master. A preview-sized development moves neither -- it is deliberately kept
out of the provenance chain -- so it must not claim the controls were
developed either.

Claiming it anyway is what made the photograph revert to the original while a
slider moved: the editor takes the slider delta as `current - developed`, that
came out zero, and a zero delta applied to the still-neutral master is the
undeveloped photograph.
"""

import copy
import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
HUB = os.path.dirname(HERE)
SHARED = os.path.join(os.path.dirname(HUB), "_shared")
for folder in (HUB, SHARED):
    if folder not in sys.path:
        sys.path.insert(0, folder)

import raw_preview


def raw_document(monkeypatch, tmp_path):
    import editor_engine
    from PIL import Image

    neutral = tmp_path / "neutral.tif"
    developed = tmp_path / "developed.tif"
    profile = tmp_path / "profile.pp3"
    preview = tmp_path / "preview.tif"
    Image.new("RGB", (400, 300), (80, 80, 80)).save(neutral)
    Image.new("RGB", (400, 300), (200, 200, 200)).save(developed)
    Image.new("RGB", (400, 300), (200, 200, 200)).save(preview)
    profile.write_text("[Version]\nVersion=352\n", encoding="utf-8")
    raw = tmp_path / "camera.CR3"
    raw.write_bytes(b"raw ingredient")

    monkeypatch.setattr(raw_preview, "development_artifacts",
                        lambda _path, _adjustments, timeout=300: {
                            "original": str(raw), "profile": str(profile),
                            "master": str(developed), "producer": "test"})
    monkeypatch.setattr(raw_preview, "develop",
                        lambda _path, _adjustments=None, timeout=300,
                        preview_max=None: str(preview))
    monkeypatch.setattr(raw_preview, "cached_master", lambda *a, **k: "")

    document = editor_engine.EditorDocument(str(neutral))
    document.attach_raw_source(str(raw), str(neutral), str(profile), "test")
    document.adjustments["exposure"] = 1.5
    return document, str(neutral), str(developed)


def test_a_preview_development_does_not_claim_the_controls_were_developed(
        monkeypatch, tmp_path):
    document, neutral, _developed = raw_document(monkeypatch, tmp_path)
    before = copy.deepcopy(document._raw_developed_adjustments)

    master, _base = document._raw_development_master((900, 700))

    assert master != neutral, "the preview must still be developed"
    assert document.source_path == neutral, (
        "a preview development must not become the edit source")
    assert document._raw_developed_adjustments == before, (
        "the baseline must keep describing the master at source_path, or the "
        "slider delta comes out zero and the photograph reverts to the "
        "original while the slider moves")
    assert document._raw_developed_adjustments["exposure"] == 0.0


def test_a_master_development_moves_both_together(monkeypatch, tmp_path):
    document, _neutral, developed = raw_document(monkeypatch, tmp_path)

    master, _base = document._raw_development_master(None)

    assert master == developed
    assert document.source_path == developed
    assert document._raw_developed_adjustments["exposure"] == 1.5, (
        "the master now has exposure developed into it, so the baseline must "
        "say so")


def test_the_slider_delta_still_shows_the_committed_adjustment(
        monkeypatch, tmp_path):
    """The editor's own arithmetic, with no canvas frame to start from."""
    import editor_engine

    document, _neutral, _developed = raw_document(monkeypatch, tmp_path)
    document._raw_development_master((900, 700))
    baseline = document._raw_developed_adjustments

    delta = (float(document.adjustments["exposure"]) -
             float(baseline.get("exposure",
                                editor_engine.DEFAULT_ADJUSTMENTS["exposure"])))
    assert delta > 0.0, (
        "a zero delta against the neutral master is the reverted photograph")


# ===== SNAPSMACK EOF =====
