"""
Unzucker — poster.py
SnapSmack API client. Forked from ft-batch-poster/poster.py.

Changes from the batch poster version:
  - Removed ManifestEntry dependency (uses ParsedPost from ig_parser)
  - Added create_carousel_post() for multi-image posts
  - Added create_single_post() using server-side image paths (FTP'd, not uploaded)
  - Removed Google Drive upload from post workflow
  - Image resize + EXIF pipeline moved to prepare_images() helper
"""

import os
import re
import secrets
import shutil
import tempfile
from dataclasses import dataclass, field
from datetime import datetime
from typing import Callable, Dict, List, Optional

import requests
from bs4 import BeautifulSoup
from PIL import Image as PILImage

import exif_writer

WEB_MAX_W = 1900
WEB_MAX_H = 1425


# ---------------------------------------------------------------------------
# Data structures
# ---------------------------------------------------------------------------

@dataclass
class PostResult:
    post_index: int
    success:    bool
    message:    str
    post_type:  str  = ''
    exif_ok:    bool = True


@dataclass
class SiteData:
    """Category and album name→ID lookups fetched from smack-post.php."""
    categories:     Dict[str, int] = field(default_factory=dict)
    albums:         Dict[str, int] = field(default_factory=dict)
    _cat_display:   Dict[str, str] = field(default_factory=dict)
    _album_display: Dict[str, str] = field(default_factory=dict)


# ---------------------------------------------------------------------------
# SnapSmack client
# ---------------------------------------------------------------------------

class SnapSmackClient:

    def __init__(self, base_url: str):
        self.base_url = base_url.rstrip('/')
        self.session  = requests.Session()
        self.session.headers.update({'User-Agent': 'unzucker/1.0'})
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

    def is_session_alive(self) -> bool:
        if not self._logged_in:
            return False
        try:
            resp = self.session.get(
                f"{self.base_url}/smack-admin.php",
                timeout=10,
                allow_redirects=True,
            )
            return 'login.php' not in resp.url
        except Exception:
            return False

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

    # ------------------------------------------------------------------
    # Post creation — carousel (multi-image)
    # ------------------------------------------------------------------

    def create_carousel_post(
        self,
        title:         str,
        body:          str,
        tags:          str,
        image_paths:   List[str],   # server-relative paths (already FTP'd)
        category_id:   Optional[int] = None,
        album_id:      Optional[int] = None,
        post_date:     Optional[str] = None,  # 'YYYY-MM-DD HH:MM:SS'
    ) -> None:
        """
        Create a multi-image carousel post via smack-post-carousel.php.
        image_paths are server-relative paths to already-uploaded images.
        """
        if not self._logged_in:
            raise RuntimeError("Not logged in.")

        form_data = {
            'title':       title,
            'body':        body,
            'tags':        tags,
            'post_status': 'published',
        }

        # Image paths as a JSON-encoded list or repeated form fields
        for i, path in enumerate(image_paths):
            form_data[f'images[{i}]'] = path

        if category_id is not None:
            form_data['cat_ids[]'] = str(category_id)
        if album_id is not None:
            form_data['album_ids[]'] = str(album_id)
        if post_date:
            form_data['post_date'] = post_date

        resp = self.session.post(
            f"{self.base_url}/smack-post-carousel.php",
            data=form_data,
            timeout=120,
        )
        resp.raise_for_status()

        # Check for error indicators in the response
        if 'error' in resp.text.lower() and 'success' not in resp.text.lower():
            raise RuntimeError(f"Server rejected carousel post: {resp.text[:200]}")

    # ------------------------------------------------------------------
    # Post creation — single image
    # ------------------------------------------------------------------

    def create_single_post(
        self,
        title:         str,
        body:          str,
        tags:          str,
        image_path:    str,       # server-relative path (already FTP'd)
        category_id:   Optional[int] = None,
        album_id:      Optional[int] = None,
        post_date:     Optional[str] = None,
    ) -> None:
        """
        Create a single-image post via smack-post.php.
        image_path is a server-relative path to an already-uploaded image.
        """
        if not self._logged_in:
            raise RuntimeError("Not logged in.")

        form_data = {
            'title':       title,
            'body':        body,
            'tags':        tags,
            'img_status':  'published',
            'image_path':  image_path,
        }

        if category_id is not None:
            form_data['cat_ids[]'] = str(category_id)
        if album_id is not None:
            form_data['album_ids[]'] = str(album_id)
        if post_date:
            form_data['post_date'] = post_date

        resp = self.session.post(
            f"{self.base_url}/smack-post.php",
            data=form_data,
            timeout=120,
        )
        resp.raise_for_status()


# ---------------------------------------------------------------------------
# Image processing pipeline
# ---------------------------------------------------------------------------

def prepare_image(
    src_path:       str,
    output_dir:     str,
    timestamp:      int,
    sequence:       int,
    copyright_text: str = '',
    title:          str = '',
    tags:           str = '',
) -> tuple:
    """
    Resize and EXIF-embed a single image.
    Returns (output_path, generated_filename, exif_ok).
    """
    rand = secrets.token_hex(2)
    filename = f"{timestamp}_{sequence:02d}_{rand}.jpg"
    output_path = os.path.join(output_dir, filename)

    img = PILImage.open(src_path)
    img.thumbnail((WEB_MAX_W, WEB_MAX_H), PILImage.LANCZOS)

    exif_ok = True
    try:
        exif_bytes = exif_writer.build_exif_bytes(title, tags, copyright_text)
        img.save(output_path, quality=92, optimize=True, exif=exif_bytes)
    except Exception:
        exif_ok = False
        img.save(output_path, quality=92, optimize=True)

    return output_path, filename, exif_ok


# ---------------------------------------------------------------------------
# Batch migration runner (background thread)
# ---------------------------------------------------------------------------

def run_migration(
    client:          SnapSmackClient,
    posts:           list,          # List[ParsedPost] from ig_parser
    site_data:       SiteData,
    ftp_transport,                  # from ftp_upload
    ftp_remote_base: str,
    staging_dir:     str,           # local temp dir for resized images
    default_category: str = '',
    default_album:    str = '',
    copyright_text:   str = '',
    on_progress:     Optional[Callable[[int, int, PostResult], None]] = None,
) -> List[PostResult]:
    """
    Full migration pipeline: resize → FTP → create post, for each post.
    """
    from ftp_upload import remote_dir_for_timestamp, upload_images

    results = []
    total   = len(posts)

    # Resolve category/album IDs once
    cat_id   = site_data.categories.get(default_category.lower()) if default_category else None
    album_id = site_data.albums.get(default_album.lower())        if default_album   else None

    for idx, post in enumerate(posts):
        if post.excluded:
            result = PostResult(idx, True, "Skipped (excluded)", post.post_type)
            results.append(result)
            if on_progress:
                on_progress(idx + 1, total, result)
            continue

        try:
            # Format tags as space-separated #tags for SnapSmack
            tags_str = ' '.join(f'#{t}' for t in post.hashtags) if post.hashtags else ''

            # Format post date from timestamp
            post_date = datetime.utcfromtimestamp(post.ig_timestamp).strftime('%Y-%m-%d %H:%M:%S')

            # ── 1. Resize + EXIF all images ──────────────────────────
            local_files  = []
            remote_files = []
            all_exif_ok  = True

            remote_dir = remote_dir_for_timestamp(ftp_remote_base, post.ig_timestamp)

            for seq, img_path in enumerate(post.images):
                out_path, filename, exif_ok = prepare_image(
                    src_path=img_path,
                    output_dir=staging_dir,
                    timestamp=post.ig_timestamp,
                    sequence=seq,
                    copyright_text=copyright_text,
                    title=post.body or '',
                    tags=tags_str,
                )
                local_files.append(out_path)
                remote_files.append(f"{remote_dir}/{filename}")
                if not exif_ok:
                    all_exif_ok = False

            # ── 2. FTP upload all images ─────────────────────────────
            ftp_results = upload_images(ftp_transport, local_files, remote_files)
            ftp_failures = [r for r in ftp_results if not r.success]
            if ftp_failures:
                msg = f"FTP failed for {len(ftp_failures)}/{len(local_files)} images"
                result = PostResult(idx, False, msg, post.post_type, all_exif_ok)
                results.append(result)
                if on_progress:
                    on_progress(idx + 1, total, result)
                continue

            # ── 3. Create post via API ───────────────────────────────
            server_paths = [r.remote_path for r in ftp_results]

            if post.post_type == 'carousel':
                client.create_carousel_post(
                    title=post.body or '',
                    body=post.caption,
                    tags=tags_str,
                    image_paths=server_paths,
                    category_id=cat_id,
                    album_id=album_id,
                    post_date=post_date,
                )
            else:
                client.create_single_post(
                    title=post.body or '',
                    body=post.caption,
                    tags=tags_str,
                    image_path=server_paths[0],
                    category_id=cat_id,
                    album_id=album_id,
                    post_date=post_date,
                )

            msg = f"{'Carousel' if post.post_type == 'carousel' else 'Single'} — {len(post.images)} image{'s' if len(post.images) != 1 else ''}"
            if not all_exif_ok:
                msg += " (EXIF partial)"
            result = PostResult(idx, True, msg, post.post_type, all_exif_ok)

        except Exception as e:
            result = PostResult(idx, False, str(e), post.post_type)

        results.append(result)
        if on_progress:
            on_progress(idx + 1, total, result)

        # Clean up staging files for this post
        for lf in local_files:
            try:
                os.unlink(lf)
            except OSError:
                pass

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
