"""
fix-your-batch-up — local_drive.py

Wraps ft-batch-poster/drive.py so Fix Your Batch Up can use the same
token.json as Smack Your Batch Up, regardless of where this script is run from.

Usage:
    import local_drive
    local_drive.set_token_base(r'C:\\SmackYourBatchUp')   # or from config
    service = local_drive.authenticate(creds_path)
"""

import os
import sys

# When running as a PyInstaller frozen exe, drive.py is bundled alongside
# local_drive.py and is already importable — no path manipulation needed.
# When running from source, add ft-batch-poster to sys.path so Python
# can find drive.py.
if not getattr(sys, 'frozen', False):
    _FT_DIR = os.path.normpath(
        os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'ft-batch-poster')
    )
    if _FT_DIR not in sys.path:
        sys.path.insert(0, _FT_DIR)

import drive as _drive   # noqa: E402 — intentional late import

_TOKEN_BASE: str = r'C:\SmackYourBatchUp'


def set_token_base(path: str) -> None:
    """
    Override the directory where token.json is read from and written to.
    Call this before authenticate() or is_authenticated().
    """
    global _TOKEN_BASE
    _TOKEN_BASE = path
    _drive._token_path = lambda: os.path.join(_TOKEN_BASE, 'token.json')


# Initialise with default so callers can use is_authenticated() immediately
set_token_base(_TOKEN_BASE)

# Re-export drive's public API unchanged
authenticate     = _drive.authenticate
is_authenticated = _drive.is_authenticated
upload           = _drive.upload
revoke_token     = _drive.revoke_token
