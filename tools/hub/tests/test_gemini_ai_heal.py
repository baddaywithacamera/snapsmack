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


def test_editor_does_not_expose_withdrawn_generative_expand():
    source = (HUB / "slapper_qt" / "editor_window.py").read_text(encoding="utf-8")
    assert "Generative Expand" not in source
    assert "open_ai_expand" not in source


def test_first_run_notice_is_tracked_source_and_gates_all_generative_tools():
    notice = (HUB / "slapper_qt" / "generative_consent.py").read_text(encoding="utf-8")
    source = (HUB / "slapper_qt" / "editor_window.py").read_text(encoding="utf-8")
    assert "SNAP SLAPPER collects no identity, telemetry, or per-user edit log" in notice
    assert '"generative_notice_acknowledged": False' in (
        HUB / "slapper_qt" / "prefs.py").read_text(encoding="utf-8")
    assert source.count("from .generative_consent import confirm") == 2
    assert source.count("if not confirm(self):") == 2


def test_provider_send_warnings_can_be_dismissed_per_tool():
    notice = (HUB / "slapper_qt" / "generative_consent.py").read_text(encoding="utf-8")
    prefs_source = (HUB / "slapper_qt" / "prefs.py").read_text(encoding="utf-8")
    heal = (HUB / "slapper_qt" / "ai_heal_dialog.py").read_text(encoding="utf-8")
    assert 'QCheckBox("Do not display again")' in notice
    for key in ("ai_heal_send_warning_hidden", "ai_fill_send_warning_hidden"):
        assert f'"{key}": False' in prefs_source
        assert key in heal

# ===== SNAPSMACK EOF =====
