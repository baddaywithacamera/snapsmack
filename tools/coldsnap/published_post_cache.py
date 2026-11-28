"""Short-lived copies of verified published essays for the normal Open flow.

# SNAPSMACK_EOF_HEADER
"""

import hashlib
import json
import os
from pathlib import Path
import tempfile
import time

from snap_home import contained_local_path, site_dir


# Set only after the owner chooses the published-post retention period.
RETENTION_SECONDS = 2_592_000  # 30 days


def _path(site, post_id):
    # Two installs on the same host can use different base paths.
    scope = hashlib.sha256(site.strip().rstrip("/").encode("utf-8")).hexdigest()
    return Path(contained_local_path(site_dir(site), f"published-post-cache/{scope}/{int(post_id)}.json"))


def _prune_expired(directory, cutoff):
    for entry in directory.glob("*.json"):
        if not entry.stem.isdecimal() or entry.is_symlink():
            continue
        try:
            if entry.stat().st_mtime <= cutoff:
                entry.unlink()
        except OSError:
            continue


def save(site, post, revision, local_photos):
    """Remember only a verified published row with a server revision."""
    if not RETENTION_SECONDS or not site or not revision or post.get("status") != "published":
        return
    try:
        post_id = int(post.get("id") or 0)
        if post_id <= 0:
            return
        path = _path(site, post_id)
        path.parent.mkdir(parents=True, exist_ok=True)
        now = time.time()
        _prune_expired(path.parent, now - RETENTION_SECONDS)
        payload = {"saved_at": now, "revision": revision,
                   "post": post, "local_photos": local_photos}
        with tempfile.NamedTemporaryFile(mode="w", encoding="utf-8", dir=path.parent,
                                         delete=False) as handle:
            temporary = Path(handle.name)
            try:
                json.dump(payload, handle, ensure_ascii=False)
                handle.close()
                os.replace(temporary, path)
            finally:
                temporary.unlink(missing_ok=True)
    except (OSError, TypeError, ValueError):
        return  # A cache failure must never turn a successful publish into an error.


def load(site, post_id, revision):
    """Miss on expiry or if the live picker reports a different revision."""
    if not RETENTION_SECONDS or not site or not revision:
        return None
    try:
        path = _path(site, post_id)
        with path.open(encoding="utf-8") as handle:
            record = json.load(handle)
        if not isinstance(record, dict):
            return None
        age = time.time() - float(record["saved_at"])
        if not (0 <= age < RETENTION_SECONDS) or record.get("revision") != revision:
            return None
        post = record.get("post")
        photos = record.get("local_photos")
        if (not isinstance(post, dict) or int(post.get("id") or 0) != int(post_id)
                or post.get("status") != "published" or not isinstance(photos, dict)):
            return None
        return post, photos
    except (OSError, KeyError, TypeError, ValueError):
        return None


# ===== SNAPSMACK EOF =====
