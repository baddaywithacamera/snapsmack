"""Rejected refresh grants reopen consent; failures never erase the saved token."""
# SNAPSMACK_EOF_HEADER: retain the canonical EOF marker at file end.
import pathlib
import sys
from unittest import mock
import pytest
sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[1]))
import drive

@pytest.fixture
def saved(tmp_path):
    path = tmp_path / 'token.json'
    path.write_text('old-test-token')
    old = mock.Mock(valid=False, expired=True, refresh_token='test-refresh')
    with mock.patch.object(drive, '_token_path', return_value=str(path)), \
         mock.patch.object(drive.Credentials, 'from_authorized_user_file', return_value=old), \
         mock.patch.object(drive, 'Request'), \
         mock.patch.object(drive.InstalledAppFlow, 'from_client_secrets_file') as flow, \
         mock.patch.object(drive, 'build') as build:
        yield path, old, flow, build

def test_invalid_grant_reopens_consent_and_saves_only_success(saved):
    path, old, flow, build = saved
    old.refresh.side_effect = drive.RefreshError('invalid_grant', {'error': 'invalid_grant'})
    fresh = flow.return_value.run_local_server.return_value
    fresh.to_json.return_value = 'new-test-token'
    drive.authenticate('test-client.json')
    flow.assert_called_once_with('test-client.json', drive.SCOPES)
    flow.return_value.run_local_server.assert_called_once_with(port=0)
    assert path.read_text() == 'new-test-token'
    assert build.call_args.kwargs['credentials'] is fresh

def test_transient_refresh_error_does_not_restart_consent_or_change_token(saved):
    path, old, flow, build = saved
    old.refresh.side_effect = drive.RefreshError('temporarily unavailable', {'error': 'temporarily_unavailable'})
    with pytest.raises(drive.RefreshError):
        drive.authenticate('test-client.json')
    flow.assert_not_called()
    build.assert_not_called()
    assert path.read_text() == 'old-test-token'

def test_cancelled_reauthorization_keeps_old_token(saved):
    path, old, flow, build = saved
    old.refresh.side_effect = drive.RefreshError('invalid_grant', {'error': 'invalid_grant'})
    flow.return_value.run_local_server.side_effect = RuntimeError('test cancelled')
    with pytest.raises(RuntimeError):
        drive.authenticate('test-client.json')
    assert path.read_text() == 'old-test-token'
    build.assert_not_called()
# ===== SNAPSMACK EOF =====
