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

CANONICAL ON-DISK SHAPE (what every tool — Python and JS — agrees on):

    {
      "schema": 1,
      "name":        "Forever Photograph",         # display name
      "site_url":    "https://foreverphotograph.ing",
      "api_key_enc": "<base64>",                    # the blog's API key, base64 —
                                                    #   obfuscation, NOT encryption
                                                    #   (matches the SYBU/SUYB
                                                    #   convention, and base64 ports
                                                    #   cleanly to GYSS's JS).
      "last_connected": null,
      "extras": { ... }                             # tool-specific / blog-default
                                                    #   keys. A tool that doesn't
                                                    #   know a key ignores it; the
                                                    #   value still travels intact.
    }

In memory a profile is the same dict but carries a plaintext "api_key" instead of
"api_key_enc". save() obfuscates on the way out; load()/list() de-obfuscate on the
way in. Nothing here is a secret store — the shared credential VAULT
(shared_library/auth) is where real secrets live; this holds per-blog connections.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import base64
import json
import os

# snap_home is a sibling in tools/_shared; tools put _shared on sys.path before
# importing this, exactly as they do for snap_home / shared creds.
from snap_home import profiles_dir, site_key


SCHEMA = 1

# Keys that live at the top level of a canonical profile. Anything else a caller
# hands us is preserved under "extras" so tool-specific fields travel with the
# profile without polluting the shared core.
_CORE_KEYS = {"name", "site_url", "api_key", "last_connected", "extras"}


def _obfuscate(plain: str) -> str:
    return base64.b64encode((plain or "").encode("utf-8")).decode("ascii")


def _deobfuscate(blob: str) -> str:
    try:
        return base64.b64decode((blob or "").encode("ascii")).decode("utf-8")
    except Exception:
        return ""


def _path_for_site(site_url: str) -> str:
    return os.path.join(profiles_dir(), site_key(site_url) + ".json")


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
    """Canonical in-memory (plaintext api_key) -> on-disk (api_key_enc)."""
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
        "api_key_enc":    _obfuscate(p.get("api_key", "")),
        "last_connected": p.get("last_connected"),
        "extras":         extras,
    }


def _from_disk(data: dict) -> dict:
    """On-disk -> canonical in-memory (plaintext api_key)."""
    return {
        "name":           data.get("name", ""),
        "site_url":       data.get("site_url", ""),
        "api_key":        _deobfuscate(data.get("api_key_enc", "")),
        "last_connected": data.get("last_connected"),
        "extras":         dict(data.get("extras") or {}),
    }


def _read(path):
    try:
        with open(path, encoding="utf-8") as f:
            return json.load(f)
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
    path = _path_for_site(site)
    tmp = path + ".tmp"
    with open(tmp, "w", encoding="utf-8") as f:
        json.dump(_to_disk(profile), f, indent=2)
    os.replace(tmp, path)   # atomic: never leave a half-written profile
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
