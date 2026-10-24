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


def test_raw_recovery_thumbnail_does_not_apply_raw_controls_twice(
        monkeypatch, tmp_path):
    import editor_engine
    from PIL import Image, ImageStat

    raw = tmp_path / "camera.CR3"
    raw.write_bytes(b"test raw ingredient")
    neutral_master = tmp_path / "neutral.tif"
    adjusted_master = tmp_path / "adjusted.tif"
    profile = tmp_path / "adjusted.pp3"
    Image.new("RGB", (80, 60), (40, 60, 80)).save(neutral_master)
    Image.new("RGB", (80, 60), (105, 115, 125)).save(adjusted_master)
    profile.write_text("[Version]\nVersion=352\n", encoding="utf-8")

    def artifacts(_path, adjustments, timeout=300):
        assert adjustments["exposure"] == 2.0
        return {"original": str(raw), "profile": str(profile),
                "master": str(adjusted_master), "producer": "test"}

    def develop(_path, adjustments=None, timeout=300, preview_max=None):
        # A thumbnail is a sized render, so it asks for a bounded development
        # rather than the full sensor.
        assert preview_max
        assert adjustments["exposure"] == 2.0
        return str(adjusted_master)

    monkeypatch.setattr(raw_preview, "development_artifacts", artifacts)
    monkeypatch.setattr(raw_preview, "develop", develop)
    monkeypatch.setattr(raw_preview, "cached_master", lambda *a, **k: "")
    document = editor_engine.EditorDocument(str(neutral_master))
    document.raw_source_path = str(raw)
    document.adjustments["exposure"] = 2.0
    recovery = tmp_path / "camera.slapper-recovery"
    document.save_recovery(str(recovery))

    thumbnail = editor_engine.project_thumbnail(str(recovery)).convert("RGB")
    means = ImageStat.Stat(thumbnail).mean
    assert all(90 < value < 140 for value in means)


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
    assert seen["adjustments"]["exposure"] == .26


def test_interactive_raw_preview_softens_large_exposure_deltas():
    from slapper_qt.editor_window import _raw_tone_proxy_delta

    assert _raw_tone_proxy_delta("exposure", .5) == .26
    assert round(_raw_tone_proxy_delta("exposure", 1.85), 4) == .8214


def test_interactive_raw_distortion_uses_local_geometry_proxy(monkeypatch):
    from slapper_qt.editor_window import _PreviewJob

    seen = {}

    class Document:
        def __init__(self, _source_path):
            self.adjustments = {}
            self.geometry = {}

        def restore(self, state):
            self.adjustments = dict(state["adjustments"])
            self.geometry = dict(state["geometry"])

        def render(self, max_size=None):
            seen["geometry"] = dict(self.geometry)
            return object()

    monkeypatch.setattr("slapper_qt.editor_window.editor_engine.EditorDocument", Document)
    current = raw_preview_adjustments(raw_lens_distortion=20)
    baseline = raw_preview_adjustments(raw_lens_distortion=0)
    _PreviewJob(1, "developed-master.tif",
                {"adjustments": current, "geometry": {}, "layers": [], "retouched": []},
                (600, 600), raw_baseline=baseline).run()
    assert seen["geometry"]["lens_distortion"] == 40
    assert seen["geometry"]["lens_edges"] == "transparent"


def test_running_raw_development_coalesces_new_requests():
    """A second release records newest-state work without queueing another CLI."""
    from slapper_qt.editor_window import EditorWindow

    class Document:
        raw_source_path = "camera.CR3"
        revision = 7

    class Status:
        message = ""

        def showMessage(self, message):
            self.message = message

    class Pool:
        def start(self, _job):
            raise AssertionError("obsolete RAW work must not be queued")

    class Window:
        doc = Document()
        status = Status()
        _preview_generation = 12
        _raw_quality_job_active = True
        _raw_quality_render_pending = False
        _raw_preview_pool = Pool()

    window = Window()
    EditorWindow._dispatch_raw_quality_render(window)

    assert window._preview_generation == 13
    assert window.doc.revision == 8
    assert window._raw_quality_render_pending is True
    assert window.status.message == "Updating RAW development…"


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


def test_pp3_maps_rawtherapee_geometry_controls():
    profile = raw_preview._pp3_text({
        "raw_rotation": 2.5,
        "raw_perspective_horizontal": -12,
        "raw_perspective_vertical": 18,
        "raw_lens_distortion": 25,
        "raw_defish": 50,
        "raw_ca_red": -1.25,
        "raw_ca_blue": 1.5,
        "raw_vignette_correction": 35,
        "raw_lensfun": True,
        "raw_lensfun_distortion": True,
        "raw_lensfun_vignette": False,
        "raw_lensfun_ca": True,
    })
    assert "[Rotation]\nDegree=2.5000" in profile
    assert "[Perspective]\nHorizontal=-12.0000\nVertical=18.0000" in profile
    assert "[Distortion]\nAmount=0.250000\nDefish=true" in profile
    assert "FocalLength=12.7500" in profile
    assert "[CACorrection]\nRed=-1.2500\nBlue=1.5000" in profile
    assert "[Vignetting Correction]\nAmount=35" in profile
    assert "[LensProfile]\nLcMode=lfauto\nLCPFile=" in profile
    assert "UseDistortion=true\nUseVignette=false\nUseCA=true" in profile


# ===== SNAPSMACK EOF =====


def test_slider_before_first_render_keeps_committed_raw_adjustments():
    """A baseline must be paired with the base frame it is a delta FROM.

    The fast RAW drag nudges the pixels already on the canvas by however far
    the slider moved. _on_adjust records the origin adjustments every time,
    but it can only record a base frame once something has rendered -- so
    reaching for a slider while a slow RAW is still opening leaves an origin
    with no frame. Paired wrongly, the absolute adjustments are replaced by
    deltas and then rendered against the developed master, and on screen
    every RAW adjustment the photographer had already committed vanishes.

    That window is precisely the wait the fast drag exists to remove, so it
    is reachable in ordinary use. There are two legitimate bases -- the
    developed master with the adjustments developed into it, and the canvas
    frame with the adjustments as they stood when the drag began -- and this
    asserts the chooser keeps each with its own.
    """
    import inspect
    from slapper_qt.editor_window import EditorWindow

    source = inspect.getsource(EditorWindow._schedule_interactive_job)         if hasattr(EditorWindow, "_schedule_interactive_job") else None
    if source is None:
        # The chooser lives wherever the interactive job is built.
        for name, member in inspect.getmembers(EditorWindow, inspect.isfunction):
            body = inspect.getsource(member)
            if "base_image=" in body and "_draft_base" in body:
                source = body
                break
    assert source is not None, "could not find where the preview job is built"

    assert "base_image=draft_base" in source, (
        "the job must receive the same frame the baseline was chosen for")
    assert "_raw_developed_adjustments" in source, (
        "with no canvas frame the baseline must fall back to the adjustments "
        "already developed into the master, not to an empty one -- an empty "
        "baseline silently drops every committed RAW adjustment")
    assert "if draft_base is not None:" in source, (
        "the frame decides which baseline is correct")

    # And the job itself still accepts a delta with no frame: that is the
    # developed-master path, which two older tests rely on.
    run = inspect.getsource(
        __import__("slapper_qt.editor_window", fromlist=["_PreviewJob"])._PreviewJob.run)
    assert "elif self.raw_baseline:" in run, (
        "the developed-master delta path must stay; gating it on a canvas "
        "frame breaks the ordinary interactive RAW preview")
