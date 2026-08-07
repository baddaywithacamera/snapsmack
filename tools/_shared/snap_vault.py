"""
SnapSmack companion tools — shared credential vault.

Passphrase-derived encryption for secrets at rest. A master key is derived from
an operator passphrase with **scrypt**; secrets are sealed with **Fernet**
(AES-128-CBC + HMAC-SHA256). The passphrase is never written anywhere — only
`vault.meta` (salt, KDF params, an encrypted verifier) rides next to the
executable, so possession of the tool's folder is no longer enough to read a key.

This is a parameterised lift of `smack-up-your-backup/secret_vault.py`, which
SECAUDIT 037 built and shipped in SUYB 0.7.19. The crypto is deliberately a
near-copy rather than a rewrite — the working implementation is the one that has
been tested. What is new here is `init()`, so several tools can share one module
instead of each growing its own copy.

Adopted by FLKR FCKR in SECAUDIT 040 (finding A). **SUYB has NOT been migrated
onto this module** — it is shipped, tested, and its module-level singleton is
wired through profile_manager / sync_manager / cloud_client / headless. Moving it
is worth doing, but as its own change with its own test pass, not as a side
effect of this one.

Why not the OS keyring alone (Unzucker's approach)? Because it is machine-bound,
and these tools keep their state next to the executable by convention. The
keyring is still used here, but only as an OPTIONAL convenience: it caches the
derived key so the operator is not retyping a passphrase on every launch. That
cache is machine-bound and never travels with the folder, so a copied folder
still cannot be opened.

Usage:
    import snap_vault
    snap_vault.init('FlkrFckr')          # once, at startup
    if snap_vault.is_enabled() and not snap_vault.unlock_with_machine_key():
        snap_vault.unlock(passphrase_from_user)
    sealed = snap_vault.encrypt('my-api-key')
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.


import base64
import hashlib
import json
import os
import sys
from typing import Optional

try:
    from cryptography.fernet import Fernet
    _CRYPTO_OK = True
except Exception:
    Fernet = None
    _CRYPTO_OK = False

try:
    import keyring
    _KEYRING_OK = True
except Exception:
    keyring = None
    _KEYRING_OK = False


# ── Constants ──────────────────────────────────────────────────────────────
_ENC_PREFIX = "enc1:"          # tags a sealed value; matches SUYB's on-disk form
_KEY_LEN    = 32
# scrypt work factors — interactive-desktop appropriate, ~100 ms on a modern CPU.
_SCRYPT_N   = 1 << 15          # 32768
_SCRYPT_R   = 8
_SCRYPT_P   = 1
# OpenSSL rejects scrypt above its default maxmem (~32 MiB); N*r*128 for these
# params is exactly that, so set an explicit ceiling with headroom.
_SCRYPT_MAXMEM = 132 * 1024 * 1024

# Per-app identity, set by init().
_app             = "SnapSmack"
_verifier_plain  = b"snapsmack-vault-verifier-v1"
_keyring_service = "SnapSmack"
_keyring_user    = "vault-master-key"
_meta_dir: Optional[str] = None

# In-memory session key. None = locked. Never written next to the executable.
_key: Optional[bytes] = None


def init(app_name: str, meta_dir: Optional[str] = None) -> None:
    """
    Bind this vault to one tool. `app_name` scopes both the keyring entry and the
    verifier, so two tools on the same machine never unlock each other's vault
    and a vault.meta copied between tools fails closed rather than half-opening.
    """
    global _app, _verifier_plain, _keyring_service, _meta_dir, _key
    _app             = app_name
    _verifier_plain  = f"{app_name.lower()}-vault-verifier-v1".encode()
    _keyring_service = f"SnapSmack.{app_name}"
    _meta_dir        = meta_dir
    _key             = None          # switching apps must not carry a key across


def _app_dir() -> str:
    if _meta_dir:
        return _meta_dir
    if getattr(sys, "frozen", False):
        return os.path.dirname(sys.executable)
    return os.path.dirname(os.path.abspath(__file__))


def _meta_path() -> str:
    return os.path.join(_app_dir(), "vault.meta")


# ── Availability ───────────────────────────────────────────────────────────
def crypto_available() -> bool:
    """True if the encryption backend is importable."""
    return _CRYPTO_OK


def keychain_available() -> bool:
    """True if a machine keychain backend is actually usable."""
    if not _KEYRING_OK:
        return False
    try:
        # A NoKeyringError backend registers but raises on use; probe it.
        keyring.get_keyring()
        from keyring.backends.fail import Keyring as _FailKeyring
        return not isinstance(keyring.get_keyring(), _FailKeyring)
    except Exception:
        return False


def is_enabled() -> bool:
    """True if a vault has been created (encryption is turned on)."""
    return os.path.exists(_meta_path())


def is_unlocked() -> bool:
    return _key is not None


# ── Key derivation ─────────────────────────────────────────────────────────
def _derive(passphrase: str, salt: bytes, n: int, r: int, p: int) -> bytes:
    dk = hashlib.scrypt(passphrase.encode("utf-8"), salt=salt,
                        n=n, r=r, p=p, dklen=_KEY_LEN, maxmem=_SCRYPT_MAXMEM)
    return base64.urlsafe_b64encode(dk)   # Fernet wants a urlsafe-b64 32-byte key


def _fernet(key: Optional[bytes] = None) -> "Fernet":
    if not _CRYPTO_OK:
        raise RuntimeError("Encryption backend (cryptography) is not available.")
    k = key if key is not None else _key
    if k is None:
        raise RuntimeError("Vault is locked.")
    return Fernet(k)


def _write_meta(salt: bytes, key: bytes) -> None:
    meta = {
        "version":  1,
        "app":      _app,
        "kdf":      "scrypt",
        "n":        _SCRYPT_N,
        "r":        _SCRYPT_R,
        "p":        _SCRYPT_P,
        "salt":     base64.b64encode(salt).decode(),
        "verifier": Fernet(key).encrypt(_verifier_plain).decode(),
    }
    _atomic_write_json(_meta_path(), meta)


# ── Lifecycle ──────────────────────────────────────────────────────────────
def enable(passphrase: str, store_machine_key: bool = False) -> None:
    """Create a vault, derive + hold the key, optionally cache a machine key."""
    if not _CRYPTO_OK:
        raise RuntimeError("Encryption backend (cryptography) is not available.")
    if is_enabled():
        raise RuntimeError("A vault already exists. Use change_passphrase / disable.")
    if not passphrase:
        raise ValueError("Passphrase must not be empty.")
    salt = os.urandom(16)
    key  = _derive(passphrase, salt, _SCRYPT_N, _SCRYPT_R, _SCRYPT_P)
    _write_meta(salt, key)
    global _key
    _key = key
    if store_machine_key:
        store_machine_key_now()


def unlock(passphrase: str) -> bool:
    """Verify a passphrase against the vault and hold the key. Returns success."""
    if not is_enabled() or not _CRYPTO_OK:
        return False
    try:
        with open(_meta_path()) as f:
            meta = json.load(f)
        salt = base64.b64decode(meta["salt"])
        key  = _derive(passphrase, salt, int(meta["n"]), int(meta["r"]), int(meta["p"]))
        if Fernet(key).decrypt(meta["verifier"].encode()) != _verifier_plain:
            return False
    except Exception:
        return False
    global _key
    _key = key
    return True


def lock() -> None:
    """Drop the in-memory key."""
    global _key
    _key = None


def change_passphrase(old_passphrase: str, new_passphrase: str) -> bool:
    """Re-key the vault. Callers MUST re-save every secret afterwards so existing
    ciphertext (sealed under the old key) is re-sealed under the new one."""
    if not new_passphrase:
        raise ValueError("New passphrase must not be empty.")
    if not unlock(old_passphrase):
        return False
    salt = os.urandom(16)
    key  = _derive(new_passphrase, salt, _SCRYPT_N, _SCRYPT_R, _SCRYPT_P)
    _write_meta(salt, key)
    global _key
    _key = key
    if has_machine_key():        # refresh the cache so it matches the new key
        store_machine_key_now()
    return True


def disable() -> None:
    """Remove the vault and any cached machine key. Requires it unlocked, so the
    caller can rewrite secrets in legacy form first."""
    if not is_unlocked():
        raise RuntimeError("Unlock before disabling so secrets can be rewritten.")
    clear_machine_key()
    try:
        os.remove(_meta_path())
    except FileNotFoundError:
        pass
    lock()


# ── Secret sealing ─────────────────────────────────────────────────────────
def encrypt(plaintext: str) -> str:
    """Seal a secret. Returns a tagged, text-safe string. Requires unlocked."""
    if plaintext is None:
        plaintext = ""
    return _ENC_PREFIX + _fernet().encrypt(plaintext.encode("utf-8")).decode()


def decrypt(blob: str) -> str:
    """Open a sealed secret produced by encrypt(). Requires unlocked."""
    if not is_encrypted(blob):
        raise ValueError("Not an encrypted value.")
    return _fernet().decrypt(blob[len(_ENC_PREFIX):].encode()).decode("utf-8")


def is_encrypted(blob) -> bool:
    return isinstance(blob, str) and blob.startswith(_ENC_PREFIX)


# ── Machine key (optional convenience) ─────────────────────────────────────
def store_machine_key_now() -> bool:
    """Cache the master key in THIS machine's keychain so the passphrase is not
    needed on later launches. Machine-bound: it never travels with the folder."""
    if not is_unlocked() or not keychain_available():
        return False
    try:
        keyring.set_password(_keyring_service, _keyring_user,
                             _key.decode() if isinstance(_key, bytes) else _key)
        return True
    except Exception:
        return False


def has_machine_key() -> bool:
    if not keychain_available():
        return False
    try:
        return keyring.get_password(_keyring_service, _keyring_user) is not None
    except Exception:
        return False


def clear_machine_key() -> None:
    if not keychain_available():
        return
    try:
        keyring.delete_password(_keyring_service, _keyring_user)
    except Exception:
        pass


def unlock_with_machine_key() -> bool:
    """Unlock from the cached machine key — no passphrase. Fails closed if the
    cached key does not match this vault (e.g. the passphrase was changed on
    another machine, or the folder was copied from one)."""
    if not is_enabled() or not _CRYPTO_OK or not keychain_available():
        return False
    try:
        stored = keyring.get_password(_keyring_service, _keyring_user)
        if not stored:
            return False
        key = stored.encode()
        with open(_meta_path()) as f:
            meta = json.load(f)
        if Fernet(key).decrypt(meta["verifier"].encode()) != _verifier_plain:
            return False
    except Exception:
        return False
    global _key
    _key = key
    return True


# ── Internals ──────────────────────────────────────────────────────────────
def _atomic_write_json(path: str, data: dict) -> None:
    tmp = path + ".tmp"
    with open(tmp, "w") as f:
        json.dump(data, f, indent=2)
    os.replace(tmp, path)
    try:
        os.chmod(path, 0o600)
    except Exception:
        pass
# ===== SNAPSMACK EOF =====
