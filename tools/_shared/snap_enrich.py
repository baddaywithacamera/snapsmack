"""
SNAPSMACK — snap_enrich.py  (shared Gemini vision enrichment for the tool family)

ONE vision engine both SYBU and COLD SNAP can call, so ALT / caption / tag / colour
generation lives in a single place instead of each tool growing its own copy. This
is a faithful extraction of the proven tools/sybu/gemini.py: same model, same 600px
image-part downscaling (vision is billed by image size), the same response format
(including the ALT line), and the same tolerant parser.

Difference from SYBU's enrich_batch (which mutates ManifestEntry and does title-
uniqueness retries): this exposes a SINGLE-IMAGE call that returns a plain dict, so
any tool's own draft object can consume it — COLD SNAP's Draft has no ManifestEntry.

    import snap_enrich
    meta = snap_enrich.enrich_image(path, categories=cats, albums=albums)
    draft.title   = meta['title']   or draft.title
    draft.caption = meta['caption'] or draft.caption
    draft.alt     = meta['alt']     or draft.alt
    draft.tags    = meta['tags']    or draft.tags
    ...

The Gemini key defaults to the SHARED store (snap_creds 'gemini_api_key'), so a key
entered in ANY tool is used here. Pass api_key=... to override.

Offline-friendly: categories/albums are passed IN by the caller — COLD SNAP reads
them from its local shared-library mirror, so enrichment needs no live site call.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import os
import re
from typing import List, Optional


MODEL_NAME = "gemini-3.5-flash"     # kept in lockstep with tools/sybu/gemini.py

_genai = None


def _import_genai():
    global _genai
    if _genai is None:
        import google.generativeai as genai      # raises ImportError if absent
        _genai = genai
    return _genai


def is_available() -> bool:
    """True if google-generativeai is importable."""
    try:
        import google.generativeai  # noqa: F401
        return True
    except Exception:
        return False


def _shared_key() -> str:
    """Gemini key from the shared credential store, or '' if unavailable."""
    try:
        import snap_creds
        snap_creds.init()
        return snap_creds.get("gemini_api_key", "")
    except Exception:
        return ""


def test_connection(api_key: str = "") -> tuple:
    """Minimal text ping to verify the key. Returns (ok, message)."""
    try:
        genai = _import_genai()
        genai.configure(api_key=api_key or _shared_key())
        model = genai.GenerativeModel(MODEL_NAME)
        model.generate_content("Reply with only the word: OK")
        return True, f"Connected — {MODEL_NAME}"
    except Exception as e:
        return False, str(e)


# ── Prompt ───────────────────────────────────────────────────────────────────
def build_prompt(
    categories: Optional[List[str]] = None,
    albums: Optional[List[str]] = None,
    cat_descriptions: Optional[dict] = None,
    album_descriptions: Optional[dict] = None,
    existing_tags: Optional[List[str]] = None,
) -> str:
    """Default enrichment prompt. Mirrors SYBU's format and its ALT guidance. A
    tool may ignore this and pass its own custom_prompt (e.g. a per-site preset)."""
    categories = categories or []
    albums = albums or []

    if categories:
        lines = []
        for c in categories:
            d = (cat_descriptions or {}).get(c.lower(), "").strip()
            lines.append(f"  - {c}" + (f" ({d})" if d else ""))
        cats_str = "\n" + "\n".join(lines)
    else:
        cats_str = " (none)"

    if albums:
        lines = []
        for a in albums:
            d = (album_descriptions or {}).get(a.lower(), "").strip()
            lines.append(f"  - {a}" + (f" ({d})" if d else ""))
        albums_str = "\n" + "\n".join(lines)
    else:
        albums_str = " (none)"

    if existing_tags:
        tags_guidance = (
            "Prefer tags from this existing list where they fit: "
            + " ".join(existing_tags[:80])
            + "\nInvent new tags only when nothing in the list applies."
        )
    else:
        tags_guidance = "Use descriptive hashtags for subject, texture, colour, and mood."

    return f"""You are generating metadata for a photo blog post on a site called SnapSmack.

Analyse the image carefully and respond ONLY in this exact format — no extra text:

TITLE: <a short, plain, descriptive title — a few words. Not poetic filler.>
CAPTION: <a short, natural one-line caption describing the image — becomes the post caption. No hashtags.>
ALT: <one plain sentence of accessibility ALT text describing what is visibly in the frame for a screen-reader user — lead with the main subject, be concrete, no "image of"/"photo of", and do not just repeat the caption.>
TAGS: <5 to 8 space-separated lowercase hashtags, e.g. #stone #rust #texture #macro #urban>
CATEGORY: <every applicable match from this list, comma-separated, or blank:{cats_str}>
ALBUM: <every applicable match from this list, comma-separated, or blank:{albums_str}>
COLORS: <the three most prominent colours as uppercase hex codes, space-separated, e.g. #A3724B #2E1F0D #8C6B3A>

Rules:
- TITLE: plain description of what is literally in the image, not poetic.
- CAPTION: one natural line, no hashtags.
- ALT: accessibility text — one plain sentence, lead with the main subject, describe only what is visibly in frame, never begin with "image of"/"photo of", do not just echo the caption.
- {tags_guidance}
- Tags must be lowercase with no spaces within a tag.
- CATEGORY / ALBUM: only exact options from the lists, comma-separated, or blank. Never invent one.
- COLORS: exactly 3 uppercase #RRGGBB hex codes.
- No preamble, Markdown, or extra lines."""


# ── Parser (verbatim behaviour from sybu/gemini.py) ──────────────────────────
def parse_response(text: str) -> dict:
    """Extract TITLE/CAPTION/ALT/TAGS/CATEGORY/ALBUM/COLORS from a model reply."""
    result = {"title": "", "caption": "", "alt": "", "tags": "",
              "category": "", "album": "", "colors": ""}
    for line in (text or "").strip().splitlines():
        m = re.match(r"^(TITLE|CAPTION|ALT|TAGS|CATEGORY|ALBUM|COLORS):\s*(.*)",
                     line.strip(), re.IGNORECASE)
        if m:
            key = m.group(1).lower()
            if key in result:
                result[key] = m.group(2).strip()
    if result["colors"]:
        hexes = re.findall(r"#[0-9A-Fa-f]{6}", result["colors"])
        result["colors"] = " ".join(h.upper() for h in hexes[:3])
    return result


# ── Image part (verbatim behaviour from sybu/gemini.py) ──────────────────────
def _load_image_part(path: str, max_edge: int = 600):
    """Load, downscale to <= max_edge on the long side, return a Gemini JPEG part.
    A 600px thumb carries the detail needed for metadata at ~1/3 the vision cost.
    Falls back to raw bytes if PIL is unavailable."""
    try:
        import io
        from PIL import Image as _PImg
        im = _PImg.open(path).convert("RGB")
        im.thumbnail((max_edge, max_edge), _PImg.LANCZOS)
        buf = io.BytesIO()
        im.save(buf, format="JPEG", quality=85)
        return {"mime_type": "image/jpeg", "data": buf.getvalue()}
    except Exception:
        ext = os.path.splitext(path)[1].lower()
        mime = {".jpg": "image/jpeg", ".jpeg": "image/jpeg",
                ".png": "image/png", ".webp": "image/webp"}.get(ext, "image/jpeg")
        with open(path, "rb") as f:
            return {"mime_type": mime, "data": f.read()}


# ── Single-image enrichment ──────────────────────────────────────────────────
def enrich_image(
    image_path: str,
    *,
    categories: Optional[List[str]] = None,
    albums: Optional[List[str]] = None,
    api_key: str = "",
    custom_prompt: str = "",
    cat_descriptions: Optional[dict] = None,
    album_descriptions: Optional[dict] = None,
    existing_tags: Optional[List[str]] = None,
) -> dict:
    """Send one image to Gemini and return the parsed metadata dict with keys
    title/caption/alt/tags/category/album/colors (any may be ''). Raises on a
    missing key, missing file, or a hard API/library error — callers show that
    to the user rather than silently posting unenriched (SYBU's contract)."""
    key = api_key or _shared_key()
    if not key:
        raise RuntimeError("No Gemini API key — set it in any tool (shared store) or Settings.")
    if not os.path.isfile(image_path):
        raise FileNotFoundError(image_path)

    genai = _import_genai()
    genai.configure(api_key=key)
    model = genai.GenerativeModel(MODEL_NAME)

    prompt = custom_prompt.strip() or build_prompt(
        categories, albums,
        cat_descriptions=cat_descriptions,
        album_descriptions=album_descriptions,
        existing_tags=existing_tags,
    )
    img_part = _load_image_part(image_path)
    response = model.generate_content([prompt, img_part])
    return parse_response(getattr(response, "text", "") or "")
# ===== SNAPSMACK EOF =====
