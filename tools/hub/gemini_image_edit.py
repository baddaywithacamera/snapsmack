"""Gemini-backed, explicitly masked image repair for SNAP SLAPPER."""

import base64
import io

import requests
from PIL import Image, ImageChops, ImageDraw, ImageFilter, ImageStat


ENDPOINT = "https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent"


def blend_mask(mask, strength=1.0):
    """Give a generated repair enough overlap to hide its local mask boundary."""
    mask = mask.convert("L")
    strength = max(0.0, min(2.0, float(strength)))
    if strength == 0:
        return mask
    short_edge = min(mask.size)
    # Gemini's reconstructed patch can be fractionally lighter or darker than
    # smooth surrounding paint. A broad, soft transition hides that low-frequency
    # seam without granting the model control over a meaningfully larger region.
    expansion = max(1, round(max(3, min(18, short_edge / 180)) * strength))
    expanded = mask.filter(ImageFilter.MaxFilter(expansion * 2 + 1))
    feather = max(1, max(8, min(42, short_edge / 60)) * strength)
    softened = expanded.filter(ImageFilter.GaussianBlur(feather))
    return ImageChops.lighter(mask, softened)


def _png_data(image):
    stream = io.BytesIO()
    image.save(stream, "PNG", optimize=True)
    return base64.b64encode(stream.getvalue()).decode("ascii")


def marked_selection(image, mask):
    """Return an unmistakable red overlay showing Gemini the repair target."""
    image = image.convert("RGB")
    mask = mask.convert("L").resize(image.size, Image.Resampling.LANCZOS)
    red = Image.new("RGB", image.size, (255, 24, 24))
    tint = Image.blend(image, red, 0.68)
    return Image.composite(tint, image, mask)


def focus_region(image, mask, target=1536):
    """Crop generous context around a small defect and enlarge it for the model."""
    bbox = mask.getbbox()
    if bbox is None:
        raise ValueError("Paint over the defect before asking Gemini to heal it.")
    left, top, right, bottom = bbox
    width, height = right - left, bottom - top
    margin = max(96, round(max(width, height) * 2.5))
    box = (max(0, left - margin), max(0, top - margin),
           min(image.width, right + margin), min(image.height, bottom + margin))
    crop = image.crop(box)
    crop_mask = mask.crop(box)
    scale = min(target / max(crop.size), 4.0)
    work_size = (max(1, round(crop.width * scale)),
                 max(1, round(crop.height * scale)))
    return box, crop.resize(work_size, Image.Resampling.LANCZOS), crop_mask.resize(
        work_size, Image.Resampling.LANCZOS)


def heal(image, mask, prompt, api_key, model="gemini-3.1-flash-image", timeout=300,
         operation="heal"):
    """Return Gemini's edited full frame. The caller owns local mask enforcement."""
    if not api_key:
        raise ValueError("Add a Gemini API key in SNAP HQ Settings first.")
    image = image.convert("RGB")
    mask = mask.convert("L").resize(image.size, Image.Resampling.LANCZOS)
    box, work_image, work_mask = focus_region(image, mask)
    marked = marked_selection(work_image, work_mask)
    if operation == "expand":
        task = (
            "Extend the photograph naturally into every white border area. "
            "Continue the existing scene, perspective, lighting, focus, grain, colour "
            "and texture beyond the captured frame. " +
            (("Additional direction: " + prompt.strip() + ".") if prompt.strip() else "")
        )
    elif operation == "fill":
        if prompt.strip():
            task = (
                "Replace the marked area with this requested content: " + prompt.strip() +
                ". Build it naturally into the photograph using the surrounding "
                "perspective, lighting, grain, focus, colour and texture."
            )
        else:
            task = (
                "Replace the marked area with natural matching content inferred from "
                "the surrounding photograph. Continue nearby shapes, surfaces, texture, "
                "perspective, lighting, grain, focus and colour into the selection."
            )
    else:
        task = (
            "Remove the scratch, scuff, wire, dust, blemish, or other defect underneath "
            "the mark, then reconstruct natural matching content from the surrounding "
            "photograph."
        )
    instruction = (
        "Edit the FIRST image and return the repaired photograph, not an explanation. "
        "The SECOND image marks the repair target in RED. The THIRD image is the "
        "same selection as a black-and-white mask; WHITE is the repair target. "
        "The red paint is an annotation, not part of the photograph. " + task + " "
        "Do not merely return the original image. Do not alter anything outside the "
        "white selection."
    )
    if operation != "fill" and prompt.strip():
        instruction += " Additional instruction: " + prompt.strip()
    payload = {
        "contents": [{"role": "user", "parts": [
            {"text": instruction},
            {"inlineData": {"mimeType": "image/png", "data": _png_data(work_image)}},
            {"inlineData": {"mimeType": "image/png", "data": _png_data(marked)}},
            {"inlineData": {"mimeType": "image/png", "data": _png_data(work_mask)}},
        ]}],
        "generationConfig": {"responseModalities": ["IMAGE"]},
    }
    response = requests.post(
        ENDPOINT.format(model=model), params={"key": api_key}, json=payload,
        timeout=timeout)
    try:
        data = response.json()
    except ValueError as error:
        raise RuntimeError(f"Gemini returned HTTP {response.status_code}, not a usable response.") from error
    if not response.ok:
        detail = ((data.get("error") or {}).get("message") or f"HTTP {response.status_code}")
        raise RuntimeError("Gemini could not heal the photograph: " + detail)
    for candidate in data.get("candidates", []):
        for part in (candidate.get("content") or {}).get("parts", []):
            blob = part.get("inlineData") or part.get("inline_data")
            if blob and blob.get("data"):
                result = Image.open(io.BytesIO(base64.b64decode(blob["data"])))
                result.load()
                result = result.convert("RGB").resize(work_image.size, Image.Resampling.LANCZOS)
                repaired = image.copy()
                repaired.paste(result.resize((box[2] - box[0], box[3] - box[1]),
                                             Image.Resampling.LANCZOS), box[:2])
                return repaired
    raise RuntimeError("Gemini returned no edited image.")


def fill(image, mask, prompt, api_key, model="gemini-3.1-flash-image", timeout=300):
    """Generate requested content inside a locally enforced selection."""
    return heal(image, mask, prompt, api_key, model=model, timeout=timeout,
                operation="fill")


def expansion_geometry(size, edges):
    """Return pixel padding, output size and generated area for four edge percentages."""
    width, height = size
    if isinstance(edges, (int, float)):
        edges = {name: edges for name in ("left", "top", "right", "bottom")}
    values = {name: max(0.0, float((edges or {}).get(name, 0)))
              for name in ("left", "top", "right", "bottom")}
    pads = {
        "left": round(width * min(20.0, values["left"]) / 100.0),
        "right": round(width * min(20.0, values["right"]) / 100.0),
        "top": round(height * min(20.0, values["top"]) / 100.0),
        "bottom": round(height * min(20.0, values["bottom"]) / 100.0),
    }
    out_size = (width + pads["left"] + pads["right"],
                height + pads["top"] + pads["bottom"])
    generated_area = out_size[0] * out_size[1] - width * height
    return pads, out_size, generated_area


def expand(image, edges, prompt, api_key, model="gemini-3.1-flash-image", timeout=300,
           max_generated_area=None):
    """Generate a larger frame while restoring the supplied photograph locally."""
    image = image.convert("RGB")
    pads, size, generated_area = expansion_geometry(image.size, edges)
    if generated_area <= 0:
        raise ValueError("Drag an edge or corner to choose an area to expand.")
    limit = (image.width * image.height * .20 if max_generated_area is None
             else max(0, int(max_generated_area)))
    if generated_area > limit:
        raise ValueError("Generative Expand is limited to 20% of the original frame area.")
    box = (pads["left"], pads["top"], pads["left"] + image.width,
           pads["top"] + image.height)
    mean = tuple(round(value) for value in ImageStat.Stat(image.resize((1, 1))).mean[:3])
    canvas = Image.new("RGB", size, mean)
    canvas.paste(image, box[:2])
    mask = Image.new("L", size, 255)
    ImageDraw.Draw(mask).rectangle(
        (box[0], box[1], box[2] - 1, box[3] - 1), fill=0)
    direction = ", ".join(name for name, value in pads.items() if value)
    continuity = ("Extend only the requested " + direction +
                  " edge area. Continue perspective, geometry, lighting, colour, "
                  "focus, grain and texture seamlessly across the original boundary. "
                  "Do not alter the supplied interior photograph. ")
    generated = heal(canvas, mask, continuity + (prompt or ""), api_key, model=model, timeout=timeout,
                     operation="expand")
    generated.paste(image, box[:2])
    return generated, mask, box

# ===== SNAPSMACK EOF =====
