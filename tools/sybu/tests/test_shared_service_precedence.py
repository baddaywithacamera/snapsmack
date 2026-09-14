"""SYBU must use SNAP HQ's live global service credentials, never stale profiles."""

import sybu_core


def test_profile_cannot_override_current_snap_hq_gemini_key(monkeypatch):
    engine = object.__new__(sybu_core.Engine)
    engine.config = {
        'gemini_api_key': 'startup-shared-key',
        'google_credentials': 'startup-drive.json',
        'drive_folder_id': 'startup-folder',
    }
    monkeypatch.setattr(sybu_core.cfg_module, 'load', lambda: {
        'gemini_api_key': 'current-snap-hq-key',
        'google_credentials': 'current-drive.json',
        'drive_folder_id': 'current-folder',
    })
    monkeypatch.setattr(sybu_core.profile_manager, 'load_profile', lambda _name: {
        'url': 'https://example.test',
        'api_key': 'site-key',
        'gemini_api_key': 'stale-profile-key',
        'google_credentials': 'stale-drive.json',
        'drive_folder_id': 'stale-folder',
    })

    fields = engine.profile_apply_to_post('example')

    assert fields['gemini_api_key'] == 'current-snap-hq-key'
    assert fields['google_credentials'] == 'current-drive.json'
    assert fields['drive_folder_id'] == 'current-folder'
    assert engine.config['gemini_api_key'] == 'current-snap-hq-key'


# ===== SNAPSMACK EOF =====
