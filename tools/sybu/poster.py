"""
ft-batch-poster — poster.py
Handles all communication with SnapSmack and Google Drive, plus
image resizing and renaming. Full per-image workflow:

  1. Resize original → 1900×1425 web version with haiku filename
  2. Embed EXIF into web version (in place)
  3. Embed EXIF into temp copy of original for Drive upload
  4. Upload original (EXIF'd) to Google Drive → get share link
  5. POST web version to SnapSmack with Drive link, title, tags, cat, album
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.




import os
import re
import shutil
import tempfile
from dataclasses import dataclass, field
from typing import Callable, Dict, List, Optional

import requests
from bs4 import BeautifulSoup
from PIL import Image as PILImage

import exif_writer
from exif_writer import COPYRIGHT as _DEFAULT_COPYRIGHT
from manifest_parser import ManifestEntry

WEB_MAX_W = 1900
WEB_MAX_H = 1425


# ---------------------------------------------------------------------------
# Filename helper
# ---------------------------------------------------------------------------

def haiku_to_filename(title: str, ext: str) -> str:
    """
    Turn a haiku title into a safe Windows filename, preserving spaces and commas.
    e.g. "Red minerals bloom, blue veins trace the ancient skin, time carves out the stone"
         → "Red minerals bloom, blue veins trace the ancient skin, time carves out the stone.jpg"
    """
    invalid = r'\/:*?"<>|'
    clean = ''.join(c for c in title if c not in invalid).strip()
    if not clean:
        clean = 'untitled'
    return f"{clean}{ext}"


# ---------------------------------------------------------------------------
# Data structures
# ---------------------------------------------------------------------------

@dataclass
class PostResult:
    entry:    ManifestEntry
    success:  bool
    message:  str
    web_path:  str  = ''    # path to the saved web version
    drive_url: str  = ''    # Google Drive share link (if uploaded)
    exif_ok:   bool = True  # False if EXIF embedding failed


@dataclass
class SiteData:
    """Category and album name→ID lookups, descriptions, tag list, and title list from the site."""
    categories:      Dict[str, int] = field(default_factory=dict)
    albums:          Dict[str, int] = field(default_factory=dict)
    _cat_display:    Dict[str, str] = field(default_factory=dict)
    _album_display:  Dict[str, str] = field(default_factory=dict)
    cat_descriptions:   Dict[str, str] = field(default_factory=dict)  # lower_name → description
    album_descriptions: Dict[str, str] = field(default_factory=dict)  # lower_name → description
    tags:            List[str]          = field(default_factory=list)  # existing hashtags
    titles:          List[str]          = field(default_factory=list)  # existing post titles


# ---------------------------------------------------------------------------
# SnapSmack client
# ---------------------------------------------------------------------------

class SnapSmackClient:

    def __init__(self, base_url: str, api_key: str = ''):
        self.base_url = base_url.rstrip('/')
        self.session  = requests.Session()
        self.session.headers.update({
            'User-Agent': 'SYBU/1.0',
            'X-Snap-Key': api_key,
        })
        self._api_key = api_key

    def verify(self) -> None:
        """
        Verify the API key by hitting sybu-data.php.
        Raises RuntimeError if the key is rejected or the server is unreachable.
        """
        resp = self.session.get(
            f"{self.base_url}/sybu-data.php",
            timeout=15,
        )
        if resp.status_code == 401:
            raise RuntimeError("Invalid API key — check Settings and regenerate if needed.")
        if resp.status_code == 403:
            raise RuntimeError("Access denied (403). Check server configuration.")
        resp.raise_for_status()

    def keepalive(self) -> bool:
        """No-op — API key auth has no session to keep alive. Always returns True."""
        return True

    # ------------------------------------------------------------------
    # Audit / Repair API (smack-audit.php)
    # ------------------------------------------------------------------

    def audit_summary(self) -> dict:
        """Return post counts and duplicate-title stats from smack-audit.php."""
        r = self.session.get(
            f"{self.base_url}/smack-audit.php",
            params={'action': 'summary'},
            timeout=15,
        )
        r.raise_for_status()
        data = r.json()
        if not data.get('ok'):
            raise RuntimeError(data.get('error', 'Audit summary failed'))
        return data

    def audit_list(self) -> list:
        """Return all published posts as a list of dicts (id, title, download_url…)."""
        r = self.session.get(
            f"{self.base_url}/smack-audit.php",
            params={'action': 'list'},
            timeout=(10, 180),  # 10s connect, 3 min read — large sites return 1000+ rows
        )
        r.raise_for_status()
        data = r.json()
        if not data.get('ok'):
            raise RuntimeError(data.get('error', 'Audit list failed'))
        return data.get('posts', [])

    def audit_update_title(self, snap_id: int, new_title: str) -> None:
        """Update the title of a published post."""
        r = self.session.post(
            f"{self.base_url}/smack-audit.php",
            data={'action': 'update_title',
                  'snap_id':   snap_id,
                  'new_title': new_title},
            timeout=15,
        )
        r.raise_for_status()
        data = r.json()
        if not data.get('ok'):
            raise RuntimeError(data.get('error', 'Title update failed'))

    def backfill_update_link(self, snap_id: int, download_url: str) -> None:
        """Write a Drive share URL back to a post via smack-backfill.php."""
        r = self.session.post(
            f'{self.base_url}/smack-backfill.php',
            data={'action': 'update', 'snap_id': snap_id,
                  'download_url': download_url},
            timeout=15,
        )
        r.raise_for_status()
        try:
            data = r.json()
        except Exception:
            raise RuntimeError(
                f'Server returned non-JSON (likely a login redirect). '
                f'Response: {r.text[:200]}'
            )
        if not data.get('ok'):
            raise RuntimeError(data.get('error', 'Drive link update failed'))

    # ------------------------------------------------------------------

    def fetch_site_data(self) -> SiteData:
        if not self._logged_in:
            raise RuntimeError("Not logged in.")

        data = SiteData()

        # ── Rich metadata from sybu-data.php (categories + descriptions + tags) ──
        try:
            api_resp = self.session.get(
                f"{self.base_url}/sybu-data.php", timeout=15)
            if api_resp.status_code == 200:
                payload = api_resp.json()
                for cat in payload.get('categories', []):
                    key = cat['name'].lower()
                    data.categories[key]        = cat['id']
                    data._cat_display[key]       = cat['name']
                    data.cat_descriptions[key]   = cat.get('description', '')
                for album in payload.get('albums', []):
                    key = album['name'].lower()
                    data.albums[key]              = album['id']
                    data._album_display[key]       = album['name']
                    data.album_descriptions[key]   = album.get('description', '')
                data.tags    = payload.get('tags', [])
                data.titles  = payload.get('titles', [])
                return data
        except Exception:
            pass  # Fall through to HTML scrape if endpoint unavailable

        # ── Fallback: scrape smack-post.php HTML (older server versions) ─────────
        url  = f"{self.base_url}/smack-post.php"
        resp = self.session.get(url, timeout=15)
        resp.raise_for_status()
        soup = BeautifulSoup(resp.text, 'html.parser')

        for inp in soup.find_all('input', {'name': 'cat_ids[]'}):
            cat_id = int(inp.get('value', 0))
            span   = inp.find_next_sibling('span') or inp.find_next('span')
            name   = span.get_text(strip=True) if span else f'Category {cat_id}'
            data.categories[name.lower()]      = cat_id
            data._cat_display[name.lower()]     = name

        for inp in soup.find_all('input', {'name': 'album_ids[]'}):
            album_id = int(inp.get('value', 0))
            span     = inp.find_next_sibling('span') or inp.find_next('span')
            name     = span.get_text(strip=True) if span else f'Album {album_id}'
            data.albums[name.lower()]           = album_id
            data._album_display[name.lower()]    = name

        return data

    def post_image(
        self,
        entry:               ManifestEntry,
        image_folder:        str,
        site_data:           SiteData,
        default_category:    str = '',
        default_album:       str = '',
        default_orientation: str = 'auto',
        drive_service=None,
        drive_folder_id:     str = '',
        copyright_text:      str = '',
    ) -> PostResult:
        """
        Full workflow for one image. Returns PostResult — never raises.
        """
        src_path  = os.path.join(image_folder, entry.file)
        ext       = os.path.splitext(entry.file)[1] or '.jpg'
        web_path  = ''
        tmp_drive = ''
        notes     = []

        copyright_str = copyright_text or _DEFAULT_COPYRIGHT

        try:
            # ── 1. Resize → web version ───────────────────────────────
            web_folder = os.path.join(image_folder, 'web')
            os.makedirs(web_folder, exist_ok=True)

            new_filename = haiku_to_filename(entry.title, ext)
            web_path     = os.path.join(web_folder, new_filename)

            img = PILImage.open(src_path)
            img.thumbnail((WEB_MAX_W, WEB_MAX_H), PILImage.LANCZOS)

            # ── 2. Build EXIF and embed during save (single step, reliable) ──
            exif_ok = True
            try:
                exif_bytes = exif_writer.build_exif_bytes(entry.title, entry.tags, copyright_str)
                img.save(web_path, quality=92, optimize=True, exif=exif_bytes)
            except Exception as e:
                exif_ok = False
                notes.insert(0, f"⚠ EXIF FAILED: {e}")
                img.save(web_path, quality=92, optimize=True)  # save without EXIF as fallback

            # ── 3. Upload original to Google Drive ────────────────────
            drive_url = ''
            if drive_service is not None:
                try:
                    # Embed EXIF into a temp copy of the original for Drive
                    try:
                        tmp_drive = exif_writer.embed(src_path, entry.title, entry.tags, copyright_text=copyright_str)
                        upload_src = tmp_drive
                    except Exception as e:
                        upload_src = src_path
                        notes.append(f"EXIF skipped on Drive copy: {e}")

                    import drive as drive_module
                    drive_url = drive_module.upload(
                        service=drive_service,
                        file_path=upload_src,
                        filename=new_filename,
                        folder_id=drive_folder_id or None,
                    )
                except Exception as e:
                    notes.append(f"Drive upload failed: {e}")
                finally:
                    if tmp_drive and os.path.isfile(tmp_drive):
                        try:
                            os.unlink(tmp_drive)
                        except OSError:
                            pass

            # ── 4. Resolve category and album IDs ─────────────────────
            cat_name   = entry.category or default_category
            album_name = entry.album    or default_album

            cat_id   = site_data.categories.get(cat_name.lower())   if cat_name   else None
            album_id = site_data.albums.get(album_name.lower())     if album_name else None

            if cat_name and cat_id is None:
                notes.append(f"category \"{cat_name}\" not matched")
            if album_name and album_id is None:
                notes.append(f"album \"{album_name}\" not matched")

            # ── 5. POST to SnapSmack ──────────────────────────────────
            orient = entry.orientation if entry.orientation != 'auto' else default_orientation

            # Merge colour hex codes into hashtags so they become searchable tags
            post_tags = entry.tags
            if entry.colors:
                hex_tags = ' '.join(entry.colors.split())
                if hex_tags:
                    post_tags = f"{post_tags} {hex_tags}" if post_tags else hex_tags

            form_data: Dict[str, str] = {
                'title':                entry.title,
                'tags':                 post_tags,
                'img_status':           'published',
                'desc':                 copyright_str,
                'allow_download':       '1' if drive_url else '0',
                'download_url':         drive_url,
                'orientation_override': orient,
                'source_file':          entry.file,   # original filename — stored in img_source_file
                'img_ai_colors':        entry.colors, # space-separated hex codes from Gemini
            }
            if cat_id is not None:
                form_data['cat_ids[]'] = str(cat_id)
            if album_id is not None:
                form_data['album_ids[]'] = str(album_id)

            with open(web_path, 'rb') as img_f:
                files = {'img_file': (new_filename, img_f, _mime(new_filename))}
                resp  = self.session.post(
                    f"{self.base_url}/smack-post.php",
                    data=form_data,
                    files=files,
                    timeout=120,
                )
            resp.raise_for_status()

            msg = "Posted"
            if drive_url:
                msg += " + Drive"
            if notes:
                msg += f" ({'; '.join(notes)})"

            return PostResult(entry, True, msg, web_path=web_path, drive_url=drive_url, exif_ok=exif_ok)

        except requests.RequestException as e:
            return PostResult(entry, False, f"Network error: {e}", web_path=web_path)
        except Exception as e:
            return PostResult(entry, False, str(e), web_path=web_path)


# ---------------------------------------------------------------------------
# Batch runner (background thread)
# ---------------------------------------------------------------------------

def run_batch(
    client:              SnapSmackClient,
    entries:             List[ManifestEntry],
    image_folder:        str,
    site_data:           SiteData,
    default_category:    str,
    default_album:       str,
    default_orientation: str = 'auto',
    on_progress:         Callable[[int, int, PostResult], None] = None,
    drive_service=None,
    drive_folder_id:     str = '',
    copyright_text:      str = '',
) -> List[PostResult]:
    results = []
    total   = len(entries)

    for i, entry in enumerate(entries, start=1):
        result = client.post_image(
            entry=entry,
            image_folder=image_folder,
            site_data=site_data,
            default_category=default_category,
            default_album=default_album,
            default_orientation=default_orientation,
            drive_service=drive_service,
            drive_folder_id=drive_folder_id,
            copyright_text=copyright_text,
        )
        results.append(result)
        on_progress(i, total, result)

    return results


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _mime(path: str) -> str:
    ext = os.path.splitext(path)[1].lower()
    return {
        '.jpg':  'image/jpeg',
        '.jpeg': 'image/jpeg',
        '.png':  'image/png',
        '.gif':  'image/gif',
        '.webp': 'image/webp',
    }.get(ext, 'image/jpeg')
# ===== SNAPSMACK EOF =====
