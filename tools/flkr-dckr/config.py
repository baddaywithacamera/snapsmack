"""
FLKR DCKR — config.py
Reads and writes flkrdckr.ini next to the executable.
Forked from tools/unzucker/config.py.

Passwords (FTP) are stored base64-obfuscated — not encrypted,
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
    """Return the path to flkrdckr.ini next to the exe (or script in dev)."""
    if getattr(sys, 'frozen', False):
        base = os.path.dirname(sys.executable)
    else:
        base = os.path.dirname(os.path.abspath(__file__))
    return os.path.join(base, 'flkrdckr.ini')


def _decode_pw(raw: str) -> str:
    if not raw:
        return ''
    try:
        return base64.b64decode(raw.encode()).decode()
    except Exception:
        return ''


def _encode_pw(plain: str) -> str:
    if not plain:
        return ''
    return base64.b64encode(plain.encode()).decode()


def load() -> dict:
    """Load config from disk. Returns a dict with all settings."""
    cfg = configparser.ConfigParser()
    cfg.read(_config_path())

    return {
        # SnapSmack site
        'site_url':         cfg.get('site', 'url',          fallback=''),
        'api_key':          _decode_pw(cfg.get('site', 'api_key', fallback='')),

        # FTP
        'ftp_host':         cfg.get('ftp', 'host',          fallback=''),
        'ftp_port':         cfg.getint('ftp', 'port',        fallback=21),
        'ftp_username':     cfg.get('ftp', 'username',       fallback=''),
        'ftp_password':     _decode_pw(cfg.get('ftp', 'password', fallback='')),
        'ftp_protocol':     cfg.get('ftp', 'protocol',       fallback='ftp'),
        'ftp_remote_base':  cfg.get('ftp', 'remote_base',    fallback='/public_html/media_assets'),

        # Import
        'export_folder':    cfg.get('import', 'export_folder', fallback=''),
        'throttle_delay':   cfg.getfloat('import', 'throttle_delay', fallback=1.5),

        # Defaults
        'private_status':   cfg.get('defaults', 'private_status', fallback='draft'),
        # 'published' or 'draft' — what to do with photos marked private on Flickr
        'unalbumed_action': cfg.get('defaults', 'unalbumed_action', fallback='feed'),
        # 'feed' (import unalbumed to main feed only) or 'default_album'
        'default_album':    cfg.get('defaults', 'default_album', fallback=''),
        # Name of album to assign unalbumed photos when unalbumed_action='default_album'
    }


def save(data: dict) -> None:
    """Write config to disk."""
    cfg = configparser.ConfigParser()

    cfg['site'] = {
        'url':     data.get('site_url', ''),
        'api_key': _encode_pw(data.get('api_key', '')),
    }

    cfg['ftp'] = {
        'host':        data.get('ftp_host', ''),
        'port':        str(data.get('ftp_port', 21)),
        'username':    data.get('ftp_username', ''),
        'password':    _encode_pw(data.get('ftp_password', '')),
        'protocol':    data.get('ftp_protocol', 'ftp'),
        'remote_base': data.get('ftp_remote_base', '/public_html/media_assets'),
    }

    cfg['import'] = {
        'export_folder': data.get('export_folder', ''),
        'throttle_delay': str(data.get('throttle_delay', 1.5)),
    }

    cfg['defaults'] = {
        'private_status':   data.get('private_status', 'draft'),
        'unalbumed_action': data.get('unalbumed_action', 'feed'),
        'default_album':    data.get('default_album', ''),
    }

    with open(_config_path(), 'w') as f:
        cfg.write(f)
# ===== SNAPSMACK EOF =====
