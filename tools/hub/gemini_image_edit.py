"""Gemini-backed, explicitly masked image repair for SNAP SLAPPER."""

import base64
import io
import json

import requests
from PIL import Image, ImageChops, ImageFilter


ENDPOINT = "https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent"
MAX_RESPONSE_BYTES = 64 * 1024 * 1024
MAX_INLINE_IMAGE_BYTES = 32 * 1024 * 1024
MAX_GENERATED_PIXELS = 16_000_000


def _response_json(response):
    """Read a response through a hard body ceiling before JSON parsing."""
    headers = getattr(response, "headers", {}) or {}
    declared = headers.get("Content-Length") or headers.get("content-length")
    if declared:
        try:
            if int(declared) > MAX_RESPONSE_BYTES:
                raise RuntimeError("Gemini returned an image response that is too large.")
        except ValueError:
            raise RuntimeError("Gemini returned an invalid response length.")
    if not hasattr(response, "iter_content"):
        return response.json()
    body = bytearray()
    for chunk in response.iter_content(64 * 1024):
        if not chunk:
            continue
        body.extend(chunk)
        if len(body) > MAX_RESPONSE_BYTES:
            raise RuntimeError("Gemini returned an image response that is too large.")
    return json.loads(body.decode("utf-8"))


def _generated_image(encoded, mime_type):
    """Decode only a bounded raster result with dimensions suitable for a patch."""
    if mime_type not in {"image/jpeg", "image/png", "image/webp"}:
        raise RuntimeError("Gemini returned an unsupported image format.")
    if not isinstance(encoded, str) or len(encoded) > ((MAX_INLINE_IMAGE_BYTES + 2) // 3) * 4:
        raise RuntimeError("Gemini returned an image that is too large.")
    try:
        raw = base64.b64decode(encoded, validate=True)
    except (ValueError, TypeError) as error:
        raise RuntimeError("Gemini returned invalid image data.") from error
    if len(raw) > MAX_INLINE_IMAGE_BYTES:
        raise RuntimeError("Gemini returned an image that is too large.")
    result = Image.open(io.BytesIO(raw))
    if result.format not in {"JPEG", "PNG", "WEBP"}:
        raise RuntimeError("Gemini returned an unsupported image format.")
    if result.width <= 0 or result.height <= 0 or result.width * result.height > MAX_GENERATED_PIXELS:
        raise RuntimeError("Gemini returned unsafe image dimensions.")
    result.load()
    return result


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
         operation="heal", return_instruction=False):
    """Return Gemini's edited full frame. The caller owns local mask enforcement."""
    if not api_key:
        raise ValueError("Add a Gemini API key in SNAP HQ Settings first.")
    image = image.convert("RGB")
    mask = mask.convert("L").resize(image.size, Image.Resampling.LANCZOS)
    box, work_image, work_mask = focus_region(image, mask)
    if operation == "fill":
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
    if operation == "heal" and prompt.strip():
        instruction += " Additional instruction: " + prompt.strip()
    marked = marked_selection(work_image, work_mask)
    parts = [
        {"text": instruction},
        {"inlineData": {"mimeType": "image/png", "data": _png_data(work_image)}},
        {"inlineData": {"mimeType": "image/png", "data": _png_data(marked)}},
    ]
    parts.append({"inlineData": {
        "mimeType": "image/png", "data": _png_data(work_mask)}})
    payload = {
        "contents": [{"role": "user", "parts": parts}],
        "generationConfig": {"responseModalities": ["IMAGE"]},
    }
    response = requests.post(
        ENDPOINT.format(model=model), params={"key": api_key}, json=payload,
        timeout=timeout, stream=True)
    try:
        data = _response_json(response)
    except (ValueError, UnicodeDecodeError, json.JSONDecodeError) as error:
        raise RuntimeError(f"Gemini returned HTTP {response.status_code}, not a usable response.") from error
    if not response.ok:
        detail = ((data.get("error") or {}).get("message") or f"HTTP {response.status_code}")
        if ("free_tier" in detail and "limit: 0" in detail) or (
                "quota" in detail.lower() and response.status_code == 429):
            raise RuntimeError(
                "Gemini image generation has no free API tier. Use a Gemini key from a "
                "billing-enabled Google project. No generated edit was added.")
        raise RuntimeError("Gemini could not heal the photograph: " + detail)
    for candidate in data.get("candidates", []):
        for part in (candidate.get("content") or {}).get("parts", []):
            blob = part.get("inlineData") or part.get("inline_data")
            if blob and blob.get("data"):
                result = _generated_image(blob["data"], blob.get("mimeType") or blob.get("mime_type"))
                result = result.convert("RGB").resize(work_image.size, Image.Resampling.LANCZOS)
                repaired = image.copy()
                repaired.paste(result.resize((box[2] - box[0], box[3] - box[1]),
                                             Image.Resampling.LANCZOS), box[:2])
                return (repaired, instruction) if return_instruction else repaired
    raise RuntimeError("Gemini returned no edited image.")


def fill(image, mask, prompt, api_key, model="gemini-3.1-flash-image", timeout=300):
    """Generate requested content inside a locally enforced selection."""
    return heal(image, mask, prompt, api_key, model=model, timeout=timeout,
                operation="fill")


# ===== SNAPSMACK EOF =====
