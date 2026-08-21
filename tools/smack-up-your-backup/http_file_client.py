"""
Smack Up Your Backup — http_file_client.py

HTTP media transport. Pulls inventoried files over the site's
`suyb-export.php?type=file&path=` endpoint, reusing SUYB's already-authenticated
session (a scoped 'suyb' Bearer key, or the admin cookie). This is the
NO-FTP-CREDENTIALS path — the whole point on a large fleet: you set the hub up once,
and the media library comes down the same HTTP channel as the database and recovery
kit. FTP/SFTP remain available as a fallback transport.

Interface parity: exposes exactly the methods Stage 3 of backup_engine.py uses —
connect(), disconnect(), reconnect(), is_alive(),
download_file(remote_rel, local_path, on_progress) -> (ok, err), remote_size(),
and the static sha256_file() — so it drops in where a FTPClient/SFTPClient would go.

Differential state is kept LOCALLY for HTTP mode (a read-only channel cannot write the
server's backups/backup-state.json back), handled by the engine, not here.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import hashlib
import os
import time
from typing import Callable, Optional, Tuple
from urllib.parse import quote


class HttpFileClient:
    """Download inventoried files via suyb-export.php?type=file over an authenticated
    session. `session` is the SnapSmackSession already built for the DB/kit stages —
    no new credentials are introduced."""

    def __init__(self, site_url: str, session, transfer_delay: float = 0.0):
        self.site_url = str(site_url).rstrip("/")
        self._sess = session                       # SnapSmackSession (has .session, .is_alive)
        self._delay = float(transfer_delay or 0)

    # ── lifecycle (HTTP is stateless; these mirror the FTP client) ──────────
    def connect(self) -> None:
        # Fail HERE, like an FTP connect, if auth is bad — not on the first file.
        if not self._sess.is_alive():
            raise RuntimeError(
                "HTTP transport: the site rejected the backup key / session. "
                "Re-run Discover Fleet in The Hub, or check the site is on a "
                "SnapSmack version with the suyb-export.php file endpoint."
            )

    def disconnect(self) -> None:
        return None

    def reconnect(self) -> None:
        try:
            self._sess.relogin()
        except Exception:
            pass

    def is_alive(self) -> bool:
        try:
            return bool(self._sess.is_alive())
        except Exception:
            return False

    # ── the one method Stage 3's loop actually leans on ─────────────────────
    def download_file(self, remote_rel_path: str, local_path: str,
                      on_progress: Optional[Callable] = None) -> Tuple[bool, str]:
        rel = str(remote_rel_path).replace("\\", "/").lstrip("/")
        # Encode the path but keep the '/' separators readable for the server.
        url = f"{self.site_url}/suyb-export.php?type=file&path={quote(rel, safe='/')}"
        try:
            os.makedirs(os.path.dirname(local_path) or ".", exist_ok=True)
            with self._sess.session.get(url, timeout=(30, 300), stream=True,
                                        allow_redirects=True) as resp:
                ctype = resp.headers.get("Content-Type", "")
                if resp.status_code != 200:
                    reason = ""
                    if "application/json" in ctype:
                        try:
                            reason = " — " + str(resp.json().get("error", ""))
                        except Exception:
                            pass
                    return False, f"HTTP {resp.status_code}{reason}"
                # Integrity gate: this endpoint serves application/octet-stream. Anything
                # else — an HTML/plain error page, a login redirect, a JSON error — must
                # NOT be written to disk as if it were a photo. Fail loud so the engine
                # counts it a failure (which now blocks a "clean" run). (Web-Claude
                # review 2026-08-21.)
                if "application/octet-stream" not in ctype.lower():
                    reason = ""
                    if "application/json" in ctype:
                        try:
                            reason = " — " + str(resp.json().get("error", ""))
                        except Exception:
                            pass
                    return False, (f"unexpected content-type '{ctype or 'none'}'{reason} "
                                   "(auth bounce, or a site without type=file)")
                expected = resp.headers.get("Content-Length")
                total = 0
                with open(local_path, "wb") as fh:
                    for chunk in resp.iter_content(chunk_size=262144):
                        if chunk:
                            fh.write(chunk)
                            total += len(chunk)
                            if on_progress:
                                try:
                                    on_progress(os.path.basename(local_path), total, 0, 0)
                                except Exception:
                                    pass
                # Truncation check: a short read (server memory_limit, dropped connection)
                # leaves a partial file. If the server advertised a length, bytes must match.
                if expected is not None:
                    try:
                        exp = int(expected)
                    except ValueError:
                        exp = -1
                    if exp >= 0 and exp != total:
                        try:
                            os.remove(local_path)
                        except Exception:
                            pass
                        return False, f"truncated download ({total} of {exp} bytes)"
            if self._delay:
                time.sleep(self._delay)
            return True, ""
        except Exception as e:
            return False, str(e)

    def remote_size(self, remote_rel_path: str) -> int:
        rel = str(remote_rel_path).replace("\\", "/").lstrip("/")
        url = f"{self.site_url}/suyb-export.php?type=file&path={quote(rel, safe='/')}"
        try:
            r = self._sess.session.head(url, timeout=30, allow_redirects=True)
            return int(r.headers.get("Content-Length", 0) or 0)
        except Exception:
            return 0

    def get_remote_size(self, remote_path: str) -> Optional[int]:
        n = self.remote_size(remote_path)
        return n if n > 0 else None

    @staticmethod
    def sha256_file(path: str) -> str:
        h = hashlib.sha256()
        with open(path, "rb") as f:
            for chunk in iter(lambda: f.read(1 << 20), b""):
                h.update(chunk)
        return h.hexdigest()

# ===== SNAPSMACK EOF =====
