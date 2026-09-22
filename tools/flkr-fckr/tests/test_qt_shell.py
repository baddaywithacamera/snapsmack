"""
FLKR FCKR — Qt shell regression tests.

Runs headless (QT_QPA_PLATFORM=offscreen). The Session is a stub so nothing
touches the network, the vault or flkrfckr.ini. Pins:

  * the exe's ENTRY is the Qt launcher, not the tkinter main.py
  * every Tk feature has a home in the Qt window (buttons, fields, dialogs)
  * filter / exclude / summary logic matches the Tk grid
  * Session events (log / parse / progress / done) drive the widgets
  * the frozen log directory is next to the exe, not the onefile temp dir
  * bump_version.py bumps main.py AND flkrfckr_core.py together

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
"""

import datetime
import os
import re
import sys
from pathlib import Path

import pytest

APP_DIR = Path(__file__).resolve().parents[1]
for p in (str(APP_DIR), str(APP_DIR.parent / '_shared')):
    if p not in sys.path:
        sys.path.insert(0, p)

os.environ.setdefault('QT_QPA_PLATFORM', 'offscreen')

pytest.importorskip('PySide6')
from PySide6.QtWidgets import QApplication, QDialog, QMessageBox   # noqa: E402

import flkrfckr_core as core       # noqa: E402
import flkrfckr_qt as qt           # noqa: E402
from flickr_parser import AlbumInfo, ParsedPhoto, ParseResult   # noqa: E402


@pytest.fixture(scope='module')
def app():
    return QApplication.instance() or QApplication([])


def _photo(fid, title='t', albums=(), missing=False, privacy='public', excluded=False):
    return ParsedPhoto(flickr_id=fid, title=title, album_ids=list(albums),
                       image_path='' if missing else f'/x/{fid}.jpg',
                       missing_image=missing, privacy=privacy, excluded=excluded,
                       date_taken=datetime.datetime(2020, 1, 2))


class StubSession:
    """Minimal Session double: real filter/summary logic from core, no I/O."""

    def __init__(self):
        self.parse_result = None
        self.running = False
        self.paused = False
        self.events = []
        self.saved = None
        self.calls = []

    # settings / vault
    def get_settings(self):
        return {'site_url': 'https://s.example', 'api_key': 'k', 'export_folder': '',
                'throttle_label': 'Easy Does It (1s)',
                'throttle_options': [l for l, _ in core.THROTTLE_OPTIONS],
                'offpeak_only': True, 'peak_start': 8, 'peak_end': 22,
                'private_status': 'published', 'unalbumed_action': 'default_album',
                'default_album': 'Misc', 'auth_username': 'sean', 'version': '0.0.0'}

    def save_settings(self, s):
        self.saved = s

    def vault_status(self):
        return {'available': True, 'enabled': False, 'unlocked': False,
                'has_machine_key': False, 'sealed': False}

    # grid
    current_photos = core.Session.current_photos
    _current_photos = core.Session._current_photos
    summary = core.Session.summary

    def thumbnail_bytes(self, fid):
        return None

    # events
    def poll_events(self):
        ev, self.events = self.events, []
        return ev

    # actions
    def check_resume(self):
        return None

    def load_export(self, folder):
        self.calls.append(('load', folder))
        return {'ok': True}

    def start_import(self, flt, album_id):
        self.calls.append(('start', flt, album_id))
        self.running = True
        return {'ok': True, 'total': 1}

    def pause_import(self):
        self.paused = True
        return {'ok': True}

    def resume_import(self):
        self.paused = False
        return {'ok': True}

    def stop_import(self):
        self.calls.append(('stop',))
        return {'ok': True}

    def open_logs(self):
        return {'ok': True}


def _loaded_window(app):
    s = StubSession()
    w = qt.MainWindow(s)
    photos = [_photo('1', 'one', ['A']), _photo('2', 'two', ['A', 'B']),
              _photo('3', 'three'), _photo('4', 'four', missing=True),
              _photo('5', 'five', privacy='private')]
    albums = [AlbumInfo('A', 'Album A', photo_ids=['1', '2']),
              AlbumInfo('B', 'Album B', photo_ids=['2'])]
    s.parse_result = ParseResult(photos=photos, albums=albums)
    w._on_parse_done({'type': 'parse_done',
                      'albums': [{'flickr_id': a.flickr_id, 'title': a.title,
                                  'count': len(a.photo_ids)} for a in albums]})
    return s, w


# ---------------------------------------------------------------------------
# Entry script / build recipe
# ---------------------------------------------------------------------------

def test_exe_entry_is_the_qt_launcher():
    spec = (APP_DIR / 'flkrfckr.spec').read_text(encoding='utf-8')
    assert "Analysis(\n    ['flkrfckr_launcher.py']" in spec
    assert "'tkinter'" in spec.split('excludes=')[1]
    assert "icon=os.path.join(_src, 'assets', 'icon.ico')" in spec
    assert "version=os.path.join(_src, 'version_info.txt')" in spec
    launcher = (APP_DIR / 'flkrfckr_launcher.py').read_text(encoding='utf-8')
    assert 'from flkrfckr_qt import run' in launcher
    assert not re.search(r'^\s*(import|from)\s+tkinter', launcher, re.M)
    build = (APP_DIR / 'build.bat').read_text(encoding='utf-8')
    assert 'flkrfckr.spec' in build
    assert 'verify_exe.py' in build and '--entry flkrfckr_launcher' in build
    assert (APP_DIR / 'assets' / 'icon.ico').is_file()


def test_qt_shell_imports_no_tkinter():
    src = (APP_DIR / 'flkrfckr_qt.py').read_text(encoding='utf-8')
    assert not re.search(r'^\s*(import|from)\s+tkinter', src, re.M)
    assert 'tkinter' not in sys.modules          # importing the shell pulled no Tk in
    assert qt.BUILD_VERSION == core.BUILD_VERSION


def test_bump_version_bumps_core_in_lockstep():
    bump = (APP_DIR / 'bump_version.py').read_text(encoding='utf-8')
    assert 'flkrfckr_core.py' in bump
    main_v = re.search(r'BUILD_VERSION = "([^"]+)"', (APP_DIR / 'main.py').read_text(encoding='utf-8')).group(1)
    assert main_v == core.BUILD_VERSION, 'main.py and flkrfckr_core.py versions drifted'


def test_frozen_log_dir_is_next_to_exe(monkeypatch):
    monkeypatch.setattr(sys, 'frozen', True, raising=False)
    monkeypatch.setattr(sys, 'executable', r'C:\snapsmack\flkr-fckr\flkr-fckr.exe')
    assert core._log_dir() == r'C:\snapsmack\flkr-fckr'
    monkeypatch.delattr(sys, 'frozen')
    assert core._log_dir() == str(APP_DIR)


# ---------------------------------------------------------------------------
# Window — every Tk control has a home
# ---------------------------------------------------------------------------

def test_window_has_every_tk_control(app):
    w = qt.MainWindow(StubSession())
    labels = {b.text() for b in w.findChildren(qt.QPushButton)}
    for needed in ('Connect', 'Browse…', 'Load Export', 'Key', 'Logs', '?',
                   'Start Import', 'Pop Out ↗'):
        assert needed in labels, needed
    assert w._url.text() == 'https://s.example'
    assert w._key.echoMode() == qt.QLineEdit.Password
    assert w._throttle.count() == len(core.THROTTLE_OPTIONS)
    assert w._throttle.currentText() == 'Easy Does It (1s)'
    assert w._offpeak.isChecked() and w._peak_start.currentText() == '8' \
        and w._peak_end.currentText() == '22'
    assert w._private.currentText() == 'published'
    assert w._unalbumed.currentText() == 'default_album'
    assert w._default_album.text() == 'Misc' and w._default_album.isEnabled()
    assert w._rb_all.isChecked() and not w._rb_unalbumed.isChecked()
    assert not w._btn_run.isEnabled()          # until an export is loaded
    assert w.windowTitle() == f'FLKR FCKR  v{core.BUILD_VERSION}'
    assert w._log.height() > 0 and w._log.isReadOnly()


def test_save_settings_round_trips_the_form(app):
    s = StubSession()
    w = qt.MainWindow(s)
    w._throttle.setCurrentText('Sunday Driver (2s)')
    w._unalbumed.setCurrentText('feed')
    assert not w._default_album.isEnabled()
    w._save_settings()
    assert s.saved['throttle'] == 'Sunday Driver (2s)'
    assert s.saved['offpeak_only'] is True
    assert s.saved['peak_start'] == 8 and s.saved['peak_end'] == 22
    assert s.saved['private_status'] == 'published'
    assert s.saved['unalbumed_action'] == 'feed'
    assert s.saved['default_album'] == 'Misc'
    assert s.saved['site_url'] == 'https://s.example'


# ---------------------------------------------------------------------------
# Grid — filter / exclude / summary
# ---------------------------------------------------------------------------

def test_parse_done_populates_sidebar_grid_and_summary(app):
    s, w = _loaded_window(app)
    assert w._album_list.count() == 3
    assert w._album_list.item(0).text() == 'All (5)'
    assert w._album_list.item(1).text().strip() == 'Album A (2)'
    assert w._model.rowCount() == 5
    assert w._lbl_grid_count.text() == '5 photos'
    assert w._lbl_summary.text() == '4 of 5 photos selected for import  (1 missing image files)'
    assert w._btn_run.isEnabled()


def test_album_and_unalbumed_filters_match_tk(app):
    s, w = _loaded_window(app)
    w._album_list.setCurrentRow(1)                 # Album A
    assert w._filter == 'album' and w._album_id == 'A'
    assert [p.flickr_id for p in w._model.photos] == ['1', '2']
    assert not w._rb_all.isChecked() and not w._rb_unalbumed.isChecked()

    w._rb_unalbumed.click()
    assert w._filter == 'unalbumed'
    assert [p.flickr_id for p in w._model.photos] == ['3', '4', '5']
    assert w._album_list.currentRow() == -1

    w._rb_all.click()
    assert w._filter == 'all' and w._model.rowCount() == 5
    assert w._album_list.currentRow() == 0


def test_click_toggles_exclude_but_never_a_missing_image(app):
    s, w = _loaded_window(app)
    w._model.toggle(0)
    assert s.parse_result.photos[0].excluded
    assert w._lbl_grid_count.text() == '5 photos  (1 excluded)'
    assert w._lbl_summary.text().startswith('3 of 5')
    w._model.toggle(3)                              # missing image — no-op
    assert not s.parse_result.photos[3].excluded
    w._model.toggle(0)
    assert not s.parse_result.photos[0].excluded
    assert w._lbl_grid_count.text() == '5 photos'


def test_badges_follow_private_status_setting(app):
    s, w = _loaded_window(app)
    p_priv = s.parse_result.photos[4]
    assert qt.PhotoDelegate.badge_for(p_priv, 'published') == ('PRIVATE → PUBLISHED', qt.WARN)
    w._private.setCurrentText('draft')
    assert w._model.private_status == 'draft'
    assert qt.PhotoDelegate.badge_for(p_priv, w._model.private_status)[0] == 'PRIVATE → DRAFT'
    assert qt.PhotoDelegate.badge_for(s.parse_result.photos[3], 'draft') == ('MISSING IMAGE', qt.ERR)
    assert qt.PhotoDelegate.badge_for(_photo('9', excluded=True), 'draft') == ('EXCLUDED', qt.DIM)
    assert qt.PhotoDelegate.badge_for(_photo('9'), 'draft')[0] == ''


def test_start_import_uses_the_filtered_list(app, monkeypatch):
    s, w = _loaded_window(app)
    w._album_list.setCurrentRow(2)                 # Album B → photo 2 only
    w._launch_import()
    assert s.calls[-1] == ('start', 'album', 'B')
    assert w._btn_run.text() == 'Pause'
    w._toggle_run()                                 # running → pause
    assert s.paused and w._btn_run.text() == 'Resume'
    w._toggle_run()                                 # paused → resume
    assert not s.paused and w._btn_run.text() == 'Pause'
    s.events.append({'type': 'done'})
    w._poll_events()
    assert w._btn_run.text() == 'Start Import' and w._progress.value() == 100


def test_start_import_refuses_when_nothing_selected(app, monkeypatch):
    s, w = _loaded_window(app)
    for p in s.parse_result.photos:
        p.excluded = True
    warned = []
    monkeypatch.setattr(QMessageBox, 'warning', lambda *a, **k: warned.append(a[2]) or QMessageBox.Ok)
    w._start_import()
    assert warned == ['No photos to import.']
    assert not any(c[0] == 'start' for c in s.calls)


def test_insecure_url_is_confirmed_before_the_key_goes_out(app, monkeypatch):
    s, w = _loaded_window(app)
    w._url.setText('http://plain.example')
    asked = []
    monkeypatch.setattr(QMessageBox, 'warning',
                        lambda *a, **k: asked.append(a[1]) or QMessageBox.Cancel)
    w._test_connection()
    assert asked == ['Unencrypted connection']
    assert w._lbl_conn.text() == 'Cancelled — use https://'
    w._start_import()
    assert asked == ['Unencrypted connection'] * 2
    assert w._log_lines[-1][0] == 'Import cancelled — site URL is not https://.'
    # https needs no prompt
    w._url.setText('https://ok.example')
    assert w._confirm_insecure('https://ok.example', 'x') is True


# ---------------------------------------------------------------------------
# Events → widgets
# ---------------------------------------------------------------------------

def test_events_drive_log_progress_and_parse_labels(app):
    s = StubSession()
    w = qt.MainWindow(s)
    s.events += [
        {'type': 'log', 'text': 'hello <b>', 'level': core.WARN},
        {'type': 'parse_progress', 'done': 500, 'total': 1000},
        {'type': 'progress', 'pct': 42.6, 'text': '[OK] 1 — ok', 'level': core.OK},
    ]
    w._poll_events()
    assert w._log_lines[0] == ('hello <b>', qt.WARN)
    assert '&lt;b&gt;' in w._log.toPlainText() or '<b>' in w._log.toPlainText()
    assert w._lbl_grid_count.text() == 'Parsing… 500 / 1,000'
    assert w._progress.value() == 42
    assert w._log_lines[-1] == ('[OK] 1 — ok', qt.ACCENT)


def test_bad_event_never_stops_the_pump(app):
    s = StubSession()
    w = qt.MainWindow(s)
    s.events += [{'type': 'progress'},                       # missing keys → KeyError
                 {'type': 'log', 'text': 'still alive', 'level': core.PRI}]
    w._poll_events()
    assert w._log_lines[-1][0] == 'still alive'


def test_log_popout_mirrors_and_seeds(app):
    s = StubSession()
    w = qt.MainWindow(s)
    w._log_write('first', qt.INK)
    w._open_log_popup()
    assert 'first' in w._log_popup.text.toPlainText()
    w._log_write('second', qt.ERR)
    assert 'second' in w._log_popup.text.toPlainText()
    w._log_popup.close()
    assert w._log_popup is None


def test_close_stops_a_running_import(app):
    s = StubSession()
    w = qt.MainWindow(s)
    s.running = True
    w.close()
    assert ('stop',) in s.calls


# ---------------------------------------------------------------------------
# Dialogs
# ---------------------------------------------------------------------------

def test_passphrase_dialog_validates(app):
    d = qt.PassphraseDialog(None, 't', 'p', confirm=True)
    d._ok()
    assert d._err.text() == 'Passphrase must not be empty.' and d.value is None
    d._e1.setText('a'); d._e2.setText('b'); d._ok()
    assert d._err.text() == 'The two entries do not match.'
    d._e2.setText('a'); d._ok()
    assert d.value == 'a' and d.result() == QDialog.Accepted


def test_stepup_dialog_requires_all_three(app):
    d = qt.StepUpDialog(None, site_url='https://s', username_default='sean', error='bad')
    assert d._user.text() == 'sean' and d._err.text() == 'bad'
    d._submit()
    assert d._err.text() == 'Username, password and code are all required.'
    d._pass.setText('pw'); d._totp.setText('123456'); d._submit()
    assert d.values == ('sean', 'pw', '123456')


def test_stepup_loop_reprompts_then_succeeds(app, monkeypatch):
    s, w = _loaded_window(app)
    attempts = []

    def fake_authorize(url, key, u, p, t):
        attempts.append((u, p, t))
        return {'ok': len(attempts) == 2, 'message': 'Wrong code', 'needs_enrollment': False}
    s.authorize_import = fake_authorize

    seen_errors = []

    class FakeDlg:
        def __init__(self, parent, site_url='', username_default='', error='', title=''):
            seen_errors.append(error); self.values = ('u', 'p', '1')
        def exec(self): return QDialog.Accepted
    monkeypatch.setattr(qt, 'StepUpDialog', FakeDlg)
    assert w._stepup_loop('https://s', 'k') is True
    assert len(attempts) == 2 and seen_errors == ['', 'Wrong code']


def test_help_dialog_carries_the_full_help(app):
    d = qt.HelpDialog(None)
    html = qt.HELP_HTML
    for section in ('Quick Start', 'Key security', 'Throttle', 'Off-peak only', 'Private →',
                    'Photo Grid', 'Unalbumed Photos', 'Commenter Names', 'Resume After Interruption',
                    'How Images Are Transferred', 'After the Import', 'flkrfckr-names.json'):
        assert section in html, section
    assert d.windowTitle().endswith('— Help')

# ===== SNAPSMACK EOF =====
