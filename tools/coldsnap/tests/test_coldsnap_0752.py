"""COLD SNAP 0.7.52 regression tests:
- Persistent per-user sessions root outside the install directory
- First-launch copy + SHA-256 migration (originals preserved)
- Backup folder discovery & explicit user-confirmed import
- No dark (#0d0e0e) horizontal bands above/below BiggieCanvas under dark theme
- Empty BIGGIE document reports 0 blocks instead of 1 block
"""

import hashlib
import os
import sys

from PySide6.QtWidgets import QApplication

_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if _ROOT not in sys.path:
    sys.path.insert(0, _ROOT)

import sumna_offline as O
from coldsnap_qt import theme
from coldsnap_qt.body_editor import BodyEditor
from coldsnap_qt.mode_take import TakeMode


def _app():
    return QApplication.instance() or QApplication([])


def test_default_sessions_root_is_outside_install_dir(monkeypatch, tmp_path):
    monkeypatch.setenv("SNAPSMACK_HOME", str(tmp_path))
    root = O.default_sessions_root()
    assert root == os.path.join(str(tmp_path), "shared_library", "coldsnap", "sob_sessions")
    assert os.path.abspath(root) != os.path.abspath(O.legacy_sessions_root())


def test_migrate_sessions_dir_copies_with_sha256_and_preserves_originals(tmp_path):
    legacy_root = tmp_path / "install_v1" / "sob_sessions"
    dest_root = tmp_path / "shared_library" / "coldsnap" / "sob_sessions"

    old_store = O.SessionStore(str(legacy_root))
    sess = old_store.create("Engines", O.MODE_SMACKTALK)
    img = tmp_path / "photo.jpg"
    img.write_bytes(b"\xff\xd8\xff\xe0" + b"x" * 128)
    os.makedirs(sess.images_dir, exist_ok=True)
    sess_img = os.path.join(sess.images_dir, "photo.jpg")
    with open(sess_img, "wb") as f:
        f.write(img.read_bytes())

    d = O.Draft(
        draft_id="d1",
        mode=O.MODE_SMACKTALK,
        kind=O.KIND_SMACKTALK,
        title="Start your engines",
        images=[O.DraftImage(local_path=sess_img)],
    )
    sess.save_draft(d)

    log_file = str(tmp_path / "logs" / "migration.log")
    res = O.migrate_sessions_dir(str(legacy_root), str(dest_root), log_path=log_file)

    assert res["sessions_touched"] == [sess.session_id]
    assert len(res["migrated"]) >= 3
    assert res["conflicts"] == []
    assert os.path.isfile(log_file)
    log_text = open(log_file, encoding="utf-8").read()
    assert "COPIED" in log_text and "sha256=" in log_text

    # Originals are untouched
    assert os.path.isfile(os.path.join(sess.drafts_dir, "d1.json"))
    assert os.path.isfile(sess_img)

    # Destination has matching SHA-256 and rebased image path
    new_store = O.SessionStore(str(dest_root))
    migrated_sess = new_store.load(sess.session_id)
    assert migrated_sess is not None
    loaded_d = migrated_sess.load_draft("d1")
    assert loaded_d is not None and loaded_d.title == "Start your engines"
    assert loaded_d.images[0].local_path == os.path.join(migrated_sess.images_dir, "photo.jpg")

    orig_sha = hashlib.sha256(open(sess_img, "rb").read()).hexdigest()
    dest_sha = hashlib.sha256(open(loaded_d.images[0].local_path, "rb").read()).hexdigest()
    assert orig_sha == dest_sha

    # Second run is idempotent (zero conflicts even after image path rebasing)
    res2 = O.migrate_sessions_dir(str(legacy_root), str(dest_root), log_path=log_file)
    assert res2["migrated"] == []
    assert res2["conflicts"] == []
    assert len(res2["skipped_identical"]) >= 3

    # Genuinely modified destination draft IS reported as a conflict
    loaded_d.title = "Edited in dest"
    migrated_sess.save_draft(loaded_d)
    res3 = O.migrate_sessions_dir(str(legacy_root), str(dest_root), log_path=log_file)
    assert res3["migrated"] == []
    assert [c["rel_path"] for c in res3["conflicts"]] == ["drafts/d1.dbrows.json", "drafts/d1.json"]


def test_install_over_existing_preserves_drafts(monkeypatch, tmp_path):
    monkeypatch.setenv("SNAPSMACK_HOME", str(tmp_path))
    store1 = O.SessionStore()
    s1 = store1.create("Persistent", O.MODE_SMACKTALK)
    s1.save_draft(O.Draft(draft_id="keep1", mode=O.MODE_SMACKTALK, kind=O.KIND_SMACKTALK, title="Survives Upgrade"))

    # Simulate replacing install dir (persistent store is outside install dir)
    store2 = O.SessionStore()
    loaded = store2.load(s1.session_id)
    assert loaded is not None
    d = loaded.load_draft("keep1")
    assert d is not None and d.title == "Survives Upgrade"


def test_discover_backup_sessions_and_explicit_import(monkeypatch, tmp_path):
    monkeypatch.setenv("SNAPSMACK_HOME", str(tmp_path))
    dest_root = O.default_sessions_root()
    bk_root = tmp_path / "coldsnap-backup-0.7.45-test" / "sob_sessions"
    bk_store = O.SessionStore(str(bk_root))
    s = bk_store.create("Old Essay", O.MODE_SMACKTALK)
    s.save_draft(O.Draft(draft_id="essay1", mode=O.MODE_SMACKTALK, kind=O.KIND_SMACKTALK, title="Start your engines"))

    found = O.discover_backup_sessions(str(tmp_path), dest_root)
    assert len(found) == 1
    assert found[0]["backup_name"] == "coldsnap-backup-0.7.45-test"
    assert found[0]["draft_count"] == 1
    assert "Start your engines" in found[0]["draft_titles"]

    # Declining import must NOT copy silently
    from coldsnap_qt.connect_panel import ConnectPanel
    from coldsnap_qt.main_window import MainWindow
    monkeypatch.setattr(ConnectPanel, "_begin_async_load", lambda self: None)
    monkeypatch.setattr(ConnectPanel, "_start_mode_discovery", lambda self: None)
    _app()
    win = MainWindow()
    try:
        win._run_startup_session_migration(
            legacy_root=str(tmp_path / "no_legacy"),
            dest_root=dest_root,
            backup_parent=str(tmp_path),
            ask_fn=lambda _prompt: False,
        )
        assert O.SessionStore(dest_root).load(s.session_id) is None

        # Accepting import copies with SHA-256 and leaves backup untouched
        prompts = []
        win._run_startup_session_migration(
            legacy_root=str(tmp_path / "no_legacy"),
            dest_root=dest_root,
            backup_parent=str(tmp_path),
            ask_fn=lambda p: (prompts.append(p), True)[1],
        )
        assert len(prompts) == 1
        imported = O.SessionStore(dest_root).load(s.session_id)
        assert imported is not None
        assert imported.load_draft("essay1").title == "Start your engines"
        assert os.path.isfile(os.path.join(s.drafts_dir, "essay1.json"))
        # Already-imported backup session is not re-offered
        assert O.discover_backup_sessions(str(tmp_path), dest_root) == []
        # Same-ID draft with different content in backup IS offered
        s.save_draft(O.Draft(draft_id="essay1", mode=O.MODE_SMACKTALK, kind=O.KIND_SMACKTALK, title="Different Version"))
        diff_found = O.discover_backup_sessions(str(tmp_path), dest_root)
        assert len(diff_found) == 1 and diff_found[0]["draft_titles"] == ["Different Version"]
    finally:
        win.close()


def test_biggie_body_editor_has_no_dark_bands_under_dark_theme():
    app = _app()
    app.setStyleSheet(theme.stylesheet())
    ed = BodyEditor(rich=True)
    ed._set_biggie(True)
    ed.resize(840, 700)
    ed.show()
    app.processEvents()

    img = ed._biggie_page.grab().toImage()
    w, h = img.width(), img.height()
    assert w > 100 and h >= 600
    x = w // 2
    dark_pixels = 0
    for y in range(10, h - 10, 4):
        c = img.pixelColor(x, y)
        if c.red() < 40 and c.green() < 40 and c.blue() < 40:
            dark_pixels += 1
    ed.close()
    assert dark_pixels == 0, f"Found {dark_pixels} dark pixels inside _biggie_page"


def test_take_mode_empty_biggie_reports_zero_blocks():
    _app()
    mode = TakeMode(lambda: {"url": "https://baddaywithacamera.com"})
    mode.body._set_biggie(True)
    mode.body.clear()
    mode._update_page_chrome()
    assert "0 blocks" in mode.page_meta.text()
    mode.close()

# ===== SNAPSMACK EOF =====
