"""Gemini-backed, explicitly masked image repair for SNAP SLAPPER."""

import base64
import io

import requests
from PIL import Image


ENDPOINT = "https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent"


def _png_data(image):
    stream = io.BytesIO()
    image.save(stream, "PNG", optimize=True)
    return base64.b64encode(stream.getvalue()).decode("ascii")


def heal(image, mask, prompt, api_key, model="gemini-3.1-flash-image", timeout=300):
    """Return Gemini's edited full frame. The caller owns local mask enforcement."""
    if not api_key:
        raise ValueError("Add a Gemini API key in SNAP HQ Settings first.")
    image = image.convert("RGB")
    mask = mask.convert("L").resize(image.size, Image.Resampling.LANCZOS)
    if mask.getbbox() is None:
        raise ValueError("Paint over the defect before asking Gemini to heal it.")
    instruction = (
        "Repair only the area painted WHITE in the supplied black-and-white mask. "
        "Remove the selected defect and reconstruct natural matching content from "
        "the surrounding photograph. Preserve perspective, lighting, grain, focus, "
        "colour and texture. Do not alter anything outside the white selection."
    )
    if prompt.strip():
        instruction += " Additional instruction: " + prompt.strip()
    payload = {
        "contents": [{"role": "user", "parts": [
            {"text": instruction},
            {"inlineData": {"mimeType": "image/png", "data": _png_data(image)}},
            {"inlineData": {"mimeType": "image/png", "data": _png_data(mask)}},
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
                return result.convert("RGB")
    raise RuntimeError("Gemini returned no edited image.")

# ===== SNAPSMACK EOF =====
