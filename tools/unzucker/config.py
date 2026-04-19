"""
Unzucker — config.py
Reads and writes unzucker.ini next to the executable.
Forked from ft-batch-poster/config.py.

Passwords (site + FTP) are stored base64-obfuscated — not encrypted,
just not plaintext at a glance.
"""

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
        # Site connection
        'url':              cfg.get('site', 'url', fallback=''),
        'username':         cfg.get('auth', 'username', fallback=''),
        'password':         _decode_pw(cfg.get('auth', 'password', fallback='')),
        'remember':         cfg.getboolean('auth', 'remember', fallback=False),

        # FTP
        'ftp_host':         cfg.get('ftp', 'host', fallback=''),
        'ftp_port':         cfg.getint('ftp', 'port', fallback=21),
        'ftp_username':     cfg.get('ftp', 'username', fallback=''),
        'ftp_password':     _decode_pw(cfg.get('ftp', 'password', fallback='')),
        'ftp_protocol':     cfg.get('ftp', 'protocol', fallback='ftp'),
        'ftp_remote_base':  cfg.get('ftp', 'remote_base', fallback='/public_html/images'),

        # Import
        'export_folder':    cfg.get('import', 'export_folder', fallback=''),
        'date_from':        cfg.get('import', 'date_from', fallback=''),
        'date_to':          cfg.get('import', 'date_to', fallback=''),

        # Defaults
        'default_category': cfg.get('defaults', 'category', fallback=''),
        'default_album':    cfg.get('defaults', 'album', fallback=''),
        'copyright_text':   cfg.get('defaults', 'copyright_text', fallback=''),

        # GOOGLE_DRIVE_HIDDEN — retained for future use
        'google_credentials': cfg.get('google', 'credentials_path', fallback=''),
        'drive_folder_id':    cfg.get('google', 'drive_folder_id', fallback=''),
    }


def save(data: dict) -> None:
    """Write config to disk."""
    cfg = configparser.ConfigParser()

    cfg['site'] = {'url': data.get('url', '')}

    remember = data.get('remember', False)
    cfg['auth'] = {
        'username': data.get('username', '') if remember else '',
        'password': _encode_pw(data.get('password', '')) if remember else '',
        'remember': str(remember),
    }

    cfg['ftp'] = {
        'host':        data.get('ftp_host', ''),
        'port':        str(data.get('ftp_port', 21)),
        'username':    data.get('ftp_username', ''),
        'password':    _encode_pw(data.get('ftp_password', '')),
        'protocol':    data.get('ftp_protocol', 'ftp'),
        'remote_base': data.get('ftp_remote_base', '/public_html/images'),
    }

    cfg['import'] = {
        'export_folder': data.get('export_folder', ''),
        'date_from':     data.get('date_from', ''),
        'date_to':       data.get('date_to', ''),
    }

    cfg['defaults'] = {
        'category':       data.get('default_category', ''),
        'album':          data.get('default_album', ''),
        'copyright_text': data.get('copyright_text', ''),
    }

    # GOOGLE_DRIVE_HIDDEN
    cfg['google'] = {
        'credentials_path': data.get('google_credentials', ''),
        'drive_folder_id':  data.get('drive_folder_id', ''),
    }

    with open(_config_path(), 'w') as f:
        cfg.write(f)
