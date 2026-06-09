"""
FLKR FCKR — poster.py
SnapSmack API client for the FLKR FCKR import pipeline.

Forked from tools/unzucker/poster.py. Key differences:
  - No session scraping. Bearer token auth via FLKR FCKR API key.
  - No BeautifulSoup dependency.
  - create_image_record() calls POST flkrfckr/images.
  - create_or_get_album() calls GET/POST flkrfckr/albums.
  - Handles duplicate detection via server response (duplicate: true).
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.


import json
import logging
from dataclasses import dataclass, field
from typing import Callable, Dict, List, Optional

import requests

log = logging.getLogger('flkrfckr')


# ---------------------------------------------------------------------------
# Data structures
# ---------------------------------------------------------------------------

@dataclass
class ImportResult:
    flickr_id:    str
    success:      bool
    message:      str
    image_id:     int  = 0
    img_slug:     str  = ''
    duplicate:    bool = False


# ---------------------------------------------------------------------------
# SnapSmack API client
# ---------------------------------------------------------------------------

class FlkrDckrClient:

    def __init__(self, base_url: str, api_key: str):
        self.base_url = base_url.rstrip('/')
        self._session = requests.Session()
        self._session.headers.update({
            'Authorization': f'Bearer {api_key}',
            'Content-Type':  'application/json',
            'User-Agent':    'flkrfckr/1.0',
        })
        self._album_cache: Dict[str, int] = {}   # name.lower() → SnapSmack album ID

    # ------------------------------------------------------------------
    # Connection test
    # ------------------------------------------------------------------

    def ping(self) -> tuple:
        """
        Test connection by calling GET flkrfckr/albums.
        Returns (ok: bool, message: str).
        """
        try:
            resp = self._session.get(
                f"{self.base_url}/api.php",
                params={'route': 'flkrfckr/albums'},
                timeout=15,
            )
            if resp.status_code == 401:
                return False, "Invalid API key."
            resp.raise_for_status()
            data = resp.json()
            albums = data.get('albums', [])
            return True, f"Connected — {len(albums)} album(s) on site."
        except requests.RequestException as e:
            return False, f"Connection failed: {e}"

    # ------------------------------------------------------------------
    # Albums
    # ------------------------------------------------------------------

    def fetch_albums(self) -> Dict[str, int]:
        """
        Fetch all albums from the site.
        Returns dict of album_name.lower() → album_id.
        Also populates the internal cache.
        """
        resp = self._session.get(
            f"{self.base_url}/api.php",
            params={'route': 'flkrfckr/albums'},
            timeout=15,
        )
        resp.raise_for_status()
        data = resp.json()
        self._album_cache = {
            a['name'].lower(): int(a['id'])
            for a in data.get('albums', [])
            if a.get('name') and a.get('id')
        }
        return dict(self._album_cache)

    def create_or_get_album(self, name: str, description: str = '') -> int:
        """
        Get existing album by name (case-insensitive) or create it.
        Returns SnapSmack album ID.
        """
        key = name.lower()
        if key in self._album_cache:
            return self._album_cache[key]

        payload = {'name': name, 'description': description}
        resp = self._session.post(
            f"{self.base_url}/api.php",
            params={'route': 'flkrfckr/albums'},
            data=json.dumps(payload),
            timeout=15,
        )
        resp.raise_for_status()
        data = resp.json()
        album_id = int(data['album_id'])
        self._album_cache[key] = album_id
        return album_id

    # ------------------------------------------------------------------
    # Images
    # ------------------------------------------------------------------

    def create_image_record(
        self,
        flickr_id:        str,
        img_file:         str,          # server-relative path (after FTP)
        img_title:        str,
        img_description:  str,
        img_date:         str,          # 'YYYY-MM-DD HH:MM:SS'
        img_width:        int,
        img_height:       int,
        img_orientation:  str,
        img_exif:         str,          # JSON string or ''
        img_thumb_square: str,          # server-relative path or ''
        img_thumb_aspect: str,          # server-relative path or ''
        album_ids:        List[int],    # SnapSmack album IDs
        tags:             List[str],    # plain tag strings (no # prefix)
        status:           str = 'published',
    ) -> ImportResult:
        """
        POST to flkrfckr/images. Handles duplicate detection.
        Returns ImportResult.
        """
        payload = {
            'flickr_id':        flickr_id,
            'img_file':         img_file,
            'img_title':        img_title,
            'img_description':  img_description,
            'img_date':         img_date,
            'img_width':        img_width,
            'img_height':       img_height,
            'img_orientation':  img_orientation,
            'img_exif':         img_exif,
            'img_source_file':  f'flickr:{flickr_id}',
            'img_thumb_square': img_thumb_square,
            'img_thumb_aspect': img_thumb_aspect,
            'album_ids':        album_ids,
            'tags':             tags,
            'status':           status,
        }
        try:
            resp = self._session.post(
                f"{self.base_url}/api.php",
                params={'route': 'flkrfckr/images'},
                data=json.dumps(payload),
                timeout=30,
            )
            resp.raise_for_status()
            data = resp.json()

            if data.get('status') != 'ok':
                return ImportResult(
                    flickr_id=flickr_id,
                    success=False,
                    message=data.get('message', 'API error'),
                )

            return ImportResult(
                flickr_id=flickr_id,
                success=True,
                message='Duplicate' if data.get('duplicate') else 'Imported',
                image_id=int(data.get('image_id', 0)),
                img_slug=data.get('img_slug', ''),
                duplicate=bool(data.get('duplicate', False)),
            )

        except requests.RequestException as e:
            return ImportResult(flickr_id=flickr_id, success=False, message=str(e))


# ---------------------------------------------------------------------------
# Migration runner (called from main.py background thread)
# ---------------------------------------------------------------------------

def run_import(
    client:           FlkrDckrClient,
    photos:           list,              # List[ParsedPhoto] from flickr_parser
    ftp_transport,                       # from ftp_upload
    ftp_remote_base:  str,
    staging_dir:      str,
    checkpoint,                          # ImportCheckpoint instance
    flickr_album_map: Dict[str, str],    # flickr album_id → album title+desc
    private_status:   str = 'draft',     # status for photos private on Flickr
    unalbumed_action: str = 'feed',      # 'feed' or 'default_album'
    default_album:    str = '',          # album name for unalbumed if action=default_album
    throttle_delay:   float = 1.5,
    on_progress:      Optional[Callable[[int, int, ImportResult], None]] = None,
) -> List[ImportResult]:
    """
    Full import pipeline: image_prep → FTP → API record creation.
    Respects checkpoint — skips already-imported Flickr IDs.
    """
    import time
    import os
    from ftp_upload import remote_dir_for_timestamp, upload_images
    from image_prep import prepare

    already_done = checkpoint.already_imported()
    results      = []
    total        = len(photos)

    # Resolve default album once if needed
    default_album_id: Optional[int] = None
    if unalbumed_action == 'default_album' and default_album:
        try:
            default_album_id = client.create_or_get_album(default_album)
        except Exception:
            pass

    for idx, photo in enumerate(photos):
        # Skip excluded photos
        if photo.excluded:
            result = ImportResult(photo.flickr_id, True, 'Skipped (excluded)')
            results.append(result)
            checkpoint.record_skipped(photo.flickr_id)
            if on_progress:
                on_progress(idx + 1, total, result)
            continue

        # Skip already-imported (checkpoint fast path)
        if photo.flickr_id in already_done:
            result = ImportResult(
                flickr_id=photo.flickr_id,
                success=True,
                message='Already imported',
                image_id=already_done[photo.flickr_id],
                duplicate=True,
            )
            results.append(result)
            if on_progress:
                on_progress(idx + 1, total, result)
            continue

        # Skip missing images
        if photo.missing_image or not photo.image_path:
            result = ImportResult(photo.flickr_id, False, 'Missing image file')
            results.append(result)
            checkpoint.record_failed(photo.flickr_id)
            if on_progress:
                on_progress(idx + 1, total, result)
            continue

        local_paths: list = []
        try:
            # ── 1. Determine import status ────────────────────────────────────
            status = private_status if photo.privacy != 'public' else 'published'

            # ── 2. Determine best date ────────────────────────────────────────
            best_date = photo.date_taken or photo.create_date
            img_date_str = best_date.strftime('%Y-%m-%d %H:%M:%S') if best_date else ''

            # ── 3. Prepare image (resize + thumbnails + EXIF) ─────────────────
            prepped = prepare(
                source_path=photo.image_path,
                output_dir=staging_dir,
                date=best_date,
                geo=photo.geo,
            )
            local_paths = [prepped.main_path, prepped.thumb_sq_path, prepped.thumb_as_path]

            # ── 4. FTP upload (main + sq thumb + aspect thumb) ────────────────
            remote_dir = remote_dir_for_timestamp(
                ftp_remote_base,
                int(best_date.timestamp()) if best_date else 0,
            )
            local_paths  = [prepped.main_path, prepped.thumb_sq_path, prepped.thumb_as_path]
            remote_paths = [
                f"{remote_dir}/{prepped.filename}",
                f"{remote_dir}/{prepped.thumb_sq_name}",
                f"{remote_dir}/{prepped.thumb_as_name}",
            ]

            ftp_results = upload_images(ftp_transport, local_paths, remote_paths)
            ftp_fails   = [r for r in ftp_results if not r.success]
            if ftp_fails:
                raise RuntimeError(f"FTP failed: {ftp_fails[0].message}")

            # ── 5. Resolve albums ─────────────────────────────────────────────
            snap_album_ids: List[int] = []

            if photo.album_ids:
                for fid in photo.album_ids:
                    album_meta = flickr_album_map.get(fid)
                    if album_meta:
                        aid = client.create_or_get_album(
                            album_meta['title'],
                            album_meta.get('description', ''),
                        )
                        snap_album_ids.append(aid)
            elif unalbumed_action == 'default_album' and default_album_id:
                snap_album_ids.append(default_album_id)

            # ── 6. Create image record via API ────────────────────────────────
            result = client.create_image_record(
                flickr_id=photo.flickr_id,
                img_file=remote_paths[0],
                img_title=photo.title,
                img_description=photo.description,
                img_date=img_date_str,
                img_width=prepped.width,
                img_height=prepped.height,
                img_orientation=prepped.orientation,
                img_exif=prepped.img_exif,
                img_thumb_square=remote_paths[1],
                img_thumb_aspect=remote_paths[2],
                album_ids=snap_album_ids,
                tags=photo.tags,
                status=status,
            )

            if result.success:
                checkpoint.record_imported(photo.flickr_id, result.image_id)
                if not result.duplicate:
                    log.info(f"Imported {photo.flickr_id} → image_id={result.image_id}")
                else:
                    log.debug(f"Duplicate skipped: {photo.flickr_id}")
            else:
                checkpoint.record_failed(photo.flickr_id)
                log.error(f"API error for {photo.flickr_id}: {result.message}")

        except requests.HTTPError as e:
            body = ''
            try:
                body = e.response.json().get('message', '') if e.response is not None else ''
            except Exception:
                body = e.response.text[:300] if e.response is not None else ''
            detail = f"{e} — server says: {body}" if body else str(e)
            log.error(f"Photo {photo.flickr_id} failed: {detail}", exc_info=True)
            result = ImportResult(photo.flickr_id, False, detail)
            checkpoint.record_failed(photo.flickr_id)

        except Exception as e:
            log.error(f"Photo {photo.flickr_id} failed: {e}", exc_info=True)
            result = ImportResult(photo.flickr_id, False, str(e))
            checkpoint.record_failed(photo.flickr_id)

        finally:
            for lp in local_paths:
                try:
                    os.unlink(lp)
                except OSError:
                    pass

        results.append(result)
        if on_progress:
            on_progress(idx + 1, total, result)

        # Throttle between posts
        if throttle_delay > 0 and not result.duplicate:
            time.sleep(throttle_delay)

    return results
# ===== SNAPSMACK EOF =====
