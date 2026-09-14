import io
import sys
from pathlib import Path

from PIL import Image


HUB = Path(__file__).resolve().parents[1]
if str(HUB) not in sys.path:
    sys.path.insert(0, str(HUB))

import stability_image_edit


def _png(size, colour):
    stream = io.BytesIO()
    Image.new("RGB", size, colour).save(stream, "PNG")
    return stream.getvalue()


def test_outpaint_maps_selected_sides_and_restores_original_pixels(monkeypatch):
    seen = {}

    class Response:
        ok = True
        status_code = 200
        content = _png((120, 50), "blue")

    def fake_post(url, **kwargs):
        seen["url"] = url
        seen.update(kwargs)
        return Response()

    monkeypatch.setattr(stability_image_edit.requests, "post", fake_post)
    source = Image.new("RGB", (100, 50), "red")
    result, mask, box, instruction = stability_image_edit.expand(
        source, {"left": 10, "right": 10}, "continue the prairie", "secret")

    assert seen["url"].endswith("/stable-image/edit/outpaint")
    assert seen["data"]["left"] == "10"
    assert seen["data"]["right"] == "10"
    assert seen["data"]["up"] == "0"
    assert seen["data"]["down"] == "0"
    assert seen["data"]["creativity"] == "0.2"
    assert box == (10, 0, 110, 50)
    assert result.crop(box).getpixel((0, 0)) == (255, 0, 0)
    assert mask.getpixel((10, 0)) == 0
    assert mask.getpixel((0, 0)) == 255
    assert "wider camera frame" in instruction
    assert "continue the prairie" in instruction


def test_outpaint_requires_stability_key():
    try:
        stability_image_edit.expand(Image.new("RGB", (100, 50)), {"left": 5}, "", "")
    except ValueError as error:
        assert "Stability AI API key" in str(error)
    else:
        raise AssertionError("missing key was accepted")

