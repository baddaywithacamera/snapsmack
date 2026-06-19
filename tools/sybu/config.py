"""
ft-batch-poster — config.py
Reads and writes config.ini next to the executable.
Password is stored base64-obfuscated (not encrypted — just not plaintext at a glance).
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.


import base64
import configparser
import json
import os
import sys


def _base_dir() -> str:
    if getattr(sys, 'frozen', False):
        return os.path.dirname(sys.executable)
    return os.path.dirname(os.path.abspath(__file__))


def _config_path() -> str:
    """Return the path to config.ini next to the exe (or script in dev)."""
    return os.path.join(_base_dir(), 'config.ini')


def _prompts_path() -> str:
    """Return the path to gemini_prompts.json next to the exe."""
    return os.path.join(_base_dir(), 'gemini_prompts.json')


def load_prompts() -> dict:
    """Load saved Gemini prompt presets. Returns {name: prompt_text}."""
    path = _prompts_path()
    if not os.path.isfile(path):
        return {}
    try:
        with open(path, 'r', encoding='utf-8') as f:
            data = json.load(f)
        return data if isinstance(data, dict) else {}
    except Exception:
        return {}


def save_prompts(prompts: dict) -> None:
    """Persist the full prompts dict to disk."""
    with open(_prompts_path(), 'w', encoding='utf-8') as f:
        json.dump(prompts, f, indent=2, ensure_ascii=False)


def load() -> dict:
    """Load config from disk. Returns a dict with all settings."""
    cfg = configparser.ConfigParser()
    cfg.read(_config_path())

    password_raw = cfg.get('auth', 'password', fallback='')
    try:
        password = base64.b64decode(password_raw.encode()).decode() if password_raw else ''
    except Exception:
        password = ''

    api_key_raw = cfg.get('auth', 'api_key', fallback='')
    try:
        api_key = base64.b64decode(api_key_raw.encode()).decode() if api_key_raw else ''
    except Exception:
        api_key = ''

    return {
        'url':                cfg.get('site', 'url', fallback='https://foundtextures.ca'),
        'username':           cfg.get('auth', 'username', fallback=''),
        'password':           password,
        'api_key':            api_key,
        'remember':           cfg.getboolean('auth', 'remember', fallback=False),
        'default_category':   cfg.get('defaults', 'category', fallback=''),
        'default_album':      cfg.get('defaults', 'album', fallback=''),
        'last_image_folder':  cfg.get('paths', 'last_image_folder', fallback=''),
        'last_manifest_file': cfg.get('paths', 'last_manifest_file', fallback=''),
        'google_credentials': cfg.get('google', 'credentials_path', fallback=''),
        'drive_folder_id':    cfg.get('google', 'drive_folder_id', fallback=''),
        'gemini_api_key':     cfg.get('gemini', 'api_key', fallback=''),
        'gemini_last_prompt': cfg.get('gemini', 'last_prompt', fallback=''),
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
    # API key is the primary credential now (Bearer auth). Persist it regardless
    # of "remember" — it's a generated, reusable token the CMS shows only once.
    api_key_enc = base64.b64encode(data['api_key'].encode()).decode() if data.get('api_key') else ''
    cfg['auth'] = {
        'username': data.get('username', '') if data.get('remember') else '',
        'password': password_enc,
        'remember': str(data.get('remember', False)),
        'api_key':  api_key_enc,
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
        'api_key':     data.get('gemini_api_key', ''),
        'last_prompt': data.get('gemini_last_prompt', ''),
    }

    cfg['metadata'] = {
        'copyright_text': data.get('copyright_text', ''),
    }

    with open(_config_path(), 'w') as f:
        cfg.write(f)
# ===== SNAPSMACK EOF =====
