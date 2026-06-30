"""
Smack Your Batch Up — gemini.py
Sends images to the Gemini API and returns AI-generated metadata
(title, tags, category, album) as ManifestEntry updates.
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.


import logging
import os
import re
from typing import Callable, List, Optional

from manifest_parser import ManifestEntry

log = logging.getLogger('sybu')

# google-generativeai is imported lazily so the app still starts
# even if the library isn't installed yet.
_genai = None

MODEL_NAME = "gemini-3.5-flash"   # 'gemini-3-flash-preview' was not a real model id


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


def _build_prompt(
    categories:        List[str],
    albums:            List[str],
    cat_descriptions:  Optional[dict] = None,
    album_descriptions: Optional[dict] = None,
    existing_tags:     Optional[List[str]] = None,
) -> str:
    # Build category list with optional descriptions
    if categories:
        cat_lines = []
        for c in categories:
            desc = (cat_descriptions or {}).get(c.lower(), '').strip()
            cat_lines.append(f"  - {c}" + (f" ({desc})" if desc else ""))
        cats_str = "\n" + "\n".join(cat_lines)
    else:
        cats_str = " (none)"

    # Build album list with optional descriptions
    if albums:
        album_lines = []
        for a in albums:
            desc = (album_descriptions or {}).get(a.lower(), '').strip()
            album_lines.append(f"  - {a}" + (f" ({desc})" if desc else ""))
        albums_str = "\n" + "\n".join(album_lines)
    else:
        albums_str = " (none)"

    # Build tag guidance
    if existing_tags:
        tag_sample = " ".join(existing_tags[:80])
        tags_guidance = (
            f"Prefer tags from this existing list where they fit: {tag_sample}\n"
            "Invent new tags only when nothing in the list applies."
        )
    else:
        tags_guidance = "Use descriptive hashtags for subject, texture, colour, and mood."

    return f"""You are generating metadata for a photo blog post on a site called SnapSmack.

Analyse the image carefully and respond ONLY in this exact format — no extra text:

TITLE: <a haiku-style title: three phrases separated by commas, e.g. "Dark stone holds the rain, rust bleeds through the ancient wall, time carves out the mark">
CAPTION: <a short, natural one-line caption describing the image — this becomes the post's caption/description. No hashtags.>
TAGS: <5 to 8 space-separated hashtags, e.g. #stone #rust #texture #macro #urban>
CATEGORY: <pick the single best match from this list, or leave blank:{cats_str}>
ALBUM: <pick the single best match from this list, or leave blank:{albums_str}>
COLORS: <the three most visually prominent colors in the image as uppercase hex codes separated by spaces, e.g. #A3724B #2E1F0D #8C6B3A>

Rules:
- TITLE must be evocative and descriptive of what is literally in the image
- CAPTION is the post's caption/description: one natural line, no hashtags
- {tags_guidance}
- Tags must be lowercase with no spaces within a tag
- CATEGORY must exactly match one of the options provided, or be left completely blank
- ALBUM must exactly match one of the options provided, or be left completely blank
- COLORS must be exactly 3 hex codes in #RRGGBB format, uppercase, space-separated
- Do not add any explanation, preamble, or extra lines"""


def _parse_response(text: str) -> dict:
    """Extract TITLE/TAGS/CATEGORY/ALBUM/COLORS from the model response."""
    result = {'title': '', 'caption': '', 'tags': '', 'category': '', 'album': '', 'colors': ''}
    for line in text.strip().splitlines():
        m = re.match(r'^(TITLE|CAPTION|TAGS|CATEGORY|ALBUM|COLORS):\s*(.*)', line.strip(), re.IGNORECASE)
        if m:
            key = m.group(1).lower()
            val = m.group(2).strip()
            if key in result:
                result[key] = val
    # Normalise colors: ensure valid hex codes only
    if result['colors']:
        hexes = re.findall(r'#[0-9A-Fa-f]{6}', result['colors'])
        result['colors'] = ' '.join(h.upper() for h in hexes[:3])
    return result


MAX_TITLE_RETRIES = 4   # max attempts to generate a unique title before giving up


def enrich_batch(
    api_key:            str,
    entries:            List[ManifestEntry],
    image_folder:       str,
    categories:         List[str],
    albums:             List[str],
    on_progress:        Optional[Callable[[int, int, ManifestEntry, Optional[str]], None]] = None,
    skip_filled:        bool = True,
    custom_prompt:      str  = '',
    cat_descriptions:   Optional[dict] = None,
    album_descriptions: Optional[dict] = None,
    existing_tags:      Optional[List[str]] = None,
    existing_titles:    Optional[List[str]] = None,
) -> List[ManifestEntry]:
    """
    Process a list of ManifestEntry objects, sending each image to Gemini
    and updating title/tags/category/album/colors in place.

    on_progress(index, total, entry, error_or_None) is called after each image.
    skip_filled=True skips any entry that already has a non-blank title.
    custom_prompt overrides the default prompt if provided.
    cat_descriptions / album_descriptions: dicts of lower_name → description text.
    existing_tags: list of hashtags already in use on the site (soft matching).
    existing_titles: list of titles already in use on the site — used to prevent
                     duplicates both within this batch and against the live database.

    Returns the (mutated) list of entries.
    """
    genai = _import_genai()
    genai.configure(api_key=api_key)
    model  = genai.GenerativeModel(MODEL_NAME)
    prompt = custom_prompt.strip() if custom_prompt.strip() else _build_prompt(
        categories, albums,
        cat_descriptions=cat_descriptions,
        album_descriptions=album_descriptions,
        existing_tags=existing_tags,
    )
    total  = len(entries)
    log.info("ENRICH start — %d item(s), %d categories, %d albums",
             total, len(categories), len(albums))
    if not categories:
        log.warning("ENRICH — NO categories provided to Gemini (site not connected?); "
                    "CATEGORY will be left blank")
    if not albums:
        log.warning("ENRICH — NO albums provided to Gemini (site not connected?); "
                    "ALBUM will be left blank")

    # Titles we must not reuse: pre-existing on the site + generated in this run.
    used_titles: set = {t.strip().lower() for t in (existing_titles or [])}

    for i, entry in enumerate(entries, start=1):
        if skip_filled and (entry.title.strip() or entry.caption.strip()):
            if on_progress:
                on_progress(i, total, entry, None)
            continue

        img_path = os.path.join(image_folder, entry.file)
        if not os.path.isfile(img_path):
            if on_progress:
                on_progress(i, total, entry, f"File not found: {entry.file}")
            continue

        try:
            img_part   = _load_image_part(genai, img_path)
            last_error = None

            for attempt in range(1, MAX_TITLE_RETRIES + 1):
                # On retries, prepend a note telling Gemini which title to avoid.
                if attempt == 1:
                    run_prompt = prompt
                else:
                    run_prompt = (
                        f"The title you previously generated — \"{entry.title}\" — is already "
                        f"in use. Generate a DIFFERENT haiku-style title for this image. "
                        f"The new title must be unique and must not match any previously used title.\n\n"
                        + prompt
                    )

                log.info("GEMINI REQUEST %s (attempt %d) — prompt:\n%s",
                         entry.file, attempt, run_prompt)
                response = model.generate_content([run_prompt, img_part])
                log.info("GEMINI RESPONSE %s (attempt %d):\n%s",
                         entry.file, attempt, response.text)
                parsed   = _parse_response(response.text)
                log.info("GEMINI PARSED %s — title=%r tags=%r category=%r album=%r colors=%r",
                         entry.file, parsed.get('title', ''), parsed.get('tags', ''),
                         parsed.get('category', ''), parsed.get('album', ''),
                         parsed.get('colors', ''))
                title    = parsed.get('title', '').strip()
                caption  = parsed.get('caption', '').strip()

                # GRAM posts have NO title — caption + hashtags only. Accept when we
                # have a unique title (solo/photoblog, where title feeds the slug) OR
                # a caption with no title (gram). Title-uniqueness only matters when a
                # title actually exists.
                title_ok = bool(title) and title.lower() not in used_titles
                gram_ok  = (not title) and bool(caption)

                if title_ok or gram_ok:
                    if title:
                        entry.title = title
                        used_titles.add(title.lower())
                    if parsed.get('caption'):
                        entry.caption = parsed['caption']
                    if parsed['tags']:
                        entry.tags = parsed['tags']
                    if parsed['category']:
                        entry.category = parsed['category']
                    if parsed['album']:
                        entry.album = parsed['album']
                    if parsed['colors']:
                        entry.colors = parsed['colors']
                    last_error = None
                    break
                else:
                    # Title present but duplicate → keep for the retry prompt and try
                    # again. Neither title nor caption → a genuinely empty response.
                    if title:
                        entry.title = title
                        last_error = f"Duplicate title after {attempt} attempt(s): \"{title}\""
                    else:
                        last_error = f"No title or caption returned (attempt {attempt})"

            if last_error:
                if on_progress:
                    on_progress(i, total, entry, last_error)
            else:
                if on_progress:
                    on_progress(i, total, entry, None)

        except Exception as e:
            msg = str(e)
            log.error("GEMINI ERROR %s: %s", entry.file, msg)
            low = msg.lower()
            # Cap-aware halt: a quota/billing/spending-cap error won't clear by
            # retrying the next image, so stop cleanly instead of grinding through
            # the rest erroring. skip_filled means re-running enrich later resumes
            # exactly here (already-done images are skipped).
            if any(k in low for k in ('resource_exhausted', 'quota', 'rate limit',
                                      '429', 'exceeded', 'billing', 'spending cap')):
                cap_msg = (f"Spending cap / quota hit at image {i} of {total} — stopping enrich. "
                           f"Raise the cap (or wait for the reset window), then run enrich again; "
                           f"already-enriched images are skipped, so it picks up from here.")
                log.warning("ENRICH HALTED — %s", cap_msg)
                if on_progress:
                    on_progress(i, total, entry, cap_msg)
                break
            if on_progress:
                on_progress(i, total, entry, msg)

    return entries


def _load_image_part(genai, path: str, max_edge: int = 600):
    """Load an image, downscale to <= max_edge on its long side, and return a
    Gemini-compatible JPEG Part. Vision is billed by image size — a 600px thumb
    carries all the detail needed for title/tags/colour metadata at roughly a
    third of the cost of sending the full-resolution original. Falls back to the
    raw bytes if PIL/resize is unavailable."""
    try:
        import io
        from PIL import Image as _PImg
        im = _PImg.open(path).convert('RGB')
        im.thumbnail((max_edge, max_edge), _PImg.LANCZOS)
        buf = io.BytesIO()
        im.save(buf, format='JPEG', quality=85)
        return {'mime_type': 'image/jpeg', 'data': buf.getvalue()}
    except Exception:
        ext  = os.path.splitext(path)[1].lower()
        mime = {'.jpg': 'image/jpeg', '.jpeg': 'image/jpeg',
                '.png': 'image/png',  '.webp': 'image/webp'}.get(ext, 'image/jpeg')
        with open(path, 'rb') as f:
            return {'mime_type': mime, 'data': f.read()}
# ===== SNAPSMACK EOF =====
