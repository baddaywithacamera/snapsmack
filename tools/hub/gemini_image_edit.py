"""Gemini-backed, explicitly masked image repair for SNAP SLAPPER."""

import base64
import io
import json

import requests
from PIL import Image, ImageChops, ImageFilter, ImageOps, ImageStat


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


# HOW MUCH OF THE PHOTOGRAPH THE MODEL GETS TO SEE.
#
# The margin used to be a fraction of the SELECTION with a 96-pixel floor, and
# that is where the bad fills came from. Paint a 40-pixel blemish on a
# 6000-pixel frame and the margin was max(96, 30) = 96: a 232-pixel tile, under
# 4% of the picture. The model was asked to invent content that matched a
# photograph it had been shown almost none of, so it matched the little it
# could see and clashed with everything else.
#
# This floor is a fraction of the IMAGE's long edge instead, so the context the
# model gets scales with the photograph rather than with how small a spot was
# painted. It is the number to turn when fills look out of place; it is here,
# named, for exactly that reason.
#
# IT IS A TRADE. The tile is resized to the model's working resolution, so a
# wider tile spends fewer of those pixels on the selection itself. Gemini works
# at 1536 and absorbs this easily. The local Diffusers model is capped at 512 by
# the model, not by us, so on a large frame a small selection can end up with
# very few pixels to generate into. CONTEXT_MARGIN_LOCAL_CAP bounds the floor
# for that provider only.
CONTEXT_MARGIN_OF_LONG_EDGE = 0.20

# The smallest the selection may become once the tile is resized to the
# model's working resolution. MEASURED, not guessed: with the 20% floor
# applied unconditionally, a 40-pixel blemish on a 6000-pixel frame gives a
# 2440-pixel tile, and at the local model's 512 cap that selection arrives as
# EIGHT pixels. Eight pixels is not something to inpaint into; the old
# behaviour gave it 88. So the floor is reduced, per call, to whatever still
# leaves the selection this many pixels to work with. Gemini works at 1536 and
# reaches the full 20% in every case measured; the local model gives up
# context on big frames because the alternative is giving up the repair.
MIN_SELECTION_PIXELS_AT_WORK_RESOLUTION = 64

# What heal_tile() sends. Named so a caller sizing a tile for Gemini
# asks this module rather than repeating the number.
WORK_RESOLUTION = 1536


# ---------------------------------------------------------------------------
# COLOUR. Every image model answers in sRGB. It is not told what space the
# pixels it was given are in, and it does not ask.
#
# SNAP SLAPPER's document keeps the source profile -- a phone photograph is
# usually Display P3, which is a visibly wider space than sRGB. The numbers in
# a P3 file mean different colours than the same numbers in an sRGB file, so
# handing them over unconverted is handing over the wrong picture: saturated
# reds and greens read as duller than they are. The model then matches what it
# was shown, the patch comes back in sRGB, and it is pasted straight into a P3
# document where those numbers mean something else again. The error happens
# twice and in opposite directions, which is why a generative fill could come
# back close in tone and wrong in colour.
#
# So: convert to sRGB on the way out, convert back on the way in. Both are
# no-ops when the document is already sRGB or carries no profile, which is the
# common case and must stay free.
def _srgb_profile():
    from PIL import ImageCms
    return ImageCms.createProfile("sRGB")


def to_srgb(image, profile_bytes):
    """The picture as the model should see it. Returns the image unchanged
    when there is nothing to convert or the conversion is not available."""
    if not profile_bytes:
        return image
    try:
        from PIL import ImageCms
        source = ImageCms.ImageCmsProfile(io.BytesIO(profile_bytes))
        return ImageCms.profileToProfile(
            image, source, _srgb_profile(), outputMode="RGB")
    except Exception:                          # noqa: BLE001
        # A broken or unreadable profile must not cost the person their edit.
        # Sending unconverted pixels is what happened before this existed.
        return image


def from_srgb(image, profile_bytes):
    """The model's sRGB answer, moved into the document's own space."""
    if not profile_bytes:
        return image
    try:
        from PIL import ImageCms
        target = ImageCms.ImageCmsProfile(io.BytesIO(profile_bytes))
        return ImageCms.profileToProfile(
            image, _srgb_profile(), target, outputMode="RGB")
    except Exception:                          # noqa: BLE001
        return image


def context_floor_ratio(image_size, bounds, work_resolution,
                        wanted=CONTEXT_MARGIN_OF_LONG_EDGE,
                        minimum_selection=MIN_SELECTION_PIXELS_AT_WORK_RESOLUTION):
    """The largest context floor that still leaves the selection usable.

    Returns ``wanted`` when the model's working resolution can afford it, and
    a smaller ratio when it cannot. Never returns less than 0.

    The tile is square with side = selection + 2 * margin, and it is resized so
    its long edge is ``work_resolution``. The selection therefore survives at
    ``selection * work_resolution / side`` pixels, so the widest usable tile is
    ``selection * work_resolution / minimum_selection`` and the margin that
    produces it follows directly.
    """
    left, top, right, bottom = bounds
    selection = max(1, max(right - left, bottom - top))
    wanted = max(0.0, float(wanted))
    long_edge = max(image_size)
    if work_resolution <= 0 or minimum_selection <= 0 or long_edge <= 0:
        return wanted
    affordable_side = selection * float(work_resolution) / float(minimum_selection)
    affordable_margin = (affordable_side - selection) / 2.0
    if affordable_margin <= 0:
        return 0.0
    return min(wanted, affordable_margin / float(long_edge))


def square_tile_box(image_size, bounds, margin_ratio=0.75, minimum_margin=96,
                    floor_ratio=CONTEXT_MARGIN_OF_LONG_EDGE):
    """Choose a SQUARE tile with context around ``bounds``, clamped to the frame.

    Square matters. Gemini's image models answer in their own aspect ratio and
    a square request is the one they leave alone; a 3:2 crop frequently comes
    back 1:1, and resizing that back to 3:2 squashes the geometry so the
    generated content no longer lines up with the photograph around it. A
    square tile removes the mismatch instead of correcting for it afterwards.

    The tile stays inside the frame. At a frame edge it is pushed inward rather
    than shrunk, so a selection in the corner still gets its context.

    ``floor_ratio`` is the share of the image's LONG edge the margin may not go
    below. Pass 0 to get the old selection-relative behaviour exactly.
    """
    width, height = image_size
    left, top, right, bottom = bounds
    # Three floors, largest wins: an absolute pixel floor, a share of the
    # selection, and a share of the PHOTOGRAPH. The third is the one that was
    # missing, and it is the one that matters on a big frame.
    margin = max(int(minimum_margin),
                 int(max(right - left, bottom - top) * margin_ratio),
                 int(max(width, height) * max(0.0, float(floor_ratio))))
    side = max(right - left, bottom - top) + margin * 2
    side = max(1, min(side, width, height))
    centre_x = (left + right) / 2.0
    centre_y = (top + bottom) / 2.0
    x = int(round(centre_x - side / 2.0))
    y = int(round(centre_y - side / 2.0))
    x = max(0, min(x, width - side))
    y = max(0, min(y, height - side))
    return (x, y, x + side, y + side)


def aspect_corrected(result, size):
    """Return the model's frame at ``size`` without squashing its geometry.

    A plain resize of a square return into a non-square request stretches one
    axis, which shifts every edge inside the selection. When the aspect ratios
    differ by more than 1% this centre-crops to the requested shape first, so
    content keeps its proportions and only the surplus is discarded.
    """
    target_width, target_height = size
    if result.size == size:
        return result
    wanted = target_width / target_height
    given = result.width / result.height
    if abs(given - wanted) / wanted > 0.01:
        result = ImageOps.fit(result, size, Image.Resampling.LANCZOS)
        return result
    return result.resize(size, Image.Resampling.LANCZOS)


def alignment_error(original, returned, mask):
    """Mean 0-255 difference OUTSIDE the selection between two tiles.

    The instruction tells the model to leave everything outside the white
    selection alone. When a return is geometrically shifted -- the failure a
    squashed aspect ratio produces -- that promise breaks everywhere, so this
    number rises sharply. It is recorded and shown rather than used to refuse
    a result the photographer asked for.
    """
    keep = ImageChops.invert(mask.convert("L").resize(
        original.size, Image.Resampling.LANCZOS))
    if not keep.getbbox():
        return 0.0
    difference = ImageChops.difference(
        original.convert("RGB"), returned.convert("RGB").resize(
            original.size, Image.Resampling.LANCZOS)).convert("L")
    outside = ImageChops.multiply(difference, keep)
    total_weight = ImageStat.Stat(keep).sum[0]
    if not total_weight:
        return 0.0
    return round(ImageStat.Stat(outside).sum[0] / (total_weight / 255.0), 2)


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


def tile_layer_image(document_size, patch, tile_mask, box, strength=1.0):
    """Place a native-resolution patch on a document-sized transparent canvas.

    The editor adds generative work as an image layer with ``fit = "stretch"``,
    which resizes the layer to the canvas. A document-sized layer therefore
    places 1:1 at export; a 2048-pixel preview-sized one was being enlarged to
    the photograph, which is why fills grew soft once documents became RAW-
    sized. Everything outside the tile is transparent, so the file stays small.
    """
    canvas = Image.new("RGBA", tuple(document_size), (0, 0, 0, 0))
    alpha = blend_mask(tile_mask.convert("L"), strength).resize(
        patch.size, Image.Resampling.LANCZOS)
    canvas.paste(patch.convert("RGB"), (int(box[0]), int(box[1])), alpha)
    return canvas


def _instruction_for(operation, prompt):
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
    return instruction


def heal_tile(tile, tile_mask, prompt, api_key, model="gemini-3.1-flash-image",
              timeout=300, operation="heal", work_target=WORK_RESOLUTION):
    """One model round trip on a prepared tile, returned at the TILE's own size.

    The caller chooses the tile, so the generated pixels can be delivered at
    the resolution of the photograph instead of the resolution of the screen
    preview. ``work_target`` only bounds what travels to the provider.

    Returns ``(patch, instruction, drift)`` where ``patch`` is the same size as
    ``tile`` and ``drift`` is the mean difference outside the selection.
    """
    if not api_key:
        raise ValueError("Add a Gemini API key in SNAP HQ Settings first.")
    tile = tile.convert("RGB")
    tile_mask = tile_mask.convert("L").resize(tile.size, Image.Resampling.LANCZOS)
    scale = min(work_target / max(tile.size), 4.0)
    work_size = (max(1, round(tile.width * scale)), max(1, round(tile.height * scale)))
    work_image = tile.resize(work_size, Image.Resampling.LANCZOS)
    work_mask = tile_mask.resize(work_size, Image.Resampling.LANCZOS)
    instruction = _instruction_for(operation, prompt)
    marked = marked_selection(work_image, work_mask)
    parts = [
        {"text": instruction},
        {"inlineData": {"mimeType": "image/png", "data": _png_data(work_image)}},
        {"inlineData": {"mimeType": "image/png", "data": _png_data(marked)}},
        {"inlineData": {"mimeType": "image/png", "data": _png_data(work_mask)}},
    ]
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
        raise RuntimeError(
            f"Gemini returned HTTP {response.status_code}, not a usable response.") from error
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
                result = _generated_image(
                    blob["data"], blob.get("mimeType") or blob.get("mime_type"))
                # Correct the aspect ratio BEFORE sizing to the tile, then
                # deliver at the tile's own resolution -- no screen-preview
                # detour, so the generated area carries the photograph's grain.
                patch = aspect_corrected(result.convert("RGB"), tile.size)
                return patch, instruction, alignment_error(tile, patch, tile_mask)
    raise RuntimeError("Gemini returned no edited image.")


def heal(image, mask, prompt, api_key, model="gemini-3.1-flash-image", timeout=300,
         operation="heal", return_instruction=False):
    """Return Gemini's edited full frame. The caller owns local mask enforcement."""
    image = image.convert("RGB")
    mask = mask.convert("L").resize(image.size, Image.Resampling.LANCZOS)
    bounds = mask.getbbox()
    if bounds is None:
        raise ValueError("Paint over the defect before asking Gemini to heal it.")
    box = square_tile_box(image.size, bounds)
    patch, instruction, _drift = heal_tile(
        image.crop(box), mask.crop(box), prompt, api_key, model=model,
        timeout=timeout, operation=operation)
    repaired = image.copy()
    repaired.paste(patch, box[:2])
    return (repaired, instruction) if return_instruction else repaired


def fill(image, mask, prompt, api_key, model="gemini-3.1-flash-image", timeout=300):
    """Generate requested content inside a locally enforced selection."""
    return heal(image, mask, prompt, api_key, model=model, timeout=timeout,
                operation="fill")


# ===== SNAPSMACK EOF =====
