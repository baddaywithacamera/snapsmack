"""RAW editing delegates development controls to RawTherapee."""

import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
HUB = os.path.dirname(HERE)
SHARED = os.path.join(os.path.dirname(HUB), "_shared")
for folder in (HUB, SHARED):
    if folder not in sys.path:
        sys.path.insert(0, folder)

import raw_preview


def test_interactive_raw_preview_uses_float_delta_without_redeveloping(monkeypatch):
    """A slider tick must not invoke RawTherapee; release does that once async."""
    from slapper_qt.editor_window import _PreviewJob

    seen = {}

    class Document:
        def __init__(self, source_path):
            seen["source_path"] = source_path
            self.adjustments = {}

        def restore(self, state):
            self.adjustments = dict(state["adjustments"])

        def render(self, max_size=None):
            seen["raw_source"] = getattr(self, "raw_source_path", "")
            seen["adjustments"] = dict(self.adjustments)
            return object()

    monkeypatch.setattr("slapper_qt.editor_window.editor_engine.EditorDocument", Document)
    state = {"adjustments": dict(raw_preview_adjustments(exposure=1.25)),
             "geometry": {}, "layers": [], "retouched": []}
    job = _PreviewJob(1, "developed-master.tif", state, (900, 900),
                      raw_baseline=raw_preview_adjustments(exposure=.75))
    job.run()

    assert seen["source_path"] == "developed-master.tif"
    assert seen["raw_source"] == ""
    assert seen["adjustments"]["exposure"] == .5


def raw_preview_adjustments(**changes):
    import editor_engine
    values = {key: editor_engine.DEFAULT_ADJUSTMENTS[key]
              for key in editor_engine.RAW_DEVELOPMENT_KEYS}
    values.update(changes)
    return values


def test_pp3_maps_snap_slapper_base_controls():
    profile = raw_preview._pp3_text({
        "exposure": 1.25, "brightness": 12, "contrast": -8,
        "highlights": -35, "shadows": 22, "temperature": 10,
        "tint": -5, "saturation": 14, "raw_noise_reduction": 63,
    })
    assert "Compensation=1.2500" in profile
    assert "Brightness=12" in profile
    assert "Contrast=-8" in profile
    assert "HighlightCompr=35" in profile
    assert "ShadowCompr=22" in profile
    assert "Saturation=14" in profile
    assert "Brightness=12.00" not in profile
    assert "Setting=Custom" in profile
    assert "[Directional Pyramid Denoising]" in profile
    assert "Enabled=true" in profile
    assert "Luma=63" in profile


# ===== SNAPSMACK EOF =====
