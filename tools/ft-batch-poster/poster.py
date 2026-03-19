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
    """Category and album name→ID lookups fetched from smack-post.php."""
    categories:    Dict[str, int] = field(default_factory=dict)
    albums:        Dict[str, int] = field(default_factory=dict)
    _cat_display:  Dict[str, str] = field(default_factory=dict)
    _album_display: Dict[str, str] = field(default_factory=dict)


# ---------------------------------------------------------------------------
# SnapSmack client
# ---------------------------------------------------------------------------

class SnapSmackClient:

    def __init__(self, base_url: str):
        self.base_url = base_url.rstrip('/')
        self.session  = requests.Session()
        self.session.headers.update({'User-Agent': 'ft-batch-poster/1.0'})
        self._logged_in = False

    def login(self, username: str, password: str) -> None:
        url  = f"{self.base_url}/login.php"
        resp = self.session.post(
            url,
            data={'username': username, 'password': password},
            timeout=15,
            allow_redirects=True,
        )
        resp.raise_for_status()
        if 'login.php' in resp.url:
            raise RuntimeError("Login failed — check your username and password.")
        self._logged_in = True

    def fetch_site_data(self) -> SiteData:
        if not self._logged_in:
            raise RuntimeError("Not logged in.")
        url  = f"{self.base_url}/smack-post.php"
        resp = self.session.get(url, timeout=15)
        resp.raise_for_status()

        soup = BeautifulSoup(resp.text, 'html.parser')
        data = SiteData()

        for inp in soup.find_all('input', {'name': 'cat_ids[]'}):
            cat_id = int(inp.get('value', 0))
            span   = inp.find_next_sibling('span') or inp.find_next('span')
            name   = span.get_text(strip=True) if span else f'Category {cat_id}'
            data.categories[name.lower()] = cat_id
            data._cat_display[name.lower()] = name

        for inp in soup.find_all('input', {'name': 'album_ids[]'}):
            album_id = int(inp.get('value', 0))
            span     = inp.find_next_sibling('span') or inp.find_next('span')
            name     = span.get_text(strip=True) if span else f'Album {album_id}'
            data.albums[name.lower()] = album_id
            data._album_display[name.lower()] = name

        return data

    def fetch_theme(self) -> dict:
        """
        Fetch the active admin theme colors from the site.
        Returns a dict with keys: accent, bg, bg_card, bg_input, border, fg, fg_dim.
        Falls back to empty dict on any failure — caller must handle missing keys.
        """
        if not self._logged_in:
            return {}
        try:
            # smack-post.php is already authenticated — reuse its HTML to find the theme CSS link.
            url  = f"{self.base_url}/smack-post.php"
            resp = self.session.get(url, timeout=10)
            resp.raise_for_status()

            soup     = BeautifulSoup(resp.text, 'html.parser')
            css_link = soup.find('link', rel='stylesheet', href=re.compile(r'adminthemes/'))
            if not css_link:
                return {}

            css_url  = f"{self.base_url}/{css_link['href'].lstrip('/')}"
            css_resp = self.session.get(css_url, timeout=10)
            css_resp.raise_for_status()
            css = css_resp.text

            def _first(pattern: str) -> str:
                m = re.search(pattern, css)
                return m.group(1).strip() if m else ''

            accent   = _first(r'\.sidebar-brand\s*\{[^}]*\bcolor\s*:\s*(#[0-9a-fA-F]{3,8})')
            bg       = _first(r'html\s*,\s*body\s*\{[^}]*background-color\s*:\s*(#[0-9a-fA-F]{3,8})')
            bg_card  = _first(r'\.box\s*\{[^}]*background-color\s*:\s*(#[0-9a-fA-F]{3,8})')
            bg_input = _first(r'input\[type="text"\][^{]*\{[^}]*background-color\s*:\s*(#[0-9a-fA-F]{3,8})')
            border   = _first(r'\.sidebar\s*\{[^}]*border-right-color\s*:\s*(#[0-9a-fA-F]{3,8})')
            fg       = _first(r'html\s*,\s*body\s*\{[^}]*\bcolor\s*:\s*(#[0-9a-fA-F]{3,8})')
            fg_dim   = _first(r'\.nav-section-links\s+a\s*\{[^}]*\bcolor\s*:\s*(#[0-9a-fA-F]{3,8})')

            result = {}
            if accent:   result['accent']   = accent
            if bg:       result['bg']        = bg
            if bg_card:  result['bg_card']   = bg_card
            if bg_input: result['bg_input']  = bg_input
            if border:   result['border']    = border
            if fg:       result['fg']        = fg
            if fg_dim:   result['fg_dim']    = fg_dim
            return result
        except Exception:
            return {}

    def post_image(
        self,
        entry:            ManifestEntry,
        image_folder:     str,
        site_data:        SiteData,
        default_category: str = '',
        default_album:    str = '',
        drive_service=None,
        drive_folder_id:  str = '',
        copyright_text:   str = '',
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
            img.save(web_path, quality=92, optimize=True)

            # ── 2. Embed EXIF into web version (in place) ────────────
            exif_ok = True
            try:
                exif_writer.embed_inplace(web_path, entry.title, entry.tags, copyright_text=copyright_str)
            except Exception as e:
                exif_ok = False
                notes.insert(0, f"⚠ EXIF FAILED: {e}")

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
            form_data: Dict[str, str] = {
                'title':          entry.title,
                'tags':           entry.tags,
                'img_status':     'published',
                'desc':           copyright_str,
                'allow_download': '1' if drive_url else '0',
                'download_url':   drive_url,
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
    client:           SnapSmackClient,
    entries:          List[ManifestEntry],
    image_folder:     str,
    site_data:        SiteData,
    default_category: str,
    default_album:    str,
    on_progress:      Callable[[int, int, PostResult], None],
    drive_service=None,
    drive_folder_id:  str = '',
    copyright_text:   str = '',
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

def _mime(filename: str) -> str:
    ext = os.path.splitext(filename)[1].lower()
    return {
        '.jpg':  'image/jpeg',
        '.jpeg': 'image/jpeg',
        '.png':  'image/png',
        '.gif':  'image/gif',
        '.webp': 'image/webp',
    }.get(ext, 'application/octet-stream')
