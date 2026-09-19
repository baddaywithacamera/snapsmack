"""
Smack Up Your Backup — backup_signing.py

SECAUDIT 054 item 8: sign every backup package, verify before restore.

WHAT THIS PROTECTS AGAINST. A backup lives in cloud storage (Drive / B2 / a
box on the LAN). If that storage is compromised — or the file is swapped in
transit — RESTORE would happily unpack whatever it was handed. 058 B stopped
the disk-bomb; this stops the quiet substitution: a package that was not
produced by THIS SUYB installation is refused before a byte is trusted.

HOW. Every package carries one extra member, `SUYB-SIGNATURE.json`:

    {"version": 1, "alg": "HMAC-SHA256", "created": "...",
     "members": {"<arcname>": "<sha256 hex>", ...},
     "hmac": "<hex>"}

`hmac` is HMAC-SHA256, keyed by a 32-byte secret that lives ONLY on this
machine (`backup-signing.key` in SUYB's config folder, created on first use;
encrypted with the credential vault when the vault is enabled and unlocked),
over the canonical JSON of the `members` map. Restore recomputes every
member's sha256 from the archive, rebuilds the map, and checks the HMAC.

A package with NO signature member (made before SUYB 0.7.44) is reported as
UNSIGNED — the caller decides (the GUI asks; headless refuses). A package with
a signature that does not verify is refused outright, no override: that is
the case this module exists for.

The secret is not the site's API key and is not derived from it — the server
must not be able to forge a "good" backup either.
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.

from __future__ import annotations

import datetime
import hashlib
import hmac
import json
import os
import secrets
import zipfile
from typing import Dict, Optional, Tuple

import config

SIGNATURE_MEMBER = "SUYB-SIGNATURE.json"
_KEY_FILE = "backup-signing.key"
_CHUNK = 1024 * 1024


# ── the local secret ──────────────────────────────────────────────────────────
def _key_path() -> str:
    return config.resolve_file(_KEY_FILE)


def _vault():
    try:
        import secret_vault
        return secret_vault
    except Exception:  # noqa: BLE001
        return None


def signing_key(create: bool = True) -> Optional[bytes]:
    """The 32-byte local secret. Created on first use when `create` is True.
    Stored vault-encrypted when the vault is enabled and unlocked, else as a
    plain hex file (same trust level as an unencrypted profile)."""
    path = _key_path()
    vault = _vault()
    if os.path.isfile(path):
        raw = open(path, encoding="utf-8").read().strip()
        if raw.startswith("enc1:"):
            if vault is None or not vault.is_unlocked():
                raise RuntimeError(
                    "The backup signing key is vault-encrypted and the vault is locked. "
                    "Unlock the vault to sign or verify backups.")
            raw = vault.decrypt(raw)
        return bytes.fromhex(raw)
    if not create:
        return None
    key = secrets.token_bytes(32)
    payload = key.hex()
    if vault is not None and vault.is_enabled() and vault.is_unlocked():
        payload = vault.encrypt(payload)
    os.makedirs(os.path.dirname(path) or ".", exist_ok=True)
    tmp = path + ".tmp"
    with open(tmp, "w", encoding="utf-8") as f:
        f.write(payload)
    os.replace(tmp, path)
    return key


# ── hashing ───────────────────────────────────────────────────────────────────
def _sha256_member(zf: zipfile.ZipFile, info: zipfile.ZipInfo) -> str:
    h = hashlib.sha256()
    with zf.open(info, "r") as src:
        while True:
            chunk = src.read(_CHUNK)
            if not chunk:
                break
            h.update(chunk)
    return h.hexdigest()


def _member_map(zf: zipfile.ZipFile) -> Dict[str, str]:
    out: Dict[str, str] = {}
    for info in zf.infolist():
        if info.filename.endswith("/") or info.filename == SIGNATURE_MEMBER:
            continue
        out[info.filename] = _sha256_member(zf, info)
    return out


def _canonical(members: Dict[str, str]) -> bytes:
    return json.dumps(members, sort_keys=True, separators=(",", ":")).encode("utf-8")


def _mac(key: bytes, members: Dict[str, str]) -> str:
    return hmac.new(key, _canonical(members), hashlib.sha256).hexdigest()


# ── sign ──────────────────────────────────────────────────────────────────────
def sign_zip(zip_path: str) -> Dict[str, str]:
    """Append SUYB-SIGNATURE.json to a finished package. Returns the member map.
    Must run AFTER every other member has been written."""
    key = signing_key(create=True)
    with zipfile.ZipFile(zip_path, "r") as zf:
        if SIGNATURE_MEMBER in zf.namelist():
            raise ValueError("Package is already signed.")
        members = _member_map(zf)
    sig = {
        "version": 1,
        "alg": "HMAC-SHA256",
        "created": datetime.datetime.now(datetime.timezone.utc).isoformat(timespec="seconds"),
        "members": members,
        "hmac": _mac(key, members),
    }
    with zipfile.ZipFile(zip_path, "a", zipfile.ZIP_DEFLATED) as zf:
        zf.writestr(SIGNATURE_MEMBER, json.dumps(sig, indent=2, sort_keys=True))
    return members


# ── verify ────────────────────────────────────────────────────────────────────
class SignatureError(Exception):
    """The package carries a signature and it does NOT verify. Never overridable."""


def verify_zip(zf: zipfile.ZipFile) -> Tuple[str, str]:
    """Verify an open package. Returns (status, detail) where status is one of
    'verified' | 'unsigned'. Raises SignatureError on a bad signature."""
    if SIGNATURE_MEMBER not in zf.namelist():
        return "unsigned", "This package carries no SUYB signature (made before SUYB 0.7.44, or not by SUYB)."
    try:
        sig = json.loads(zf.read(SIGNATURE_MEMBER).decode("utf-8"))
    except Exception as e:  # noqa: BLE001
        raise SignatureError(f"Signature member is unreadable: {e}")
    if sig.get("alg") != "HMAC-SHA256" or not isinstance(sig.get("members"), dict):
        raise SignatureError("Signature member has an unknown format.")
    key = signing_key(create=False)
    if key is None:
        raise SignatureError(
            "This package is signed, but this machine has no backup signing key — it was "
            "made by a different SUYB installation. Restore it from the machine that made it, "
            "or copy that machine's backup-signing.key into this SUYB's config folder.")
    actual = _member_map(zf)
    claimed = sig["members"]
    if not hmac.compare_digest(_mac(key, claimed), str(sig.get("hmac", ""))):
        raise SignatureError("Signature does not verify — the package was not signed by this SUYB.")
    if actual != claimed:
        missing = sorted(set(claimed) - set(actual))
        extra = sorted(set(actual) - set(claimed))
        changed = sorted(k for k in set(claimed) & set(actual) if claimed[k] != actual[k])
        raise SignatureError(
            "Package contents do not match its signature — "
            f"{len(changed)} changed, {len(missing)} missing, {len(extra)} added. "
            "The file was altered after it was made.")
    return "verified", f"Signature verified: {len(actual)} members, signed {sig.get('created', '?')}."

# ===== SNAPSMACK EOF =====
