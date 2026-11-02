"""SS-1091: partial saves and password-forget do not erase unrelated settings."""
# SNAPSMACK_EOF_HEADER: retain the canonical EOF marker as the final line.
import importlib.util
import configparser
from pathlib import Path
import pytest

FIELDS = {
    'username': ('auth', 'username'),
    'default_category': ('defaults', 'category'),
    'default_album': ('defaults', 'album'),
    'last_image_folder': ('paths', 'last_image_folder'),
    'last_manifest_file': ('paths', 'last_manifest_file'),
    'copyright_text': ('metadata', 'copyright_text'),
}

@pytest.fixture
def scratch_config(tmp_path, monkeypatch):
    monkeypatch.setenv('SNAPSMACK_HOME', str(tmp_path))
    spec = importlib.util.spec_from_file_location('partial_settings_config', Path(__file__).parents[1] / 'config.py')
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    path = tmp_path / 'config.ini'
    monkeypatch.setattr(module, '_config_path', lambda: str(path))
    monkeypatch.setattr(module, '_shared_creds', lambda: None)
    monkeypatch.setattr(module, '_seal_secret', lambda value: 'test-sealed:' + value)
    module.save(dict({key: 'saved-' + key for key in FIELDS}, remember=True, password='test-only'))
    return module, path

def read(path):
    parser = configparser.ConfigParser()
    parser.read(path)
    return parser

@pytest.mark.parametrize('field', FIELDS)
def test_partial_save_preserves_each_field(scratch_config, field):
    module, path = scratch_config
    module.save({'biggie_enabled': True})
    assert read(path).get(*FIELDS[field]) == 'saved-' + field
    assert read(path).getboolean('auth', 'remember') is True

@pytest.mark.parametrize('field', FIELDS)
def test_explicit_empty_still_clears_each_field(scratch_config, field):
    module, path = scratch_config
    module.save({field: ''})
    assert read(path).get(*FIELDS[field]) == ''

def test_forgetting_password_preserves_identity_and_defaults(scratch_config):
    module, path = scratch_config
    module.save({'remember': False})
    stored = read(path)
    assert stored.get('auth', 'password') == ''
    assert stored.getboolean('auth', 'remember') is False
    for field, target in FIELDS.items():
        assert stored.get(*target) == 'saved-' + field
# ===== SNAPSMACK EOF =====
