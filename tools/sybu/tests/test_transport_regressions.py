"""
SYBU / SMACK YOUR BATCH UP — SECAUDIT 042 transport regressions.

SECAUDIT 040 recorded SYBU as covered by the shared plaintext-HTTP fix. SYBU does
import `snap_stepup` — for the step-up password dialog — but never called the
transport gate, so the scoped Bearer key still crossed `http://` unchecked on
every request. "Imports the module" and "is protected by the module" are not the
same claim, and only one of them is testable by grep.

SYBU builds a client in THREE places, and they need different treatment:

  1. Connect button        — user action on the main thread: warn and confirm.
  2. Settings test button  — same, but the work happens in a worker, so the gate
                             has to run before the thread is spawned.
  3. Auto-connect at start — NOT a user action. A modal raised from a background
                             thread at launch is worse than not connecting, so
                             this one refuses quietly and explains itself.

Warn-and-confirm rather than refusal because this is a scoped key, not an account
password — the line SECAUDIT 039 drew for GYSS.

Run: python -m unittest discover -s tests

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import unittest
from pathlib import Path

TOOL = Path(__file__).resolve().parents[1]


class TransportGuardTests(unittest.TestCase):

    def setUp(self):
        self.src = (TOOL / 'main.py').read_text(encoding='utf-8')

    def test_the_guard_is_imported(self):
        self.assertIn('from snap_stepup import confirm_insecure_transport, insecure_transport_reason',
                      self.src, 'SYBU does not import the shared transport guard')

    def test_all_three_client_sites_are_gated(self):
        """The count is the test. A fourth connect path added later without a
        gate is exactly how this regressed the first time."""
        builds = self.src.count('SnapSmackClient(url, api_key=')
        gates = (self.src.count('confirm_insecure_transport(self, url')
                 + self.src.count('insecure_transport_reason(url)'))
        self.assertGreaterEqual(gates, builds,
                                f'{builds} client construction sites but only {gates} guards')

    def test_connect_button_gates_before_building_the_client(self):
        body = self.src[self.src.index('def _on_connect'):]
        body = body[:body.index('def ', 10)]
        self.assertLess(body.index('confirm_insecure_transport'),
                        body.index('SnapSmackClient(url, api_key=key)'),
                        'the key-carrying client is built before the transport check')

    def test_auto_connect_refuses_without_a_modal(self):
        """A background thread must not raise a dialog; it declines and says why."""
        self.assertIn('if url and api_key and insecure_transport_reason(url):', self.src,
                      'startup auto-connect is not gated')
        idx = self.src.index('if url and api_key and insecure_transport_reason(url):')
        block = self.src[idx:idx + 700]
        self.assertNotIn('confirm_insecure_transport', block,
                         'auto-connect raises a modal from a background thread')
        self.assertIn('not https://', block,
                      'auto-connect refuses without telling the operator why')

    def test_settings_test_button_gates_on_the_main_thread(self):
        idx = self.src.index('_sp_test_lbl.configure(text="Cancelled')
        before = self.src[max(0, idx - 600):idx]
        self.assertIn('confirm_insecure_transport', before,
                      'the settings test button spawns its worker before checking')

    def test_a_missing_helper_fails_closed(self):
        # Anchored on the guard's own import, not on the first `except` in the
        # file — that one belongs to the logging setup hundreds of lines earlier.
        anchor = self.src.index('from snap_stepup import confirm_insecure_transport')
        fallback = self.src[anchor:anchor + 1600]
        self.assertIn("startswith('https://')", fallback,
                      'the fallback guard does not require https')
        self.assertIn('return False', fallback,
                      'the fallback guard does not refuse when it cannot verify')


if __name__ == '__main__':
    unittest.main()
# ===== SNAPSMACK EOF =====
