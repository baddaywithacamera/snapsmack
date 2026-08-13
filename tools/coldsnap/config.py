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


# This tool's folder key in the shared C:\snapsmack layout (config_files/<tool>,
# logs/<tool>_*, C:\snapsmack\<tool>). One place to change the name.
_TOOL = 'coldsnap'


def _add_shared_to_path() -> None:
    """Put tools/_shared (C:\\snapsmack\\_shared at runtime) on sys.path. No-op if
    already present or unreachable."""
    try:
        _sd = os.path.join(_base_dir(), '..', '_shared')
        if os.path.isdir(_sd) and _sd not in sys.path:
            sys.path.insert(0, _sd)
    except Exception:
        pass


def _shared_home():
    """Import snap_home (the C:\\snapsmack directory contract). None if unreachable —
    the tool then falls back to its old next-to-exe files, so old installs still run."""
    _add_shared_to_path()
    try:
        import snap_home
        return snap_home
    except Exception:
        return None


def _resolve(name: str) -> str:
    """Path to one of this tool's config files. Prefers the shared
    C:\\snapsmack\\config_files\\<tool>\\<name>, migrating the legacy next-to-exe copy
    in on first run (adopt_legacy). Falls back to next-to-exe if the shared home
    isn't reachable, so existing installs are never left without their settings."""
    legacy = os.path.join(_base_dir(), name)
    h = _shared_home()
    if not h:
        return legacy
    try:
        new_path = h.config_path(_TOOL, name)
        h.adopt_legacy(legacy, new_path)
        return new_path
    except Exception:
        return legacy


def _config_path() -> str:
    """config.ini under the shared config_files/<tool> (migrated from next-to-exe)."""
    return _resolve('config.ini')


def _prompts_path() -> str:
    """gemini_prompts.json under the shared config_files/<tool>."""
    return _resolve('gemini_prompts.json')


def _shared_creds():
    """Import the shared credential store from tools/_shared. Returns the module,
    or None if it isn't reachable — the tool then just uses its own config.ini.
    Sharing lets a key entered in ANY tool (Gemini key, Drive creds) fill in here."""
    _add_shared_to_path()
    try:
        import snap_creds
        return snap_creds
    except Exception:
        return None


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


def _shared_prompts():
    """The shared snap_prompts store (shared_library/prompts), or None if the
    shared home is unreachable — the tool then falls back to its own per-tool
    gemini_prompts.json so a stray standalone copy still runs."""
    _add_shared_to_path()
    try:
        import snap_prompts
        return snap_prompts
    except Exception:
        return None


_PROMPTS_MIGRATION_MARKER = '.prompts_migrated_to_shared'

# The user-preset snapshot this process last read from the SHARED store. save_prompts
# applies only this process's delta against it onto the FRESH on-disk store, so a
# sibling tool (SYBU) running at the same time can't have its just-added presets
# clobbered by our stale in-memory copy (the old last-writer-wins would lose them).
_last_shared_user = {}


def _user_presets(all_prompts: dict) -> dict:
    """The subset of a prompts dict that is genuinely user-owned — anything that
    isn't a verbatim built-in. Keeps user overrides of a built-in name, drops the
    untouched built-ins so the shared store never fills up with shipped defaults."""
    return {k: v for k, v in (all_prompts or {}).items()
            if not (k in DEFAULT_PROMPTS and DEFAULT_PROMPTS[k] == v)}


def _migrate_prompts_once(sp) -> None:
    """Fold this tool's legacy per-tool gemini_prompts.json into the shared store
    ONCE. Marker-guarded so a preset the user later deletes never resurrects, and
    the legacy file is left in place as a backup."""
    marker = os.path.join(os.path.dirname(_prompts_path()), _PROMPTS_MIGRATION_MARKER)
    if os.path.exists(marker):
        return
    try:
        legacy_path = _prompts_path()
        if os.path.isfile(legacy_path):
            with open(legacy_path, 'r', encoding='utf-8') as f:
                data = json.load(f)
            if isinstance(data, dict):
                sp.migrate_in(_user_presets(data), overwrite=False)
        os.makedirs(os.path.dirname(marker), exist_ok=True)
        with open(marker, 'w', encoding='utf-8') as f:
            f.write('gemini prompts migrated to shared_library/prompts\n')
    except Exception:
        pass


def load_prompts() -> dict:
    """Gemini prompt presets: the built-in generic SOLO + GRAM presets, with any
    user-saved presets merged on top (a user preset of the same name overrides the
    built-in). User presets live in the SHARED store (shared_library/prompts) so a
    prompt written in any Gemini tool shows up here and survives a reinstall; a
    tool with no shared home falls back to its own per-tool file."""
    out = dict(DEFAULT_PROMPTS)
    sp = _shared_prompts()
    if sp:
        _migrate_prompts_once(sp)
        shared = sp.load()
        global _last_shared_user
        _last_shared_user = dict(shared)
        out.update(shared)
        return out
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
    """Persist the user presets. Writes to the SHARED store when reachable (so every
    Gemini tool sees them), stripping the untouched built-ins so the store stays
    user-owned; falls back to the per-tool file otherwise.

    Shared writes are a read-modify-write against the CURRENT on-disk store, applying
    only this process's delta vs what it last loaded — so a sibling tool's presets
    added since we loaded are preserved instead of clobbered."""
    sp = _shared_prompts()
    if sp:
        _migrate_prompts_once(sp)
        user = _user_presets(prompts)
        global _last_shared_user
        disk = sp.load()
        base = _last_shared_user
        merged = dict(disk)
        # Deletions: names this process had at load but dropped now — only remove
        # when the on-disk value still matches what we loaded (don't stomp a change
        # another tool made to that name in the meantime).
        for k in list(base.keys()):
            if k not in user and merged.get(k) == base.get(k):
                del merged[k]
        # Additions / edits from this process.
        for k, v in user.items():
            merged[k] = v
        sp.save(merged)
        _last_shared_user = dict(user)
        return
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

    _data = {
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
    # Shared store wins when set, else this tool's own config — configure once,
    # every tool sees it. No-op if _shared is absent (existing installs unaffected).
    _sc = _shared_creds()
    if _sc:
        _sc.apply_shared(_data)
    return _data


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

    # Propagate the shared secrets so setting the Gemini key / Drive creds in this
    # tool fills them in for the others too. Never writes empty values.
    _sc = _shared_creds()
    if _sc:
        _sc.push_shared(data)
# ===== SNAPSMACK EOF =====
