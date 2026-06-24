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
import html
import json
import os
import re
import sys
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
    count_faves:    int          = 0            # Flickr fave count → like seed on import
    excluded:       bool         = False        # user can toggle in GUI
    comments:       List[dict]   = field(default_factory=list)  # imported Flickr comments


@dataclass
class AlbumInfo:
    flickr_id:   str
    title:       str
    description: str = ''
    photo_ids:   List[str] = field(default_factory=list)
    cover_flickr_id: str = ''                   # Flickr photo ID of album cover (from cover_photo URL)
    view_count:  int = 0                        # Flickr album view_count → snap_albums.view_count


@dataclass
class ParseResult:
    photos:  List[ParsedPhoto] = field(default_factory=list)
    albums:  List[AlbumInfo]   = field(default_factory=list)
    errors:  List[str]         = field(default_factory=list)
    stats:   dict              = field(default_factory=dict)


# ---------------------------------------------------------------------------
# Filename → Flickr ID matching
#
# Flickr exports image files under several naming conventions. Real-world
# exports almost always embed the photo *secret* between the id and the size
# code, so a simple "id immediately before size" assumption does NOT hold:
#
#   mountain_pass_53298148_o.jpg              → id 53298148  (no secret)
#   my_photo_title_53298148_k.jpg             → id 53298148  (no secret)
#   53298148_abc123def456_o.jpg               → id 53298148  (secret, no title)
#   sunset_49214987863_a1b2c3d4e5_o.jpg       → id 49214987863  (title+id+secret+size)
#
# Strategy: index every image file by ALL long numeric runs (>= 7 digits) found
# in its name. A photo's Flickr id is matched against that index. We also keep a
# basename map so we can match exactly against the `original` URL from the
# sidecar when available (the strongest possible signal). Scanning the folder
# once (rather than per photo) also turns the old O(n^2) walk into O(n).
# ---------------------------------------------------------------------------

_IMG_EXTS = ('.jpg', '.jpeg', '.png', '.gif', '.webp')

# Video files the Flickr export ships (as <id>_<size>.<ext>). These are
# intentionally NOT imported — but a sidecar whose photo has a video file on
# disk should be reported as an ignored video, not a missing photo.
_VIDEO_EXTS = ('.mp4', '.mov', '.avi', '.mkv', '.webm', '.m4v', '.3gp', '.mpg', '.mpeg', '.wmv')

# Long numeric runs that could be a Flickr photo id (ids are ~10-11 digits;
# >= 7 avoids matching years/short numbers that appear in titles).
_ID_RUN_RE = re.compile(r'\d{7,}')

# Trailing size code, e.g. ..._o.jpg → 'o'
_SIZE_RE = re.compile(r'_([a-zA-Z])\.[a-zA-Z0-9]+$')

# Prefer larger size codes first (original > k > b > h > etc.)
_SIZE_PRIORITY = {'o': 0, 'k': 1, 'b': 2, 'h': 3, 'l': 4, 'c': 5, 'z': 6, 'm': 7, 's': 8, 't': 9, 'q': 10}


def _size_priority(filename: str) -> int:
    """Lower number = higher quality. Unknown/absent size code sorts mid-pack."""
    m = _SIZE_RE.search(filename)
    code = m.group(1).lower() if m else ''
    return _SIZE_PRIORITY.get(code, 50)


def _build_image_index(folder: str):
    """
    Single pass over the export folder. Returns:
      by_id:   dict flickr_id → list of (size_priority, abs_path)
      by_name: dict lowercased_basename → abs_path
    """
    by_id: Dict[str, list]  = {}
    by_name: Dict[str, str] = {}
    try:
        names = os.listdir(folder)
    except OSError:
        return by_id, by_name
    for fname in names:
        ext = os.path.splitext(fname)[1].lower()
        if ext not in _IMG_EXTS:
            continue
        path = os.path.join(folder, fname)
        by_name[fname.lower()] = path
        prio = _size_priority(fname)
        for run in _ID_RUN_RE.findall(fname):
            by_id.setdefault(run, []).append((prio, path))
    return by_id, by_name


def _build_video_index(folder: str) -> set:
    """Flickr ids that have a VIDEO file on disk. Lets a 'missing image' that is
    really an intentionally-skipped video be reported correctly (Flickr exports
    videos as <id>_<size>.mp4/.mov/etc.)."""
    vid_ids: set = set()
    try:
        names = os.listdir(folder)
    except OSError:
        return vid_ids
    for fname in names:
        if os.path.splitext(fname)[1].lower() not in _VIDEO_EXTS:
            continue
        for run in _ID_RUN_RE.findall(fname):
            vid_ids.add(run)
    return vid_ids


def _best_image(by_id: dict, by_name: dict,
                flickr_id: str, original_url: str = '') -> Optional[str]:
    """
    Resolve the best local image file for a photo.

    1. Exact match against the `original` URL basename (strongest signal).
    2. Otherwise, the highest-quality file indexed under the photo's id.
    Returns None if nothing matches.
    """
    if original_url:
        base = os.path.basename(original_url.split('?')[0]).lower()
        if base in by_name:
            return by_name[base]
    candidates = by_id.get(str(flickr_id))
    if candidates:
        candidates.sort(key=lambda x: x[0])
        return candidates[0][1]
    return None


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
    Flickr tags are objects: {"tag": "Medicine Hat", "raw": "...", ...}.
    SnapSmack stores single-token hashtags (the server tag parser only accepts
    [a-zA-Z][a-zA-Z0-9_]*), so multi-word Flickr tags like "Medicine Hat" must
    be collapsed to "medicinehat" — which mirrors Flickr's own clean-tag form —
    rather than truncated to the first word. Returns deduped, order-preserved
    single-token tags.
    """
    tags: List[str] = []
    seen = set()
    for t in tag_list:
        if isinstance(t, dict):
            tag = t.get('tag') or t.get('raw') or ''
        elif isinstance(t, str):
            tag = t
        else:
            continue
        # Collapse to a single lowercase token: keep [a-z0-9_], drop the rest.
        tag = re.sub(r'[^a-z0-9_]+', '', tag.strip().lower())
        if tag and tag not in seen:
            seen.add(tag)
            tags.append(tag)
    return tags


# ---------------------------------------------------------------------------
# Comments
#
# Each photo sidecar carries an inline `comments` array:
#   {id, date: 'YYYY-MM-DD HH:MM:SS', user: '<NSID>', comment: '<html-escaped>', url}
# The commenter is identified only by NSID — no screen name in the comment
# record. contacts_part*.json maps display-name → profile URL; we reverse it,
# keyed on the URL's final path segment (the NSID for NSID-form profile URLs),
# so contacts resolve to a name and everyone else falls back to the NSID.
# ---------------------------------------------------------------------------

def _build_contacts_map(export_folder: str) -> Dict[str, str]:
    """Map Flickr NSID → display name from contacts_part*.json (best effort)."""
    result: Dict[str, str] = {}
    for path in glob.glob(os.path.join(export_folder, 'contacts_part*.json')):
        try:
            with open(path, 'r', encoding='utf-8') as f:
                data = json.load(f)
        except Exception:
            continue
        contacts = data.get('contacts', {}) if isinstance(data, dict) else {}
        if not isinstance(contacts, dict):
            continue
        for name, url in contacts.items():
            seg = str(url).rstrip('/').rsplit('/', 1)[-1]
            if seg and seg not in result:
                result[seg] = name
    return result


# Optional hand-maintained ID → name sidecar. Most commenters aren't in your
# contacts and the export carries no screen names for them, so this lets you
# name the people who matter. Keys = Flickr NSID (e.g. 196612229@N04) or profile
# slug; values = the display name. Keys starting with '_' are ignored (notes).
NAME_SIDECAR_FILENAME = 'flkrfckr-names.json'


def _app_dir() -> str:
    """Directory of the running exe (frozen) or this script (dev)."""
    if getattr(sys, 'frozen', False):
        return os.path.dirname(sys.executable)
    return os.path.dirname(os.path.abspath(__file__))


def _load_name_sidecar(folder: str) -> Dict[str, str]:
    """Load a flkrfckr-names.json name map from a folder (empty if absent/bad)."""
    path = os.path.join(folder, NAME_SIDECAR_FILENAME)
    if not os.path.isfile(path):
        return {}
    try:
        with open(path, 'r', encoding='utf-8') as f:
            data = json.load(f)
    except Exception:
        return {}
    if not isinstance(data, dict):
        return {}
    return {str(k): str(v).strip()
            for k, v in data.items()
            if not str(k).startswith('_') and str(v).strip()}


def _build_name_map(export_folder: str) -> Dict[str, str]:
    """
    NSID/slug → display name for comment authors.
    Precedence (later overrides earlier):
      1. Flickr contacts_part*.json
      2. flkrfckr-names.json next to the app
      3. flkrfckr-names.json in the export folder (most specific wins)
    """
    name_map = _build_contacts_map(export_folder)
    name_map.update(_load_name_sidecar(_app_dir()))
    name_map.update(_load_name_sidecar(export_folder))
    return name_map


def _parse_comments(raw_list: list, contacts_map: Dict[str, str]) -> List[dict]:
    """
    Turn a photo's inline Flickr comments into import-ready dicts:
      {author_name, author_url, text, date}
    Resolves the name from contacts where possible, else uses the NSID.
    HTML entities in the comment body are decoded.
    """
    out: List[dict] = []
    for c in raw_list or []:
        if not isinstance(c, dict):
            continue
        text = html.unescape(str(c.get('comment', '') or '')).strip()
        if not text:
            continue
        nsid = str(c.get('user', '') or '').strip()
        name = (contacts_map.get(nsid) if nsid else '') or nsid or 'Flickr member'
        url  = f'https://www.flickr.com/people/{nsid}/' if nsid else ''
        out.append({
            'author_name': name,
            'author_url':  url,
            'text':        text,
            'date':        str(c.get('date', '') or '').strip(),
        })
    return out


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

def parse(export_folder: str, on_progress=None) -> ParseResult:
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
                # Cover photo: Flickr exports it as a URL whose final path
                # segment is the photo ID (".../photos/<user>/<id>"). A trailing
                # "0" (or empty) means no cover was set on the album.
                cover_url = str(a.get('cover_photo', '') or '').rstrip('/')
                cover_id  = cover_url.rsplit('/', 1)[-1] if cover_url else ''
                if cover_id in ('', '0'):
                    cover_id = ''
                info = AlbumInfo(
                    flickr_id=aid,
                    title=a.get('title', '').strip() or f'Album {aid}',
                    description=a.get('description', '').strip(),
                    photo_ids=[str(pid) for pid in a.get('photos', [])],
                    cover_flickr_id=cover_id,
                    view_count=int(a.get('view_count') or 0),
                )
                album_map[aid] = info
                result.albums.append(info)
        except Exception as e:
            result.errors.append(f"Could not parse albums.json: {e}")
    # No albums.json is fine — account may have no photosets.

    # ── 1b. Count collections (Flickr galleries → SnapSmack Collections) ──────
    collections_count = 0
    galleries_path = os.path.join(export_folder, 'galleries.json')
    if os.path.isfile(galleries_path):
        try:
            with open(galleries_path, 'r', encoding='utf-8') as f:
                galleries_raw = json.load(f)
            collections_count = len(galleries_raw.get('galleries', []))
        except Exception as e:
            result.errors.append(f"Could not parse galleries.json: {e}")

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
    skipped_videos  = 0

    # Index every image file in the folder once (id → files, basename → file).
    img_by_id, img_by_name = _build_image_index(export_folder)
    # Ids that have a video file on disk — used to distinguish ignored videos
    # from genuinely missing photos below.
    video_ids = _build_video_index(export_folder)

    # Map NSID → display name for resolving comment authors (contacts +
    # the hand-maintained flkrfckr-names.json sidecar).
    name_map = _build_name_map(export_folder)

    total_sidecars = len(sidecar_files)
    for _idx, sidecar_path in enumerate(sidecar_files):
        if on_progress and (_idx % 100 == 0):
            try:
                on_progress(_idx, total_sidecars)
            except Exception:
                pass
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

        # Dates — Flickr exports use 'date_taken' (string) and 'date_imported'
        # (string) as the upload time. Older/other exports may carry epoch
        # 'date_upload'/'create_date' fields, so handle both shapes as fallback.
        date_taken  = _parse_date_taken(data.get('date_taken', ''))
        create_date = (
            _parse_date_taken(data.get('date_imported', ''))
            or _parse_epoch(data.get('create_date') or data.get('date_upload'))
        )

        # Tags
        tags = _parse_tags(data.get('tags', []))

        # Flickr fave count → seed the post's like tally at import time.
        try:
            count_faves = int(data.get('count_faves', 0) or 0)
        except (TypeError, ValueError):
            count_faves = 0

        # Album memberships — listed in sidecar as array of {id, title} objects
        album_ids: List[str] = []
        for a in data.get('albums', []):
            aid = str(a.get('id', ''))
            if aid:
                album_ids.append(aid)

        # Geo — may be absent ([]), a dict, or a list with one dict.
        geo = None
        geo_data = data.get('geo')
        if isinstance(geo_data, list):
            geo_data = geo_data[0] if geo_data else None
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

        # Original format — there is usually no 'original_format' field; derive
        # it from the 'original' download URL extension when present.
        original_url = str(data.get('original', '') or '').strip()
        original_format = str(data.get('original_format', '') or '').lower().strip('.')
        if not original_format and original_url:
            original_format = os.path.splitext(original_url.split('?')[0])[1].lower().strip('.')
        if not original_format:
            original_format = 'jpg'
        if original_format not in ('jpg', 'jpeg', 'png', 'gif', 'webp'):
            # Could be a video — skip
            if original_format in ('mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v'):
                skipped_videos += 1
                continue
            original_format = 'jpg'

        # Find best image file on disk
        image_path = _best_image(img_by_id, img_by_name, flickr_id, original_url)
        missing = image_path is None
        if missing:
            # No image on disk, but a video file with this id IS present → this
            # is an intentionally-ignored video, not a lost photo. Count it as a
            # skipped video and drop it from the import set (matches how videos
            # detected via original_format are handled above).
            if str(flickr_id) in video_ids:
                skipped_videos += 1
                continue
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
            count_faves=count_faves,
            comments=_parse_comments(data.get('comments', []), name_map),
        ))

    if on_progress:
        try:
            on_progress(len(sidecar_files), len(sidecar_files))
        except Exception:
            pass

    # Sort oldest-first by best available date
    result.photos.sort(key=_best_date)

    result.stats = {
        'total_photos':       len(result.photos),
        'total_albums':       len(result.albums),
        'albums_with_covers': sum(1 for a in result.albums if a.cover_flickr_id),
        'total_collections':  collections_count,
        'total_comments':     sum(len(p.comments) for p in result.photos),
        'total_likes':        sum(p.count_faves for p in result.photos),
        'missing_images':     missing_images,
        'skipped_videos':     skipped_videos,
        'private_photos':     sum(1 for p in result.photos if p.privacy != 'public'),
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
