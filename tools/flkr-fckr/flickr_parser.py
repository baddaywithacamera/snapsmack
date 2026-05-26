"""
FLKR FCKR — flickr_parser.py
Parses a Flickr data export folder into ParsedPhoto objects
ready for migration to SnapSmack.

Flickr export structure (after unzip):
  albums.json                — array of photosets with photo_ids[]
  photo_{ID}.json            — one sidecar per photo: title, description,
                               date_taken, tags[], albums[], geo, privacy
  {title}_{ID}_{size}.{ext}  — image files, ID before the last size suffix

Forked from tools/unzucker/ig_parser.py — IG-specific logic replaced
with Flickr-specific logic. ParsedPhoto mirrors ParsedPost structure.
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.


import glob
import json
import os
import re
from dataclasses import dataclass, field
from datetime import datetime
from typing import Dict, List, Optional, Tuple


# ---------------------------------------------------------------------------
# Data structures
# ---------------------------------------------------------------------------

@dataclass
class ParsedPhoto:
    flickr_id:      str
    title:          str          = ''
    description:    str          = ''
    date_taken:     Optional[datetime] = None   # from date_taken field
    create_date:    Optional[datetime] = None   # from create_date (epoch) — fallback
    original_format: str         = 'jpg'
    tags:           List[str]    = field(default_factory=list)
    album_ids:      List[str]    = field(default_factory=list)  # Flickr album IDs
    geo:            Optional[Tuple[float, float]] = None        # (lat, lon)
    image_path:     str          = ''           # absolute path to best-quality image
    missing_image:  bool         = False        # True if no image file found
    privacy:        str          = 'public'     # 'public', 'private', 'friends', etc.
    license:        str          = ''
    excluded:       bool         = False        # user can toggle in GUI


@dataclass
class AlbumInfo:
    flickr_id:   str
    title:       str
    description: str = ''
    photo_ids:   List[str] = field(default_factory=list)


@dataclass
class ParseResult:
    photos:  List[ParsedPhoto] = field(default_factory=list)
    albums:  List[AlbumInfo]   = field(default_factory=list)
    errors:  List[str]         = field(default_factory=list)
    stats:   dict              = field(default_factory=dict)


# ---------------------------------------------------------------------------
# Filename → Flickr ID extraction
#
# Flickr exports image files as: {title}_{photo_id}_{size_code}.{ext}
# The photo_id is the numeric string immediately before the last
# underscore+size_code suffix. Size codes are single letters: o, k, b, h, etc.
#
# Examples:
#   mountain_pass_53298148_o.jpg      → ID 53298148
#   53298148_abc123def456_o.jpg       → ID 53298148  (no title prefix)
#   my_photo_title_53298148_k.jpg     → ID 53298148
#
# Strategy: find the last occurrence of _(\d+)_[a-z]\. in the filename.
# ---------------------------------------------------------------------------

_FLICKR_ID_RE = re.compile(r'_(\d+)_[a-zA-Z]\.[a-zA-Z0-9]+$')

# Prefer larger size codes first (original > k > b > h > etc.)
_SIZE_PRIORITY = {'o': 0, 'k': 1, 'b': 2, 'h': 3, 'l': 4, 'c': 5, 'z': 6, 'm': 7, 's': 8, 't': 9, 'q': 10}


def _extract_flickr_id(filename: str) -> Optional[str]:
    """Extract Flickr photo ID from a filename. Returns None if not found."""
    m = _FLICKR_ID_RE.search(filename)
    return m.group(1) if m else None


def _size_code(filename: str) -> str:
    """Extract the size code character from a Flickr filename."""
    m = re.search(r'_([a-zA-Z])\.[a-zA-Z0-9]+$', filename)
    return m.group(1).lower() if m else 'z'


def _best_image_for_id(folder: str, flickr_id: str) -> Optional[str]:
    """
    Among all image files in folder whose name contains the given Flickr ID,
    return the path to the highest-quality version (lowest size priority number).
    Returns None if no file found.
    """
    candidates = []
    for fname in os.listdir(folder):
        ext = os.path.splitext(fname)[1].lower()
        if ext not in ('.jpg', '.jpeg', '.png', '.gif', '.webp'):
            continue
        fid = _extract_flickr_id(fname)
        if fid == flickr_id:
            priority = _SIZE_PRIORITY.get(_size_code(fname), 99)
            candidates.append((priority, os.path.join(folder, fname)))
    if not candidates:
        return None
    candidates.sort(key=lambda x: x[0])
    return candidates[0][1]


# ---------------------------------------------------------------------------
# Date parsing
# ---------------------------------------------------------------------------

def _parse_date_taken(s: str) -> Optional[datetime]:
    """
    Parse Flickr date_taken string: 'YYYY-MM-DD HH:MM:SS'
    Returns None on failure.
    """
    if not s:
        return None
    try:
        return datetime.strptime(s.strip(), '%Y-%m-%d %H:%M:%S')
    except ValueError:
        # Try date-only fallback
        try:
            return datetime.strptime(s.strip()[:10], '%Y-%m-%d')
        except ValueError:
            return None


def _parse_epoch(v) -> Optional[datetime]:
    """Convert Unix epoch (int or string) to datetime. Returns None on failure."""
    try:
        return datetime.utcfromtimestamp(int(v))
    except (TypeError, ValueError, OSError):
        return None


def _best_date(photo: ParsedPhoto) -> datetime:
    """Return the best available date for sorting — date_taken preferred, create_date fallback."""
    if photo.date_taken:
        return photo.date_taken
    if photo.create_date:
        return photo.create_date
    return datetime(1970, 1, 1)


# ---------------------------------------------------------------------------
# Tag extraction
# ---------------------------------------------------------------------------

def _parse_tags(tag_list: list) -> List[str]:
    """
    Flickr tags are objects: {"id": "...", "tag": "landscape", "raw": "Landscape"}
    Returns a list of cleaned lowercase tag strings.
    """
    tags = []
    for t in tag_list:
        if isinstance(t, dict):
            tag = t.get('tag') or t.get('raw') or ''
        elif isinstance(t, str):
            tag = t
        else:
            continue
        tag = tag.strip().lower()
        if tag:
            tags.append(tag)
    return tags


# ---------------------------------------------------------------------------
# Description cleaning
#
# Flickr descriptions may contain HTML (links, line breaks). Strip it for
# plain-text storage in SnapSmack. Users can re-add markup post-import.
# ---------------------------------------------------------------------------

_HTML_TAG_RE = re.compile(r'<[^>]+>')

def _strip_html(s: str) -> str:
    s = _HTML_TAG_RE.sub('', s)
    # Normalise whitespace
    s = re.sub(r'\n\s*\n', '\n', s).strip()
    return s


# ---------------------------------------------------------------------------
# Core parse function
# ---------------------------------------------------------------------------

def parse(export_folder: str) -> ParseResult:
    """
    Parse a Flickr export folder.

    Expects the folder to contain:
      - albums.json   (may be absent for accounts with no albums)
      - photo_{ID}.json files (one per photo)
      - Image files matching the Flickr naming convention

    Returns a ParseResult with photos sorted oldest-first.
    """
    result = ParseResult()

    if not os.path.isdir(export_folder):
        result.errors.append(f"Folder not found: {export_folder}")
        return result

    # ── 1. Parse albums.json ─────────────────────────────────────────────────
    album_map: Dict[str, AlbumInfo] = {}  # flickr_id → AlbumInfo

    albums_path = os.path.join(export_folder, 'albums.json')
    if os.path.isfile(albums_path):
        try:
            with open(albums_path, 'r', encoding='utf-8') as f:
                albums_raw = json.load(f)
            for a in albums_raw.get('albums', []):
                aid = str(a.get('id', ''))
                if not aid:
                    continue
                info = AlbumInfo(
                    flickr_id=aid,
                    title=a.get('title', '').strip() or f'Album {aid}',
                    description=a.get('description', '').strip(),
                    photo_ids=[str(pid) for pid in a.get('photos', [])],
                )
                album_map[aid] = info
                result.albums.append(info)
        except Exception as e:
            result.errors.append(f"Could not parse albums.json: {e}")
    # No albums.json is fine — account may have no photosets.

    # ── 2. Parse photo sidecar files ─────────────────────────────────────────
    sidecar_pattern = os.path.join(export_folder, 'photo_*.json')
    sidecar_files = glob.glob(sidecar_pattern)

    if not sidecar_files:
        result.errors.append(
            "No photo_*.json sidecar files found. "
            "Make sure you selected the root of the unzipped Flickr export folder."
        )
        return result

    missing_images = 0
    skipped_private = 0
    skipped_videos  = 0

    for sidecar_path in sidecar_files:
        try:
            with open(sidecar_path, 'r', encoding='utf-8') as f:
                data = json.load(f)
        except Exception as e:
            result.errors.append(f"Skipping {os.path.basename(sidecar_path)}: {e}")
            continue

        flickr_id = str(data.get('id', ''))
        if not flickr_id:
            # Try to derive from filename: photo_12345678.json
            m = re.match(r'photo_(\d+)\.json', os.path.basename(sidecar_path))
            if m:
                flickr_id = m.group(1)
        if not flickr_id:
            result.errors.append(f"No ID in {os.path.basename(sidecar_path)} — skipped.")
            continue

        # Privacy — note in ParsedPhoto, let UI/poster decide import status
        privacy = str(data.get('privacy', 'public')).lower()

        # Title and description
        title = _strip_html(str(data.get('name', '') or '').strip())
        if not title:
            title = f'Photo {flickr_id}'
        description = _strip_html(str(data.get('description', '') or '').strip())

        # Dates
        date_taken  = _parse_date_taken(data.get('date_taken', ''))
        create_date = _parse_epoch(data.get('create_date') or data.get('date_upload'))

        # Tags
        tags = _parse_tags(data.get('tags', []))

        # Album memberships — listed in sidecar as array of {id, title} objects
        album_ids: List[str] = []
        for a in data.get('albums', []):
            aid = str(a.get('id', ''))
            if aid:
                album_ids.append(aid)

        # Geo
        geo = None
        geo_data = data.get('geo')
        if isinstance(geo_data, dict):
            try:
                lat = float(geo_data.get('latitude', 0))
                lon = float(geo_data.get('longitude', 0))
                if lat != 0 or lon != 0:
                    geo = (lat, lon)
            except (TypeError, ValueError):
                pass

        # License
        license_str = str(data.get('license', '') or '').strip()

        # Original format
        original_format = str(data.get('original_format', 'jpg') or 'jpg').lower().strip('.')
        if original_format not in ('jpg', 'jpeg', 'png', 'gif', 'webp'):
            # Could be a video — skip
            if original_format in ('mp4', 'mov', 'avi', 'mkv', 'webm'):
                skipped_videos += 1
                continue
            original_format = 'jpg'

        # Find best image file on disk
        image_path = _best_image_for_id(export_folder, flickr_id)
        missing = image_path is None
        if missing:
            missing_images += 1
            # Include in result but flagged — user sees warning in UI
            image_path = ''

        result.photos.append(ParsedPhoto(
            flickr_id=flickr_id,
            title=title,
            description=description,
            date_taken=date_taken,
            create_date=create_date,
            original_format=original_format,
            tags=tags,
            album_ids=album_ids,
            geo=geo,
            image_path=image_path,
            missing_image=missing,
            privacy=privacy,
            license=license_str,
        ))

    # Sort oldest-first by best available date
    result.photos.sort(key=_best_date)

    result.stats = {
        'total_photos':    len(result.photos),
        'total_albums':    len(result.albums),
        'missing_images':  missing_images,
        'skipped_videos':  skipped_videos,
        'private_photos':  sum(1 for p in result.photos if p.privacy != 'public'),
    }

    if not result.photos:
        result.errors.append("No valid photos found in the export.")

    return result


# ---------------------------------------------------------------------------
# CLI test harness
# ---------------------------------------------------------------------------

if __name__ == '__main__':
    import sys
    folder = sys.argv[1] if len(sys.argv) > 1 else '.'
    r = parse(folder)
    print(f"\nParsed {r.stats.get('total_photos', 0)} photos "
          f"across {r.stats.get('total_albums', 0)} albums")
    if r.stats.get('missing_images'):
        print(f"Missing image files: {r.stats['missing_images']}")
    if r.stats.get('skipped_videos'):
        print(f"Skipped videos: {r.stats['skipped_videos']}")
    if r.stats.get('private_photos'):
        print(f"Private photos: {r.stats['private_photos']}")
    for err in r.errors:
        print(f"ERROR: {err}")
    for p in r.photos[:5]:
        print(f"\n  [{p.flickr_id}] {p.title[:50]}")
        print(f"  date_taken={p.date_taken}  tags={p.tags[:3]}")
        print(f"  image={'MISSING' if p.missing_image else p.image_path[-40:]}")
    input("\nPress Enter to close...")
# ===== SNAPSMACK EOF =====
