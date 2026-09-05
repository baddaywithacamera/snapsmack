"""
SNAPSMACK — snap_creds.py  (ONE shared secret store for the whole tool family)

The friction this kills: today every tool is configured on its own — the Gemini
key here, the Drive credentials there. This is a single store under the shared
auth dir (snap_home.auth_dir()) that every tool reads and writes, so a secret is
entered ONCE and all tools see it.

Security posture:
  * Every new value is sealed by snap_vault (scrypt-derived Fernet, 'enc1:' form).
  * On systems with a protected credential service, first use creates a random
    machine-bound vault automatically.
  * Existing plaintext/base64 values are readable only as a migration source and
    are atomically re-sealed after unlock. A locked/unavailable vault refuses new
    writes rather than silently downgrading them to recoverable obfuscation.

This module does NOT replace a tool's own config.ini. It is the shared layer for
the handful of secrets that are genuinely the same across tools. A tool reads the
shared value first and falls back to its own config (see each tool's wiring), so
existing installs keep working untouched.

Well-known keys (conventional, not enforced):
  claude_api_key      Anthropic Claude API key
  gemini_api_key      Google Gemini API key (vision enrichment / ALT)
  openai_api_key      OpenAI API key
  deepseek_api_key    DeepSeek API key
  kimi_api_key        Kimi / Moonshot API key
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
import secrets

import snap_vault
import snap_home


_SHARED_APP = "SnapSmackShared"     # one vault scope shared by ALL tools (see snap_vault.init)
_STORE_NAME = "shared_creds.json"
_B64_PREFIX = "b64:"
_initialized_for = None


def _store_path() -> str:
    return os.path.join(snap_home.auth_dir(), _STORE_NAME)


def init() -> None:
    """Bind the shared vault to the shared auth dir. Idempotent; safe every launch.
    Unlike a per-tool vault, this deliberately uses ONE scope so every tool opens
    the SAME vault — that is the whole point of a shared store."""
    global _initialized_for
    auth = os.path.abspath(snap_home.auth_dir())
    if _initialized_for == auth:
        return
    os.makedirs(auth, exist_ok=True)
    snap_vault.init(_SHARED_APP, meta_dir=auth)
    _initialized_for = auth

    if snap_vault.is_enabled():
        snap_vault.unlock_with_machine_key()
    elif snap_vault.crypto_available() and snap_vault.keychain_available():
        # First secure launch: create a random vault key and bind it to this
        # machine's protected credential service. Nothing recoverable is written
        # beside the executables.
        snap_vault.enable(secrets.token_urlsafe(48), store_machine_key=False)
        if not snap_vault.store_machine_key_now():
            # Do not strand a new vault behind a random passphrase if the OS
            # credential-store write fails after its availability probe.
            snap_vault.disable()

    if snap_vault.is_unlocked():
        _migrate_legacy_values()


# ── Sealing (vault if available, else base64) ────────────────────────────────
def _seal(value: str) -> str:
    value = value or ""
    if snap_vault.is_unlocked():
        return snap_vault.encrypt(value)                      # 'enc1:...'
    raise RuntimeError(
        "The shared credential vault is locked or no protected credential service is available. "
        "Unlock or enable the vault before saving secrets.")


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
    os.makedirs(os.path.dirname(p), exist_ok=True)
    tmp = p + ".tmp"
    with open(tmp, "w", encoding="utf-8") as f:
        json.dump(data, f, indent=2, ensure_ascii=False)
    os.replace(tmp, p)
    try:
        os.chmod(p, 0o600)
    except Exception:
        pass


def _migrate_legacy_values() -> None:
    """Seal plaintext/base64 entries only after the vault is usable.

    The complete replacement is written atomically; a failure leaves the old
    readable store untouched (never delete-first).
    """
    data = _read()
    changed = False
    migrated = {}
    for key, blob in data.items():
        if snap_vault.is_encrypted(blob):
            migrated[key] = blob
            continue
        value = _open(blob)
        migrated[key] = snap_vault.encrypt(value)
        changed = True
    if changed:
        _write(migrated)


# ── Public API ───────────────────────────────────────────────────────────────
def get(key: str, default: str = "") -> str:
    """Return the shared secret for `key`, or `default` if unset/unreadable."""
    init()
    data = _read()
    if key not in data:
        return default
    val = _open(data[key])
    return val if val else default


def set(key: str, value: str) -> None:
    """Store `value` under `key`, sealed with the vault if unlocked else base64."""
    init()
    data = _read()
    data[key] = _seal(value or "")
    _write(data)


def has(key: str) -> bool:
    """True if `key` is present (regardless of whether it decrypts right now)."""
    init()
    return key in _read()


def delete(key: str) -> None:
    init()
    data = _read()
    if key in data:
        del data[key]
        _write(data)


def site_secret_name(site_url: str, field: str) -> str:
    """Stable vault key for one site's credential (never written to profiles)."""
    clean_field = re.sub(r"[^a-z0-9_]+", "_", str(field or "").lower()).strip("_")
    if not clean_field:
        raise ValueError("site credential field is required")
    return "site:%s:%s" % (snap_home.site_key(site_url), clean_field)


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
SHARED_KEYS = ("claude_api_key", "gemini_api_key", "openai_api_key",
               "deepseek_api_key", "kimi_api_key",
               "google_credentials", "drive_folder_id")


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
