"""
Smack Your Batch Up — gemini.py
Sends images to the Gemini API and returns AI-generated metadata
(title, tags, category, album) as ManifestEntry updates.
"""

import os
import re
from typing import Callable, List, Optional

from manifest_parser import ManifestEntry

# google-generativeai is imported lazily so the app still starts
# even if the library isn't installed yet.
_genai = None

MODEL_NAME = "gemini-1.5-flash"


def _import_genai():
    global _genai
    if _genai is None:
        try:
            import google.generativeai as genai
            _genai = genai
        except ImportError:
            raise RuntimeError(
                "google-generativeai is not installed.\n"
                "Run: pip install google-generativeai"
            )
    return _genai


def is_available() -> bool:
    """Return True if google-generativeai is importable."""
    try:
        import google.generativeai  # noqa: F401
        return True
    except ImportError:
        return False


def test_connection(api_key: str) -> tuple[bool, str]:
    """
    Send a minimal text-only request to Gemini to verify the key works.
    Returns (True, model_name) on success, (False, error_message) on failure.
    """
    try:
        genai = _import_genai()
        genai.configure(api_key=api_key)
        model    = genai.GenerativeModel(MODEL_NAME)
        response = model.generate_content("Reply with only the word: OK")
        return True, f"Connected — {MODEL_NAME}"
    except Exception as e:
        return False, str(e)


def _build_prompt(categories: List[str], albums: List[str]) -> str:
    cats_str   = ", ".join(categories) if categories else "(none)"
    albums_str = ", ".join(albums)     if albums     else "(none)"
    return f"""You are generating metadata for a photo blog post on a site called SnapSmack.

Analyse the image carefully and respond ONLY in this exact format — no extra text:

TITLE: <a haiku-style title: three phrases separated by commas, e.g. "Dark stone holds the rain, rust bleeds through the ancient wall, time carves out the mark">
TAGS: <5 to 8 space-separated hashtags, e.g. #stone #rust #texture #macro #urban>
CATEGORY: <pick the single best match from this list, or leave blank: {cats_str}>
ALBUM: <pick the single best match from this list, or leave blank: {albums_str}>

Rules:
- TITLE must be evocative and descriptive of what is literally in the image
- TAGS should describe subject, texture, colour, mood — lowercase, no spaces within a tag
- CATEGORY must exactly match one of the options provided, or be left completely blank
- ALBUM must exactly match one of the options provided, or be left completely blank
- Do not add any explanation, preamble, or extra lines"""


def _parse_response(text: str) -> dict:
    """Extract TITLE/TAGS/CATEGORY/ALBUM from the model response."""
    result = {'title': '', 'tags': '', 'category': '', 'album': ''}
    for line in text.strip().splitlines():
        m = re.match(r'^(TITLE|TAGS|CATEGORY|ALBUM):\s*(.*)', line.strip(), re.IGNORECASE)
        if m:
            key = m.group(1).lower()
            val = m.group(2).strip()
            if key in result:
                result[key] = val
    return result


def enrich_batch(
    api_key:       str,
    entries:       List[ManifestEntry],
    image_folder:  str,
    categories:    List[str],
    albums:        List[str],
    on_progress:   Optional[Callable[[int, int, ManifestEntry, Optional[str]], None]] = None,
    skip_filled:   bool = True,
    custom_prompt: str  = '',
) -> List[ManifestEntry]:
    """
    Process a list of ManifestEntry objects, sending each image to Gemini
    and updating title/tags/category/album in place.

    on_progress(index, total, entry, error_or_None) is called after each image.
    skip_filled=True skips any entry that already has a non-blank title.
    custom_prompt overrides the default prompt if provided.

    Returns the (mutated) list of entries.
    """
    genai = _import_genai()
    genai.configure(api_key=api_key)
    model  = genai.GenerativeModel(MODEL_NAME)
    prompt = custom_prompt.strip() if custom_prompt.strip() else _build_prompt(categories, albums)
    total  = len(entries)

    for i, entry in enumerate(entries, start=1):
        if skip_filled and entry.title.strip():
            if on_progress:
                on_progress(i, total, entry, None)
            continue

        img_path = os.path.join(image_folder, entry.file)
        if not os.path.isfile(img_path):
            if on_progress:
                on_progress(i, total, entry, f"File not found: {entry.file}")
            continue

        try:
            img_part = _load_image_part(genai, img_path)
            response = model.generate_content([prompt, img_part])
            parsed   = _parse_response(response.text)

            if parsed['title']:
                entry.title = parsed['title']
            if parsed['tags']:
                entry.tags = parsed['tags']
            if parsed['category']:
                entry.category = parsed['category']
            if parsed['album']:
                entry.album = parsed['album']

            if on_progress:
                on_progress(i, total, entry, None)

        except Exception as e:
            if on_progress:
                on_progress(i, total, entry, str(e))

    return entries


def _load_image_part(genai, path: str):
    """Load an image file and return a Gemini-compatible Part."""
    ext  = os.path.splitext(path)[1].lower()
    mime = {'.jpg': 'image/jpeg', '.jpeg': 'image/jpeg',
            '.png': 'image/png',  '.webp': 'image/webp'}.get(ext, 'image/jpeg')
    with open(path, 'rb') as f:
        data = f.read()
    return {'mime_type': mime, 'data': data}
