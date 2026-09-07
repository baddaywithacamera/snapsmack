"""
SECAUDIT 054 follow-through — secrets are sealed at rest, or refused.

Rewritten 2026-09-06 for the MANDATORY-vault contract that superseded the
original opt-in design (the 652D merge landed the stronger snap_creds: a new
value is sealed with the shared vault, and a locked/unavailable vault REFUSES
the write instead of silently downgrading to recoverable base64). The old
test asserted the interim behaviour (`sealing_active()`, b64 fallback) and
went stale; these checks pin the current, stronger promise.

Sandboxed: SNAPSMACK_HOME points at a temp dir and the vault is enabled with
a throwaway passphrase (store_machine_key=False), so the test never touches
the real Windows Credential Manager or the real shared vault.

Run: python tools/_shared/tests/test_snap_creds_sealing.py   (exit 0 = all pass)
Skips the sealed-at-rest assertions cleanly if the crypto backend is absent.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import os
import sys
import tempfile

os.environ["SNAPSMACK_HOME"] = tempfile.mkdtemp(prefix="creds-seal-")
sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

import snap_creds
import snap_home
import snap_vault


def _store_bytes() -> bytes:
    try:
        with open(snap_creds._store_path(), "rb") as f:
            return f.read()
    except FileNotFoundError:
        return b""


def _checks() -> int:
    n = 0
    SECRET = "AT-REST-SECRET-9Z"

    if not snap_vault.crypto_available():
        # No crypto backend: the contract is REFUSAL, never a recoverable write.
        try:
            snap_creds.set("probe", SECRET)
            raise AssertionError("set() must refuse when no vault can exist")
        except RuntimeError:
            pass
        assert SECRET.encode() not in _store_bytes(), "refused write must leave no trace"
        print("  (crypto backend absent — refusal path verified, sealing skipped)")
        return n + 1

    # Bind snap_creds FIRST (its init() re-binds the vault and clears any held
    # key). On a machine with a protected credential service init() auto-creates
    # a machine-bound sandbox vault (DPAPI file INSIDE the sandbox meta dir —
    # the real credential store is never written); elsewhere we enable one with
    # a throwaway passphrase.
    snap_creds.init()
    auto_vault = snap_vault.is_enabled()
    if not auto_vault:
        snap_vault.enable("test-passphrase-only", store_machine_key=False)
    assert snap_vault.is_unlocked(), "the sandbox vault should be unlocked after init"

    # Sealed at rest: round-trips, stored as enc1:, plaintext nowhere on disk.
    snap_creds.set("gemini_api_key", SECRET)
    assert snap_creds.get("gemini_api_key") == SECRET
    raw = _store_bytes()
    assert b"enc1:" in raw, "stored value must carry the vault-sealed form"
    assert SECRET.encode() not in raw, "plaintext secret must never reach disk"
    assert b"b64:" not in raw, "no recoverable-obfuscation fallback for new writes"
    n += 3

    # Locked vault: reads fail soft (empty), writes REFUSE — never downgrade.
    snap_vault.lock()
    assert snap_creds.get("gemini_api_key") == "", "locked vault must not decrypt"
    try:
        snap_creds.set("gemini_api_key", "NEW-VALUE-WHILE-LOCKED")
        raise AssertionError("set() must refuse while the vault is locked")
    except RuntimeError:
        pass
    assert b"NEW-VALUE-WHILE-LOCKED" not in _store_bytes()
    n += 3

    # Unlock restores access to the sealed value untouched.
    if auto_vault:
        assert snap_vault.unlock_with_machine_key()
    else:
        assert snap_vault.unlock("test-passphrase-only")
    assert snap_creds.get("gemini_api_key") == SECRET
    n += 1

    return n


passed = _checks()
print(f"OK — {passed} checks passed")

# ===== SNAPSMACK EOF =====
