"""
GOBSMACKED Scanner — config.py
Persistent non-secret settings stored in gobsmacked-scanner.ini next to the
executable. Secrets (admin_token, api_key, db_password) are stored in the OS
credential store via keyring (Windows Credential Manager / macOS Keychain /
libsecret) and never written to the INI file when keyring is available.
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.


import configparser
import os
import sys

try:
    import keyring
    import keyring.errors
    _HAS_KEYRING = True
except ImportError:
    keyring = None
    _HAS_KEYRING = False

_KR_SERVICE = 'gobsmacked-scanner'

# Fields that MUST be stored in the OS keyring rather than plaintext INI.
SECRET_KEYS = ('admin_token', 'api_key', 'db_password')

# admin_token is NEVER allowed to fall back to the INI file (SS-1457 constraint:
# Sean's desktop admin token lives in the OS credential store only).
KEYRING_ONLY_KEYS = ('admin_token',)

DEFAULTS = {
    'db_host':     'localhost',
    'db_port':     '3306',
    'db_name':     '',
    'db_user':     '',
    'db_password': '',
    'api_url':     '',
    'api_key':     '',
    'admin_token': '',
    'threshold':   '0.55',
    'min_words':   '30',
}


def _config_path() -> str:
    if getattr(sys, 'frozen', False):
        base = os.path.dirname(sys.executable)
    else:
        base = os.path.dirname(os.path.abspath(__file__))
    return os.path.join(base, 'gobsmacked-scanner.ini')


def has_keyring() -> bool:
    """True if an OS keyring backend is available and not the Fail backend."""
    if not _HAS_KEYRING:
        return False
    try:
        kr = keyring.get_keyring()
        from keyring.backends.fail import Keyring as _FailKeyring
        return not isinstance(kr, _FailKeyring)
    except Exception:
        return False


def _secret_account(key: str, scope: str = '') -> str:
    scope = (scope or '').strip().rstrip('/')
    return f"{scope}:{key}" if scope else key


def get_secret(key: str, scope: str = '') -> str:
    """Read a secret from the OS keyring. Returns '' if absent or unavailable."""
    if not has_keyring():
        return ''
    for account in ([_secret_account(key, scope), key] if scope else [key]):
        try:
            val = keyring.get_password(_KR_SERVICE, account)
            if val:
                return val
        except Exception:
            pass
    return ''


def set_secret(key: str, value: str, scope: str = '') -> bool:
    """Store or delete a secret in the OS keyring. Returns True on success."""
    if not has_keyring():
        return False
    account = _secret_account(key, scope)
    try:
        if value:
            keyring.set_password(_KR_SERVICE, account, value)
        else:
            for acc in ({account, key}):
                try:
                    keyring.delete_password(_KR_SERVICE, acc)
                except Exception:
                    pass
        return True
    except Exception:
        return False


def _scope_for(key: str, values: dict) -> str:
    if key in ('admin_token', 'api_key'):
        return str(values.get('api_url', '') or '').strip()
    if key == 'db_password':
        host = str(values.get('db_host', '') or '').strip()
        db   = str(values.get('db_name', '') or '').strip()
        user = str(values.get('db_user', '') or '').strip()
        return f"{user}@{host}/{db}" if (host or db or user) else ''
    return ''


def load() -> dict:
    path = _config_path()
    cfg = configparser.ConfigParser()
    cfg.read(path, encoding='utf-8')
    result = dict(DEFAULTS)
    ini_had_secrets = False
    if cfg.has_section('settings'):
        for k in DEFAULTS:
            if cfg.has_option('settings', k):
                result[k] = cfg.get('settings', k)

    # Resolve secrets from the OS keyring first; migrate any legacy plaintext
    # values out of gobsmacked-scanner.ini on sight.
    kr_ok = has_keyring()
    for key in SECRET_KEYS:
        scope = _scope_for(key, result)
        ini_val = (result.get(key) or '').strip()
        kr_val = get_secret(key, scope) if kr_ok else ''
        if kr_val:
            result[key] = kr_val
            if ini_val:
                ini_had_secrets = True
        elif ini_val and kr_ok:
            if set_secret(key, ini_val, scope):
                result[key] = ini_val
                ini_had_secrets = True
        elif key in KEYRING_ONLY_KEYS:
            # Never honour or retain a plaintext admin_token from the INI file.
            if ini_val:
                ini_had_secrets = True
            result[key] = ''

    if ini_had_secrets and os.path.isfile(path):
        try:
            save(result)
        except OSError:
            pass
    return result


def save(values: dict) -> None:
    kr_ok = has_keyring()
    ini_settings: dict[str, str] = {}
    for k in DEFAULTS:
        val = str(values.get(k, DEFAULTS.get(k, '')) or '')
        if k in SECRET_KEYS:
            scope = _scope_for(k, values)
            if kr_ok and set_secret(k, val, scope):
                ini_settings[k] = ''
            elif k in KEYRING_ONLY_KEYS:
                if val:
                    raise RuntimeError(
                        "OS credential store (keyring) is unavailable; refusing to "
                        "write admin_token to gobsmacked-scanner.ini."
                    )
                ini_settings[k] = ''
            else:
                ini_settings[k] = val
        else:
            ini_settings[k] = val

    cfg = configparser.ConfigParser()
    cfg['settings'] = ini_settings
    path = _config_path()
    with open(path, 'w', encoding='utf-8') as f:
        cfg.write(f)
    try:
        os.chmod(path, 0o600)
    except OSError:
        pass
# ===== SNAPSMACK EOF =====
