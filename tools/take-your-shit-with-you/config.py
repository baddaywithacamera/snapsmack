"""
TAKE YOUR SHIT WITH YOU — settings and saved sites.

Writes `tyswy.ini` next to the executable (the portable-app convention every
SnapSmack desktop tool follows: settings ride with the tool, never in %APPDATA%).

The export key is a scoped, READ-ONLY `tyswy` key. It still gets the same
treatment as any other credential, for two reasons: it names a site and grants
a full read of everything in it, and a folder copied to a colleague's machine
should not carry a working key with it.

  Encryption ON  — sealed with the shared vault (tools/_shared/snap_vault.py:
                   scrypt-derived master key + Fernet). The passphrase is never
                   written anywhere.
  Encryption OFF — base64, which is an ENCODING and not encryption. Kept so the
                   tool still runs where `cryptography` is unavailable, and so
                   turning encryption off never strands a saved key.

Both forms load, so switching either way is safe. Saving while the vault is
locked RAISES rather than quietly rewriting a sealed key as base64 — a silent
downgrade of someone's credential store is worse than an error message.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import base64
import configparser
import json
import os
import sys

_SHARED_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '_shared')
if os.path.isdir(_SHARED_DIR) and _SHARED_DIR not in sys.path:
    sys.path.insert(0, _SHARED_DIR)
try:
    import snap_vault
    _VAULT_OK = True
except Exception:
    snap_vault = None
    _VAULT_OK = False

CONFIG_NAME = 'tyswy.ini'


def vault_available():
    return bool(_VAULT_OK and snap_vault.crypto_available())


def _app_dir():
    if getattr(sys, 'frozen', False):
        return os.path.dirname(sys.executable)
    return os.path.dirname(os.path.abspath(__file__))


def _config_path():
    return os.path.join(_app_dir(), CONFIG_NAME)


def init_vault():
    """Bind the shared vault to this tool. Scoped per tool, so a vault.meta
    copied from another SnapSmack tool fails closed instead of unlocking."""
    if _VAULT_OK:
        snap_vault.init('TakeYourShitWithYou', _app_dir())


def vault_enabled():
    return bool(_VAULT_OK and snap_vault.is_enabled())


def vault_unlocked():
    return bool(_VAULT_OK and snap_vault.is_unlocked())


def _decode(raw):
    """Read a stored secret in EITHER form. Returns '' when sealed and locked —
    not an error: the GUI unlocks and re-reads, and every other setting stays
    loadable meanwhile."""
    if not raw:
        return ''
    if _VAULT_OK and snap_vault.is_encrypted(raw):
        if not snap_vault.is_unlocked():
            return ''
        try:
            return snap_vault.decrypt(raw)
        except Exception:
            return ''
    try:
        return base64.b64decode(raw.encode()).decode()
    except Exception:
        return ''


def _encode(plain):
    if not plain:
        return ''
    if _VAULT_OK and snap_vault.is_enabled():
        if not snap_vault.is_unlocked():
            raise RuntimeError(
                'The credential vault is locked — refusing to re-save your export '
                'key in the weaker base64 form. Unlock first.')
        return snap_vault.encrypt(plain)
    return base64.b64encode(plain.encode()).decode()


def is_key_sealed():
    cfg = configparser.ConfigParser()
    cfg.read(_config_path())
    raw = cfg.get('site', 'api_key', fallback='')
    return bool(_VAULT_OK and snap_vault.is_encrypted(raw))


def load():
    cfg = configparser.ConfigParser()
    cfg.read(_config_path())
    try:
        sites = json.loads(cfg.get('sites', 'saved', fallback='[]'))
        if not isinstance(sites, list):
            sites = []
    except ValueError:
        sites = []
    return {
        'site_url':    cfg.get('site', 'url', fallback=''),
        'api_key':     _decode(cfg.get('site', 'api_key', fallback='')),
        'destination': cfg.get('export', 'destination', fallback=''),
        'include_thumbnails': cfg.getboolean('export', 'include_thumbnails', fallback=False),
        'media_concurrency':  cfg.getint('export', 'media_concurrency', fallback=2),
        'courtesy_wordpress': cfg.getboolean('export', 'courtesy_wordpress', fallback=True),
        'compress':           cfg.getboolean('export', 'compress', fallback=False),
        'allow_http':         cfg.getboolean('export', 'allow_http', fallback=False),
        # Development only, and deliberately not exposed in the normal UI: a
        # plaintext export is a plaintext copy of everything you own.
        'saved_sites': sites,
    }


def save(data):
    cfg = configparser.ConfigParser()
    cfg['site'] = {
        'url':     data.get('site_url', ''),
        'api_key': _encode(data.get('api_key', '')),
    }
    cfg['export'] = {
        'destination':        data.get('destination', ''),
        'include_thumbnails': str(bool(data.get('include_thumbnails', False))),
        'media_concurrency':  str(int(data.get('media_concurrency', 2))),
        'courtesy_wordpress': str(bool(data.get('courtesy_wordpress', True))),
        'compress':           str(bool(data.get('compress', False))),
        'allow_http':         str(bool(data.get('allow_http', False))),
    }
    # Saved sites hold NO key material — only a URL and a label. The key for the
    # active site lives in [site] and nowhere else, so a list of sites is not a
    # list of credentials.
    cfg['sites'] = {'saved': json.dumps(data.get('saved_sites', []), ensure_ascii=False)}

    path = _config_path()
    with open(path, 'w', encoding='utf-8') as f:
        cfg.write(f)
    # Owner-only, best-effort. A no-op on FAT and only partly meaningful on
    # NTFS; it is the floor, not the ceiling — the vault is the ceiling.
    try:
        os.chmod(path, 0o600)
    except OSError:
        pass


# ---------------------------------------------------------------------------
# Encryption lifecycle
#
# Read the key BEFORE changing vault state, in both directions, so an
# interrupted switch can never leave a key that nothing can read.
# ---------------------------------------------------------------------------

def enable_encryption(passphrase, remember_on_this_machine=False):
    if not vault_available():
        raise RuntimeError(
            'Encryption is unavailable: the `cryptography` package is not '
            'installed in this build.')
    data = load()                       # plaintext key in hand first
    snap_vault.enable(passphrase, store_machine_key=remember_on_this_machine)
    save(data)                          # rewritten sealed


def disable_encryption():
    data = load()                       # readable only while still unlocked
    snap_vault.disable()
    save(data)                          # rewritten base64


def change_passphrase(old, new):
    return snap_vault.change_passphrase(old, new)


def unlock(passphrase):
    return snap_vault.unlock(passphrase)


def unlock_with_machine_key():
    return bool(_VAULT_OK and snap_vault.unlock_with_machine_key())


def has_machine_key():
    return bool(_VAULT_OK and snap_vault.has_machine_key())
# ===== SNAPSMACK EOF =====
