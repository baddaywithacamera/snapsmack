"""
UNZUCKER — Qt shell regression tests. (SS-1191)

Runs headless (QT_QPA_PLATFORM=offscreen) against a SYNTHETIC EXPORT built in
the temp directory: real JPEGs and a real posts_1.json in the layout ig_parser
expects. Nothing is stubbed out below the window, so these drive the actual
engine, the actual parser and the actual image code.

IT RUNS FROM A COPY OF THE TOOL, NOT FROM THE REPOSITORY, and that is not
tidiness. Running it in place once, which I did, showed two side effects:

  * config._config_path() writes unzucker.ini NEXT TO THE SCRIPT in dev, so the
    run left a config file and a jobs/ directory in the working tree.
  * config._kr_set() DELETES the keyring entry when handed an empty secret, and
    with a blank URL the account name is the literal 'api_key'. A run with an
    empty API-key field therefore issues delete_password against the real
    'unzucker' credential-manager service.

A copy plus a throwaway keyring service name exercises the same save path and
can do neither. A test that quietly edits the owner's credentials is worse than
no test.

WHAT IS NOT HERE, said plainly: no migration is started. start_migration()
uploads to a live site. Its refusal paths are driven; the upload is not, and
nothing in this file can reach the fleet.

Run: python -m pytest tools/unzucker/tests/test_qt_shell.py
"""

import base64
import json
import os
import shutil
import sys
import tempfile

import pytest

TOOLS = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
TOOLS_ROOT = os.path.dirname(TOOLS)

os.environ.setdefault('QT_QPA_PLATFORM', 'offscreen')
pytest.importorskip('PySide6')
pytest.importorskip('PIL')


@pytest.fixture(scope='module')
def rundir():
    """A throwaway copy of tools/unzucker + tools/_shared, on sys.path."""
    base = tempfile.mkdtemp(prefix='unzucker_test_')
    shutil.copytree(TOOLS, os.path.join(base, 'unzucker'))
    shutil.copytree(os.path.join(TOOLS_ROOT, '_shared'), os.path.join(base, '_shared'))
    for p in (os.path.join(base, 'unzucker'), os.path.join(base, '_shared')):
        if p not in sys.path:
            sys.path.insert(0, p)
    import config
    config._KR_SERVICE = 'unzucker-test-scratch'
    yield base
    shutil.rmtree(base, ignore_errors=True)


@pytest.fixture(scope='module')
def export(rundir):
    """Seven posts; number three is a carousel of three images."""
    root = os.path.join(rundir, 'export')
    media = os.path.join(root, 'media', 'posts', '202404')
    os.makedirs(media, exist_ok=True)
    from PIL import Image
    entries = []
    for i in range(7):
        count = 3 if i == 3 else 1
        items = []
        for j in range(count):
            name = 'p%d_%d.jpg' % (i, j)
            Image.new('RGB', (900 + i, 700 + j * 10),
                      (30 + i * 20 % 200, 90, 160 - j * 30)).save(
                os.path.join(media, name), 'JPEG', quality=70)
            items.append({
                'uri': 'media/posts/202404/' + name,
                'creation_timestamp': 1700000000 + i * 3600,
                'title': ('Single caption %d #alpha #beta' % i) if count == 1 else '',
            })
        entries.append({
            'media': items,
            'title': ('Carousel caption %d #gamma' % i) if count > 1 else '',
            'creation_timestamp': 1700000000 + i * 3600,
        })
    with open(os.path.join(root, 'posts_1.json'), 'w', encoding='utf-8') as fh:
        json.dump(entries, fh)
    return root


@pytest.fixture(scope='module')
def app(rundir):
    from PySide6.QtWidgets import QApplication
    return QApplication.instance() or QApplication([])


@pytest.fixture
def win(app, export, monkeypatch):
    from PySide6.QtWidgets import QMessageBox
    # The window asks real questions. Answer them rather than removing them.
    monkeypatch.setattr(QMessageBox, 'question', staticmethod(lambda *a, **k: QMessageBox.Yes))
    monkeypatch.setattr(QMessageBox, 'warning', staticmethod(lambda *a, **k: QMessageBox.No))
    monkeypatch.setattr(QMessageBox, 'information', staticmethod(lambda *a, **k: QMessageBox.Ok))
    monkeypatch.setattr(QMessageBox, 'critical', staticmethod(lambda *a, **k: QMessageBox.Ok))

    import unzucker_core as core
    import unzucker_qt as qt
    app.setStyleSheet(qt.STYLE)
    session = core.Session()
    window = qt.MainWindow(session)
    window.resize(1000, 820)
    window.show()
    app.processEvents()
    session.parse_export(export)
    window._on_job_begun(session.begin_job(False, 'test', ''))
    app.processEvents()
    yield window
    window._posting = False
    window.close()


# ---------------------------------------------------------------------------
# The entry point. THE FAULT THIS PINS ACTUALLY SHIPPED: SYBU 0.7.67 went out
# built from its tkinter main.py after the Qt port, so the port was done and
# nobody got it.
# ---------------------------------------------------------------------------

def test_spec_entry_is_the_qt_launcher():
    spec = open(os.path.join(TOOLS, 'unzucker.spec'), encoding='utf-8').read()
    assert 'unzucker_launcher.py' in spec
    assert "Analysis(\n    [os.path.join(_src, 'unzucker_launcher.py')]" in spec, \
        'the Analysis entry script must be the Qt launcher'
    assert "'main.py'" in spec, 'main.py must be named in the skip set'


def test_launcher_runs_the_qt_window_and_not_tkinter():
    src = open(os.path.join(TOOLS, 'unzucker_launcher.py'), encoding='utf-8').read()
    assert 'from unzucker_qt import run' in src
    # IMPORTS, NOT THE WORD. A bare "'tkinter' not in src" failed on the
    # launcher's own docstring, which says main.py is the tkinter window -- the
    # comment explaining the rule tripped the rule. Two mistakes in one line
    # first time round: this one, and a mangled expression that could never
    # fail at all.
    assert 'import tkinter' not in src and 'from tkinter' not in src
    assert 'import main' not in src and 'from main' not in src


def test_spec_excludes_tkinter_and_ships_the_lgpl_text():
    spec = open(os.path.join(TOOLS, 'unzucker.spec'), encoding='utf-8').read()
    for module in ('tkinter', 'PIL.ImageTk', 'PIL._tkinter_finder'):
        assert "'%s'" % module in spec, '%s must be excluded' % module
    # LGPLv3 relinking: a COLLECT directory, never onefile, with the notice.
    assert 'COLLECT(' in spec and 'exclude_binaries=True' in spec
    assert 'LGPL-3.0.txt' in spec and 'Qt-PySide6-LGPLv3-NOTICE.txt' in spec
    for name in ('LGPL-3.0.txt', 'Qt-PySide6-LGPLv3-NOTICE.txt'):
        assert os.path.isfile(os.path.join(TOOLS_ROOT, '..', 'licenses', name)), \
            'licenses/%s must exist for the spec to ship it' % name


def test_the_qt_window_never_imports_tkinter(win):
    assert 'tkinter' not in sys.modules


# ---------------------------------------------------------------------------
# Three across, which the engine's trigram arithmetic requires
# ---------------------------------------------------------------------------

def test_grid_is_exactly_three_across(win):
    import unzucker_core as core
    win._grid._refit()
    expected = win._grid.viewport().width() // core.GRID_COLS
    assert win._model.thumb_px() == expected
    assert win._grid.gridSize().width() == expected


def test_grid_columns_come_from_the_engine():
    import unzucker_core as core
    import unzucker_qt as qt
    # Re-declaring 3 in the Qt file is how the two drift apart.
    assert qt.GRID_COLS is core.GRID_COLS
    src = open(os.path.join(TOOLS, 'unzucker_qt.py'), encoding='utf-8').read()
    assert 'GRID_COLS    = core.GRID_COLS' in src


# ---------------------------------------------------------------------------
# The engine's new bytes accessors, and the Linux port's URI form
# ---------------------------------------------------------------------------

def test_thumb_bytes_and_data_uri_cannot_drift(win):
    path = win._model.cells()[0]['first_image']
    raw = win._s.thumb_bytes(path, 160)
    assert raw[:2] == b'\xff\xd8', 'a real JPEG'
    uri = win._s.thumb(path, 160)
    assert uri.startswith('data:image/jpeg;base64,')
    assert base64.b64decode(uri.split(',', 1)[1]) == raw


def test_preview_bytes_and_uri_cannot_drift(win):
    path = win._model.cells()[0]['first_image']
    raw = win._s.preview_bytes(path, 900, 520)
    assert raw[:2] == b'\xff\xd8'
    assert base64.b64decode(win._s.preview(path, 900, 520).split(',', 1)[1]) == raw


def test_the_path_guard_still_refuses_a_file_outside_the_export(win):
    with pytest.raises(Exception):
        win._s.thumb_bytes(os.path.join(tempfile.gettempdir(), 'not-in-export.jpg'))


# ---------------------------------------------------------------------------
# The flows, as a person drives them
# ---------------------------------------------------------------------------

def test_parse_fills_the_grid_and_collapses_the_config(win):
    assert win._model.rowCount() == 7
    assert win._grid_section.isVisible()
    assert not win._cfg.isVisible()
    assert 'POSTS — 7' in win._grid_lbl.text()


def test_detail_page_shows_the_carousel(win, app):
    win._show_detail(3)
    app.processEvents()
    assert win._stack.currentIndex() == 1
    assert win._detail_info.text() == '4 / 7'
    assert win._d_type.text() == 'CAROUSEL'
    assert win._d_count.text() == '3 images'
    assert win._strip.isVisible() and win._strip_layout.count() >= 3
    pm = win._preview.pixmap()
    assert pm is not None and not pm.isNull(), 'the large preview decoded'
    assert '#gamma' in win._d_tags.text()


def test_detail_navigation_and_back(win, app):
    win._show_detail(3)
    win._detail_next()
    assert win._detail_index == 4
    win._detail_prev()
    assert win._detail_index == 3
    win._show_detail(0)
    app.processEvents()
    assert not win._strip.isVisible(), 'a single-image post has no strip'
    win._show_grid()
    assert win._stack.currentIndex() == 0


def test_detail_prev_and_next_stop_at_the_ends(win):
    win._show_detail(0)
    win._detail_prev()
    assert win._detail_index == 0, 'PREV on the first post stays put'
    win._show_detail(6)
    win._detail_next()
    assert win._detail_index == 6, 'NEXT on the last post stays put'


def test_exclude_round_trip_reaches_the_engine(win, app):
    win._toggle_exclude(5)
    app.processEvents()
    assert win._model.cells()[5]['excluded'] is True
    assert '6 to migrate' in win._grid_lbl.text()
    assert win._s.validate()['active_count'] == 6, 'the ENGINE agrees, not just the cell'
    win._toggle_exclude(5)
    assert win._model.cells()[5]['excluded'] is False
    assert win._s.validate()['active_count'] == 7


def test_trigram_selection_then_lock_then_remove(win, app):
    import unzucker_qt as qt
    win._on_ctrl_click(0)
    win._on_ctrl_click(1)
    assert win._model.is_selecting(0) and win._model.is_selecting(1)
    assert '2 of 3' in win._status_lbl.text()

    opened = {}
    real = win._open_trigram_dialog
    win._open_trigram_dialog = lambda panel, indices: opened.update(
        panel=panel, indices=indices)
    win._on_ctrl_click(2)
    win._open_trigram_dialog = real
    assert opened['indices'] == [0, 1, 2], 'the third click hands over three posts'

    dlg = qt.TrigramDialog(win, win._s, opened['panel'], opened['indices'])
    assert [i for _, i in dlg._order] == [0, 1, 2]
    pm = dlg._thumbs[0].pixmap()
    assert pm is not None and not pm.isNull()
    dlg._swap(0)
    assert [i for _, i in dlg._order] == [1, 0, 2]
    dlg._lock()
    assert dlg.locked_order == [1, 0, 2]

    locked = win._s.lock_trigram(dlg.locked_order)
    win._model.set_cells(locked['posts'])
    win._update_tg_label(locked['trigrams'])
    app.processEvents()
    badged = [(c['index'], c['tg_group'], c['tg_slot'])
              for c in win._model.cells() if c['tg_group']]
    assert len(badged) == 3
    assert [s for _, _, s in badged] == [1, 2, 3], 'slots L, M, R'
    assert [i for i, _, _ in badged] == [0, 1, 2], \
        'the locked run sits in ONE row — the whole point of three across'
    assert '1 trigram locked' in win._tg_lbl.text()

    win._remove_trigram(0)
    app.processEvents()
    assert not any(c['tg_group'] for c in win._model.cells())


def test_a_fourth_trigram_pick_is_refused_not_silently_dropped(win):
    for row in (0, 1):
        win._on_ctrl_click(row)
    # The engine caps a selection at three; the third pick opens the dialog, so
    # reach the cap by selecting three without letting the dialog open.
    win._s.tg_selection = [0, 1, 2]
    result = win._s.trigram_select(4)
    assert result.get('error'), 'a fourth pick must say so rather than be ignored'


def test_validate_reports_a_missing_file(win, export):
    victim = win._model.cells()[2]['first_image']
    os.remove(victim)
    try:
        result = win._s.validate()
        assert result['ok'] is False
        assert any('Missing' in i for i in result['issues'])
        win._on_validate()
        assert 'problem' in win._status_lbl.text()
    finally:
        from PIL import Image
        Image.new('RGB', (900, 700), (40, 90, 150)).save(victim, 'JPEG', quality=70)


def test_nothing_can_reach_a_live_site_without_connecting(win):
    assert win._s.migration_preview().get('error') == 'Click Connect first.'
    assert win._s.start_migration().get('error') == 'Click Connect first.'
    assert win._s.posting is False


def test_unload_clears_the_job_and_shows_the_config_again(win, app):
    win._unload_job()
    app.processEvents()
    assert win._model.rowCount() == 0
    assert win._cfg.isVisible()
    assert not win._grid_section.isVisible()
    assert not win._unload_btn.isEnabled()


def test_insecure_transport_still_fails_closed(win):
    """SECAUDIT 041's guard: an http URL must come back as a QUESTION, not a
    silent send. The engine answers that; this pins that the Qt window asks."""
    result = win._s.connect('http://example.invalid', 'k' * 20)
    assert result.get('needs_confirm') and result.get('reason') == 'not_https'
    src = open(os.path.join(TOOLS, 'unzucker_qt.py'), encoding='utf-8').read()
    assert "result.get('needs_confirm')" in src
    assert 'allow_insecure=True' in src, 'and only sends after a yes'


def test_window_geometry_survives_a_close(win, app):
    import config
    win.setGeometry(120, 90, 1024, 768)
    win.close()
    app.processEvents()
    assert config.load().get('window_geometry') == '120,90,1024,768'


# ---------------------------------------------------------------------------
# The two versions that have to agree
# ---------------------------------------------------------------------------

def test_build_version_is_read_from_one_place():
    import unzucker_core as core
    import unzucker_qt as qt
    assert qt.BUILD_VERSION == core.BUILD_VERSION
    spec = open(os.path.join(TOOLS, 'unzucker.spec'), encoding='utf-8').read()
    assert 'unzucker_core.py' in spec and 'BUILD_VERSION' in spec, \
        'the spec must stamp the exe from the same constant'

# ===== SNAPSMACK EOF =====
