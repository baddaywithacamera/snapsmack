"""Qt SLAP HAPPY page: the engine gets exactly the chosen inputs, off the main thread."""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.

import json
import os
import sys
import threading
import time
import zipfile

os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")

HERE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if HERE not in sys.path:
    sys.path.insert(0, HERE)

import pytest
from PySide6.QtWidgets import QApplication

import profile_manager
import slap_happy
import suyb_qt_slaphappy
from suyb_qt_slaphappy import LOCAL, SlapHappyPage

APP = QApplication.instance() or QApplication([])


def pump(until, timeout=10.0):
    end = time.time() + timeout
    while time.time() < end:
        APP.processEvents()
        if until():
            return True
        time.sleep(0.01)
    APP.processEvents()
    return until()


class FakeWindow:
    def __init__(self, cloud=None):
        self._cloud = cloud or {}

    def global_cloud(self):
        return dict(self._cloud)


@pytest.fixture
def home(tmp_path, monkeypatch):
    """Same SNAP SLAPPER layout as tests/test_slap_happy.py, as SNAPSMACK_HOME."""
    root = tmp_path / "home"
    config = root / "config_files" / "snap-slapper"
    catalog = root / "shared_library"
    photos = root / "my photos"
    for path in (config, catalog, photos):
        path.mkdir(parents=True)
    (config / "settings.json").write_text('{"theme":"mean"}', encoding="utf-8")
    (catalog / "index.json").write_text('{"photos":1}', encoding="utf-8")
    (photos / "one.jpg").write_bytes(b"photo-one")
    (photos / "edit.slapper").write_text('{"format":1}', encoding="utf-8")
    (config / "backup_contract.json").write_text(json.dumps({
        "format": 1, "settings_dir": str(config), "catalog_dir": str(catalog),
        "image_roots": [str(photos)], "saved_roots": []}), encoding="utf-8")
    monkeypatch.setenv("SNAPSMACK_HOME", str(root))
    profiles = tmp_path / "profiles"; profiles.mkdir()
    monkeypatch.setattr(profile_manager, "PROFILES_DIR", str(profiles))
    monkeypatch.setattr(profile_manager, "sync_shared_profiles", lambda: 0)
    monkeypatch.setattr(profile_manager.secret_vault, "is_enabled", lambda: False)
    return root


def make_page(window=None):
    page = SlapHappyPage(window or FakeWindow())
    page.told = []
    page._tell = lambda kind, title, text: page.told.append((kind, title, text))
    return page


@pytest.fixture
def fake_engine(monkeypatch):
    calls = {"create": [], "commit": [], "threads": []}

    def create_backup(home, output_dir, mode="incremental", components=(), state_path=None,
                      destination_key="local", commit=True, on_progress=None):
        calls["threads"].append(threading.current_thread() is threading.main_thread())
        calls["create"].append({"home": home, "output_dir": output_dir, "mode": mode,
                                "components": list(components),
                                "destination_key": destination_key, "commit": commit})
        if on_progress:
            on_progress("Scanning", 2, 4)
        return {"path": os.path.join(output_dir, "SLAP-HAPPY-x.zip"), "changed": 3,
                "deleted": 0, "total": 4}
    monkeypatch.setattr(slap_happy, "create_backup", create_backup)
    monkeypatch.setattr(slap_happy, "commit_backup_state", lambda r: calls["commit"].append(r))
    return calls


def test_refresh_shows_handoff_and_local_destination(home):
    page = make_page(); page.refresh()
    text = page._paths.text()
    assert "Original locations: 1 chosen by photographer" in text
    assert "my photos" in text
    assert [page._destination.itemText(i) for i in range(page._destination.count())] == [LOCAL]


def test_punch_it_local_passes_chosen_inputs(home, fake_engine, tmp_path):
    page = make_page(); page.refresh()
    page._parts["photos"].setChecked(False)
    page._mode.setCurrentIndex(page._mode.findData("full"))
    out = str(tmp_path / "out")
    page._folder.setText(out)
    page._run_btn.click()
    assert not page._run_btn.isEnabled()
    assert pump(lambda: not page._running)
    assert fake_engine["create"] == [{
        "home": os.path.abspath(str(home)), "output_dir": out, "mode": "full",
        "components": ["suyb_settings", "settings", "catalog", "projects"],
        "destination_key": LOCAL, "commit": True}]
    assert fake_engine["threads"] == [False]            # ran on a worker thread
    assert fake_engine["commit"] == []                   # local commits inside create_backup
    assert page._run_btn.isEnabled()
    assert page._status.text() == "Done — 3 file(s) packed to local storage."
    assert page._progress.maximum() == 4 and page._progress.value() == 2
    assert page.told[-1][0] == "info" and "SLAP-HAPPY-x.zip" in page.told[-1][2]


class FakeClient:
    def __init__(self, verified=True):
        self.verified = verified
        self.uploaded = []

    def upload_file(self, path, name):
        self.uploaded.append((path, name)); return "remote-1"

    def verify_upload(self, remote_id, path):
        return self.verified


def _cloud_site(monkeypatch, client):
    profile = profile_manager.new_profile_template()
    profile.update({"name": "Cloudy", "site_url": "https://cloudy.test",
                    "cloud_provider": "google_drive", "cloud_credentials_file": "creds.json"})
    profile_manager.save_profile(profile)
    seen = []
    def get_client(p, global_cloud=None):
        seen.append(global_cloud)
        return client if p.get("name") == "Cloudy" else None
    monkeypatch.setattr(suyb_qt_slaphappy.cloud_module, "get_cloud_client", get_client)
    return seen


def test_cloud_destination_uploads_verifies_then_commits(home, fake_engine, monkeypatch):
    client = FakeClient()
    seen = _cloud_site(monkeypatch, client)
    page = make_page(FakeWindow({"cloud_provider": "box"})); page.refresh()
    label = "Cloud — Cloudy (Google Drive)"
    assert page._destination.findText(label) >= 0
    page._destination.setCurrentText(label)
    page._run_btn.click()
    assert pump(lambda: not page._running)
    assert fake_engine["create"][0]["commit"] is False
    assert fake_engine["create"][0]["destination_key"] == label
    assert client.uploaded and client.uploaded[0][1] == "SLAP-HAPPY-x.zip"
    assert len(fake_engine["commit"]) == 1
    assert page._status.text() == f"Done — 3 file(s) packed to {label}."
    assert {"cloud_provider": "box"} in seen


def test_unverified_upload_stops_safely_without_commit(home, fake_engine, monkeypatch):
    _cloud_site(monkeypatch, FakeClient(verified=False))
    page = make_page(); page.refresh()
    page._destination.setCurrentText("Cloud — Cloudy (Google Drive)")
    page._run_btn.click()
    assert pump(lambda: not page._running)
    assert fake_engine["commit"] == []
    assert page._status.text() == "Backup stopped safely."
    assert page.told[-1][0] == "error" and "could not be verified" in page.told[-1][2]
    assert page._run_btn.isEnabled()


def test_nothing_ticked_or_no_folder_never_calls_engine(home, fake_engine):
    page = make_page(); page.refresh()
    for box in page._parts.values():
        box.setChecked(False)
    page._run_btn.click()
    assert page.told[-1][1] == "Pick something"
    page._parts["photos"].setChecked(True)
    page._folder.setText("   ")
    page._run_btn.click()
    assert page.told[-1][1] == "Pick a folder"
    assert fake_engine["create"] == [] and not page._running


def test_real_engine_writes_a_zip(home, tmp_path):
    page = make_page(); page.refresh()
    out = str(tmp_path / "real-out")
    page._folder.setText(out)
    page._run_btn.click()
    assert pump(lambda: not page._running)
    kind, _title, text = page.told[-1]
    assert kind == "info", text
    path = text.split("\n\n", 1)[1]
    with zipfile.ZipFile(path) as package:
        names = package.namelist()
    assert "SLAP-HAPPY-MANIFEST.json" in names
    assert any(name.endswith("one.jpg") for name in names)
    assert any(name.endswith("edit.slapper") for name in names)

# ===== SNAPSMACK EOF =====
