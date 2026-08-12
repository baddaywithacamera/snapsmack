"""
Smack Up Your Backup — secret_vault.py

Passphrase-derived encryption for credentials at rest (SECAUDIT 037, Finding A).

Problem: SUYB is a PORTABLE tool — profiles and OAuth token caches ride next to
the executable on a thumb drive. Before this module those secrets were base64
(obfuscation, not encryption), so whoever held the drive held working FTP, admin,
API, and cloud credentials. The OS keychain — the fix SECAUDIT 036 used for the
sibling SmackPress client — is machine-bound and would break SUYB's portability.

Solution: a portable vault. A master key is derived from an operator passphrase
with scrypt; secrets are sealed with Fernet (AES-128-CBC + HMAC-SHA256). The
passphrase is never stored; the drive alone is no longer enough to read secrets.

Unattended backups (OS scheduler / cron) have no human to type a passphrase. For
that path ONLY, the derived master key may be cached in this machine's OS keychain
(via `keyring`) so a scheduled run can unlock without interaction. That cache is
machine-bound and lives OFF the portable drive, so a stolen drive still cannot
decrypt. If no keychain backend is present, unattended backups are cleanly
disabled while encryption is on (the GUI still works) — see headless.py.

State files (portable, in the app dir):
    vault.meta   — JSON: kdf params, salt, and an encrypted verifier. NOT secret.

Nothing here writes a passphrase or an unencrypted master key to the portable drive.
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

import config  # resolve_file() → shared C:\snapsmack layout (vault.meta is not machine-bound; safe to relocate)

# Crypto backend (ships transitively via paramiko → cryptography).
try:
    from cryptography.fernet import Fernet, InvalidToken
    _CRYPTO_OK = True
except Exception:
    Fernet = None
    InvalidToken = Exception
    _CRYPTO_OK = False

# Machine keychain for the unattended path only (optional dependency).
try:
    import keyring
    _KEYRING_OK = True
except Exception:
    keyring = None
    _KEYRING_OK = False


# ── Constants ──────────────────────────────────────────────────────────────
_ENC_PREFIX      = "enc1:"                     # tags an encrypted secret field
_VERIFIER_PLAIN  = b"suyb-vault-verifier-v1"   # sealed under the key to check unlock
_KEYRING_SERVICE = "SmackUpYourBackup"
_KEYRING_USER    = "vault-master-key"
# scrypt work factors — interactive-desktop appropriate, ~100ms on a modern CPU.
_SCRYPT_N        = 1 << 15                      # 32768
_SCRYPT_R        = 8
_SCRYPT_P        = 1
_KEY_LEN         = 32
# OpenSSL rejects scrypt above its default maxmem (~32 MiB); N*r*128 for these
# params is exactly that, so set an explicit ceiling with headroom.
_SCRYPT_MAXMEM   = 132 * 1024 * 1024

# In-memory session key. None = locked. Never written to the portable drive.
_key: Optional[bytes] = None


def _app_dir() -> str:
    if getattr(sys, "frozen", False):
        return os.path.dirname(sys.executable)
    return os.path.dirname(os.path.abspath(__file__))


def _meta_path() -> str:
    return config.resolve_file("vault.meta")


# ── Availability ───────────────────────────────────────────────────────────
def crypto_available() -> bool:
    """True if the encryption backend is importable."""
    return _CRYPTO_OK


def keychain_available() -> bool:
    """True if a machine keychain backend is usable for the unattended path."""
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


# ── Lifecycle ──────────────────────────────────────────────────────────────
def enable(passphrase: str, store_machine_key: bool = False) -> None:
    """Create a new vault, derive + hold the key, and (optionally) cache a
    machine key for unattended runs. Raises if a vault already exists or the
    passphrase is empty."""
    if not _CRYPTO_OK:
        raise RuntimeError("Encryption backend (cryptography) is not available.")
    if is_enabled():
        raise RuntimeError("A vault already exists. Use change_passphrase / disable.")
    if not passphrase:
        raise ValueError("Passphrase must not be empty.")
    salt = os.urandom(16)
    key  = _derive(passphrase, salt, _SCRYPT_N, _SCRYPT_R, _SCRYPT_P)
    verifier = Fernet(key).encrypt(_VERIFIER_PLAIN)
    meta = {
        "version":  1,
        "kdf":      "scrypt",
        "n":        _SCRYPT_N,
        "r":        _SCRYPT_R,
        "p":        _SCRYPT_P,
        "salt":     base64.b64encode(salt).decode(),
        "verifier": verifier.decode(),
    }
    _atomic_write_json(_meta_path(), meta)
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
        if Fernet(key).decrypt(meta["verifier"].encode()) != _VERIFIER_PLAIN:
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
    """Re-key the vault. Callers must re-save all secrets afterward so existing
    ciphertext (sealed under the old key) is re-sealed under the new one."""
    if not new_passphrase:
        raise ValueError("New passphrase must not be empty.")
    if not unlock(old_passphrase):
        return False
    # Rebuild meta with a fresh salt + verifier under the new passphrase.
    salt = os.urandom(16)
    key  = _derive(new_passphrase, salt, _SCRYPT_N, _SCRYPT_R, _SCRYPT_P)
    verifier = Fernet(key).encrypt(_VERIFIER_PLAIN)
    meta = {
        "version": 1, "kdf": "scrypt",
        "n": _SCRYPT_N, "r": _SCRYPT_R, "p": _SCRYPT_P,
        "salt": base64.b64encode(salt).decode(),
        "verifier": verifier.decode(),
    }
    _atomic_write_json(_meta_path(), meta)
    global _key
    _key = key
    return True


def disable() -> None:
    """Remove the vault and any cached machine key. Requires the vault unlocked.
    Callers must first re-save all secrets in cleartext/legacy form."""
    if not is_unlocked():
        raise RuntimeError("Unlock before disabling so secrets can be rewritten.")
    try:
        os.remove(_meta_path())
    except FileNotFoundError:
        pass
    lock()


# ── Secret sealing ─────────────────────────────────────────────────────────
def encrypt(plaintext: str) -> str:
    """Seal a secret. Returns a tagged, drive-safe string. Requires unlocked."""
    if plaintext is None:
        plaintext = ""
    token = _fernet().encrypt(plaintext.encode("utf-8")).decode()
    return _ENC_PREFIX + token


def decrypt(blob: str) -> str:
    """Open a sealed secret produced by encrypt(). Requires unlocked."""
    if not is_encrypted(blob):
        raise ValueError("Not an encrypted value.")
    token = blob[len(_ENC_PREFIX):].encode()
    return _fernet().decrypt(token).decode("utf-8")


def is_encrypted(blob: str) -> bool:
    return isinstance(blob, str) and blob.startswith(_ENC_PREFIX)


# ── Machine key (unattended path) ──────────────────────────────────────────
def store_machine_key_now() -> bool:
    """Cache the current master key in this machine's keychain for unattended
    runs. Requires the vault unlocked and a keychain backend. Returns success."""
    if not is_unlocked() or not keychain_available():
        return False
    try:
        keyring.set_password(_KEYRING_SERVICE, _KEYRING_USER,
                            _key.decode() if isinstance(_key, bytes) else _key)
        return True
    except Exception:
        return False


def has_machine_key() -> bool:
    if not keychain_available():
        return False
    try:
        return keyring.get_password(_KEYRING_SERVICE, _KEYRING_USER) is not None
    except Exception:
        return False


def clear_machine_key() -> None:
    if not keychain_available():
        return
    try:
        keyring.delete_password(_KEYRING_SERVICE, _KEYRING_USER)
    except Exception:
        pass


def unlock_with_machine_key() -> bool:
    """Unlock using the cached machine key — no passphrase. For headless runs.
    Returns success. Fails closed if the cached key does not match the vault."""
    if not is_enabled() or not _CRYPTO_OK or not keychain_available():
        return False
    try:
        stored = keyring.get_password(_KEYRING_SERVICE, _KEYRING_USER)
        if not stored:
            return False
        key = stored.encode()
        with open(_meta_path()) as f:
            meta = json.load(f)
        if Fernet(key).decrypt(meta["verifier"].encode()) != _VERIFIER_PLAIN:
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
