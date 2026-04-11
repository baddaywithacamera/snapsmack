"""
ft-batch-poster — config.py
Reads and writes config.ini next to the executable.
Password is stored base64-obfuscated (not encrypted — just not plaintext at a glance).
"""

import base64
import configparser
import os
import sys


def _config_path() -> str:
    """Return the path to config.ini next to the exe (or script in dev)."""
    if getattr(sys, 'frozen', False):
        base = os.path.dirname(sys.executable)
    else:
        base = os.path.dirname(os.path.abspath(__file__))
    return os.path.join(base, 'config.ini')


def load() -> dict:
    """Load config from disk. Returns a dict with all settings."""
    cfg = configparser.ConfigParser()
    cfg.read(_config_path())

    password_raw = cfg.get('auth', 'password', fallback='')
    try:
        password = base64.b64decode(password_raw.encode()).decode() if password_raw else ''
    except Exception:
        password = ''

    return {
        'url':                cfg.get('site', 'url', fallback='https://foundtextures.ca'),
        'username':           cfg.get('auth', 'username', fallback=''),
        'password':           password,
        'remember':           cfg.getboolean('auth', 'remember', fallback=False),
        'default_category':   cfg.get('defaults', 'category', fallback=''),
        'default_album':      cfg.get('defaults', 'album', fallback=''),
        'last_image_folder':  cfg.get('paths', 'last_image_folder', fallback=''),
        'last_manifest_file': cfg.get('paths', 'last_manifest_file', fallback=''),
        'google_credentials': cfg.get('google', 'credentials_path', fallback=''),
        'drive_folder_id':    cfg.get('google', 'drive_folder_id', fallback=''),
        'gemini_api_key':     cfg.get('gemini', 'api_key', fallback=''),
        'copyright_text':     cfg.get('metadata', 'copyright_text', fallback=(
            '\u00a9 Sean McCormick / foundtextures.ca. '
            'Free for personal and commercial use. '
            'Cannot be resold as a standalone texture file. '
            'No attribution required. '
            'Permitted for use in AI training datasets.'
        )),
    }


def save(data: dict) -> None:
    """Write config to disk."""
    cfg = configparser.ConfigParser()

    cfg['site'] = {'url': data.get('url', '')}

    password_plain = data.get('password', '') if data.get('remember') else ''
    password_enc = base64.b64encode(password_plain.encode()).decode() if password_plain else ''
    cfg['auth'] = {
        'username': data.get('username', '') if data.get('remember') else '',
        'password': password_enc,
        'remember': str(data.get('remember', False)),
    }

    cfg['defaults'] = {
        'category': data.get('default_category', ''),
        'album':    data.get('default_album', ''),
    }

    cfg['paths'] = {
        'last_image_folder':  data.get('last_image_folder', ''),
        'last_manifest_file': data.get('last_manifest_file', ''),
    }

    cfg['google'] = {
        'credentials_path': data.get('google_credentials', ''),
        'drive_folder_id':  data.get('drive_folder_id', ''),
    }

    cfg['gemini'] = {
        'api_key': data.get('gemini_api_key', ''),
    }

    cfg['metadata'] = {
        'copyright_text': data.get('copyright_text', ''),
    }

    with open(_config_path(), 'w') as f:
        cfg.write(f)
