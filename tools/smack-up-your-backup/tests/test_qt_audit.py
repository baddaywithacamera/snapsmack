"""Qt Audit page: each job calls its engine correctly, renders, saves, and
clean-up never touches ZIPs without an explicit yes.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
"""

import os
import sys
import time

os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")
HERE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if HERE not in sys.path:
    sys.path.insert(0, HERE)

import pytest
from PySide6.QtCore import QObject, Signal
from PySide6.QtWidgets import QApplication, QFileDialog, QMessageBox

import suyb_qt_audit as page_mod
from audit_engine import (AuditEntry, AuditReport, HEALTHY, MISSING_FROM_SERVER,
                          ORPHANED_ON_SERVER, WRONG_LOCATION)
from coverage_engine import (CoverageEntry, CoverageReport, DedupeResult, DedupeZipResult,
                             COVERED, OVER_BACKED, NEVER_BACKED)

APP = QApplication.instance() or QApplication([])


class FakeWindow(QObject):
    profileChanged = Signal(object)

    def __init__(self, profile=None, busy=False):
        super().__init__()
        self.current_profile = profile
        self._busy = busy

    def busy(self):
        return self._busy


def wait_for(cond, timeout=5.0):
    end = time.time() + timeout
    while time.time() < end:
        APP.processEvents()
        if cond():
            return
        time.sleep(0.01)
    raise AssertionError("timed out waiting for the page")


@pytest.fixture
def site(tmp_path):
    kit = tmp_path / "photowalk-ing_recovery_kit_20260101_000000.tar.gz"
    kit.write_bytes(b"not really a tar")
    return {"name": "Photo Walk", "site_url": "https://photowalk.ing/",
            "backup_dir": str(tmp_path), "ftp_host": "ftp.example.test"}, str(kit)


@pytest.fixture(autouse=True)
def quiet_boxes(monkeypatch):
    shown = []
    for name in ("information", "warning", "critical"):
        monkeypatch.setattr(QMessageBox, name, staticmethod(lambda *a, _n=name, **k: shown.append((_n, a[1:]))))
    monkeypatch.setattr(page_mod.manifest_reader, "from_tar", lambda path: ("MANIFEST", path))
    return shown


def audit_report():
    r = AuditReport(site_name="Photo Walk", site_url="https://photowalk.ing", audit_date="2026-09-25")
    r.entries = [AuditEntry("a", "img_uploads/a.jpg", HEALTHY),
                 AuditEntry("b", "img_uploads/b.jpg", MISSING_FROM_SERVER),
                 AuditEntry("c", "img_uploads/c.jpg", WRONG_LOCATION, note="Found at: x/c.jpg")]
    r.orphan_server = ["img_uploads/junk.jpg"]
    r.summary = {HEALTHY: 1, MISSING_FROM_SERVER: 1, WRONG_LOCATION: 1, ORPHANED_ON_SERVER: 1}
    return r


def coverage_report(backup_dir):
    r = CoverageReport(site_name="Photo Walk", backup_dir=backup_dir, scan_date="2026-09-25",
                       zips_scanned=["a.zip", "b.zip", "c.zip"])
    r.entries = [CoverageEntry("k1", "img_uploads/1.jpg", 10, COVERED, 1, ["a.zip"]),
                 CoverageEntry("k2", "img_uploads/2.jpg", 10, OVER_BACKED, 3, ["a.zip", "b.zip", "c.zip"]),
                 CoverageEntry("k3", "img_uploads/3.jpg", 10, OVER_BACKED, 2, ["b.zip", "c.zip"]),
                 CoverageEntry("k4", "img_uploads/4.jpg", 2048, NEVER_BACKED, 0, [])]
    r.summary = {COVERED: 1, OVER_BACKED: 2, NEVER_BACKED: 1}
    return r


def test_audit_calls_engine_with_profile_and_kit_manifest(monkeypatch, site):
    profile, kit = site
    calls = []

    class FakeAudit:
        def __init__(self, prof, manifest, on_progress=None, on_log=None):
            calls.append((prof, manifest)); self.on_progress = on_progress

        def run(self):
            self.on_progress("audit", "Checking", 0.5)
            return audit_report()

    monkeypatch.setattr(page_mod, "AuditEngine", FakeAudit)
    page = page_mod.AuditPage(FakeWindow(profile))
    assert not page.save_txt_btn.isEnabled()
    page.audit_btn.click()
    wait_for(lambda: page._report is not None)
    assert calls == [(profile, ("MANIFEST", kit))]
    text = page.results.toPlainText()
    assert "AUDIT REPORT" in text and "MISSING FROM SERVER (1)" in text
    assert "Found at: x/c.jpg" in text and "img_uploads/junk.jpg" in text
    assert page.save_txt_btn.isEnabled() and page.audit_btn.isEnabled()
    assert page.progress.value() == 100


def test_audit_shows_engine_log_when_connection_fails(monkeypatch, site):
    profile, _ = site

    class FailingAudit:
        def __init__(self, prof, manifest, on_progress=None, on_log=None):
            self.on_log = on_log

        def run(self):
            self.on_log("Connection failed: refused")
            return AuditReport(site_name="Photo Walk")

    monkeypatch.setattr(page_mod, "AuditEngine", FailingAudit)
    page = page_mod.AuditPage(FakeWindow(profile))
    page.audit_btn.click()
    wait_for(lambda: page._report is not None)
    assert "Connection failed: refused" in page.results.toPlainText()
    assert page.status.objectName() == "StatusBad"


def test_audit_refuses_without_profile_kit_or_ftp(monkeypatch, site, quiet_boxes, tmp_path):
    monkeypatch.setattr(page_mod, "AuditEngine", lambda *a, **k: pytest.fail("engine must not run"))
    page = page_mod.AuditPage(FakeWindow(None)); page.audit_btn.click()
    profile, kit = site
    page = page_mod.AuditPage(FakeWindow(dict(profile, ftp_host=""))); page.audit_btn.click()
    os.remove(kit)
    page = page_mod.AuditPage(FakeWindow(profile)); page.audit_btn.click()
    assert [k for k, _ in quiet_boxes] == ["information", "warning", "critical"]
    assert page._running == ""


def test_find_latest_kit_uses_url_token_and_legacy_name(tmp_path):
    for n in ("photowalk-ing_recovery_kit_20260101.tar.gz", "photowalk-ing_recovery_kit_20260301.tar.gz",
              "other-site_recovery_kit_20270101.tar.gz", "Photo_Walk_recovery_kit_20250101.tar.gz"):
        (tmp_path / n).write_bytes(b"x")
    prof = {"name": "Photo Walk", "site_url": "https://photowalk.ing", "backup_dir": str(tmp_path)}
    assert os.path.basename(page_mod.find_latest_kit(prof)) == "photowalk-ing_recovery_kit_20260301.tar.gz"
    assert page_mod.find_latest_kit(dict(prof, backup_dir=str(tmp_path / "nope"))) is None


def test_coverage_calls_engine_and_enables_dedupe(monkeypatch, site):
    profile, kit = site
    calls = []

    class FakeCoverage:
        def __init__(self, backup_dir, manifest, blog_name="", on_progress=None, on_log=None):
            calls.append((backup_dir, manifest, blog_name))

        def run(self):
            return coverage_report(profile["backup_dir"])

    monkeypatch.setattr(page_mod, "CoverageEngine", FakeCoverage)
    page = page_mod.AuditPage(FakeWindow(profile))
    assert not page.dedupe_btn.isEnabled()
    page.coverage_btn.click()
    wait_for(lambda: page._coverage_report is not None)
    assert calls == [(profile["backup_dir"], ("MANIFEST", kit), "Photo Walk")]
    text = page.results.toPlainText()
    assert "NEVER BACKED UP (1)" in text and "OVER-BACKED (2)" in text and "(2 KB)" in text
    assert page.dedupe_btn.isEnabled() and page.dedupe_btn.objectName() == "Danger"
    assert page._report is None  # coverage is not what Save writes


def test_dedupe_does_nothing_without_confirmation(monkeypatch, site):
    profile, _ = site
    page = page_mod.AuditPage(FakeWindow(profile))
    page._coverage_report = coverage_report(profile["backup_dir"]); page._update_buttons()
    monkeypatch.setattr(page_mod, "DedupeEngine", lambda *a, **k: pytest.fail("must not rewrite ZIPs"))
    asked = []
    monkeypatch.setattr(QMessageBox, "exec", lambda self: asked.append(self.text()) or 0)  # closed = no button
    page.dedupe_btn.click()
    assert asked and "2 file(s)" in asked[0] and "remove 3 extra copies" in asked[0]
    assert "rewriting 2 older ZIP(s)" in asked[0] and "a.zip" in asked[0] and "c.zip" not in asked[0]
    assert page._running == "" and page._coverage_report is not None


def test_dedupe_refused_while_backup_running(monkeypatch, site, quiet_boxes):
    profile, _ = site
    page = page_mod.AuditPage(FakeWindow(profile, busy=True))
    page._coverage_report = coverage_report(profile["backup_dir"]); page._update_buttons()
    monkeypatch.setattr(page, "_confirm_dedupe", lambda *a: pytest.fail("must not ask"))
    page.dedupe_btn.click()
    assert quiet_boxes and quiet_boxes[-1][0] == "information"


def test_dedupe_runs_after_yes_and_clears_coverage(monkeypatch, site):
    profile, _ = site
    page = page_mod.AuditPage(FakeWindow(profile))
    report = coverage_report(profile["backup_dir"])
    page._coverage_report = report; page._update_buttons()
    got = []

    class FakeDedupe:
        def __init__(self, report=None, on_progress=None, on_log=None):
            got.append(report)

        def run(self):
            res = DedupeResult(backup_dir=profile["backup_dir"], run_date="now", total_removed=3, total_saved=4096)
            res.zips_modified = [DedupeZipResult("a.zip", 5, 1, 4, 2048, True),
                                 DedupeZipResult("b.zip", 5, 2, 3, 2048, True)]
            return res

    monkeypatch.setattr(page_mod, "DedupeEngine", FakeDedupe)
    monkeypatch.setattr(page, "_confirm_dedupe", lambda files, copies, zips: (files, copies, zips) == (2, 3, ["a.zip", "b.zip"]))
    page.dedupe_btn.click()
    wait_for(lambda: "CLEANUP REPORT" in page.results.toPlainText())
    assert got == [report]
    assert page._coverage_report is None and not page.dedupe_btn.isEnabled()
    assert "Removed 3 duplicate entries across 2 ZIP(s)" in page.results.toPlainText()


@pytest.mark.parametrize("fmt", ["txt", "html"])
def test_save_writes_through_report_writer(monkeypatch, site, tmp_path, fmt):
    profile, _ = site
    page = page_mod.AuditPage(FakeWindow(profile))
    page._report = audit_report(); page._update_buttons()
    target = tmp_path / "out" / "report"
    target.parent.mkdir()
    monkeypatch.setattr(QFileDialog, "getSaveFileName", staticmethod(lambda *a, **k: (str(target), "")))
    (page.save_txt_btn if fmt == "txt" else page.save_html_btn).click()
    saved = tmp_path / "out" / f"report.{fmt}"
    body = saved.read_text(encoding="utf-8")
    assert ("AUDIT REPORT" in body) if fmt == "txt" else ("<!DOCTYPE html>" in body)
    assert "img_uploads/b.jpg" in body


def test_profile_change_drops_old_results_and_help_and_shutdown(site):
    profile, _ = site
    win = FakeWindow(profile)
    page = page_mod.AuditPage(win)
    page._report = audit_report(); page._coverage_report = coverage_report(profile["backup_dir"])
    win.profileChanged.emit({"name": "Other"})
    assert page._report is None and page._coverage_report is None and not page.dedupe_btn.isEnabled()
    titles = [t for t, _ in page.help_topics()]
    assert any("Audit" in t for t in titles) and any("clean-up" in t for t in titles)
    before = page._run_id
    page.shutdown()
    assert page._run_id != before

# ===== SNAPSMACK EOF =====
