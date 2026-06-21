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
import os
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
        img_license:      str = '',     # rights label from Flickr (optional)
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
            'img_license':      img_license,
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

    # ------------------------------------------------------------------
    # Image upload (multipart over HTTPS — replaces FTP/SFTP)
    # ------------------------------------------------------------------

    def upload_image(self, local_path: str) -> dict:
        """
        Upload a JPEG to flkrfckr/upload as multipart/form-data. The server
        saves it under img_uploads/YYYY/MM/, generates the t_/a_ thumbnails,
        and returns {path, thumb_square, thumb_aspect, width, height}.
        Raises on HTTP error or a non-ok response.
        """
        with open(local_path, 'rb') as fh:
            files = {'image': (os.path.basename(local_path), fh, 'image/jpeg')}
            resp = self._session.post(
                f"{self.base_url}/api.php",
                params={'route': 'flkrfckr/upload'},
                files=files,
                # Drop the session's JSON Content-Type so requests can set the
                # correct multipart boundary header for this request.
                headers={'Content-Type': None},
                timeout=120,
            )
        resp.raise_for_status()
        data = resp.json()
        if data.get('status') != 'ok':
            raise RuntimeError(data.get('message', 'Image upload failed'))
        return data

    # ------------------------------------------------------------------
    # Comments
    # ------------------------------------------------------------------

    def import_comment(self, image_id: int, author_name: str, author_url: str,
                       comment_text: str, comment_date: str) -> bool:
        """
        POST one Flickr comment to flkrfckr/comments (attached to image_id).
        Returns True on success. Uses the session's default JSON Content-Type.
        """
        payload = {
            'image_id':     image_id,
            'author_name':  author_name,
            'author_url':   author_url,
            'comment_text': comment_text,
            'comment_date': comment_date,
        }
        resp = self._session.post(
            f"{self.base_url}/api.php",
            params={'route': 'flkrfckr/comments'},
            data=json.dumps(payload),
            timeout=30,
        )
        resp.raise_for_status()
        return resp.json().get('status') == 'ok'


# ---------------------------------------------------------------------------
# Migration runner (called from main.py background thread)
# ---------------------------------------------------------------------------

def _in_peak(peak_start: int, peak_end: int) -> bool:
    """True if the current local hour is inside the peak window (midnight-safe)."""
    from datetime import datetime
    h = datetime.now().hour
    if peak_start < peak_end:
        return peak_start <= h < peak_end
    # Window wraps midnight (e.g. 22 → 8)
    return h >= peak_start or h < peak_end


def run_import(
    client:           FlkrDckrClient,
    photos:           list,              # List[ParsedPhoto] from flickr_parser
    staging_dir:      str,
    checkpoint,                          # ImportCheckpoint instance
    flickr_album_map: Dict[str, str],    # flickr album_id → album title+desc
    private_status:   str = 'draft',     # status for photos private on Flickr
    unalbumed_action: str = 'feed',      # 'feed' or 'default_album'
    default_album:    str = '',          # album name for unalbumed if action=default_album
    throttle_delay:   float = 1.5,
    offpeak_only:     bool  = False,  # pause entirely during peak hours
    peak_start:       int   = 9,      # peak window start hour (0–23, local)
    peak_end:         int   = 23,     # peak window end hour (exclusive)
    on_wait:          Optional[Callable[[int], None]] = None,  # called w/ resume hour while paused
    on_progress:      Optional[Callable[[int, int, ImportResult], None]] = None,
    stop_event=None,        # threading.Event — set to abort the run
    pause_event=None,       # threading.Event — cleared to pause, set to resume
) -> List[ImportResult]:
    """
    Full import pipeline: image_prep → HTTPS multipart upload → API record creation.
    Respects checkpoint — skips already-imported Flickr IDs.

    Cooperative control:
      - if ``stop_event`` is set, the loop exits cleanly between photos;
      - if ``pause_event`` is provided, the loop blocks on it before each photo
        (the caller clears it to pause and sets it to resume).
    """
    import time
    import os
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
        # Cooperative pause/stop — checked before each photo.
        if pause_event is not None:
            pause_event.wait()
        if stop_event is not None and stop_event.is_set():
            log.info("Import stopped by user at photo %d/%d", idx, total)
            break

        # Off-peak gate: when enabled, pause the run entirely during peak hours.
        # Re-checks every 30s and honours stop. (Ported from unzucker — the
        # off-peak scheduling requested but missed in the original build.)
        if offpeak_only:
            while _in_peak(peak_start, peak_end):
                if stop_event is not None and stop_event.is_set():
                    break
                if on_wait:
                    on_wait(peak_end)
                time.sleep(30)
            if stop_event is not None and stop_event.is_set():
                log.info("Import stopped by user while waiting for off-peak")
                break

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

            # ── 3. Prepare image (resize + EXIF). Thumbnails are generated
            #       server-side by the upload endpoint, so we only make the main.
            prepped = prepare(
                source_path=photo.image_path,
                output_dir=staging_dir,
                date=best_date,
                geo=photo.geo,
            )
            local_paths = [prepped.main_path]

            # ── 4. Upload over HTTPS (multipart) — no FTP. The server saves the
            #       file under img_uploads/YYYY/MM/, generates the t_/a_ thumbs,
            #       and returns their paths.
            up = client.upload_image(prepped.main_path)

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
                img_file=up['path'],
                img_title=photo.title,
                img_description=photo.description,
                img_date=img_date_str,
                img_width=prepped.width,
                img_height=prepped.height,
                img_orientation=prepped.orientation,
                img_exif=prepped.img_exif,
                img_thumb_square=up.get('thumb_square', ''),
                img_thumb_aspect=up.get('thumb_aspect', ''),
                album_ids=snap_album_ids,
                tags=photo.tags,
                status=status,
                img_license=photo.license,
            )

            if result.success:
                checkpoint.record_imported(photo.flickr_id, result.image_id)
                if not result.duplicate:
                    log.info(f"Imported {photo.flickr_id} → image_id={result.image_id}")
                    # Import this photo's Flickr comments (best-effort; only on a
                    # fresh import so re-runs don't duplicate them).
                    if result.image_id and photo.comments:
                        posted = 0
                        for cm in photo.comments:
                            try:
                                if client.import_comment(
                                    image_id=result.image_id,
                                    author_name=cm.get('author_name', ''),
                                    author_url=cm.get('author_url', ''),
                                    comment_text=cm.get('text', ''),
                                    comment_date=cm.get('date', ''),
                                ):
                                    posted += 1
                            except Exception as ce:
                                log.warning(f"Comment import failed for {photo.flickr_id}: {ce}")
                        if posted:
                            log.info(f"Imported {posted} comment(s) for {photo.flickr_id}")
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
