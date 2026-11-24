"""SYBU keeps Drive authorization across process and build boundaries."""
# SNAPSMACK_EOF_HEADER: retain the canonical EOF marker at file end.
import json
import pathlib
import sys
from unittest import mock

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[1]))
import drive
import sybu_core


class _Store:
    def __init__(self):
        self.values = {}

    def get(self, key, default=''):
        return self.values.get(key, default)

    def set(self, key, value):
        self.values[key] = value

    def delete(self, key):
        self.values.pop(key, None)


def _authorized_user():
    return json.dumps({
        'token': 'access', 'refresh_token': 'refresh',
        'client_id': 'client', 'client_secret': 'secret',
        'token_uri': 'https://oauth2.googleapis.com/token',
        'scopes': drive.SCOPES,
    })


def test_authorization_is_saved_in_protected_store_not_build_directory(tmp_path):
    store = _Store()
    legacy = tmp_path / 'token.json'
    creds = mock.Mock(valid=True)
    creds.to_json.return_value = _authorized_user()
    with mock.patch.object(drive, '_shared_credential_store', return_value=store), \
         mock.patch.object(drive, '_token_path', return_value=str(legacy)), \
         mock.patch.object(drive, '_credentials_from_text', return_value=creds), \
         mock.patch.object(drive, 'build', return_value='service'):
        drive._store_authorization(creds.to_json())
        assert drive.authenticate('client.json') == 'service'
    assert store.get(drive._TOKEN_VAULT_KEY) == _authorized_user()
    assert not legacy.exists()


def test_existing_executable_token_is_migrated_without_deleting_first(tmp_path):
    store = _Store()
    legacy = tmp_path / 'token.json'
    legacy.write_text(_authorized_user())
    with mock.patch.object(drive, '_shared_credential_store', return_value=store), \
         mock.patch.object(drive, '_token_path', return_value=str(legacy)):
        assert drive._saved_authorization() == _authorized_user()
    assert store.get(drive._TOKEN_VAULT_KEY) == _authorized_user()
    assert legacy.exists()


def test_protected_store_wins_after_reconnect_even_if_legacy_file_remains(tmp_path):
    store = _Store()
    store.set(drive._TOKEN_VAULT_KEY, 'new-protected-token')
    legacy = tmp_path / 'token.json'
    legacy.write_text(_authorized_user())
    with mock.patch.object(drive, '_shared_credential_store', return_value=store), \
         mock.patch.object(drive, '_token_path', return_value=str(legacy)):
        assert drive._saved_authorization() == 'new-protected-token'


def test_startup_restore_never_allows_consent_browser():
    engine = object.__new__(sybu_core.Engine)
    engine.config = {'drive_enabled': True}
    engine.drive_service = None
    captured = {}
    fake_drive = mock.Mock()
    fake_drive.authorization_state.return_value = ('expired', 'refresh available')
    fake_drive.authenticate.return_value = 'restored-service'

    def start(_key, target):
        op = mock.Mock(result=None)
        target(op)
        captured['result'] = op.result
        return {'started': True}

    engine.start_op = start
    with mock.patch.object(sybu_core, '_drive', return_value=fake_drive):
        assert engine.restore_drive('client.json')['started'] is True
    fake_drive.authenticate.assert_called_once_with('client.json', allow_consent=False)
    assert engine.drive_service == 'restored-service'
    assert captured['result']['restored'] is True


def test_drive_dialog_has_one_sentence_and_reconnect_action_in_source():
    source = (pathlib.Path(__file__).resolve().parents[1] / 'sybu_qt.py').read_text(encoding='utf-8')
    assert 'QPushButton("Reconnect Drive")' in source
    assert "out.get('reason') == 'no_service'" in source
    assert 'box.addButton("Reconnect Drive"' in source

# ===== SNAPSMACK EOF =====
