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


def test_heal_sends_photo_mask_and_extracts_image(monkeypatch):
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
    assert len([part for part in parts if "inlineData" in part]) == 2
    assert "remove dust" in parts[0]["text"]
    assert "secret" not in str(seen["json"])
    assert seen["params"] == {"key": "secret"}


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
    assert 'add_image_layer(path, name="AI Heal", record=False)' in source
    assert 'layer["mask"] = editor_engine._mask_to_text(mask)' in source
    assert '"kind": "generative-repair"' in source
    assert '"retouch": (self.act_heal, self.act_redeye, self.act_ai_heal,' in source


def test_ai_heal_blend_mask_expands_and_feathers_without_leaking_across_frame():
    mask = Image.new("L", (1200, 800), 0)
    for y in range(390, 410):
        for x in range(590, 610):
            mask.putpixel((x, y), 255)
    blended = gemini_image_edit.blend_mask(mask)
    assert blended.getpixel((600, 400)) > 240
    assert 0 < blended.getpixel((580, 400)) < 255
    assert blended.getpixel((0, 0)) == 0

# ===== SNAPSMACK EOF =====
