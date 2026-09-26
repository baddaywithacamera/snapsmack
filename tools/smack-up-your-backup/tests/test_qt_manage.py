"""Qt Manage page + CloudBrowserDialog — listing, filters, delete, download, dialog result.

No network, no visible windows: a fake window and a fake cloud client.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
"""

import os
import pathlib
import sys
import tempfile
import time

os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")

HERE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if HERE not in sys.path:
    sys.path.insert(0, HERE)

import pytest
from PySide6.QtCore import QObject, Qt, Signal
from PySide6.QtWidgets import QApplication, QDialog, QFileDialog, QMessageBox

import suyb_qt_manage as manage

APP = QApplication.instance() or QApplication([])
# Every widget made here stays alive until the process ends: letting Python
# collect a widget while its worker thread's queued call is in flight crashes
# Qt inside processEvents (a test-harness artefact; real pages live as long as
# the window).
KEEP = []


FILES = [
    {"id": "1", "name": "alpha-ca_backup_2026-08-01_1200.zip", "size": 1048576, "modifiedTime": "2026-08-01T12:00:00Z"},
    {"id": "2", "name": "alpha-ca_backup_2026-08-20_1200.zip", "size": 3145728, "modifiedTime": "2026-08-20T12:00:00Z"},
    {"id": "3", "name": "bravo-com_backup_2026-07-15_0900.zip", "size": 2097152, "modifiedTime": ""},
    {"id": "4", "name": "bravo-com_backup_2026-09-02_0900.zip", "size": 512, "modifiedTime": "2026-09-02T09:00:00Z"},
    {"id": "5", "name": "notes_backup_2026-09-01.txt", "size": 10, "modifiedTime": "2026-09-01T00:00:00Z"},
]


class FakeClient:
    def __init__(self, files=None, fail_list=None):
        self.files = list(files if files is not None else FILES)
        self.fail_list = fail_list
        self.deleted = []
        self.downloaded = []

    def list_files(self, name_filter=""):
        if self.fail_list:
            raise RuntimeError(self.fail_list)
        return [dict(f) for f in self.files if name_filter in f["name"]]

    def delete_file(self, file_id, file_name=""):
        self.deleted.append((file_id, file_name))
        self.files = [f for f in self.files if f["id"] != file_id]

    def download_file(self, file_id, local_path, on_progress=None):
        data = f"zip-{file_id}".encode()
        with open(local_path, "wb") as fh:
            fh.write(data)
        self.downloaded.append((file_id, local_path))
        if on_progress:
            on_progress(len(data), len(data))


class FakeWindow(QObject):
    profileChanged = Signal(object)

    def __init__(self, profile=None):
        super().__init__()
        self.current_profile = profile if profile is not None else {"name": "alpha", "cloud_provider": "google_drive"}

    def global_cloud(self):
        return {"cloud_provider": "google_drive", "cloud_credentials_file": "x.json", "cloud_folder_id": "f"}

    def busy(self):
        return False


def wait_until(cond, timeout=5.0):
    end = time.time() + timeout
    while time.time() < end:
        APP.processEvents()
        if cond():
            return True
        time.sleep(0.01)
    APP.processEvents()
    return cond()


@pytest.fixture
def folder():
    """A throw-away folder (tempfile, not pytest's folder: its shared
    pytest-of-<user> root is not always readable on this machine)."""
    with tempfile.TemporaryDirectory() as d:
        yield pathlib.Path(d)


@pytest.fixture
def client(monkeypatch):
    fake = FakeClient()
    monkeypatch.setattr(manage.cloud_client, "get_cloud_client", lambda profile, global_cloud=None: fake)
    monkeypatch.setattr(manage.sync_manager, "list_jobs", lambda: [])
    return fake


@pytest.fixture
def page(client):
    p = ManagePageHolder.make()
    yield p
    p.shutdown()


class ManagePageHolder:
    @staticmethod
    def make():
        window = FakeWindow()
        p = manage.ManagePage(window)
        KEEP.append((p, window))
        return p


def loaded(p):
    p.refresh()
    assert wait_until(lambda: not p._busy and p._loaded), "list never loaded"
    return p


def names(p):
    return [b["name"] for b in p.visible_backups()]


def tick(p, wanted_ids):
    for item, b in p._rows:
        item.setCheckState(manage.COL_TICK, Qt.Checked if b["id"] in wanted_ids else Qt.Unchecked)


# -- construction does no work -------------------------------------------------

def test_init_does_not_touch_cloud(monkeypatch):
    calls = []
    monkeypatch.setattr(manage.cloud_client, "get_cloud_client", lambda *a, **k: calls.append(1))
    monkeypatch.setattr(manage.sync_manager, "list_jobs", lambda: calls.append(2) or [])
    p = manage.ManagePage(FakeWindow()); KEEP.append(p)
    assert calls == []
    assert p.help_topics() and p.help_topics()[0][0].startswith("Manage")


# -- listing ---------------------------------------------------------------------

def test_listing_only_backup_zips_newest_first(page):
    loaded(page)
    assert names(page) == [
        "bravo-com_backup_2026-09-02_0900.zip",
        "alpha-ca_backup_2026-08-20_1200.zip",
        "alpha-ca_backup_2026-08-01_1200.zip",
        "bravo-com_backup_2026-07-15_0900.zip",   # date taken from the filename
    ]
    assert len(page._rows) == 4
    assert "4 backup(s) shown" in page.totals_lbl.text()


def test_listing_error_is_reported_not_hidden(monkeypatch):
    fake = FakeClient(fail_list="401 unauthorized")
    monkeypatch.setattr(manage.cloud_client, "get_cloud_client", lambda *a, **k: fake)
    monkeypatch.setattr(manage.sync_manager, "list_jobs", lambda: [])
    p = manage.ManagePage(FakeWindow()); KEEP.append(p)
    p.refresh()
    assert wait_until(lambda: not p._busy)
    assert "401 unauthorized" in p.status_lbl.text()
    assert p.status_lbl.objectName() == "StatusBad"


def test_no_profile_asks_for_a_site(client):
    p = manage.ManagePage(FakeWindow(profile={})); KEEP.append(p)
    p.window.current_profile = None
    p.refresh()
    assert not p._busy and "Pick a site" in p.status_lbl.text()


def test_backblaze_sources_come_from_sync_jobs(monkeypatch, client):
    job = {"source_provider": "google_drive", "dest_provider": "backblaze_b2",
           "dest_b2_key_id": "kid", "dest_b2_app_key": "akey", "dest_folder": "my-bucket"}
    monkeypatch.setattr(manage.sync_manager, "list_jobs", lambda: ["nightly", "locked"])
    monkeypatch.setattr(manage.sync_manager, "load_job",
                        lambda n: job if n == "nightly" else (_ for _ in ()).throw(RuntimeError("vault locked")))
    made = []
    b2 = FakeClient(files=FILES[:2])
    monkeypatch.setattr(manage.cloud_client, "B2Client", lambda k, a, b: made.append((k, a, b)) or b2)
    p = manage.ManagePage(FakeWindow()); KEEP.append(p)
    loaded(p)
    labels = [p.source_combo.itemText(i) for i in range(p.source_combo.count())]
    assert labels == [manage.PROFILE_SOURCE_LABEL, "Backblaze — my-bucket  (job: nightly)"]
    p.source_combo.setCurrentIndex(1)            # switching source reloads
    assert wait_until(lambda: not p._busy and len(p._all) == 2)
    assert made[-1] == ("kid", "akey", "my-bucket")


# -- filters and sorting ----------------------------------------------------------

def test_search_filters_blog_and_file(page):
    loaded(page)
    page.search_edit.setText("bravo")
    assert names(page) == ["bravo-com_backup_2026-09-02_0900.zip", "bravo-com_backup_2026-07-15_0900.zip"]
    assert "filtered from 4" in page.totals_lbl.text()


def test_date_filter_apply_and_clear(page):
    loaded(page)
    page.from_edit.setText("2026-08-01"); page.to_edit.setText("2026-08-31")
    assert len(names(page)) == 4                 # not applied until Apply dates
    page.apply_btn.click()
    assert names(page) == ["alpha-ca_backup_2026-08-20_1200.zip", "alpha-ca_backup_2026-08-01_1200.zip"]
    page.sort_combo.setCurrentText(manage.SORT_LARGEST)
    page.clear_btn.click()
    assert len(names(page)) == 4 and page.sort_combo.currentText() == manage.SORT_NEWEST
    assert page.from_edit.text() == "" and page.to_edit.text() == ""


def test_bad_date_is_refused(page):
    loaded(page)
    page.from_edit.setText("2026-8-1")
    page.apply_btn.click()
    assert len(names(page)) == 4
    assert page.status_lbl.objectName() == "StatusBad" and "YYYY-MM-DD" in page.status_lbl.text()


def test_sorts_and_column_headers(page):
    loaded(page)
    page.sort_combo.setCurrentText(manage.SORT_LARGEST)
    assert names(page)[0] == "alpha-ca_backup_2026-08-20_1200.zip"
    page.sort_combo.setCurrentText(manage.SORT_SMALLEST)
    assert names(page)[0] == "bravo-com_backup_2026-09-02_0900.zip"
    page.sort_combo.setCurrentText(manage.SORT_OLDEST)
    assert names(page)[0] == "bravo-com_backup_2026-07-15_0900.zip"
    page._sort_by_column(manage.COL_FILE)
    assert page.sort_combo.currentText() == manage.SORT_NAME
    assert names(page)[0].startswith("alpha-ca_backup_2026-08-01")
    page._sort_by_column(manage.COL_BLOG)
    assert page.sort_combo.currentText() == manage.SORT_BLOG


def test_grouped_view_has_blog_headings_with_totals(page):
    loaded(page)
    page.sort_combo.setCurrentText(manage.SORT_GROUPED)
    tops = [page.tree.topLevelItem(i) for i in range(page.tree.topLevelItemCount())]
    assert [t.text(0).split()[0] for t in tops] == ["alpha-ca", "bravo-com"]
    assert "2 backup(s), 4.0 MB" in tops[0].text(0)
    assert tops[0].childCount() == 2 and len(page._rows) == 4


# -- selection ---------------------------------------------------------------------

def test_tick_all_and_buttons(page):
    loaded(page)
    assert not page.delete_btn.isEnabled() and not page.restore_btn.isEnabled()
    page.tick_all.click()
    assert len(page.selected_backups()) == 4
    assert page.delete_btn.isEnabled() and page.download_btn.isEnabled()
    assert not page.restore_btn.isEnabled()      # restore needs exactly one
    tick(page, {"2"})
    assert page.restore_btn.isEnabled()
    assert page.delete_btn.objectName() == "Danger"


def test_row_click_ticks(page):
    loaded(page)
    item = page._rows[0][0]
    page._item_clicked(item, manage.COL_FILE)
    assert item.checkState(manage.COL_TICK) == Qt.Checked
    page._item_clicked(item, manage.COL_FILE)
    assert item.checkState(manage.COL_TICK) == Qt.Unchecked


# -- delete -------------------------------------------------------------------------

def _answer_boxes(monkeypatch, confirm):
    seen = []

    def fake_exec(box):
        seen.append((box.text(), box.informativeText(), [b.text() for b in box.buttons()]))
        return 0

    def fake_clicked(box):
        return getattr(box, "_suyb_confirm", None) if confirm else None

    monkeypatch.setattr(QMessageBox, "exec", fake_exec)
    monkeypatch.setattr(QMessageBox, "clickedButton", fake_clicked)
    for name in ("warning", "information", "critical"):
        monkeypatch.setattr(QMessageBox, name, staticmethod(lambda *a, **k: seen.append(a[1:3])))
    return seen


def test_delete_cancel_deletes_nothing(page, client, monkeypatch):
    loaded(page)
    tick(page, {"1", "3"})
    seen = _answer_boxes(monkeypatch, confirm=False)
    page.delete_btn.click()
    assert client.deleted == []
    text, info, buttons = seen[0]
    assert "Permanently delete 2 backup(s)" in text
    assert "CANNOT be undone" in info and "alpha-ca_backup_2026-08-01_1200.zip" in info
    assert "Delete 2 backup(s)" in buttons and "Keep them" in buttons


def test_delete_confirm_deletes_exactly_the_ticked(page, client, monkeypatch):
    loaded(page)
    tick(page, {"1", "3"})
    _answer_boxes(monkeypatch, confirm=True)
    page.delete_btn.click()
    assert wait_until(lambda: not page._busy and page._loaded and len(page._all) == 2)
    assert sorted(client.deleted) == [("1", "alpha-ca_backup_2026-08-01_1200.zip"),
                                      ("3", "bravo-com_backup_2026-07-15_0900.zip")]


def test_delete_confirmation_truncates_long_lists(page):
    many = [{"id": str(i), "name": f"b_backup_2026-01-{i:02d}.zip", "size_bytes": 1024} for i in range(1, 21)]
    headline, details, full = page.delete_confirmation_text(many)
    assert "20 backup(s)" in headline
    assert "and 5 more" in details and len(full.splitlines()) == 20


# -- download -------------------------------------------------------------------------

def test_download_selected_to_folder(page, client, monkeypatch, folder):
    loaded(page)
    tick(page, {"2", "4"})
    _answer_boxes(monkeypatch, confirm=True)
    monkeypatch.setattr(QFileDialog, "getExistingDirectory", staticmethod(lambda *a, **k: str(folder)))
    page.download_btn.click()
    assert wait_until(lambda: not page._busy and len(client.downloaded) == 2)
    wait_until(lambda: "Downloaded 2" in page.status_lbl.text())
    for fid, name in (("2", "alpha-ca_backup_2026-08-20_1200.zip"), ("4", "bravo-com_backup_2026-09-02_0900.zip")):
        assert (folder / name).read_bytes() == f"zip-{fid}".encode()
    assert not list(folder.glob("*.part"))


def test_failed_download_leaves_no_zip(folder):
    class Broken:
        def download_file(self, fid, path, cb=None):
            open(path, "wb").write(b"half")
            raise OSError("connection reset")
    dest = str(folder / "x_backup_2026-01-01.zip")
    with pytest.raises(OSError):
        manage.download_to(Broken(), {"id": "9"}, dest)
    assert list(folder.iterdir()) == []


def test_unsafe_cloud_names_are_flattened():
    assert manage.safe_file_name("../../evil_backup_.zip") == "evil_backup_.zip"
    assert manage.safe_file_name("..") == ""


# -- restore hand-off -------------------------------------------------------------------

def test_restore_cloud_backup_emits_id_and_name(page, monkeypatch):
    loaded(page)
    got = []
    page.cloudRestoreRequested.connect(lambda fid, name: got.append((fid, name)))
    tick(page, {"2"})
    page.restore_btn.click()
    assert got == [("2", "alpha-ca_backup_2026-08-20_1200.zip")]


def test_restore_backblaze_downloads_then_emits_local_path(monkeypatch, folder):
    job = {"dest_provider": "b2", "dest_b2_key_id": "k", "dest_b2_app_key": "a", "dest_folder": "bkt"}
    monkeypatch.setattr(manage.sync_manager, "list_jobs", lambda: ["j"])
    monkeypatch.setattr(manage.sync_manager, "load_job", lambda n: job)
    b2 = FakeClient(files=FILES[:1])
    monkeypatch.setattr(manage.cloud_client, "B2Client", lambda *a: b2)
    monkeypatch.setattr(manage.cloud_client, "get_cloud_client", lambda *a, **k: FakeClient())
    p = manage.ManagePage(FakeWindow()); KEEP.append(p)
    loaded(p)
    p.source_combo.setCurrentIndex(1)
    assert wait_until(lambda: not p._busy and len(p._all) == 1)
    dest = str(folder / "picked.zip")
    monkeypatch.setattr(QFileDialog, "getSaveFileName", staticmethod(lambda *a, **k: (dest, "")))
    got = []
    p.restoreRequested.connect(got.append)
    tick(p, {"1"})
    p.restore_btn.click()
    assert wait_until(lambda: got == [dest])
    assert open(dest, "rb").read() == b"zip-1"


# -- CloudBrowserDialog -------------------------------------------------------------------

def test_cloud_browser_dialog_result_shape():
    backups = [{"id": "a", "name": "old_backup_2026-01-01.zip", "size_bytes": 1048576, "date": "2026-01-01T00:00:00Z"},
               {"id": "b", "name": "new_backup_2026-02-01.zip", "size_bytes": 2097152, "date": "2026-02-01T00:00:00Z"}]
    dlg = manage.CloudBrowserDialog(None, {}, backups=backups)
    KEEP.append(dlg)
    assert dlg.result is None and not dlg.select_btn.isEnabled()
    dlg.tree.topLevelItem(0).setSelected(True)       # newest first
    dlg.select_btn.click()
    assert dlg.result == ("b", "new_backup_2026-02-01.zip")
    assert dlg.exec is not None and dlg.isHidden()


def test_cloud_browser_dialog_loads_itself(monkeypatch):
    fake = FakeClient()
    seen = []
    monkeypatch.setattr(manage.cloud_client, "get_cloud_client",
                        lambda profile, global_cloud=None: seen.append((profile, global_cloud)) or fake)
    dlg = manage.CloudBrowserDialog(None, {"cloud_provider": "box"}, profile={"name": "p"})
    KEEP.append(dlg)
    assert seen == []                                # nothing until shown
    dlg.show()
    assert wait_until(lambda: dlg.tree.topLevelItemCount() == 4)
    assert seen == [({"name": "p"}, {"cloud_provider": "box"})]
    dlg.tree.topLevelItem(0).setSelected(True)
    dlg._select()
    assert dlg.result == ("4", "bravo-com_backup_2026-09-02_0900.zip")
    assert dlg.close() is not None


def test_cloud_browser_dialog_cancel_leaves_result_none():
    dlg = manage.CloudBrowserDialog(None, {}, backups=[{"id": "a", "name": "x_backup_2026-01-01.zip"}])
    KEEP.append(dlg)
    dlg.reject()
    assert dlg.result is None

# ===== SNAPSMACK EOF =====
