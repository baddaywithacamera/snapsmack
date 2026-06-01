"""
Unzucker — config.py
Reads and writes unzucker.ini next to the executable.
Forked from ft-batch-poster/config.py.

API key is stored base64-obfuscated — not encrypted,
just not plaintext at a glance.
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.


import base64
import configparser
import os
import sys


def _config_path() -> str:
    """Return the path to unzucker.ini next to the exe (or script in dev)."""
    if getattr(sys, 'frozen', False):
        base = os.path.dirname(sys.executable)
    else:
        base = os.path.dirname(os.path.abspath(__file__))
    return os.path.join(base, 'unzucker.ini')


def _decode(raw: str) -> str:
    if not raw:
        return ''
    try:
        return base64.b64decode(raw.encode()).decode()
    except Exception:
        return ''


def _encode(plain: str) -> str:
    if not plain:
        return ''
    return base64.b64encode(plain.encode()).decode()


def load() -> dict:
    """Load config from disk. Returns a dict with all settings."""
    cfg = configparser.ConfigParser()
    cfg.read(_config_path())

    return {
        # Site connection
        'url':             cfg.get('site', 'url',     fallback=''),
        'api_key':         _decode(cfg.get('site', 'api_key', fallback='')),

        # FTP
        'ftp_host':        cfg.get('ftp', 'host',          fallback=''),
        'ftp_port':        cfg.getint('ftp', 'port',        fallback=21),
        'ftp_username':    cfg.get('ftp', 'username',       fallback=''),
        'ftp_password':    _decode(cfg.get('ftp', 'password', fallback='')),
        'ftp_protocol':    cfg.get('ftp', 'protocol',       fallback='ftp'),
        'ftp_remote_base': cfg.get('ftp', 'remote_base',    fallback='/public_html/images'),

        # Import
        'export_folder':   cfg.get('import', 'export_folder', fallback=''),

        # Defaults
        'copyright_text':  cfg.get('defaults', 'copyright_text', fallback=''),
    }


def save(data: dict) -> None:
    """Write config to disk."""
    cfg = configparser.ConfigParser()

    cfg['site'] = {
        'url':     data.get('url', ''),
        'api_key': _encode(data.get('api_key', '')),
    }

    cfg['ftp'] = {
        'host':        data.get('ftp_host', ''),
        'port':        str(data.get('ftp_port', 21)),
        'username':    data.get('ftp_username', ''),
        'password':    _encode(data.get('ftp_password', '')),
        'protocol':    data.get('ftp_protocol', 'ftp'),
        'remote_base': data.get('ftp_remote_base', '/public_html/images'),
    }

    cfg['import'] = {
        'export_folder': data.get('export_folder', ''),
    }

    cfg['defaults'] = {
        'copyright_text': data.get('copyright_text', ''),
    }

    with open(_config_path(), 'w') as f:
        cfg.write(f)
# ===== SNAPSMACK EOF =====
