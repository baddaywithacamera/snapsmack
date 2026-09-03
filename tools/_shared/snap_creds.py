"""
SNAPSMACK — snap_creds.py  (ONE shared secret store for the whole tool family)

The friction this kills: today every tool is configured on its own — the Gemini
key here, the Drive credentials there. This is a single store under the shared
auth dir (snap_home.auth_dir()) that every tool reads and writes, so a secret is
entered ONCE and all tools see it.

Security posture — strictly >= what the tools do today:
  * If the shared vault (snap_vault) is set up AND unlocked, each value is SEALED
    with it (scrypt-derived Fernet, 'enc1:' form) — stronger than any tool has now.
  * Otherwise values are base64-obfuscated ('b64:' form) — exactly the at-rest
    form config.py already uses for api_key. No mandatory passphrase, nothing
    breaks, and turning the vault on later re-seals on the next set().

This module does NOT replace a tool's own config.ini. It is the shared layer for
the handful of secrets that are genuinely the same across tools. A tool reads the
shared value first and falls back to its own config (see each tool's wiring), so
existing installs keep working untouched.

Well-known keys (conventional, not enforced):
  gemini_api_key      Google Gemini API key (vision enrichment / ALT)
  google_credentials  path to the Google OAuth client-secret json (Drive)
  drive_folder_id     default Drive folder id
  hub_key             SnapSmack hub key (future hub-authoritative auth)
  backup_hub_key      ONE fleet backup key (546D): provisioned to every site as a
                      revocable row; SUYB authenticates every blog's backup with it

Usage:
    import snap_creds
    snap_creds.init()                          # once, at startup
    key = snap_creds.get('gemini_api_key')     # '' if unset
    snap_creds.set('gemini_api_key', new_key)

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import base64
import json
import os
import re

import snap_vault
import snap_home


_SHARED_APP = "SnapSmackShared"     # one vault scope shared by ALL tools (see snap_vault.init)
_STORE_NAME = "shared_creds.json"
_B64_PREFIX = "b64:"


def _store_path() -> str:
    return os.path.join(snap_home.auth_dir(), _STORE_NAME)


def init() -> None:
    """Bind the shared vault to the shared auth dir, and restore the key from the
    OS keychain when one was cached. Idempotent; safe every launch and every call.

    SECAUDIT 054 — binding alone leaves the vault LOCKED (snap_vault.init clears the
    held key), and nothing here used to re-open it, so every seal silently fell back
    to recoverable base64: the at-rest hole. Now, if the operator enabled the vault
    with "remember on this machine", the key is restored from the keychain here — so
    secrets are actually ENCRYPTED at rest, transparently, with no per-call
    passphrase. The bind is skipped when already bound to this scope so the restored
    key is not clobbered (one keychain read per process, not per credential)."""
    if getattr(snap_vault, "_app", None) != _SHARED_APP:
        snap_vault.init(_SHARED_APP, meta_dir=snap_home.auth_dir())
    if snap_vault.is_enabled() and not snap_vault.is_unlocked():
        try:
            snap_vault.unlock_with_machine_key()
        except Exception:
            pass


# ── Sealing (vault if available, else base64) ────────────────────────────────
def _seal(value: str) -> str:
    value = value or ""
    if snap_vault.is_unlocked():
        return snap_vault.encrypt(value)                      # 'enc1:...'
    return _B64_PREFIX + base64.b64encode(value.encode("utf-8")).decode()


def _open(blob) -> str:
    if snap_vault.is_encrypted(blob):
        try:
            return snap_vault.decrypt(blob)
        except Exception:
            return ""                                         # locked / wrong key
    if isinstance(blob, str) and blob.startswith(_B64_PREFIX):
        try:
            return base64.b64decode(blob[len(_B64_PREFIX):]).decode("utf-8")
        except Exception:
            return ""
    return blob if isinstance(blob, str) else ""              # tolerate legacy plaintext


# ── Store I/O ────────────────────────────────────────────────────────────────
def _read() -> dict:
    p = _store_path()
    if not os.path.isfile(p):
        return {}
    try:
        with open(p, "r", encoding="utf-8") as f:
            data = json.load(f)
        return data if isinstance(data, dict) else {}
    except Exception:
        return {}


def _write(data: dict) -> None:
    p = _store_path()
    tmp = p + ".tmp"
    with open(tmp, "w", encoding="utf-8") as f:
        json.dump(data, f, indent=2, ensure_ascii=False)
    os.replace(tmp, p)
    try:
        os.chmod(p, 0o600)
    except Exception:
        pass


# ── Public API ───────────────────────────────────────────────────────────────
def get(key: str, default: str = "") -> str:
    """Return the shared secret for `key`, or `default` if unset/unreadable."""
    data = _read()
    if key not in data:
        return default
    val = _open(data[key])
    return val if val else default


def set(key: str, value: str) -> None:
    """Store `value` under `key`, sealed with the vault if unlocked else base64."""
    data = _read()
    data[key] = _seal(value or "")
    _write(data)


def has(key: str) -> bool:
    """True if `key` is present (regardless of whether it decrypts right now)."""
    return key in _read()


def delete(key: str) -> None:
    data = _read()
    if key in data:
        del data[key]
        _write(data)


# ── Per-site secrets (the at-rest home for site API keys — SECAUDIT 054) ──────
# A site's posting key must NOT live base64-encoded in its profile file. It lives
# here, sealed by the vault, addressed by a stable per-site name. snap_profiles
# (schema 2) hydrates api_key from here instead of carrying api_key_enc on disk.
def site_secret_name(site_url: str, field: str) -> str:
    """Stable vault key for one site's credential (never written to profiles)."""
    clean_field = re.sub(r"[^a-z0-9_]+", "_", str(field or "").lower()).strip("_")
    if not clean_field:
        raise ValueError("site credential field is required")
    return "site:%s:%s" % (snap_home.site_key(site_url), clean_field)


def sealing_active() -> bool:
    """True when secrets are actually ENCRYPTED at rest (the vault is unlocked),
    False when they would fall back to recoverable base64. Callers use this to
    report honest at-rest status — not to gate storage (storage always works)."""
    init()
    return snap_vault.is_unlocked()


def get_site(site_url: str, field: str, default: str = "") -> str:
    init()
    return get(site_secret_name(site_url, field), default)


def set_site(site_url: str, field: str, value: str) -> None:
    init()
    set(site_secret_name(site_url, field), value)


def delete_site(site_url: str, field: str) -> None:
    init()
    delete(site_secret_name(site_url, field))


# ── Config integration ───────────────────────────────────────────────────────
# The "configure once" set: secrets that are genuinely the same across tools.
# SYBU and COLD SNAP already use these exact dict keys, so the overlay is a
# straight key match.
SHARED_KEYS = (
    "gemini_api_key", "kimi_api_key", "deepseek_api_key", "claude_api_key",
    "openai_api_key", "google_credentials", "drive_folder_id",
)


def apply_shared(cfg: dict, keys=SHARED_KEYS) -> dict:
    """Overlay shared secrets onto a tool's loaded config dict IN PLACE: a shared
    value wins when set, otherwise the tool's own value is kept. Total no-op if the
    shared store / vault is unavailable — existing installs keep working."""
    try:
        init()
        for k in keys:
            v = get(k)
            if v:
                cfg[k] = v
    except Exception:
        pass
    return cfg


def push_shared(cfg: dict, keys=SHARED_KEYS) -> None:
    """Write a tool's non-empty values back to the shared store so setting a key in
    ANY tool propagates to all of them. Never clobbers a good shared value with ''."""
    try:
        init()
        for k in keys:
            v = cfg.get(k)
            if v:
                set(k, v)
    except Exception:
        pass
# ===== SNAPSMACK EOF =====
