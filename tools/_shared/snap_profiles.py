"""
SNAPSMACK — snap_profiles.py  (the ONE cross-tool connection-profile store)

Every SnapSmack desktop tool used to keep its own per-site connection profiles in
its own folder — SYBU in `sybu\\profiles`, GYSS in `config_files\\gyss`, and so on —
so a blog you set up in one tool was invisible to the next. This is the shared
store that fixes that: one JSON file per site under

    <root>\\shared_library\\profiles\\<site_key>.json

keyed by the site's hostname (snap_home.site_key), so EVERY tool computes the same
filename for the same blog and therefore finds what another tool saved. Set a blog
up once; the rest pick it up.

CANONICAL ON-DISK SHAPE:

    {
      "schema": 2,
      "name":        "Forever Photograph",         # display name
      "site_url":    "https://foreverphotograph.ing",
      "last_connected": null,
      "extras": { ... }                             # tool-specific / blog-default
                                                    #   keys. A tool that doesn't
                                                    #   know a key ignores it; the
                                                    #   value still travels intact.
    }

In memory a profile carries a plaintext ``api_key`` hydrated from the protected
shared credential vault.  No credential is written to profile JSON.  Schema-1
base64/plaintext fields are accepted only as migration input, verified in the
vault, and then atomically scrubbed from the profile.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import json
import os

# snap_home is a sibling in tools/_shared; tools put _shared on sys.path before
# importing this, exactly as they do for snap_home / shared creds.
from snap_home import profiles_dir, site_key
import snap_creds
import snap_site_settings


SCHEMA = 2

# Keys that live at the top level of a canonical profile. Anything else a caller
# hands us is preserved under "extras" so tool-specific fields travel with the
# profile without polluting the shared core.
_CORE_KEYS = {"name", "site_url", "api_key", "last_connected", "extras",
              "portable", "portable_sync"}
_SECRET_EXTRA_KEYS = {
    "api_key", "api_key_local", "api_key_remote", "api_key_backup",
    "api_key_gyss", "api_key_sybu", "api_key_ohsnap", "api_key_tyswy",
    "api_key_unzucker", "api_key_flkrfckr", "api_key_smackpress",
    "api_key_bloggerflogger",
    "heartbeat_key", "backup_key",
}


def _legacy_deobfuscate(blob: str) -> str:
    import base64
    try:
        return base64.b64decode((blob or "").encode("ascii")).decode("utf-8")
    except Exception:
        return ""


def _path_for_site(site_url: str) -> str:
    return os.path.join(profiles_dir(), site_key(site_url) + ".json")


def _atomic_write(path: str, data: dict) -> None:
    """Recovered post-652D-merge — save() calls this; the merge dropped it and
    every profile save crashed with NameError."""
    os.makedirs(os.path.dirname(path), exist_ok=True)
    tmp = path + ".tmp"
    with open(tmp, "w", encoding="utf-8") as handle:
        json.dump(data, handle, indent=2, ensure_ascii=False)
        handle.flush()
        os.fsync(handle.fileno())
    os.replace(tmp, path)


def _profile_is_untampered(path: str, data: dict) -> str:
    """SECAUDIT 054 F1 — tamper check on a shared profile.

    save() names every profile <site_key(site_url)>.json, so a profile's in-file
    site_url MUST reduce to the filename it is stored under. If it does not, the
    file was altered — site_url flipped to an attacker's host while the real
    api_key rides along — and any tool that then builds a Bearer session from it
    would hand that site's key to the attacker. Return '' when the profile is
    self-consistent, else a one-line reason it is refused. Enforced HERE, at the
    shared loader, so every reader inherits it instead of one remembered call site.
    """
    site = (data.get("site_url") or "").strip()
    if not site:
        return "profile has no site_url"
    stem = os.path.basename(path)
    if stem.endswith(".json"):
        stem = stem[:-5]
    try:
        actual = site_key(site)
    except Exception as e:
        return f"unusable site_url {site!r}: {e}"
    if actual != stem:
        return (f"profile host {actual!r} != filename host {stem!r} "
                f"— tampered profile refused (re-save it to confirm the site)")
    return ""


def _read_verified(path: str):
    """_read() + the F1 tamper check. Returns the raw on-disk dict, or None if the
    file is missing/unreadable OR fails the tamper check (loud on stderr, never a
    silent drop)."""
    data = _read(path)
    if not data:
        return None
    reason = _profile_is_untampered(path, data)
    if reason:
        import sys
        print("[snap_profiles] REFUSED %s: %s" % (os.path.basename(path), reason),
              file=sys.stderr)
        return None
    return data


def _to_disk(profile: dict) -> dict:
    """Canonical in-memory profile -> non-secret on-disk profile."""
    p = dict(profile)
    extras = dict(p.get("extras") or {})
    # Fold any stray non-core keys into extras rather than dropping them.
    for k in list(p.keys()):
        if k not in _CORE_KEYS:
            extras[k] = p.pop(k)
    site = (p.get("site_url") or "").strip()
    return {
        "schema":         SCHEMA,
        "name":           p.get("name") or (site_key(site) if site else ""),
        "site_url":       site,
        "last_connected": p.get("last_connected"),
        "extras":         extras,
        "portable":       snap_site_settings.validate_portable(p.get("portable") or {}),
        "portable_sync":  dict(p.get("portable_sync") or {}),
    }


def _from_disk(data: dict) -> dict:
    """On-disk -> canonical in-memory, hydrating secrets from the vault."""
    site = data.get("site_url", "")
    return {
        "name":           data.get("name", ""),
        "site_url":       data.get("site_url", ""),
        "api_key":        snap_creds.get_site(site, "api_key") if site else "",
        "last_connected": data.get("last_connected"),
        "extras":         dict(data.get("extras") or {}),
        "portable":       snap_site_settings.validate_portable(data.get("portable") or {}),
        "portable_sync":  dict(data.get("portable_sync") or {}),
    }


def _migrate_secrets(path: str, data: dict) -> dict:
    """Copy legacy profile secrets to the vault, verify, then scrub atomically."""
    site = (data.get("site_url") or "").strip()
    if not site:
        return data
    candidates = {}
    legacy_primary = _legacy_deobfuscate(data.get("api_key_enc", ""))
    if legacy_primary:
        candidates["api_key"] = legacy_primary
    extras = dict(data.get("extras") or {})
    for key in _SECRET_EXTRA_KEYS:
        value = extras.get(key)
        if isinstance(value, str) and value:
            candidates[key] = value
    if not candidates and data.get("schema") == SCHEMA:
        return data
    for field, value in candidates.items():
        snap_creds.set_site(site, field, value)
        if snap_creds.get_site(site, field) != value:
            return data
    cleaned = dict(data)
    cleaned.pop("api_key_enc", None)
    cleaned["schema"] = SCHEMA
    cleaned_extras = dict(extras)
    for field in candidates:
        cleaned_extras.pop(field, None)
    cleaned["extras"] = cleaned_extras
    cleaned["portable"] = snap_site_settings.validate_portable(cleaned.get("portable") or {})
    cleaned["portable_sync"] = dict(cleaned.get("portable_sync") or {})
    _atomic_write(path, cleaned)
    return cleaned


def _read(path):
    try:
        with open(path, encoding="utf-8") as f:
            data = json.load(f)
        return _migrate_secrets(path, data) if isinstance(data, dict) else None
    except Exception:
        return None


def _iter_files():
    d = profiles_dir()
    try:
        os.makedirs(d, exist_ok=True)
        for fname in sorted(os.listdir(d)):
            if fname.endswith(".json"):
                yield os.path.join(d, fname)
    except Exception:
        return


def save(profile: dict) -> str:
    """Write a canonical profile (needs a non-empty site_url). Returns the path."""
    site = (profile.get("site_url") or "").strip()
    if not site:
        raise ValueError("snap_profiles.save: profile has no site_url")
    os.makedirs(profiles_dir(), exist_ok=True)
    if profile.get("api_key"):
        snap_creds.set_site(site, "api_key", profile["api_key"])
    extras = dict(profile.get("extras") or {})
    for field in _SECRET_EXTRA_KEYS:
        value = extras.pop(field, None)
        if isinstance(value, str) and value:
            snap_creds.set_site(site, field, value)
            if snap_creds.get_site(site, field) != value:
                raise RuntimeError("could not verify site credential vault write")
    profile = dict(profile)
    profile["extras"] = extras
    path = _path_for_site(site)
    _atomic_write(path, _to_disk(profile))
    return path


def list_profiles() -> list:
    """Every stored profile as a canonical dict (plaintext api_key), sorted by name."""
    out = []
    for path in _iter_files():
        data = _read_verified(path)
        if data:
            out.append(_from_disk(data))
    out.sort(key=lambda p: (p.get("name") or "").lower())
    return out


def load_by_site(site_url: str):
    data = _read_verified(_path_for_site(site_url))
    return _from_disk(data) if data else None


def load_by_name(name: str):
    for path in _iter_files():
        data = _read_verified(path)
        if data and data.get("name") == name:
            return _from_disk(data)
    return None


def delete_by_site(site_url: str) -> bool:
    path = _path_for_site(site_url)
    if os.path.exists(path):
        os.remove(path)
        return True
    return False


def delete_by_name(name: str) -> bool:
    for path in _iter_files():
        data = _read(path)
        if data and data.get("name") == name:
            os.remove(path)
            return True
    return False


def migrate_in(profiles, overwrite: bool = False) -> int:
    """Import an iterable of canonical dicts into the shared store. A site that
    already has a shared profile is left alone unless overwrite=True (so a tool's
    one-time migration never clobbers a profile another tool already shared, and a
    profile the user later deleted is not resurrected). Returns the number written."""
    written = 0
    for prof in profiles:
        site = (prof.get("site_url") or "").strip()
        if not site:
            continue
        if not overwrite and os.path.exists(_path_for_site(site)):
            continue
        try:
            save(prof)
            written += 1
        except Exception:
            pass
    return written
# ===== SNAPSMACK EOF =====
