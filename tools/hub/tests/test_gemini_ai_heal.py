import base64
import io
import re
import sys
from pathlib import Path

from PIL import Image, ImageChops, ImageDraw


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
    assert seen["stream"] is True


def test_response_body_ceiling_is_enforced_while_streaming():
    class Response:
        headers = {}

        def iter_content(self, _size):
            yield b"x" * (gemini_image_edit.MAX_RESPONSE_BYTES + 1)

    try:
        gemini_image_edit._response_json(Response())
    except RuntimeError as error:
        assert "too large" in str(error)
    else:
        raise AssertionError("oversized Gemini response was accepted")


def test_generated_image_rejects_unsafe_dimensions(monkeypatch):
    encoded = _encoded_png("blue")
    monkeypatch.setattr(gemini_image_edit, "MAX_GENERATED_PIXELS", 10)
    try:
        gemini_image_edit._generated_image(encoded, "image/png")
    except RuntimeError as error:
        assert "unsafe image dimensions" in str(error)
    else:
        raise AssertionError("oversized generated image was accepted")


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
    assert '"retouch": (self.act_heal, self.act_redeye, self.act_clone, self.act_patch,' in source
    assert 'self.setWindowTitle(f"{name}{raw}{dirty}")' in source
    assert 'QTimer.singleShot(0, self._scroll_rail_to_layers)' in source
    panel = (HUB / "slapper_qt" / "layers_panel.py").read_text(encoding="utf-8")
    assert 'self.feather.setRange(0, 200)' in panel
    assert 'self._feather_timer.setInterval(300)' in panel
    assert 'self._apply_pending_ai_heal_feather()' in panel
    assert 'self.doc.record("AI Heal feather")' in panel
    assert 'legacy_mask.point(lambda pixel: 255 if pixel >= 128 else 0)' in panel


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
    """Generative Expand stays withdrawn, including under a split label.

    A later build put the command back as ``"Generative " + "Expand…"``
    specifically so this check would not see it, and wired its button to a host
    method that does not exist -- so pressing it did nothing, every time. The
    assertions below read the source with its string concatenations folded, and
    name the dialog and the dead host hooks as well as the label.
    """
    source = (HUB / "slapper_qt" / "editor_window.py").read_text(encoding="utf-8")
    folded = re.sub(r'"\s*\+\s*"', "", source)
    assert "Generative Expand" not in folded
    assert "open_ai_expand" not in source
    assert "act_ai_expand" not in source
    assert "ai_expand_dialog" not in source
    assert "generative_expand_budget" not in source
    assert not (HUB / "slapper_qt" / "ai_expand_dialog.py").exists()
    assert not (HUB / "slapper_qt" / "expand_canvas.py").exists()


def test_first_run_notice_is_tracked_source_and_gates_all_generative_tools():
    notice = (HUB / "slapper_qt" / "generative_consent.py").read_text(encoding="utf-8")
    source = (HUB / "slapper_qt" / "editor_window.py").read_text(encoding="utf-8")
    assert "SNAP SLAPPER collects no identity, telemetry, or per-user edit log" in notice
    assert '"generative_notice_acknowledged": False' in (
        HUB / "slapper_qt" / "prefs.py").read_text(encoding="utf-8")
    # Two generative tools remain -- AI Heal and Generative Fill -- and each
    # passes the first-run notice before it opens. Generative Expand was the
    # third gate; it is withdrawn, so these counts are one, not two.
    assert source.count("from .generative_consent import confirm") == 1
    assert source.count("if not confirm(self):") == 1
    assert "if not generative_consent.confirm(self):" in source


def test_provider_send_warnings_can_be_dismissed_per_tool():
    notice = (HUB / "slapper_qt" / "generative_consent.py").read_text(encoding="utf-8")
    prefs_source = (HUB / "slapper_qt" / "prefs.py").read_text(encoding="utf-8")
    heal = (HUB / "slapper_qt" / "ai_heal_dialog.py").read_text(encoding="utf-8")
    assert 'QCheckBox("Do not display again")' in notice
    for key in ("ai_heal_send_warning_hidden", "ai_fill_send_warning_hidden"):
        assert f'"{key}": False' in prefs_source
        assert key in heal


# --- Native-resolution tiles -----------------------------------------------
# Generative Fill used to be computed on a 2048-pixel preview and then added as
# a layer with fit="stretch", so on a RAW-sized document every generated pixel
# was enlarged about threefold at export. On a 2048-pixel web JPEG that same
# code was 1:1, which is why the tool appeared to get worse without changing.


def test_tile_box_is_square_stays_in_frame_and_keeps_the_selection():
    for size, bounds in (((6000, 4000), (2980, 1980, 3020, 2020)),   # centre
                         ((6000, 4000), (0, 0, 40, 40)),             # corner
                         ((6000, 4000), (5960, 3960, 6000, 4000)),   # far corner
                         ((800, 3000), (100, 2900, 200, 2990))):     # tall frame
        box = gemini_image_edit.square_tile_box(size, bounds)
        left, top, right, bottom = box
        assert right - left == bottom - top, (size, bounds, box)
        assert 0 <= left and 0 <= top and right <= size[0] and bottom <= size[1]
        assert left <= bounds[0] and top <= bounds[1]
        assert right >= bounds[2] and bottom >= bounds[3]
        assert right - left > bounds[2] - bounds[0]   # real context, not a hug


def test_square_return_is_not_squashed_into_a_rectangular_request():
    # A circle drawn in the model's square answer must still be a circle after
    # correction. A plain resize -- what the code did before -- flattens it.
    square = Image.new("RGB", (1024, 1024), "black")
    ImageDraw.Draw(square).ellipse((412, 412, 612, 612), fill="white")
    corrected = gemini_image_edit.aspect_corrected(square, (1500, 1000))
    assert corrected.size == (1500, 1000)
    white = corrected.convert("L").point(lambda pixel: 255 if pixel > 128 else 0)
    left, top, right, bottom = white.getbbox()
    width, height = right - left, bottom - top
    assert abs(width - height) / max(width, height) < 0.05, (width, height)
    stretched = square.resize((1500, 1000), Image.Resampling.LANCZOS)
    s_left, s_top, s_right, s_bottom = stretched.convert("L").point(
        lambda pixel: 255 if pixel > 128 else 0).getbbox()
    assert (s_right - s_left) > (s_bottom - s_top) * 1.3   # the old behaviour


def test_matching_aspect_ratios_are_resized_not_cropped():
    source = Image.new("RGB", (1024, 1024), "black")
    ImageDraw.Draw(source).ellipse((0, 0, 1023, 1023), fill="white")
    corrected = gemini_image_edit.aspect_corrected(source, (512, 512))
    assert corrected.size == (512, 512)
    assert corrected.getpixel((256, 256)) == (255, 255, 255)


def test_alignment_error_sees_a_shifted_return_but_not_an_honest_one():
    tile = Image.new("RGB", (400, 400), "black")
    ImageDraw.Draw(tile).rectangle((0, 0, 399, 199), fill=(200, 60, 60))
    mask = Image.new("L", (400, 400), 0)
    ImageDraw.Draw(mask).rectangle((150, 150, 250, 250), fill=255)
    honest = tile.copy()
    ImageDraw.Draw(honest).rectangle((150, 150, 250, 250), fill="white")
    assert gemini_image_edit.alignment_error(tile, honest, mask) == 0.0
    shifted = ImageChops.offset(tile, 0, 60)
    assert gemini_image_edit.alignment_error(tile, shifted, mask) > 20


def test_heal_tile_sends_a_bounded_copy_and_answers_at_the_tiles_own_size(monkeypatch):
    sent = {}

    class Response:
        ok = True
        status_code = 200

        def __init__(self, body):
            self._body = body

        def json(self):
            return self._body

    def fake_post(url, params=None, json=None, timeout=None, stream=None):
        sent["parts"] = json["contents"][0]["parts"]
        work = Image.open(io.BytesIO(base64.b64decode(
            sent["parts"][1]["inlineData"]["data"])))
        sent["work_size"] = work.size
        buffer = io.BytesIO()
        Image.new("RGB", (1024, 1024), "green").save(buffer, "PNG")
        return Response({"candidates": [{"content": {"parts": [
            {"inlineData": {"mimeType": "image/png",
                            "data": base64.b64encode(buffer.getvalue()).decode()}}]}}]})

    monkeypatch.setattr(gemini_image_edit.requests, "post", fake_post)
    tile = Image.new("RGB", (2400, 2400), "red")
    mask = Image.new("L", tile.size, 0)
    ImageDraw.Draw(mask).rectangle((1000, 1000, 1400, 1400), fill=255)
    patch, instruction, drift = gemini_image_edit.heal_tile(
        tile, mask, "", "key", operation="fill")
    assert max(sent["work_size"]) <= 1536          # bounded on the wire
    assert patch.size == tile.size                 # native where it lands
    assert instruction and isinstance(drift, float)


def test_generated_layer_is_document_sized_and_transparent_outside_the_tile():
    document_size = (6000, 4000)
    box = (2000, 1200, 2800, 2000)
    patch = Image.new("RGB", (800, 800), "white")
    tile_mask = Image.new("L", (800, 800), 0)
    ImageDraw.Draw(tile_mask).rectangle((300, 300, 500, 500), fill=255)
    layer = gemini_image_edit.tile_layer_image(document_size, patch, tile_mask, box)
    assert layer.size == document_size             # fit="stretch" is now 1:1
    assert layer.getpixel((10, 10))[3] == 0        # nothing outside the tile
    assert layer.getpixel((2400, 1600))[3] == 255  # solid inside the selection
    # Scaled to the old preview-sized layer, the selection would have been
    # rebuilt from roughly a ninth of these pixels.
    assert layer.size[0] / 2048 > 2.9


# ===== SNAPSMACK EOF =====
