import base64
import io
import sys
from pathlib import Path

from PIL import Image


HUB = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(HUB))

import gemini_image_edit


def _encoded_png(colour):
    stream = io.BytesIO()
    Image.new("RGB", (32, 24), colour).save(stream, "PNG")
    return base64.b64encode(stream.getvalue()).decode("ascii")


def test_heal_sends_photo_marked_target_mask_and_extracts_image(monkeypatch):
    seen = {}

    class Response:
        ok = True
        status_code = 200

        def json(self):
            return {"candidates": [{"content": {"parts": [
                {"inlineData": {"mimeType": "image/png", "data": _encoded_png("blue")}}
            ]}}]}

    def fake_post(url, **kwargs):
        seen["url"] = url
        seen.update(kwargs)
        return Response()

    monkeypatch.setattr(gemini_image_edit.requests, "post", fake_post)
    photo = Image.new("RGB", (32, 24), "red")
    mask = Image.new("L", (32, 24), 0); mask.putpixel((10, 10), 255)
    result = gemini_image_edit.heal(photo, mask, "remove dust", "secret")
    assert result.size == photo.size
    assert result.getpixel((0, 0)) == (0, 0, 255)
    parts = seen["json"]["contents"][0]["parts"]
    images = [part for part in parts if "inlineData" in part]
    assert len(images) == 3
    assert "remove dust" in parts[0]["text"]
    assert "marks the repair target in RED" in parts[0]["text"]
    assert "secret" not in str(seen["json"])
    assert seen["params"] == {"key": "secret"}


def test_marked_selection_only_tints_the_masked_area():
    photo = Image.new("RGB", (20, 20), (40, 50, 60))
    mask = Image.new("L", photo.size, 0)
    mask.putpixel((10, 10), 255)
    marked = gemini_image_edit.marked_selection(photo, mask)
    assert marked.getpixel((0, 0)) == photo.getpixel((0, 0))
    assert marked.getpixel((10, 10))[0] > photo.getpixel((10, 10))[0]


def test_small_defect_is_enlarged_with_surrounding_context():
    photo = Image.new("RGB", (2400, 1600), "gray")
    mask = Image.new("L", photo.size, 0)
    for y in range(50, 90):
        for x in range(1100, 1120):
            mask.putpixel((x, y), 255)
    box, working_photo, working_mask = gemini_image_edit.focus_region(photo, mask)
    assert box[0] < 1100 and box[2] > 1120
    assert box[1] == 0  # clipped safely at the top edge
    assert max(working_photo.size) > max(box[2] - box[0], box[3] - box[1])
    assert working_photo.size == working_mask.size


def test_heal_refuses_empty_selection():
    photo = Image.new("RGB", (20, 20), "white")
    mask = Image.new("L", photo.size, 0)
    try:
        gemini_image_edit.heal(photo, mask, "", "secret")
    except ValueError as error:
        assert "Paint over the defect" in str(error)
    else:
        raise AssertionError("empty selection was sent")


def test_editor_wires_ai_heal_as_a_masked_layer():
    source = (HUB / "slapper_qt" / "editor_window.py").read_text(encoding="utf-8")
    assert 'QAction("AI Heal…"' in source
    assert 'QAction("Spot Heal", self)' in source
    assert 'self.act_ai_fill = QAction("Generative Fill…", self)' in source
    assert 'def apply_ai_generation(' in source
    assert 'layer["ai_heal_source_mask"] = editor_engine._mask_to_text(mask)' in source
    assert '"kind": "generative-fill" if is_fill else "generative-repair"' in source
    assert '"retouch": (self.act_heal, self.act_redeye, self.act_ai_heal,' in source
    assert 'f"{name}{dirty} — {BUILD_VERSION}"' in source
    assert 'QTimer.singleShot(0, self._scroll_rail_to_layers)' in source
    panel = (HUB / "slapper_qt" / "layers_panel.py").read_text(encoding="utf-8")
    assert 'self.feather.setRange(0, 200)' in panel
    assert 'self._feather_timer.setInterval(300)' in panel
    assert 'self._apply_pending_ai_heal_feather()' in panel
    assert 'self.doc.record("AI Heal feather")' in panel
    assert 'legacy_mask.point(lambda value: 255 if value >= 128 else 0)' in panel


def test_generative_fill_can_infer_content_without_a_description(monkeypatch):
    seen = {}

    class Response:
        ok = True
        status_code = 200

        def json(self):
            return {"candidates": [{"content": {"parts": [
                {"inlineData": {"mimeType": "image/png", "data": _encoded_png("blue")}}
            ]}}]}

    def fake_post(_url, **kwargs):
        seen.update(kwargs)
        return Response()

    monkeypatch.setattr(gemini_image_edit.requests, "post", fake_post)
    photo = Image.new("RGB", (20, 20), "white")
    mask = Image.new("L", photo.size, 255)
    gemini_image_edit.fill(photo, mask, "", "secret")
    instruction = seen["json"]["contents"][0]["parts"][0]["text"]
    parts = seen["json"]["contents"][0]["parts"]
    assert "natural matching content inferred" in instruction


def test_ai_heal_blend_mask_expands_and_feathers_without_leaking_across_frame():
    mask = Image.new("L", (1200, 800), 0)
    for y in range(390, 410):
        for x in range(590, 610):
            mask.putpixel((x, y), 255)
    blended = gemini_image_edit.blend_mask(mask)
    assert blended.getpixel((600, 400)) > 240
    assert 0 < blended.getpixel((560, 400)) < 255
    assert blended.getpixel((0, 0)) == 0
    assert gemini_image_edit.blend_mask(mask, 0).tobytes() == mask.tobytes()


def test_generative_expand_grows_canvas_and_restores_original_interior(monkeypatch):
    class Response:
        ok = True
        status_code = 200

        def json(self):
            return {"candidates": [{"content": {"parts": [
                {"inlineData": {"mimeType": "image/png", "data": _encoded_png("blue")}}
            ]}}]}

    monkeypatch.setattr(gemini_image_edit.requests, "post", lambda *_args, **_kwargs: Response())
    photo = Image.new("RGB", (20, 10), "red")
    result, mask, box = gemini_image_edit.expand(
        photo, {"right": 20}, "continue the sky", "secret")
    assert result.size == (24, 10)
    assert box == (0, 0, 20, 10)
    assert result.crop(box).tobytes() == photo.tobytes()
    assert mask.getpixel((0, 0)) == 0
    assert mask.getpixel((10, 5)) == 0
    assert mask.getpixel((23, 5)) == 255


def test_generative_expand_caps_total_generated_area_at_twenty_percent(monkeypatch):
    class Response:
        ok = True
        status_code = 200

        def json(self):
            return {"candidates": [{"content": {"parts": [
                {"inlineData": {"mimeType": "image/png", "data": _encoded_png("blue")}}
            ]}}]}

    monkeypatch.setattr(gemini_image_edit.requests, "post", lambda *_args, **_kwargs: Response())
    photo = Image.new("RGB", (100, 50), "red")
    result, _mask, box = gemini_image_edit.expand(
        photo, {"right": 20}, "", "secret")
    assert result.size == (120, 50)
    assert box == (0, 0, 100, 50)
    try:
        gemini_image_edit.expand(photo, {"left": 20, "right": 20}, "", "secret")
    except ValueError as error:
        assert "20%" in str(error)
    else:
        raise AssertionError("an over-cap expansion was sent")


def test_expand_geometry_starts_at_zero_and_reports_actual_area():
    pads, size, area = gemini_image_edit.expansion_geometry(
        (100, 50), {"right": 15})
    assert pads == {"left": 0, "right": 15, "top": 0, "bottom": 0}
    assert size == (115, 50)
    assert area == 750


def test_expand_prompt_is_edge_specific_conservative_and_not_duplicated(monkeypatch):
    seen = {}

    class Response:
        ok = True
        status_code = 200

        def json(self):
            return {"candidates": [{"content": {"parts": [
                {"inlineData": {"mimeType": "image/png", "data": _encoded_png("blue")}}
            ]}}]}

    def fake_post(_url, **kwargs):
        seen.update(kwargs)
        return Response()

    monkeypatch.setattr(gemini_image_edit.requests, "post", fake_post)
    gemini_image_edit.expand(
        Image.new("RGB", (100, 50), "red"), {"left": 10},
        "continue the brick wall", "secret")
    instruction = seen["json"]["contents"][0]["parts"][0]["text"]
    parts = seen["json"]["contents"][0]["parts"]
    assert "Outpaint only the WHITE masked border region" in instruction
    assert "Requested expansion edges: left." in instruction
    assert "Do not introduce a new focal subject" in instruction
    assert instruction.count("continue the brick wall") == 1
    assert "every white border area" not in instruction.lower()
    assert len(parts) == 3  # instruction, canvas, red-marked reference; raw mask stays local
    assert "not an explanation, mask, matte" in instruction


def test_editor_exposes_generative_expand_with_class_c_provenance():
    source = (HUB / "slapper_qt" / "editor_window.py").read_text(encoding="utf-8")
    assert 'QAction("Generative Expand…", self)' in source
    assert 'operation_class="C", tool_name="Generative Expand"' in source
    assert 'canvas_extension=True, scene_invention=True' in source


def test_first_run_notice_is_tracked_source_and_gates_all_generative_tools():
    notice = (HUB / "slapper_qt" / "generative_consent.py").read_text(encoding="utf-8")
    source = (HUB / "slapper_qt" / "editor_window.py").read_text(encoding="utf-8")
    assert "SNAP SLAPPER collects no identity, telemetry, or per-user edit log" in notice
    assert '"generative_notice_acknowledged": False' in (
        HUB / "slapper_qt" / "prefs.py").read_text(encoding="utf-8")
    assert source.count("from .generative_consent import confirm") == 3
    assert source.count("if not confirm(self):") == 3


def test_provider_send_warnings_can_be_dismissed_per_tool():
    notice = (HUB / "slapper_qt" / "generative_consent.py").read_text(encoding="utf-8")
    prefs_source = (HUB / "slapper_qt" / "prefs.py").read_text(encoding="utf-8")
    heal = (HUB / "slapper_qt" / "ai_heal_dialog.py").read_text(encoding="utf-8")
    expand = (HUB / "slapper_qt" / "ai_expand_dialog.py").read_text(encoding="utf-8")
    assert 'QCheckBox("Do not display again")' in notice
    for key in ("ai_heal_send_warning_hidden", "ai_fill_send_warning_hidden",
                "ai_expand_send_warning_hidden"):
        assert f'"{key}": False' in prefs_source
        assert key in heal + expand

# ===== SNAPSMACK EOF =====
