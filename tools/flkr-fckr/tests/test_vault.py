"""
FLKR FCKR — credential vault regression tests (SECAUDIT 040, finding A).

The API key used to sit in flkrfckr.ini as base64, which is an encoding, not
encryption. These tests pin the properties that make the vault worth having, and
— more importantly — the ones that stop it eating the operator's key:

  * a saved key must survive every state transition (on, off, re-key)
  * a wrong passphrase must not open the vault
  * turning encryption on must leave NO plaintext or base64 copy behind
  * a locked vault must never silently downgrade the store back to base64

stdlib unittest only, so this runs in the packaged build environment too.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import base64
import os
import sys
import tempfile
import unittest
from pathlib import Path

APP_DIR = Path(__file__).resolve().parents[1]
for p in (str(APP_DIR), str(APP_DIR.parent / '_shared')):
    if p not in sys.path:
        sys.path.insert(0, p)

import config as cfg_mod          # noqa: E402
import snap_vault                 # noqa: E402


KEY = 'a1b2c3d4e5f6' * 5          # 60 chars, shaped like a real scoped key


class VaultTests(unittest.TestCase):

    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.ini = os.path.join(self.tmp.name, 'flkrfckr.ini')

        # Point config at a throwaway dir, and the vault at the same place.
        self._orig_path = cfg_mod._config_path
        cfg_mod._config_path = lambda: self.ini
        self.addCleanup(lambda: setattr(cfg_mod, '_config_path', self._orig_path))

        snap_vault.init('FlkrFckrTest', self.tmp.name)
        self.addCleanup(snap_vault.lock)

        # No machine keyring in tests — it would touch the real credential store.
        self._orig_keychain = snap_vault.keychain_available
        snap_vault.keychain_available = lambda: False
        self.addCleanup(lambda: setattr(snap_vault, 'keychain_available',
                                        self._orig_keychain))

        cfg_mod.save({'site_url': 'https://example.com', 'api_key': KEY})

    def _raw(self) -> str:
        import configparser
        c = configparser.ConfigParser()
        c.read(self.ini)
        return c.get('site', 'api_key', fallback='')

    # ── baseline ────────────────────────────────────────────────────────────
    def test_legacy_base64_roundtrip(self):
        self.assertEqual(cfg_mod.load()['api_key'], KEY)
        self.assertEqual(base64.b64decode(self._raw().encode()).decode(), KEY)

    # ── enable ──────────────────────────────────────────────────────────────
    def test_enable_seals_key_and_leaves_no_plaintext(self):
        cfg_mod.enable_encryption('correct horse battery staple')
        raw = self._raw()
        self.assertTrue(snap_vault.is_encrypted(raw))
        self.assertTrue(cfg_mod.is_key_sealed())
        # The key must not survive anywhere in the file, in any obvious form.
        blob = Path(self.ini).read_text(encoding='utf-8')
        self.assertNotIn(KEY, blob)
        self.assertNotIn(base64.b64encode(KEY.encode()).decode(), blob)
        # ...and it must still load.
        self.assertEqual(cfg_mod.load()['api_key'], KEY)

    def test_locked_vault_hides_key_but_keeps_other_settings(self):
        cfg_mod.enable_encryption('pass one')
        snap_vault.lock()
        data = cfg_mod.load()
        self.assertEqual(data['api_key'], '')                    # hidden, not lost
        self.assertEqual(data['site_url'], 'https://example.com')  # rest still loads

    def test_wrong_passphrase_refused(self):
        cfg_mod.enable_encryption('pass one')
        snap_vault.lock()
        self.assertFalse(snap_vault.unlock('pass two'))
        self.assertFalse(snap_vault.is_unlocked())
        self.assertTrue(snap_vault.unlock('pass one'))
        self.assertEqual(cfg_mod.load()['api_key'], KEY)

    def test_locked_save_refuses_rather_than_downgrading(self):
        """A locked vault must not quietly rewrite the key as base64."""
        cfg_mod.enable_encryption('pass one')
        snap_vault.lock()
        with self.assertRaises(RuntimeError):
            cfg_mod.save({'site_url': 'https://example.com', 'api_key': KEY})
        # the sealed value on disk is untouched
        self.assertTrue(snap_vault.is_encrypted(self._raw()))

    # ── disable ─────────────────────────────────────────────────────────────
    def test_disable_restores_legacy_and_key_survives(self):
        cfg_mod.enable_encryption('pass one')
        cfg_mod.disable_encryption()
        self.assertFalse(snap_vault.is_enabled())
        self.assertFalse(cfg_mod.is_key_sealed())
        self.assertEqual(cfg_mod.load()['api_key'], KEY)

    def test_disable_while_locked_refused(self):
        cfg_mod.enable_encryption('pass one')
        snap_vault.lock()
        with self.assertRaises(RuntimeError):
            cfg_mod.disable_encryption()
        self.assertTrue(snap_vault.is_enabled())     # still on, key still readable
        self.assertTrue(snap_vault.unlock('pass one'))
        self.assertEqual(cfg_mod.load()['api_key'], KEY)

    # ── re-key ──────────────────────────────────────────────────────────────
    def test_change_passphrase_reseals(self):
        cfg_mod.enable_encryption('old pass')
        self.assertTrue(cfg_mod.change_passphrase('old pass', 'new pass'))
        snap_vault.lock()
        self.assertFalse(snap_vault.unlock('old pass'))
        self.assertTrue(snap_vault.unlock('new pass'))
        self.assertEqual(cfg_mod.load()['api_key'], KEY)

    def test_change_passphrase_wrong_old_refused(self):
        cfg_mod.enable_encryption('old pass')
        self.assertFalse(cfg_mod.change_passphrase('not it', 'new pass'))
        snap_vault.lock()
        self.assertTrue(snap_vault.unlock('old pass'))   # unchanged

    # ── tamper / isolation ──────────────────────────────────────────────────
    def test_ciphertext_tamper_refused(self):
        cfg_mod.enable_encryption('pass one')
        sealed = self._raw()
        body = sealed[len('enc1:'):]
        flipped = ('B' if body[10] != 'B' else 'C')
        tampered = 'enc1:' + body[:10] + flipped + body[11:]
        self.assertEqual(cfg_mod._decode_pw(tampered), '')   # rejected, not returned

    def test_another_tools_vault_does_not_open_this_one(self):
        """init() scopes the verifier per app, so a copied vault.meta fails closed."""
        cfg_mod.enable_encryption('shared pass')
        snap_vault.lock()
        snap_vault.init('SomeOtherTool', self.tmp.name)
        self.assertFalse(snap_vault.unlock('shared pass'))

    def test_full_cycle_key_never_lost(self):
        """on → re-key → off → on again; the key survives every hop."""
        cfg_mod.enable_encryption('one')
        self.assertTrue(cfg_mod.change_passphrase('one', 'two'))
        cfg_mod.disable_encryption()
        cfg_mod.enable_encryption('three')
        self.assertEqual(cfg_mod.load()['api_key'], KEY)


if __name__ == '__main__':
    unittest.main()
# ===== SNAPSMACK EOF =====
