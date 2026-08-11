"""
SNAPSMACK — snap_home.py  (shared on-disk layout for the desktop tool family)

The single directory contract every SnapSmack tool shares. One root — default
C:\\snapsmack, overridable with the SNAPSMACK_HOME env var — under which:

    shared_library/
        auth/                ONE shared credential vault (snap_vault) — configure
                             the hub key / Gemini key / Drive creds ONCE, every
                             tool reads them here instead of each being set up.
        <site>/db/           SQLite content mirror (manifest + post/asset rows)
        <site>/thumbs/       downloaded thumbs, shared so tools don't re-fetch
    <app>/out/               per-tool FINISHED output (e.g. coldsnap/out)

Only SHARED data + credentials live here. Each tool's own config.ini still lives
next to its exe, unchanged — this module does not touch that.

<site> folders are derived from a site URL/hostname. A hostname is data, and data
is untrusted: a crafted value like '..\\..\\Windows' must never escape the root.
site_dir() therefore routes every site segment through snap_paths.contained_local_path,
the same containment SUYB has shipped since the recovery-kit work.

Usage:
    import snap_home
    auth = snap_home.auth_dir()                 # C:\\snapsmack\\shared_library\\auth
    db   = snap_home.site_db_dir(site_url)      # …\\shared_library\\<site>\\db
    out  = snap_home.app_out_dir('coldsnap')    # C:\\snapsmack\\coldsnap\\out

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import os
import re
from urllib.parse import urlparse

# snap_paths is a sibling in tools/_shared/. Tools put _shared on sys.path before
# importing snap_home, so this plain import resolves the same way snap_stepup does.
from snap_paths import contained_local_path


_DEFAULT_ROOT = r"C:\snapsmack"


# ── Root ─────────────────────────────────────────────────────────────────────
def home() -> str:
    """The SnapSmack root. SNAPSMACK_HOME overrides the C:\\snapsmack default so
    tests (and non-Windows dev) can point it at a scratch dir."""
    root = (os.environ.get("SNAPSMACK_HOME") or "").strip() or _DEFAULT_ROOT
    return os.path.abspath(root)


def _ensure(path: str) -> str:
    os.makedirs(path, exist_ok=True)
    return path


# ── Shared library ───────────────────────────────────────────────────────────
def shared_library() -> str:
    """…/shared_library — the tool-agnostic store (created on first use)."""
    return _ensure(os.path.join(home(), "shared_library"))


def auth_dir() -> str:
    """…/shared_library/auth — the ONE shared credential vault location."""
    return _ensure(os.path.join(shared_library(), "auth"))


# ── Per-site ─────────────────────────────────────────────────────────────────
def site_key(site: str) -> str:
    """Normalise a site URL or hostname to a safe single folder name.

    'https://UsedCarParts.PhotoBlogs.fyi/x' -> 'usedcarparts.photoblogs.fyi'
    Keeps host only, lowercased; anything outside [a-z0-9.-] becomes '-'. Raises
    ValueError on input that yields nothing usable (so callers fail loud, not into
    a surprise folder)."""
    raw = (site or "").strip()
    if not raw:
        raise ValueError("site is empty")
    # Give urlparse a scheme to latch onto so a bare host parses as the netloc.
    parsed = urlparse(raw if "://" in raw else "https://" + raw)
    host = (parsed.hostname or "").lower()
    if not host:
        # No recognisable host — fall back to the raw string, still sanitised.
        host = raw.lower()
    key = re.sub(r"[^a-z0-9.-]", "-", host).strip(".-")
    key = re.sub(r"-{2,}", "-", key)
    if not key:
        raise ValueError(f"site yields no usable folder name: {site!r}")
    return key


def site_dir(site: str) -> str:
    """…/shared_library/<site> — contained under the library root (never escapes)."""
    lib = shared_library()
    # contained_local_path validates the segment and blocks traversal/absolute paths.
    return _ensure(contained_local_path(lib, site_key(site)))


def site_db_dir(site: str) -> str:
    """…/shared_library/<site>/db — the SQLite content mirror lives here."""
    return _ensure(os.path.join(site_dir(site), "db"))


def site_thumbs_dir(site: str) -> str:
    """…/shared_library/<site>/thumbs — downloaded thumbs, shared across tools."""
    return _ensure(os.path.join(site_dir(site), "thumbs"))


# ── Per-app output ───────────────────────────────────────────────────────────
def app_out_dir(app_name: str) -> str:
    """C:\\snapsmack\\<app>\\out — a tool's finished output. <app> is a fixed,
    code-supplied name (e.g. 'coldsnap'), but contain it anyway for consistency."""
    name = re.sub(r"[^A-Za-z0-9_.-]", "-", (app_name or "").strip()).strip(".-")
    if not name:
        raise ValueError(f"app_name yields no usable folder name: {app_name!r}")
    return _ensure(os.path.join(contained_local_path(home(), name), "out"))
# ===== SNAPSMACK EOF =====
