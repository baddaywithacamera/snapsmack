"""
Unzucker — poster.py
SnapSmack API client. Forked from ft-batch-poster/poster.py.

Changes from the batch poster version:
  - Removed ManifestEntry dependency (uses ParsedPost from ig_parser)
  - Bearer token auth via API key — no session scraping, no BeautifulSoup
  - fetch_site_data() hits GET unzucker/site
  - upload_image() POSTs image bytes to POST unzucker/upload (no FTP required)
  - create_post() calls POST unzucker/posts (handles single + carousel)
  - Image resize + EXIF pipeline moved to prepare_image() helper
  - prepare_image() returns (path, filename, width, height, exif_ok)
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.


import json
import logging
import os
import secrets
import shutil
import tempfile
import time
from dataclasses import dataclass, field
from datetime import datetime
from typing import Callable, Dict, List, Optional

import requests
from PIL import Image as PILImage

import exif_writer

log = logging.getLogger('unzucker')

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
    post_id:    int  = 0    # snap_posts.id returned by create_post; 0 if failed/skipped
    duplicate:  bool = False  # True if post already existed on server


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

    def upload_image(self, local_path: str, filename: str) -> str:
        """
        Upload a single JPEG to the server via POST unzucker/upload.
        Returns the server-relative img_file path (e.g. '2024/07/foo.jpg').
        Raises requests.RequestException on failure.
        """
        with open(local_path, 'rb') as fh:
            file_data = fh.read()
        resp = self._session.post(
            f"{self.base_url}/api.php",
            params={'route': 'unzucker/upload'},
            files={'image': (filename, file_data, 'image/jpeg')},
            headers={'Content-Type': None},   # clear session JSON header; let requests set multipart
            timeout=300,
        )
        resp.raise_for_status()
        data = resp.json()
        if data.get('status') != 'ok' or 'path' not in data:
            raise RuntimeError(f"Upload response error: {data.get('message', data)}")
        return data['path']

    def create_trigram(
        self,
        post_id_1:   int,
        post_id_2:   int,
        post_id_3:   int,
        orientation: str = 'h',
    ) -> dict:
        """
        Create a soft (group) trigram record via POST unzucker/trigram.
        Call this after all three posts have been created.
        Returns the parsed JSON response dict.
        """
        payload = {
            'post_id_1':   post_id_1,
            'post_id_2':   post_id_2,
            'post_id_3':   post_id_3,
            'orientation': orientation,
        }
        resp = self._session.post(
            f"{self.base_url}/api.php",
            params={'route': 'unzucker/trigram'},
            data=json.dumps(payload),
            timeout=30,
        )
        resp.raise_for_status()
        return resp.json()

    def check_posts(self, ig_ids: List[str]) -> Dict[str, int]:
        """
        Bulk-check which Instagram post IDs already exist on the server.
        Returns {ig_id: snap_posts.id} for every match found.
        """
        if not ig_ids:
            return {}
        resp = self._session.post(
            f"{self.base_url}/api.php",
            params={'route': 'unzucker/posts/check'},
            json={'ig_ids': ig_ids},
            timeout=30,
        )
        resp.raise_for_status()
        return resp.json().get('existing', {})

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
# Off-peak scheduling
# ---------------------------------------------------------------------------

def _in_peak(peak_start: int, peak_end: int) -> bool:
    """True if the current local hour falls within the defined peak window."""
    h = datetime.now().hour
    if peak_start < peak_end:
        return peak_start <= h < peak_end
    # Wraps midnight (e.g., peak_start=22, peak_end=8)
    return h >= peak_start or h < peak_end


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
    client:           UnzuckerClient,
    posts:            list,          # List[ParsedPost] from ig_parser
    site_data:        SiteData,
    staging_dir:      str,           # local temp dir for resized images
    default_category: str = '',
    default_album:    str = '',
    copyright_text:   str = '',
    on_progress:      Optional[Callable[[int, int, PostResult], None]] = None,
    trigram_groups:   Optional[List[dict]] = None,
    # trigram_groups: [{'indices': [i,j,k], 'slots': [1,2,3], 'orientation': 'h'}, ...]
    # indices are into the `posts` list passed here (already filtered to active posts).
    post_delay:       float = 0.5,   # seconds to sleep between posts; 0 = no delay
    offpeak_only:     bool  = False, # if True, pause entirely during peak hours
    peak_start:       int   = 9,     # peak window start hour (0–23, local time)
    peak_end:         int   = 23,    # peak window end hour (exclusive)
    on_wait:          Optional[Callable[[int], None]] = None,  # called with resume_hour while paused
) -> List[PostResult]:
    """
    Full migration pipeline: resize → HTTP upload → create post, for each post.
    After all posts are created, creates trigram records for any locked groups.
    No FTP required — images go directly to the server over HTTPS.
    """
    results = []
    total   = len(posts)

    # Resolve category/album IDs once
    cat_id   = site_data.categories.get(default_category.lower()) if default_category else None
    album_id = site_data.albums.get(default_album.lower())        if default_album   else None

    # ── Pre-run server reconciliation ────────────────────────────────────────
    # Ask the server which ig_ids already exist so we can skip them without
    # attempting an upload. This catches posts that were uploaded but whose
    # record_uploaded() call never fired (e.g. crash mid-run).
    ig_ids  = [str(p.ig_timestamp) for p in posts]
    try:
        server_existing = client.check_posts(ig_ids)  # {ig_id_str: post_id}
    except Exception as e:
        log.warning(f"Pre-run server check failed (continuing anyway): {e}")
        server_existing = {}

    for idx, post in enumerate(posts):
        # Off-peak gate: if enabled and currently in peak hours, pause until off-peak.
        if offpeak_only:
            while _in_peak(peak_start, peak_end):
                if on_wait:
                    on_wait(peak_end)
                time.sleep(60)

        if post.excluded:
            result = PostResult(idx, True, "Skipped (excluded)", post.post_type)
            results.append(result)
            if on_progress:
                on_progress(idx + 1, total, result)
            continue

        ig_id = str(post.ig_timestamp)

        # Skip posts already confirmed on the server (pre-run check or prior run).
        if ig_id in server_existing:
            existing_id = server_existing[ig_id]
            msg    = f"Skipped (already on server post_id={existing_id})"
            result = PostResult(idx, True, msg, post.post_type, post_id=existing_id, duplicate=True)
            results.append(result)
            if on_progress:
                on_progress(idx + 1, total, result)
            continue

        local_files = []
        try:
            tags_list = list(post.hashtags) if post.hashtags else []
            tags_str  = ' '.join(f'#{t}' for t in tags_list)
            post_date = datetime.utcfromtimestamp(post.ig_timestamp).strftime('%Y-%m-%d %H:%M:%S')

            # ── 1. Resize + EXIF all images locally ──────────────────
            image_meta  = []   # [{path, width, height, orientation}]
            all_exif_ok = True

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
                local_files.append(out_path)
                if not exif_ok:
                    all_exif_ok = False

                # ── 2. HTTP upload each image ─────────────────────────
                server_path = client.upload_image(out_path, filename)

                orientation = 'portrait' if h > w else 'landscape'
                image_meta.append({
                    'path':        server_path,
                    'width':       w,
                    'height':      h,
                    'orientation': orientation,
                })

            # ── 3. Create post record via API ─────────────────────────
            resp = client.create_post(
                title='',                  # IG posts have no title; caption goes in body
                                           # only (was title=post.body, which double-wrote
                                           # the caption — The Grid renders title+body).
                body=post.caption or '',
                tags=tags_list,
                images=image_meta,
                ig_id=ig_id,
                post_date=post_date,
                category_id=cat_id,
                album_id=album_id,
            )

            returned_id = int(resp.get('post_id', 0))
            if resp.get('duplicate'):
                msg = f"Skipped (duplicate post_id={returned_id})"
                result = PostResult(idx, True, msg, post.post_type, all_exif_ok, returned_id, duplicate=True)
                # Also add to server_existing so subsequent runs in this session skip it
                server_existing[ig_id] = returned_id
            else:
                n = len(post.images)
                msg = f"{'Carousel' if n > 1 else 'Single'} — {n} image{'s' if n != 1 else ''}"
                if not all_exif_ok:
                    msg += " (EXIF partial)"
                result = PostResult(idx, True, msg, post.post_type, all_exif_ok, returned_id)

        except requests.HTTPError as e:
            # Extract PHP error message from response body if available
            body = ''
            try:
                body = e.response.json().get('message', '') if e.response is not None else ''
            except Exception:
                body = (e.response.text[:300] if e.response is not None else '')
            detail = f"{e} — server says: {body}" if body else str(e)
            log.error(f"Post {idx+1}/{total} failed: {detail}", exc_info=True)
            result = PostResult(idx, False, detail, post.post_type)
        except Exception as e:
            log.error(f"Post {idx+1}/{total} failed: {e}", exc_info=True)
            result = PostResult(idx, False, str(e), post.post_type)

        results.append(result)
        if result.success:
            log.debug(f"Post {idx+1}/{total} ok — id={result.post_id} {result.message}")
        if on_progress:
            on_progress(idx + 1, total, result)

        # Throttle: pause between posts to avoid hammering shared hosts.
        # post_delay=0.0 disables the sleep entirely.
        if post_delay > 0 and idx < total - 1:
            time.sleep(post_delay)

        # Clean up local staging files for this post
        for lf in local_files:
            try:
                os.unlink(lf)
            except OSError:
                pass

    # ── Trigram second call ────────────────────────────────────────────────
    # After all posts are created, link trigram groups via the server endpoint.
    if trigram_groups:
        # Build index → post_id map from results.
        idx_to_pid: Dict[int, int] = {}
        for r in results:
            if r.success and r.post_id:
                idx_to_pid[r.post_index] = r.post_id

        for group in trigram_groups:
            indices     = group.get('indices', [])
            slots       = group.get('slots',   [1, 2, 3])
            orientation = group.get('orientation', 'h')

            if len(indices) != 3:
                continue

            # Map indices → post_ids, ordered by slot assignment.
            slot_order = sorted(zip(slots, indices), key=lambda x: x[0])
            pids = [idx_to_pid.get(idx_to_pid_key, 0) for _, idx_to_pid_key in slot_order]

            if any(p == 0 for p in pids):
                continue  # one or more posts failed; skip trigram

            try:
                client.create_trigram(pids[0], pids[1], pids[2], orientation)
                log.info(f"Trigram created — posts {pids[0]},{pids[1]},{pids[2]}")
            except Exception as e:
                # Non-fatal: trigram record failed but posts exist.
                # The user can link them manually in the Lighttable.
                log.error(f"Trigram link failed for posts {pids}: {e}", exc_info=True)

    return results
# ===== SNAPSMACK EOF =====
