"""
SNAPSMACK — snap_home.py  (shared on-disk layout for the desktop tool family)

The single directory contract every SnapSmack tool shares. One root — default
C:\\snapsmack, overridable with the SNAPSMACK_HOME env var — under which:

    <tool>/                  each tool INSTALLS here (e.g. C:\\snapsmack\\sybu). The
                             .exe and its bundled assets live here.
    shared_library/
        auth/                ONE shared credential vault (snap_vault) — configure
                             the hub key / Gemini key / Drive creds ONCE, every
                             tool reads them here instead of each being set up.
        <site>/db/           SQLite content mirror (manifest + post/asset rows)
        <site>/thumbs/       downloaded thumbs, shared so tools don't re-fetch
    config_files/
        <tool>/              each tool's own config (config.ini etc.), moved off
                             next-to-exe into one shared, backed-up-together place.
    logs/
        <tool>_<logname>_YYYY-MM-DD-HH-MM.log    per-run log files, all in one place.
    <app>/out/               per-tool FINISHED output (e.g. coldsnap/out)

LAYOUT HISTORY. Tools used to keep config.ini next to their exe (SUYB was a
portable thumb-drive tool that NEVER wrote to fixed locations). As of the
C:\\snapsmack consolidation that is retired family-wide: config + logs live under
the shared root. On first run each tool MIGRATES its old next-to-exe config into
config_files/ via adopt_legacy() so nobody loses their settings. Shared data +
credentials already lived here; this extends the contract to config and logs.

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
import shutil
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


def profiles_dir() -> str:
    """…/shared_library/profiles — the ONE cross-tool connection-profile store.

    One JSON file per site, named by site_key(), so every tool computes the same
    filename for the same blog and therefore sees what another tool saved. Set a
    blog up in one tool; the rest find it. See snap_profiles.py for the format."""
    return _ensure(os.path.join(shared_library(), "profiles"))


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


# ── Tool name → safe folder segment ──────────────────────────────────────────
def _tool_key(tool: str) -> str:
    """Normalise a tool name to a safe, lowercase single folder segment.
    'COLDSNAP' -> 'coldsnap'. Raises on input that yields nothing usable."""
    name = re.sub(r"[^A-Za-z0-9_.-]", "-", (tool or "").strip()).strip(".-").lower()
    name = re.sub(r"-{2,}", "-", name)
    if not name:
        raise ValueError(f"tool yields no usable folder name: {tool!r}")
    return name


# ── Install dir (where a tool's exe lives) ───────────────────────────────────
def install_dir(tool: str) -> str:
    """C:\\snapsmack\\<tool> — where a tool installs (exe + bundled assets)."""
    return _ensure(contained_local_path(home(), _tool_key(tool)))


# ── Config files (shared config location) ────────────────────────────────────
def config_dir(tool: str = None) -> str:
    """C:\\snapsmack\\config_files[\\<tool>] — configs moved off next-to-exe. With no
    tool, the parent bucket; with a tool, that tool's own contained subfolder."""
    base = _ensure(os.path.join(home(), "config_files"))
    if tool is None:
        return base
    return _ensure(contained_local_path(base, _tool_key(tool)))


def config_path(tool: str, name: str = "config.ini") -> str:
    """Full path to one of a tool's config files under config_files/<tool>/.
    `name` is code-supplied but contained anyway."""
    return contained_local_path(config_dir(tool), name)


# ── Logs (shared, one file per run) ──────────────────────────────────────────
def logs_dir() -> str:
    """C:\\snapsmack\\logs — every tool's run logs in one place."""
    return _ensure(os.path.join(home(), "logs"))


def log_path(tool: str, logname: str = "run", when=None) -> str:
    """C:\\snapsmack\\logs\\<tool>_<logname>_YYYY-MM-DD-HH-MM.log for this run.
    Pass `when` (a datetime) in tests for a deterministic name; defaults to now."""
    from datetime import datetime
    stamp = (when or datetime.now()).strftime("%Y-%m-%d-%H-%M")
    seg = re.sub(r"[^A-Za-z0-9_.-]", "-", (logname or "run").strip()).strip(".-").lower() or "run"
    return os.path.join(logs_dir(), f"{_tool_key(tool)}_{seg}_{stamp}.log")


# ── First-run migration ──────────────────────────────────────────────────────
def adopt_legacy(old_path: str, new_path: str) -> bool:
    """Migrate a legacy next-to-exe file into the new shared location: if `new_path`
    does not yet exist but `old_path` does, copy old -> new (preserving the old file
    so a downgrade still finds it). Returns True iff a copy happened. Never raises."""
    try:
        if old_path and os.path.isfile(old_path) and not os.path.exists(new_path):
            os.makedirs(os.path.dirname(new_path), exist_ok=True)
            shutil.copy2(old_path, new_path)
            return True
    except Exception:
        pass
    return False


def adopt_legacy_tree(old_dir: str, new_dir: str) -> bool:
    """Directory form of adopt_legacy: if `new_dir` does not yet exist (or is empty)
    but `old_dir` exists with contents, copy the whole tree old -> new, preserving the
    old dir. Returns True iff a copy happened. Never raises."""
    try:
        if not old_dir or not os.path.isdir(old_dir):
            return False
        if not os.listdir(old_dir):
            return False
        if os.path.isdir(new_dir) and os.listdir(new_dir):
            return False  # new location already populated — don't clobber
        shutil.copytree(old_dir, new_dir, dirs_exist_ok=True)
        return True
    except Exception:
        pass
    return False
# ===== SNAPSMACK EOF =====
