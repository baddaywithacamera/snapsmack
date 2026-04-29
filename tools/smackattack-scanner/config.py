"""
GOBSMACKED Scanner — config.py
Persistent settings stored as INI file next to the executable.
"""

import configparser
import os
import sys

def _config_path() -> str:
    if getattr(sys, 'frozen', False):
        base = os.path.dirname(sys.executable)
    else:
        base = os.path.dirname(os.path.abspath(__file__))
    return os.path.join(base, 'gobsmacked-scanner.ini')

DEFAULTS = {
    'db_host':     'localhost',
    'db_port':     '3306',
    'db_name':     '',
    'db_user':     '',
    'db_password': '',
    'api_url':     '',
    'api_key':     '',
    'threshold':   '0.55',
    'min_words':   '30',
}

def load() -> dict:
    cfg = configparser.ConfigParser()
    cfg.read(_config_path(), encoding='utf-8')
    result = dict(DEFAULTS)
    if cfg.has_section('settings'):
        for k in DEFAULTS:
            if cfg.has_option('settings', k):
                result[k] = cfg.get('settings', k)
    return result

def save(values: dict) -> None:
    cfg = configparser.ConfigParser()
    cfg['settings'] = {k: str(values.get(k, DEFAULTS.get(k, ''))) for k in DEFAULTS}
    with open(_config_path(), 'w', encoding='utf-8') as f:
        cfg.write(f)
