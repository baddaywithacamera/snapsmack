"""Stability AI outpainting for SNAP SLAPPER Generative Expand."""

import io

import requests
from PIL import Image, ImageDraw

from gemini_image_edit import expansion_geometry


ENDPOINT = "https://api.stability.ai/v2beta/stable-image/edit/outpaint"


def expand(image, edges, prompt, api_key, timeout=300, max_generated_area=None):
    """Outpaint selected sides, then restore every captured source pixel locally."""
    if not api_key:
        raise ValueError("Add a Stability AI API key in SNAP HQ Settings first.")
    image = image.convert("RGB")
    pads, size, generated_area = expansion_geometry(image.size, edges)
    if generated_area <= 0:
        raise ValueError("Drag an edge or corner to choose an area to expand.")
    limit = (image.width * image.height * .20 if max_generated_area is None
             else max(0, int(max_generated_area)))
    if generated_area > limit:
        raise ValueError("Generative Expand is limited to 20% of the original frame area.")
    if any(value > 2000 for value in pads.values()):
        raise ValueError("Stability AI limits each expanded side to 2,000 pixels.")

    stream = io.BytesIO()
    image.save(stream, "PNG", optimize=True)
    instruction = str(prompt or "").strip()
    data = {
        "left": str(pads["left"]), "right": str(pads["right"]),
        "up": str(pads["top"]), "down": str(pads["bottom"]),
        "output_format": "png",
    }
    if instruction:
        data["prompt"] = instruction
    response = requests.post(
        ENDPOINT,
        headers={"Authorization": f"Bearer {api_key}", "Accept": "image/*"},
        files={"image": ("source.png", stream.getvalue(), "image/png")},
        data=data, timeout=timeout)
    if not response.ok:
        try:
            body = response.json()
            detail = body.get("message") or body.get("errors") or body
        except ValueError:
            detail = response.text[:300] or f"HTTP {response.status_code}"
        raise RuntimeError(f"Stability AI could not expand the photograph: {detail}")
    try:
        result = Image.open(io.BytesIO(response.content))
        result.load()
    except Exception as error:
        raise RuntimeError("Stability AI returned no usable expanded image.") from error
    result = result.convert("RGB")
    if result.size != size:
        raise RuntimeError(
            f"Stability AI returned {result.width} × {result.height} pixels; "
            f"SNAP SLAPPER requested {size[0]} × {size[1]}. Nothing was added.")
    box = (pads["left"], pads["top"], pads["left"] + image.width,
           pads["top"] + image.height)
    result.paste(image, box[:2])
    mask = Image.new("L", size, 255)
    ImageDraw.Draw(mask).rectangle((box[0], box[1], box[2] - 1, box[3] - 1), fill=0)
    return result, mask, box, instruction


# ===== SNAPSMACK EOF =====
