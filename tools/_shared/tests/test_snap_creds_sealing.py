"""
SECAUDIT 054 — the shared credential vault was never actually engaging.

snap_vault.init() clears the held key, and snap_creds.init() (called on every
get/set) used to only re-bind — so every secret silently fell back to recoverable
base64 even when a vault existed. snap_creds.init() now restores the key from the
OS keychain (and, once unlocked, stays unlocked across calls). This proves secrets
are genuinely ENCRYPTED at rest, including across the keychain-restore path that is
the real production mechanism (enable with "remember on this machine").

Run: python tools/_shared/tests/test_snap_creds_sealing.py   (exit 0 = all pass)
Skips the sealed-at-rest assertions cleanly if the crypto backend is absent.
"""

import base64
import os
import sys
import tempfile

os.environ["SNAPSMACK_HOME"] = tempfile.mkdtemp(prefix="creds-seal-")
sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

import snap_creds
import snap_vault


def _store_bytes():
    with open(snap_creds._store_path(), "rb") as f:
        return f.read()


def _checks():
    n = 0
    SECRET = "AT-REST-SECRET-9Z"

    # Baseline: with NO vault, secrets are base64 (the pre-fix at-rest exposure) —
    # documents the interim, and that storage always works.
    snap_creds.set("plain_probe", "PROBE-VALUE")
    assert snap_creds.get("plain_probe") == "PROBE-VALUE"
    assert snap_creds.sealing_active() is False, "no vault yet, so nothing is sealed"
    n += 1

    if not snap_vault.crypto_available():
        print("  (vault crypto not available — sealed-at-rest assertions skipped)")
        return n

    # THE FIX, held-key path: enable a vault; snap_creds.init() (every set) must not
    # re-lock it, so the secret is sealed, not base64.
    snap_creds.init()
    if not snap_vault.is_enabled():
        snap_vault.enable("test-pass", store_machine_key=False)
    assert snap_creds.sealing_active() is True
    snap_creds.set("held_secret", SECRET)
    assert snap_creds.sealing_active() is True, "vault re-locked between calls (the old bug)"
    raw = _store_bytes()
    assert SECRET.encode() not in raw, "plaintext secret in store"
    assert base64.b64encode(SECRET.encode()) not in raw, "base64 secret in store — not sealed"
    assert snap_creds.get("held_secret") == SECRET, "sealed secret won't round-trip"
    n += 1

    # THE FIX, keychain-restore path (the real transparent mechanism): cache the key
    # in the OS keychain, drop the in-process key, and confirm snap_creds.init()
    # transparently restores it and still seals. Cleans up the keychain entry after.
    if snap_vault.keychain_available():
        try:
            snap_vault.store_machine_key_now()
            snap_vault.lock()                       # forget the in-process key
            assert snap_vault.is_unlocked() is False
            # get_site / set_site call snap_creds.init(), which now restores the key
            # from the keychain — the real per-site path profiles use.
            snap_creds.set_site("https://kc.ing", "api_key", "KC-" + SECRET)
            assert snap_creds.sealing_active() is True, "keychain restore did not re-open the vault"
            raw = _store_bytes()
            assert ("KC-" + SECRET).encode() not in raw, "keychain-path secret left plaintext"
            assert base64.b64encode(("KC-" + SECRET).encode()) not in raw, "keychain-path secret base64 — not sealed"
            assert snap_creds.get_site("https://kc.ing", "api_key") == "KC-" + SECRET
            n += 1
        finally:
            try:
                snap_vault.clear_machine_key()
            except Exception:
                pass
    else:
        print("  (no usable keychain — restore-path assertion skipped)")

    return n


if __name__ == "__main__":
    try:
        print("OK — %d checks passed" % _checks())
    finally:
        import shutil
        shutil.rmtree(os.environ["SNAPSMACK_HOME"], ignore_errors=True)
