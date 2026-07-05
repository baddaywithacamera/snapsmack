"""
COLD SNAP — config.py
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


# Built-in, generic prompt presets shipped with the tool. ALWAYS available in
# the preset dropdown (pre-saved) so no one is stuck with a site-specific
# default. SOLO = plain title + caption + tags + colours; GRAM = caption +
# hashtags + colours only (gram posts carry no title). User presets saved in
# gemini_prompts.json are merged on top and override by name.
DEFAULT_PROMPTS = {
    "Solo — generic": """You are generating metadata for a single-image photo blog post on SnapSmack.

Analyse the image and respond ONLY in this exact format — no extra text:

TITLE: <a short, plain, descriptive title — a few words. Not a haiku.>
CAPTION: <one natural sentence describing the image — becomes the post caption. No hashtags.>
TAGS: <5 to 8 space-separated hashtags for subject, setting, colour, and mood, e.g. #landscape #sunset #prairie>
COLORS: <the three most prominent colours as uppercase hex codes, space-separated, e.g. #A3724B #2E1F0D #8C6B3A>

Rules:
- TITLE: plain and descriptive of what is in the image.
- CAPTION: one natural line, no hashtags.
- TAGS: lowercase, no spaces within a tag.
- COLORS: exactly 3 hex codes, uppercase.
- No preamble or extra lines.""",
    "Gram — generic": """You are generating metadata for a single gram (Instagram-style) post on SnapSmack. Gram posts have NO title — the caption and hashtags are the whole post.

Analyse the image and respond ONLY in this exact format — no extra text:

CAPTION: <one or two natural sentences describing the image — this is the post. No hashtags here.>
TAGS: <6 to 12 space-separated hashtags for subject, setting, colour, and mood, e.g. #sunset #prairie #bigsky>
COLORS: <the three most prominent colours as uppercase hex codes, space-separated, e.g. #A3724B #2E1F0D #8C6B3A>

Rules:
- Do NOT output a TITLE line.
- CAPTION: natural, no hashtags.
- TAGS: lowercase, no spaces within a tag.
- COLORS: exactly 3 hex codes, uppercase.
- No preamble or extra lines.""",
}


def load_prompts() -> dict:
    """Gemini prompt presets: the built-in generic SOLO + GRAM presets, with any
    user-saved presets from gemini_prompts.json merged on top (a user preset of
    the same name overrides the built-in)."""
    out = dict(DEFAULT_PROMPTS)
    path = _prompts_path()
    if os.path.isfile(path):
        try:
            with open(path, 'r', encoding='utf-8') as f:
                data = json.load(f)
            if isinstance(data, dict):
                out.update(data)
        except Exception:
            pass
    return out


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
        # UI prefs / dismissable flags (see [ui] section in save()).
        'post_as_grams':           cfg.getboolean('ui', 'post_as_grams', fallback=False),
        'drive_warning_dismissed': cfg.getboolean('ui', 'drive_warning_dismissed', fallback=False),
        'win_maximized':           cfg.getboolean('ui', 'win_maximized', fallback=False),
        'win_geometry':            cfg.get('ui', 'win_geometry', fallback=''),
    }


def save(data: dict) -> None:
    """Write config to disk."""
    cfg = configparser.ConfigParser()

    # [ui] — UI prefs + dismissable flags. save() rebuilds the file from scratch,
    # so re-read the existing [ui] and preserve every key (dedication.py writes
    # dedication_dismissed directly), then layer on only the keys this save
    # actually supplied. A key absent from `data` is left as it was, never reset.
    _existing = configparser.ConfigParser()
    _existing.read(_config_path())
    ui = dict(_existing['ui']) if _existing.has_section('ui') else {}
    if 'post_as_grams' in data:
        ui['post_as_grams'] = str(bool(data.get('post_as_grams')))
    if 'drive_warning_dismissed' in data:
        ui['drive_warning_dismissed'] = str(bool(data.get('drive_warning_dismissed')))
    if 'win_maximized' in data:
        ui['win_maximized'] = str(bool(data.get('win_maximized')))
    if 'win_geometry' in data and data.get('win_geometry'):
        ui['win_geometry'] = str(data.get('win_geometry'))
    if ui:
        cfg['ui'] = ui

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
