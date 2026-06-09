"""
Unzucker — config.py
Reads and writes unzucker.ini next to the executable.

Secrets (API key) are stored in the OS credential store via the keyring
library (Windows Credential Manager, macOS Keychain, libsecret on Linux).
If keyring is not installed, falls back to base64 obfuscation in the ini
file with a visible warning — base64 is NOT encryption.

Non-secret settings (URL, paths) remain in unzucker.ini.
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.


import base64
import configparser
import os
import sys

# Keyring: OS credential store. Optional — falls back to base64 if absent.
try:
    import keyring
    import keyring.errors
    _HAS_KEYRING = True
except ImportError:
    _HAS_KEYRING = False

_KR_SERVICE = 'unzucker'


def _config_path() -> str:
    """Return the path to unzucker.ini next to the exe (or script in dev)."""
    if getattr(sys, 'frozen', False):
        base = os.path.dirname(sys.executable)
    else:
        base = os.path.dirname(os.path.abspath(__file__))
    return os.path.join(base, 'unzucker.ini')


# ---------------------------------------------------------------------------
# Keyring helpers
# ---------------------------------------------------------------------------

def _kr_get(account: str) -> str:
    """Retrieve a secret from the OS keyring. Returns '' on any failure."""
    if not _HAS_KEYRING or not account:
        return ''
    try:
        return keyring.get_password(_KR_SERVICE, account) or ''
    except Exception:
        return ''


def _kr_set(account: str, secret: str) -> bool:
    """Store a secret in the OS keyring. Returns True on success."""
    if not _HAS_KEYRING or not account:
        return False
    try:
        if secret:
            keyring.set_password(_KR_SERVICE, account, secret)
        else:
            try:
                keyring.delete_password(_KR_SERVICE, account)
            except keyring.errors.PasswordDeleteError:
                pass
        return True
    except Exception:
        return False


# ---------------------------------------------------------------------------
# Base64 fallback (obfuscation only — not encryption)
# ---------------------------------------------------------------------------

def _b64_decode(raw: str) -> str:
    if not raw:
        return ''
    try:
        return base64.b64decode(raw.encode()).decode()
    except Exception:
        return ''


def _b64_encode(plain: str) -> str:
    if not plain:
        return ''
    return base64.b64encode(plain.encode()).decode()


# ---------------------------------------------------------------------------
# Account name helper — URL-scoped so multiple sites don't collide
# ---------------------------------------------------------------------------

def _api_key_account(url: str) -> str:
    return f"{url.rstrip('/')}:api_key" if url else 'api_key'


# ---------------------------------------------------------------------------
# Public API
# ---------------------------------------------------------------------------

def load() -> dict:
    """Load config from disk. Returns a dict with all settings."""
    cfg = configparser.ConfigParser()
    cfg.read(_config_path())

    url = cfg.get('site', 'url', fallback='')

    # API key — keyring first, then base64 fallback (migration path from old ini)
    api_key = _kr_get(_api_key_account(url)) if _HAS_KEYRING else ''
    if not api_key:
        api_key = _b64_decode(cfg.get('site', 'api_key', fallback=''))
        if api_key and _HAS_KEYRING:
            _kr_set(_api_key_account(url), api_key)  # migrate on first load

    return {
        'url':             url,
        'api_key':         api_key,
        'export_folder':   cfg.get('import',   'export_folder',  fallback=''),
        'import_delay':    cfg.get('import',   'import_delay',   fallback='0.5'),
        'offpeak_only':    cfg.get('import',   'offpeak_only',   fallback='false'),
        'peak_start':      cfg.get('import',   'peak_start',     fallback='9'),
        'peak_end':        cfg.get('import',   'peak_end',       fallback='23'),
        'copyright_text':  cfg.get('defaults',  'copyright_text', fallback=''),
        'window_geometry': cfg.get('window',   'geometry',       fallback=''),
        'window_state':    cfg.get('window',   'state',          fallback='normal'),
    }


def save(data: dict) -> None:
    """Write config to disk. Secrets go to keyring; everything else to ini."""
    url     = data.get('url', '').strip()
    api_key = data.get('api_key', '')

    # Store API key secret
    if _HAS_KEYRING:
        _kr_set(_api_key_account(url), api_key)
        ini_api_key = ''   # wipe any old base64 value so it doesn't linger
    else:
        ini_api_key = _b64_encode(api_key)

    cfg = configparser.ConfigParser()

    cfg['site'] = {
        'url':     url,
        'api_key': ini_api_key,
    }

    cfg['import'] = {
        'export_folder': data.get('export_folder', '').strip(),
        'import_delay':  data.get('import_delay',  '0.5').strip(),
        'offpeak_only':  data.get('offpeak_only',  'false').strip(),
        'peak_start':    data.get('peak_start',    '9').strip(),
        'peak_end':      data.get('peak_end',      '23').strip(),
    }

    cfg['defaults'] = {
        'copyright_text': data.get('copyright_text', '').strip(),
    }

    cfg['window'] = {
        'geometry': data.get('window_geometry', '').strip(),
        'state':    data.get('window_state', 'normal').strip(),
    }

    with open(_config_path(), 'w') as f:
        cfg.write(f)


def has_keyring() -> bool:
    """True if the keyring library is available and functional."""
    return _HAS_KEYRING
# ===== SNAPSMACK EOF =====
