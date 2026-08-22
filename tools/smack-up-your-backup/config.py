"""
Smack Up Your Backup — config.py
Global config persistence (window geometry, last-used profile, defaults).
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.




import configparser
import os
import sys


def _app_dir() -> str:
    """The tool's own directory — next to the .exe when frozen, source dir otherwise.
    Historically SUYB was a portable thumb-drive tool that kept ALL state here; as of
    the C:\\snapsmack consolidation, config.ini moves to the shared config_files/ (see
    _config_file() below). Remaining state files still resolve from here pending their
    own migration pass."""
    if getattr(sys, 'frozen', False):
        return os.path.dirname(sys.executable)
    return os.path.dirname(os.path.abspath(__file__))


def _shared_home():
    """Import snap_home (the C:\\snapsmack directory contract). None if unreachable —
    callers then fall back to next-to-exe so old installs keep working."""
    try:
        _sd = os.path.join(_app_dir(), '..', '_shared')
        if os.path.isdir(_sd) and _sd not in sys.path:
            sys.path.insert(0, _sd)
        import snap_home
        return snap_home
    except Exception:
        return None


def shared_cred(key: str, default: str = "") -> str:
    """Read one value from The Hub's shared credential store (snap_creds, the same
    box THE HUB app fills on Discover Fleet). This is how SUYB honours the rule that
    every tool needing a login pulls from The Hub, not its own private store.
    Returns `default` if the shared store isn't reachable (old / portable installs),
    so nothing breaks where the shared home doesn't exist yet."""
    try:
        _sd = os.path.join(_app_dir(), '..', '_shared')
        if os.path.isdir(_sd) and _sd not in sys.path:
            sys.path.insert(0, _sd)
        import snap_creds
        val = snap_creds.get(key, default)
        return val if val else default
    except Exception:
        return default


def effective_backup_key(profile: dict) -> str:
    """The key SUYB actually authenticates a backup with (546D hub-key model).

    Prefers the ONE fleet 'backup_hub_key' from The Hub's shared store when it is
    set: one key, provisioned to every site as a labeled/revocable row, held once
    in The Hub. Falls back to the profile's own api_key when the hub key is unset —
    so existing installs (and any site not yet provisioned) keep working exactly as
    before. Empty hub key (today's default) => identical to the old behaviour, so
    this is non-breaking until The Hub actually publishes a backup_hub_key.

    Rollout order matters: provision the hub key onto every site FIRST, THEN set
    backup_hub_key in the shared store — otherwise a not-yet-provisioned site 401s
    (there is deliberately no per-site fallback once the hub key is in force, so a
    per-blog revoke actually stops that blog).

    SELF-HEAL ("just works"): if this profile carries the site's FULL hub key
    (extras.api_key_local — stored by The Hub's Discover Fleet), mint a FRESH
    backup key from it right here, every run. The site retires the prior one, so
    the backup key can never drift, expire, or get revoked into a 401 — there is
    no key to paste, no CLI, no per-site setup. If that path isn't available
    (older/manual profile), fall back to the shared hub key, then the stored key,
    exactly as before — non-breaking."""
    profile = profile or {}
    akl  = ((profile.get("extras") or {}).get("api_key_local") or "").strip()
    site = (profile.get("site_url") or profile.get("url") or "").strip()
    if akl and site:
        try:
            _sd = os.path.join(_app_dir(), '..', '_shared')
            if os.path.isdir(_sd) and _sd not in sys.path:
                sys.path.insert(0, _sd)
            import snap_discovery
            fresh = snap_discovery._provision_spoke_key(site, akl, "suyb")
            if fresh:
                return fresh
        except Exception:
            pass  # fall through to the stored key — never block a backup on this
    hub = shared_cred("backup_hub_key")
    if hub:
        return hub
    return profile.get("api_key", "") or ""


def resolve_file(name: str) -> str:
    """Path to a SUYB config/state FILE under C:\\snapsmack\\config_files\\suyb,
    migrating the legacy next-to-exe copy in on first run (adopt_legacy). Falls back
    to next-to-exe if the shared home isn't reachable. Shared by the sibling modules
    (credential_store, profile_manager, secret_vault) so all of SUYB's state lands in
    one place."""
    legacy = os.path.join(_app_dir(), name)
    h = _shared_home()
    if not h:
        return legacy
    try:
        new_path = h.config_path('suyb', name)
        h.adopt_legacy(legacy, new_path)
        return new_path
    except Exception:
        return legacy


def resolve_dir(name: str) -> str:
    """Directory form of resolve_file (profiles/, sync_jobs/) — migrates the whole
    tree on first run (adopt_legacy_tree). Falls back to next-to-exe."""
    legacy = os.path.join(_app_dir(), name)
    h = _shared_home()
    if not h:
        return legacy
    try:
        new_dir = os.path.join(h.config_dir('suyb'), name)
        h.adopt_legacy_tree(legacy, new_dir)
        return new_dir
    except Exception:
        return legacy


CONFIG_FILE = resolve_file("config.ini")

DEFAULTS = {
    "window": {
        "width":  "1100",
        "height": "920",
        "x":      "",
        "y":      "",
    },
    "app": {
        "last_profile":    "",
        "active_tab":      "backup",
    },
    "pacing": {
        "transfer_delay":      "2",
        "batch_size":          "0",
        "keepalive_interval":  "60",
    },
    "cloud": {
        "provider":         "google_drive",
        "credentials_file": "",
        "folder_id":        "",
    },
}


def load() -> configparser.ConfigParser:
    cfg = configparser.ConfigParser()
    # Seed with defaults
    for section, values in DEFAULTS.items():
        cfg[section] = values
    if os.path.exists(CONFIG_FILE):
        cfg.read(CONFIG_FILE)
    return cfg


def save(cfg: configparser.ConfigParser) -> None:
    with open(CONFIG_FILE, "w") as f:
        cfg.write(f)
# ===== SNAPSMACK EOF =====
