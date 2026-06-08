"""
Unzucker — ig_parser.py
Parses an Instagram data export's posts_1.json into structured
ParsedPost objects ready for migration to SnapSmack.

Expected JSON: a flat array of post objects, each with:
  media[]  — list of {uri, creation_timestamp, ...}
  title    — caption text (empty string for captionless singles)
  creation_timestamp — post-level Unix timestamp
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.


import json
import os
import re
from dataclasses import dataclass, field
from typing import List, Tuple


@dataclass
class ParsedPost:
    ig_timestamp:   int            # original Instagram Unix timestamp
    caption:        str   = ''     # full caption (top-level title)
    body:           str   = ''     # caption with hashtags stripped
    hashtags:       List[str] = field(default_factory=list)
    images:         List[str] = field(default_factory=list)  # resolved absolute paths
    post_type:      str   = 'single'   # 'carousel' or 'single'
    original_index: int   = 0     # position in the JSON array
    excluded:       bool  = False # user can toggle in GUI


@dataclass
class ParseResult:
    posts:  List[ParsedPost] = field(default_factory=list)
    errors: List[str]        = field(default_factory=list)
    stats:  dict             = field(default_factory=dict)


# ---------------------------------------------------------------------------
# Hashtag extraction
# ---------------------------------------------------------------------------

_HASHTAG_RE = re.compile(r'#(\w+)', re.UNICODE)


def _extract_hashtags(caption: str) -> Tuple[str, List[str]]:
    """
    Extract hashtags from caption text.
    Returns (body_without_hashtags, [list_of_tags]).
    """
    tags = _HASHTAG_RE.findall(caption)
    # Strip the hashtag tokens from the body text
    body = _HASHTAG_RE.sub('', caption).strip()
    # Clean up leftover whitespace / trailing newlines
    body = re.sub(r'\n\s*\n', '\n', body).strip()
    return body, tags


# ---------------------------------------------------------------------------
# JSON parsing
# ---------------------------------------------------------------------------

def parse(export_folder: str) -> ParseResult:
    """
    Parse an Instagram export folder.
    Looks for your_instagram_activity/media/posts_1.json (or falls back
    to posts_1.json directly in the folder for flexibility).
    """
    result = ParseResult()

    # Find posts_1.json
    candidates = [
        os.path.join(export_folder, 'your_instagram_activity', 'media', 'posts_1.json'),
        os.path.join(export_folder, 'media', 'posts_1.json'),
        os.path.join(export_folder, 'posts_1.json'),
    ]
    json_path = None
    for c in candidates:
        if os.path.isfile(c):
            json_path = c
            break

    if not json_path:
        result.errors.append(
            "Could not find posts_1.json. Expected at:\n"
            f"  {candidates[0]}\n"
            "Make sure you selected the root of the Instagram export folder."
        )
        return result

    # The base directory for resolving media URIs
    # Instagram URIs are relative to the export root, e.g. "media/posts/202404/abc.jpg"
    export_root = os.path.dirname(json_path)
    # If json_path is inside your_instagram_activity/media/, back up to export root
    if 'your_instagram_activity' in json_path:
        export_root = json_path.split('your_instagram_activity')[0]

    # Load JSON
    try:
        with open(json_path, 'r', encoding='utf-8') as f:
            raw = json.load(f)
    except json.JSONDecodeError as e:
        result.errors.append(f"Malformed JSON in posts_1.json: {e}")
        return result
    except OSError as e:
        result.errors.append(f"Cannot read posts_1.json: {e}")
        return result

    if not isinstance(raw, list):
        result.errors.append("posts_1.json is not a JSON array.")
        return result

    # Parse each post
    carousel_count = 0
    single_count   = 0
    total_images   = 0
    skipped_videos = 0
    missing_images = 0

    for idx, post_obj in enumerate(raw):
        if not isinstance(post_obj, dict):
            result.errors.append(f"Entry {idx}: not a JSON object — skipped.")
            continue

        media_list = post_obj.get('media', [])
        if not isinstance(media_list, list) or not media_list:
            result.errors.append(f"Entry {idx}: empty or missing media[] — skipped.")
            continue

        # Resolve image paths, skip videos
        images = []
        for m in media_list:
            uri = m.get('uri', '')
            if not uri:
                continue
            # Skip video files
            ext = os.path.splitext(uri)[1].lower()
            if ext in ('.mp4', '.mov', '.avi', '.mkv', '.webm'):
                skipped_videos += 1
                continue
            # Resolve to absolute path and verify it stays within the export root.
            # os.path.join with an absolute URI would escape export_root entirely,
            # and '../..' traversal could reach arbitrary files on the user's machine.
            abs_path = os.path.normpath(os.path.join(export_root, uri))
            if not abs_path.startswith(os.path.normpath(export_root) + os.sep) \
                    and abs_path != os.path.normpath(export_root):
                result.errors.append(
                    f"Entry {idx}: URI escapes export root (possible path traversal) — skipped: {uri}"
                )
                missing_images += 1
                continue
            if os.path.isfile(abs_path):
                images.append(abs_path)
            else:
                missing_images += 1
                result.errors.append(f"Entry {idx}: image not found: {uri}")

        if not images:
            result.errors.append(f"Entry {idx}: no valid images after filtering — skipped.")
            continue

        # Caption and hashtags
        caption = post_obj.get('title', '') or ''
        # Instagram sometimes encodes as Latin-1 bytes in the JSON
        if isinstance(caption, str):
            try:
                caption = caption.encode('latin-1').decode('utf-8')
            except (UnicodeDecodeError, UnicodeEncodeError):
                pass  # already proper UTF-8

        body, hashtags = _extract_hashtags(caption)

        # Timestamp — post-level, falling back to first media item
        timestamp = post_obj.get('creation_timestamp', 0) or 0
        if not timestamp and media_list:
            timestamp = media_list[0].get('creation_timestamp', 0) or 0

        # Classify
        post_type = 'carousel' if len(images) > 1 else 'single'
        if post_type == 'carousel':
            carousel_count += 1
        else:
            single_count += 1
        total_images += len(images)

        result.posts.append(ParsedPost(
            ig_timestamp=timestamp,
            caption=caption,
            body=body,
            hashtags=hashtags,
            images=images,
            post_type=post_type,
            original_index=idx,
        ))

    # Sort chronologically (oldest first).
    # Secondary key: -original_index because Instagram JSON is newest-first,
    # so higher original_index = older post = should sort earlier for ties.
    result.posts.sort(key=lambda p: (p.ig_timestamp, -p.original_index))

    result.stats = {
        'total_posts':    len(result.posts),
        'carousel_posts': carousel_count,
        'single_posts':   single_count,
        'total_images':   total_images,
        'skipped_videos': skipped_videos,
        'missing_images': missing_images,
    }

    if not result.posts:
        result.errors.append("No valid posts found in the export.")

    return result


# ---------------------------------------------------------------------------
# CLI test harness
# ---------------------------------------------------------------------------

if __name__ == '__main__':
    import sys
    folder = sys.argv[1] if len(sys.argv) > 1 else '.'
    r = parse(folder)
    print(f"\nParsed {r.stats.get('total_posts', 0)} posts "
          f"({r.stats.get('carousel_posts', 0)} carousel, "
          f"{r.stats.get('single_posts', 0)} single)")
    print(f"Total images: {r.stats.get('total_images', 0)}")
    if r.stats.get('skipped_videos'):
        print(f"Skipped videos: {r.stats['skipped_videos']}")
    for err in r.errors:
        print(f"ERROR: {err}")
    for p in r.posts[:5]:
        print(f"\n  [{p.post_type.upper()}] {len(p.images)} imgs  "
              f"ts={p.ig_timestamp}  tags={p.hashtags[:3]}")
        print(f"  caption: {p.caption[:80]}...")
    input("\nPress Enter to close...")
# ===== SNAPSMACK EOF =====
