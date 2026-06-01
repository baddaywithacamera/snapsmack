"""
Unzucker — poster.py
SnapSmack API client. Forked from ft-batch-poster/poster.py.

Changes from the batch poster version:
  - Removed ManifestEntry dependency (uses ParsedPost from ig_parser)
  - Bearer token auth via API key — no session scraping, no BeautifulSoup
  - fetch_site_data() hits GET unzucker/site
  - create_post() calls POST unzucker/posts (handles single + carousel)
  - Image resize + EXIF pipeline moved to prepare_images() helper
  - prepare_image() now returns (path, filename, width, height, exif_ok)
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.


import json
import os
import secrets
import shutil
import tempfile
from dataclasses import dataclass, field
from datetime import datetime
from typing import Callable, Dict, List, Optional

import requests
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
    """Category and album name→ID lookups fetched from unzucker/site."""
    categories:     Dict[str, int] = field(default_factory=dict)
    albums:         Dict[str, int] = field(default_factory=dict)
    _cat_display:   Dict[str, str] = field(default_factory=dict)
    _album_display: Dict[str, str] = field(default_factory=dict)


# ---------------------------------------------------------------------------
# SnapSmack client — Bearer token auth
# ---------------------------------------------------------------------------

class UnzuckerClient:

    def __init__(self, base_url: str, api_key: str):
        self.base_url = base_url.rstrip('/')
        self._session = requests.Session()
        self._session.headers.update({
            'Authorization': f'Bearer {api_key}',
            'Content-Type':  'application/json',
            'User-Agent':    'unzucker/1.0',
        })

    def ping(self) -> tuple:
        """
        Test connection. Returns (ok: bool, message: str).
        """
        try:
            resp = self._session.get(
                f"{self.base_url}/api.php",
                params={'route': 'unzucker/ping'},
                timeout=15,
            )
            if resp.status_code == 401:
                return False, "Invalid API key."
            resp.raise_for_status()
            data = resp.json()
            cats   = data.get('cat_count', 0)
            albums = data.get('album_count', 0)
            return True, f"Connected — {cats} categories, {albums} albums"
        except requests.RequestException as e:
            return False, f"Connection failed: {e}"

    def fetch_site_data(self) -> SiteData:
        resp = self._session.get(
            f"{self.base_url}/api.php",
            params={'route': 'unzucker/site'},
            timeout=15,
        )
        resp.raise_for_status()
        data = resp.json()

        site = SiteData()
        for c in data.get('categories', []):
            name = c.get('name', '')
            cid  = int(c.get('id', 0))
            site.categories[name.lower()] = cid
            site._cat_display[name.lower()] = name
        for a in data.get('albums', []):
            name = a.get('name', '')
            aid  = int(a.get('id', 0))
            site.albums[name.lower()] = aid
            site._album_display[name.lower()] = name
        return site

    def create_post(
        self,
        title:         str,
        body:          str,
        tags:          List[str],
        images:        List[dict],   # [{'path': str, 'width': int, 'height': int, 'orientation': str}]
        ig_id:         str           = '',
        post_date:     str           = '',
        category_id:   Optional[int] = None,
        album_id:      Optional[int] = None,
    ) -> dict:
        """
        Create a post via POST unzucker/posts.
        Returns the parsed JSON response dict.
        """
        payload = {
            'title':     title,
            'body':      body,
            'tags':      tags,
            'images':    images,
            'ig_id':     ig_id,
            'post_date': post_date,
            'cat_ids':   [category_id] if category_id is not None else [],
            'album_ids': [album_id]    if album_id    is not None else [],
        }
        resp = self._session.post(
            f"{self.base_url}/api.php",
            params={'route': 'unzucker/posts'},
            data=json.dumps(payload),
            timeout=120,
        )
        resp.raise_for_status()
        return resp.json()


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
    Returns (output_path, generated_filename, width, height, exif_ok).
    """
    rand = secrets.token_hex(2)
    filename = f"{timestamp}_{sequence:02d}_{rand}.jpg"
    output_path = os.path.join(output_dir, filename)

    img = PILImage.open(src_path)
    img.thumbnail((WEB_MAX_W, WEB_MAX_H), PILImage.LANCZOS)
    width, height = img.size

    exif_ok = True
    try:
        exif_bytes = exif_writer.build_exif_bytes(title, tags, copyright_text)
        img.save(output_path, quality=92, optimize=True, exif=exif_bytes)
    except Exception:
        exif_ok = False
        img.save(output_path, quality=92, optimize=True)

    return output_path, filename, width, height, exif_ok


# ---------------------------------------------------------------------------
# Batch migration runner (background thread)
# ---------------------------------------------------------------------------

def run_migration(
    client:          UnzuckerClient,
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
            tags_list = list(post.hashtags) if post.hashtags else []
            tags_str  = ' '.join(f'#{t}' for t in tags_list)
            post_date = datetime.utcfromtimestamp(post.ig_timestamp).strftime('%Y-%m-%d %H:%M:%S')
            ig_id     = str(post.ig_timestamp)

            # ── 1. Resize + EXIF all images ──────────────────────────
            local_files  = []
            remote_files = []
            image_meta   = []   # [{path, width, height, orientation}]
            all_exif_ok  = True

            remote_dir = remote_dir_for_timestamp(ftp_remote_base, post.ig_timestamp)

            for seq, img_path in enumerate(post.images):
                out_path, filename, w, h, exif_ok = prepare_image(
                    src_path=img_path,
                    output_dir=staging_dir,
                    timestamp=post.ig_timestamp,
                    sequence=seq,
                    copyright_text=copyright_text,
                    title=post.body or '',
                    tags=tags_str,
                )
                orientation = 'portrait' if h > w else 'landscape'
                remote_path = f"{remote_dir}/{filename}"

                local_files.append(out_path)
                remote_files.append(remote_path)
                image_meta.append({
                    'path':        remote_path,
                    'width':       w,
                    'height':      h,
                    'orientation': orientation,
                })
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
            resp = client.create_post(
                title=post.body or '',
                body=post.caption or '',
                tags=tags_list,
                images=image_meta,
                ig_id=ig_id,
                post_date=post_date,
                category_id=cat_id,
                album_id=album_id,
            )

            if resp.get('duplicate'):
                msg = f"Skipped (duplicate post_id={resp.get('post_id')})"
                result = PostResult(idx, True, msg, post.post_type, all_exif_ok)
            else:
                n = len(post.images)
                msg = f"{'Carousel' if n > 1 else 'Single'} — {n} image{'s' if n != 1 else ''}"
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
# ===== SNAPSMACK EOF =====
