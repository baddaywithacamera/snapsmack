"""Gemini-backed, explicitly masked image repair for SNAP SLAPPER."""

import base64
import io

import requests
from PIL import Image, ImageChops, ImageDraw, ImageFilter


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
            "Outpaint only the WHITE masked border region on the requested edge or "
            "edges. Preserve the supplied interior photograph exactly. At the inner "
            "mask boundary, continue every intersecting line, surface, object, texture, "
            "lighting gradient, perspective, focus characteristic, grain and noise "
            "pattern without a visible seam. Extend existing content conservatively. "
            "Do not introduce a new focal subject or a large foreground object unless "
            "the user's direction explicitly requests one. The outer canvas edge may "
            "crop objects naturally. Return the complete expanded canvas at the supplied "
            "dimensions. " + prompt.strip()
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
    if operation == "expand":
        instruction = (
            "Edit the FIRST image and return the complete expanded photograph, not an "
            "explanation, mask, matte, diagram, or marked reference. The SECOND image "
            "marks the outpainting target in RED; the red paint is an annotation and "
            "must not appear in the result. " + task + " Do not alter anything outside "
            "the marked border region."
        )
    else:
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
    parts = [
        {"text": instruction},
        {"inlineData": {"mimeType": "image/png", "data": _png_data(work_image)}},
        {"inlineData": {"mimeType": "image/png", "data": _png_data(marked)}},
    ]
    if operation != "expand":
        parts.append({"inlineData": {
            "mimeType": "image/png", "data": _png_data(work_mask)}})
    payload = {
        "contents": [{"role": "user", "parts": parts}],
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


def edge_extended_canvas(image, pads):
    """Seed each new border from the nearest captured edge pixels."""
    image = image.convert("RGB")
    left, right = pads["left"], pads["right"]
    top, bottom = pads["top"], pads["bottom"]
    width = image.width + left + right
    horizontal = Image.new("RGB", (width, image.height))
    horizontal.paste(image, (left, 0))
    if left:
        horizontal.paste(image.crop((0, 0, 1, image.height)).resize(
            (left, image.height), Image.Resampling.NEAREST), (0, 0))
    if right:
        horizontal.paste(image.crop((image.width - 1, 0, image.width, image.height)).resize(
            (right, image.height), Image.Resampling.NEAREST), (left + image.width, 0))
    canvas = Image.new("RGB", (width, image.height + top + bottom))
    canvas.paste(horizontal, (0, top))
    if top:
        canvas.paste(horizontal.crop((0, 0, width, 1)).resize(
            (width, top), Image.Resampling.NEAREST), (0, 0))
    if bottom:
        canvas.paste(horizontal.crop((0, image.height - 1, width, image.height)).resize(
            (width, bottom), Image.Resampling.NEAREST), (0, top + image.height))
    return canvas


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
    canvas = edge_extended_canvas(image, pads)
    mask = Image.new("L", size, 255)
    ImageDraw.Draw(mask).rectangle(
        (box[0], box[1], box[2] - 1, box[3] - 1), fill=0)
    direction = ", ".join(name for name, value in pads.items() if value)
    request = (
        "Requested expansion edges: " + direction + ". Preserve all structures that "
        "cross the boundary. Continue curbs, sidewalks, road edges, lane markings, "
        "rooflines, fences and horizon lines at exactly the same position, angle, width, "
        "perspective and material. Do not create a second or replacement curb, sidewalk, "
        "road edge, roofline or fence."
    )
    if str(prompt or "").strip():
        request += " User direction: " + str(prompt).strip()
    generated = heal(canvas, mask, request, api_key, model=model, timeout=timeout,
                     operation="expand")
    generated.paste(image, box[:2])
    return generated, mask, box

# ===== SNAPSMACK EOF =====
