"""SMACK UP YOUR BACKUP — Qt Audit page (audit, coverage check, duplicate clean-up).

Port of the Tk `AuditTab` in main.py. Three jobs, same engines:

  RUN AUDIT        AuditEngine    — compares the newest recovery kit's file list
                                    (manifest) with the live server over FTP/SFTP.
  COVERAGE CHECK   CoverageEngine — checks which manifest files are inside the
                                    backup ZIPs in this site's local folder.
  CLEAN UP DUPES   DedupeEngine   — rewrites local ZIPs so a file stored in
                                    several ZIPs is kept only in the newest one.
                                    Destructive: always confirmed with exact counts.

Save .txt / Save .html write the last AUDIT report through report_writer, as Tk did.

Differences from Tk, all deliberate:
  * The recovery kit is found by the site-URL filename token the backup engine
    actually writes (e.g. photowalk-ing_recovery_kit_*.tar.gz) as well as by the
    old profile-name match. Tk only tried the profile name, so it usually
    reported "No recovery kit found" for kits that were sitting right there.
  * Reading the kit happens on the worker thread (Tk did it on the UI thread).
  * Engine log lines (e.g. "Connection failed: ...") are shown under the
    results. Tk threw them away, so a failed connection looked like a clean
    audit with every count at zero.
  * Clean-up refuses to run while a backup or restore is running (it rewrites
    the same ZIP files a backup writes).

The engines have no cancel switch, so there is no Cancel button; shutdown()
only makes the page ignore results that arrive after the window closes.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
"""

import os

from PySide6.QtGui import QColor, QFont, QTextCharFormat, QTextCursor
from PySide6.QtWidgets import (
    QFileDialog, QHBoxLayout, QMessageBox, QProgressBar, QPushButton, QTextEdit,
    QWidget,
)

import manifest_reader
from audit_engine import (
    AuditEngine,
    HEALTHY, MISSING_FROM_SERVER, ORPHANED_ON_SERVER, ORPHANED_IN_DB,
    NOT_IN_DB, SIZE_MISMATCH, WRONG_LOCATION,
)
from coverage_engine import (
    CoverageEngine, DedupeEngine, COVERED, OVER_BACKED, NEVER_BACKED,
)
from report_writer import write_html, write_txt
from suyb_qt_common import (
    AMBER, DIM, GREEN, INK, RED, Relay, card, label, make_page, run_in_thread,
    set_status,
)


# ---------------------------------------------------------------------------
# Finding the recovery kit (the manifest lives inside it)
# ---------------------------------------------------------------------------

def _url_token(profile):
    """The filename token BackupEngine uses for this site's files."""
    try:
        from backup_engine import filename_token
    except Exception:
        return ""
    return filename_token(profile.get("site_url", ""), profile.get("name", ""))


def find_latest_kit(profile):
    """Newest *.tar.gz recovery kit for this site in its backup folder, or None."""
    backup_dir = str(profile.get("backup_dir", "") or "")
    if not backup_dir or not os.path.isdir(backup_dir):
        return None
    tokens = {t for t in (str(profile.get("name", "")).replace(" ", "_"), _url_token(profile)) if t}
    if not tokens:
        tokens = {""}
    kits = [os.path.join(backup_dir, f) for f in os.listdir(backup_dir)
            if f.endswith(".tar.gz") and any(t in f for t in tokens)]
    if not kits:
        return None
    # Newest by the timestamp in the name (as Tk did), then by modified time.
    kits.sort(key=lambda p: (os.path.basename(p), os.path.getmtime(p)), reverse=True)
    return kits[0]


# ---------------------------------------------------------------------------
# Report text (pure functions: list of (text, tag) lines; tag = ok/err/warn/dim/heading)
# ---------------------------------------------------------------------------

def audit_lines(report):
    out = []
    line = lambda text, tag="": out.append((text, tag))
    line(f"AUDIT REPORT  —  {report.site_name}", "heading")
    line(f"{report.site_url}  ·  {report.audit_date}", "dim")
    line("")
    for cat, name, tag in [
        (HEALTHY,             "Healthy",              "ok"),
        (MISSING_FROM_SERVER, "Missing from server",  "err"),
        (WRONG_LOCATION,      "Wrong location",       "warn"),
        (SIZE_MISMATCH,       "Size mismatch",        "warn"),
        (NOT_IN_DB,           "Not in database",      "warn"),
        (ORPHANED_IN_DB,      "Orphaned in database", "dim"),
        (ORPHANED_ON_SERVER,  "Orphaned on server",   "dim"),
    ]:
        n = report.summary.get(cat, 0)
        line(f"  {name:<30} {n:>5}", tag if n > 0 else "dim")
    line("")
    for cat, name, tag in [
        (MISSING_FROM_SERVER, "MISSING FROM SERVER", "err"),
        (WRONG_LOCATION,      "WRONG LOCATION",      "warn"),
        (SIZE_MISMATCH,       "SIZE MISMATCH",       "warn"),
        (NOT_IN_DB,           "NOT IN DATABASE",     "warn"),
        (ORPHANED_IN_DB,      "ORPHANED IN DB",      "dim"),
    ]:
        entries = report.by_category(cat)
        if not entries:
            continue
        line(f"\n── {name} ({len(entries)}) ──", "heading")
        for e in entries:
            detail = f"  {e.restores_to}"
            if e.note:
                detail += f"  →  {e.note}"
            line(detail, tag)
    if report.orphan_server:
        line(f"\n── ORPHANED ON SERVER ({len(report.orphan_server)}) ──", "heading")
        for p in report.orphan_server[:100]:
            line(f"  {p}", "dim")
        if len(report.orphan_server) > 100:
            line(f"  … and {len(report.orphan_server) - 100} more.", "dim")
    return out


def coverage_lines(report):
    out = []
    line = lambda text, tag="": out.append((text, tag))
    covered = report.count(COVERED)
    over = report.count(OVER_BACKED)
    never = report.count(NEVER_BACKED)
    line(f"COVERAGE REPORT  —  {report.site_name}", "heading")
    line(f"{report.backup_dir}  ·  {report.scan_date}", "dim")
    line(f"ZIPs scanned: {len(report.zips_scanned)}", "dim")
    line("")
    line(f"  {'Covered (in exactly 1 ZIP)':<35} {covered:>5}", "ok" if covered else "dim")
    line(f"  {'Over-backed (in 2+ ZIPs)':<35} {over:>5}", "warn" if over else "dim")
    line(f"  {'Never backed up':<35} {never:>5}", "err" if never else "dim")
    line(f"  {'Total manifest media files':<35} {covered + over + never:>5}", "dim")
    nb = report.by_status(NEVER_BACKED)
    if nb:
        line(f"\n── NEVER BACKED UP ({len(nb)}) ──", "heading")
        line("  These files are in the manifest but not found in any backup ZIP.", "dim")
        line("  They may have been missed by the backup or deleted from the server", "dim")
        line("  after the manifest was generated.", "dim")
        line("")
        for e in nb:
            kb = f"{e.manifest_size // 1024:,} KB" if e.manifest_size else "unknown size"
            line(f"  {e.restores_to}  ({kb})", "err")
    ob = report.by_status(OVER_BACKED)
    if ob:
        line(f"\n── OVER-BACKED ({len(ob)}) ──", "heading")
        line("  These files appear in more than one ZIP (wasting space).", "dim")
        line("")
        for e in ob:
            line(f"  {e.restores_to}", "warn")
            for zname in e.zip_names:
                line(f"      in: {zname}", "dim")
    if not nb and not ob:
        line("\n  ✓  All manifest files are covered exactly once.", "ok")
    return out


def dedupe_lines(result):
    out = []
    line = lambda text, tag="": out.append((text, tag))
    line("CLEANUP REPORT", "heading")
    line(f"{result.backup_dir}  ·  {result.run_date}", "dim")
    line("")
    if not result.zips_modified:
        line("  Nothing was changed.", "dim")
        return out
    line(f"  Removed {result.total_removed} duplicate entries "
         f"across {len(result.zips_modified)} ZIP(s).", "ok")
    line(f"  Approximate space recovered: {result.total_saved // 1024:,} KB", "ok")
    line("")
    for zr in result.zips_modified:
        if zr.ok:
            line(f"  ✓  {zr.zip_name}", "ok")
            line(f"       {zr.entries_before} entries → {zr.entries_after}  "
                 f"(removed {zr.entries_removed},  saved {zr.bytes_saved // 1024:,} KB)", "dim")
        else:
            line(f"  ✗  {zr.zip_name}", "err")
            line(f"       {zr.error}", "err")
    if result.errors:
        line(f"\n── ERRORS ({len(result.errors)}) ──", "heading")
        for err in result.errors:
            line(f"  {err}", "err")
    line("")
    line("  Run Coverage Check again to confirm all files are now covered exactly once.", "dim")
    return out


def dedupe_plan(report):
    """Exact counts for the confirmation box: (files, copies_to_remove, sorted zip names)."""
    entries = report.by_status(OVER_BACKED)
    zips, copies = set(), 0
    for e in entries:
        older = sorted(e.zip_names)[:-1]      # every ZIP but the newest keeps losing it
        copies += len(older)
        zips.update(older)
    return len(entries), copies, sorted(zips)


_COLOURS = {"ok": GREEN, "err": RED, "warn": AMBER, "dim": DIM, "heading": GREEN, "": INK}


HELP = [
    ("Audit page", """
The Audit page has three buttons. The first two only look; the third changes files.

RUN AUDIT — checks the live server against your newest backup.
COVERAGE CHECK — checks the backup ZIPs on this computer.
CLEAN UP DUPES — removes extra copies of files from those ZIPs (asks first).

Both checks need a recovery kit (the .tar.gz file every backup writes into the
site's backup folder). If there isn't one yet, run a backup first.
"""),
    ("Audit — what the results mean", """
Audit performs a three-way comparison between:
— The manifest (what the blog database says should exist)
— The server filesystem (what FTP can actually see)
— The database image records (what the CMS knows about)

Results are categorised:
✓ Healthy — file exists, size matches, database record present
✗ Missing from server — in the manifest but not on FTP
✗ Orphaned on server — on FTP but not in the manifest
✗ Size mismatch — file exists but wrong size
✗ Wrong location — file found by name but in a different path
✗ Not in database — on server but no database record

Audit needs this site's FTP or SFTP details. If the connection fails, the reason
is shown under "Notes from the check" at the bottom of the results.

Save the report as HTML or plain text for reference (Save .txt / Save .html).
Saving covers the audit report only, not the coverage check.
"""),
    ("Coverage check and clean-up", """
Coverage check opens every backup ZIP in this site's backup folder and sorts each
file from the newest recovery kit into one of three groups:
✓ Covered — in exactly one ZIP (good)
! Over-backed — in two or more ZIPs (wasting disk space)
✗ Never backed up — in no ZIP at all

CLEAN UP DUPES only lights up after a coverage check finds over-backed files.
It keeps each file in its NEWEST ZIP and removes the extra copies from the older
ZIPs. Before anything happens you see exactly how many files, how many copies
and which ZIPs. The ZIPs are rewritten in place — this cannot be undone.
It will not run while a backup or restore is running. Afterwards, run Coverage
Check again to confirm every file is covered exactly once.
"""),
]


class AuditPage(QWidget):
    def __init__(self, window):
        super().__init__()
        self._win = window
        self.relay = Relay(self)
        self._report = None            # last AuditReport (what Save writes)
        self._coverage_report = None   # last CoverageReport (what clean-up uses)
        self._running = ""             # "", "audit", "coverage", "dedupe"
        self._run_id = 0               # results from an older/abandoned run are ignored
        self._closing = False
        self._log = []
        self._build()
        sig = getattr(window, "profileChanged", None)
        if sig is not None:
            sig.connect(self._profile_changed)

    # ------------------------------------------------------------------ build
    def _build(self):
        layout = make_page(self, "Audit",
                           "Check the live server and your local backup ZIPs against your newest backup's file list.")

        box, col = card("Checks", "Audit compares your newest backup's file list with the live server. "
                                  "Coverage checks which files are inside your backup ZIPs on this computer.")
        row = QHBoxLayout()
        self.audit_btn = QPushButton("RUN AUDIT"); self.audit_btn.setObjectName("Primary")
        self.audit_btn.setToolTip("Connects to the server over FTP/SFTP and looks. Changes nothing.")
        self.audit_btn.clicked.connect(self._run_audit)
        self.coverage_btn = QPushButton("COVERAGE CHECK")
        self.coverage_btn.setToolTip("Opens the backup ZIPs on this computer and looks. Changes nothing.")
        self.coverage_btn.clicked.connect(self._run_coverage)
        self.dedupe_btn = QPushButton("CLEAN UP DUPES…"); self.dedupe_btn.setObjectName("Danger")
        self.dedupe_btn.setToolTip("Run a Coverage Check first. Removes extra copies from older ZIPs — asks before doing anything.")
        self.dedupe_btn.setEnabled(False)
        self.dedupe_btn.clicked.connect(self._run_dedupe)
        for b in (self.audit_btn, self.coverage_btn, self.dedupe_btn):
            b.setMinimumHeight(44); row.addWidget(b)
        row.addStretch(1)
        col.addLayout(row)
        self.progress = QProgressBar(); self.progress.setRange(0, 100); self.progress.setValue(0)
        col.addWidget(self.progress)
        self.status = label("Pick a check above.", "Muted")
        col.addWidget(self.status)
        layout.addWidget(box)

        res, rcol = card("Results")
        self.results = QTextEdit(); self.results.setReadOnly(True)
        self.results.setLineWrapMode(QTextEdit.NoWrap); self.results.setMinimumHeight(320)
        self.results.setPlaceholderText("Results appear here after a check.")
        rcol.addWidget(self.results, 1)
        saves = QHBoxLayout(); saves.addStretch(1)
        self.save_txt_btn = QPushButton("Save .txt"); self.save_txt_btn.clicked.connect(lambda: self._save("txt"))
        self.save_html_btn = QPushButton("Save .html"); self.save_html_btn.clicked.connect(lambda: self._save("html"))
        for b in (self.save_txt_btn, self.save_html_btn):
            b.setMinimumHeight(40); b.setEnabled(False)
            b.setToolTip("Saves the last audit report. Run an audit first.")
            saves.addWidget(b)
        rcol.addLayout(saves)
        layout.addWidget(res, 1)

    # --------------------------------------------------------------- contract
    def help_topics(self):
        return [(t, body.strip()) for t, body in HELP]

    def shutdown(self):
        # The engines cannot be interrupted; their threads are daemons. Stop
        # listening so nothing touches widgets after the window is gone.
        self._closing = True
        self._run_id += 1

    def refresh(self):
        self._update_buttons()

    # ---------------------------------------------------------------- helpers
    def _profile(self):
        profile = getattr(self._win, "current_profile", None)
        if not profile:
            QMessageBox.information(self, "No site chosen", "Choose a site at the top of the window first.")
            return None
        return profile

    def _update_buttons(self):
        idle = not self._running
        self.audit_btn.setEnabled(idle)
        self.coverage_btn.setEnabled(idle)
        over = self._coverage_report.count(OVER_BACKED) if self._coverage_report else 0
        self.dedupe_btn.setEnabled(idle and over > 0)
        self.dedupe_btn.setText(f"CLEAN UP {over} DUPES…" if over else "CLEAN UP DUPES…")
        self.save_txt_btn.setEnabled(idle and self._report is not None)
        self.save_html_btn.setEnabled(idle and self._report is not None)

    def _show(self, lines):
        self.results.clear()
        cursor = self.results.textCursor()
        cursor.movePosition(QTextCursor.End)
        for text, tag in lines:
            fmt = QTextCharFormat()
            fmt.setForeground(QColor(_COLOURS.get(tag, INK)))
            fmt.setFontFamilies(["Consolas", "Courier New"]); fmt.setFontFixedPitch(True)
            if tag == "heading":
                fmt.setFontWeight(QFont.Bold)
            cursor.insertText(text + "\n", fmt)
        self.results.moveCursor(QTextCursor.Start)

    def _with_log(self, lines):
        if not self._log:
            return lines
        return lines + [("\n── NOTES FROM THE CHECK ──", "heading")] + [(f"  {m}", "dim") for m in self._log]

    def _start(self, kind, busy_text):
        self._running = kind
        self._run_id += 1
        self._log = []
        self.progress.setValue(0)
        self.results.clear()
        set_status(self.status, busy_text, "warn")
        self._update_buttons()
        return self._run_id

    def _callbacks(self, run_id):
        def on_progress(_stage, message, pct):
            def apply(m=str(message), p=float(pct or 0)):
                if run_id != self._run_id:
                    return
                self.progress.setValue(max(0, min(100, int(p * 100))))
                set_status(self.status, m, "good" if p >= 1.0 else "warn")
            self.relay.call.emit(apply)

        def on_log(message):
            def apply(m=str(message)):
                if run_id == self._run_id:
                    self._log.append(m)
            self.relay.call.emit(apply)
        return on_progress, on_log

    def _finish_error(self, run_id, what, exc):
        if run_id != self._run_id:
            return
        self._running = ""
        self._update_buttons()
        set_status(self.status, f"{what} stopped: {exc}", "bad")
        self._show(self._with_log([(f"{what} stopped before it finished.", "heading"), (f"  {exc}", "err")]))

    # ------------------------------------------------------------------ audit
    def _run_audit(self):
        if self._running:
            return
        profile = self._profile()
        if not profile:
            return
        if not str(profile.get("ftp_host", "") or "").strip():
            QMessageBox.warning(self, "No server connection details",
                                "Audit looks at the live server over FTP or SFTP, and this site has no FTP/SFTP host saved.\n\n"
                                "Add the server details for this site first.")
            return
        kit = find_latest_kit(profile)
        if not kit:
            QMessageBox.critical(self, "No recovery kit",
                                 "No recovery kit (.tar.gz) for this site was found in its backup folder.\n\n"
                                 "Run a backup first — every backup writes one.")
            return
        run_id = self._start("audit", "Reading the recovery kit…")
        on_progress, on_log = self._callbacks(run_id)
        profile = dict(profile)

        def work():
            manifest = manifest_reader.from_tar(kit)
            return AuditEngine(profile, manifest, on_progress=on_progress, on_log=on_log).run()

        run_in_thread(self.relay, work,
                      on_done=lambda r: self._audit_done(run_id, r),
                      on_error=lambda e: self._finish_error(run_id, "Audit", e))

    def _audit_done(self, run_id, report):
        if run_id != self._run_id:
            return
        self._running = ""
        self._report = report
        self.progress.setValue(100)
        empty = not report.entries and not report.orphan_server
        if empty and self._log:
            set_status(self.status, "Audit could not finish — see the notes at the bottom of the results.", "bad")
        else:
            set_status(self.status, "Audit complete.", "good")
        self._show(self._with_log(audit_lines(report)))
        self._update_buttons()

    # --------------------------------------------------------------- coverage
    def _run_coverage(self):
        if self._running:
            return
        profile = self._profile()
        if not profile:
            return
        backup_dir = str(profile.get("backup_dir", "") or "")
        if not backup_dir or not os.path.isdir(backup_dir):
            QMessageBox.critical(self, "No backup folder",
                                 "This site has no backup folder on this computer yet.\n\n"
                                 "Set the working folder for this site first.")
            return
        kit = find_latest_kit(profile)
        if not kit:
            QMessageBox.critical(self, "No recovery kit",
                                 "No recovery kit (.tar.gz) for this site was found in its backup folder.\n\n"
                                 "Run a backup first to create one.")
            return
        run_id = self._start("coverage", "Reading the recovery kit…")
        on_progress, on_log = self._callbacks(run_id)
        name = profile.get("name", "")

        def work():
            manifest = manifest_reader.from_tar(kit)
            return CoverageEngine(backup_dir=backup_dir, manifest=manifest, blog_name=name,
                                  on_progress=on_progress, on_log=on_log).run()

        run_in_thread(self.relay, work,
                      on_done=lambda r: self._coverage_done(run_id, r),
                      on_error=lambda e: self._finish_error(run_id, "Coverage check", e))

    def _coverage_done(self, run_id, report):
        if run_id != self._run_id:
            return
        self._running = ""
        self._coverage_report = report
        self.progress.setValue(100)
        if not report.zips_scanned:
            set_status(self.status, "No backup ZIPs found in the backup folder.", "warn")
        else:
            set_status(self.status, "Coverage check complete.", "good")
        # Coverage's own log repeats the summary; only show it when something went wrong.
        if report.zips_scanned and not any("Could not" in m for m in self._log):
            self._log = []
        self._show(self._with_log(coverage_lines(report)))
        self._update_buttons()

    # ----------------------------------------------------------------- dedupe
    def _confirm_dedupe(self, files, copies, zips):
        """Ask before rewriting ZIPs. Returns True only on the explicit yes button."""
        shown = "\n".join(f"  • {z}" for z in zips[:10])
        if len(zips) > 10:
            shown += f"\n  … and {len(zips) - 10} more"
        box = QMessageBox(self)
        box.setIcon(QMessageBox.Warning)
        box.setWindowTitle("Clean up duplicate backup copies?")
        box.setText(f"{files} file(s) are stored in more than one ZIP.\n\n"
                    f"This will remove {copies} extra cop{'y' if copies == 1 else 'ies'} by rewriting "
                    f"{len(zips)} older ZIP(s). Each file is kept in its newest ZIP.\n\n"
                    f"ZIPs that will be rewritten:\n{shown}\n\n"
                    "The ZIPs are rewritten in place — this cannot be undone.")
        keep = box.addButton("Keep everything", QMessageBox.RejectRole)
        go = box.addButton(f"Remove {copies} extra cop{'y' if copies == 1 else 'ies'}", QMessageBox.DestructiveRole)
        go.setObjectName("Danger")
        box.setDefaultButton(keep); box.setEscapeButton(keep)
        box.exec()
        return box.clickedButton() is go

    def _run_dedupe(self):
        if self._running:
            return
        report = self._coverage_report
        if not report or report.count(OVER_BACKED) == 0:
            QMessageBox.information(self, "Nothing to clean up", "Run a coverage check first.")
            return
        busy = getattr(self._win, "busy", None)
        if callable(busy) and busy():
            QMessageBox.information(self, "A backup is running",
                                    "Clean-up rewrites backup ZIPs, so it waits until the backup or restore finishes.")
            return
        files, copies, zips = dedupe_plan(report)
        if not self._confirm_dedupe(files, copies, zips):
            set_status(self.status, "Clean-up cancelled — nothing was changed.", "good")
            return
        run_id = self._start("dedupe", "Rewriting ZIPs…")
        on_progress, on_log = self._callbacks(run_id)

        def work():
            return DedupeEngine(report=report, on_progress=on_progress, on_log=on_log).run()

        run_in_thread(self.relay, work,
                      on_done=lambda r: self._dedupe_done(run_id, r),
                      on_error=lambda e: self._dedupe_error(run_id, e))

    def _dedupe_done(self, run_id, result):
        if run_id != self._run_id:
            return
        self._running = ""
        self._coverage_report = None   # stale now: force a fresh scan before another clean-up
        self.progress.setValue(100)
        if result.errors:
            set_status(self.status, f"Clean-up finished with {len(result.errors)} error(s) — run Coverage Check again.", "bad")
        else:
            set_status(self.status, "Cleanup complete — run Coverage Check again to verify.", "good")
        self._show(dedupe_lines(result))
        self._update_buttons()

    def _dedupe_error(self, run_id, exc):
        if run_id == self._run_id:
            self._coverage_report = None
        self._finish_error(run_id, "Clean-up", exc)

    # ------------------------------------------------------------------- save
    def _save(self, fmt):
        if not self._report:
            QMessageBox.information(self, "No report", "Run an audit first.")
            return
        if fmt == "html":
            flt, writer = "HTML report (*.html)", write_html
        else:
            flt, writer = "Text report (*.txt)", write_txt
        path, _ = QFileDialog.getSaveFileName(self, "Save audit report", f"audit-report.{fmt}", flt)
        if not path:
            return
        if not path.lower().endswith(f".{fmt}"):
            path += f".{fmt}"
        try:
            writer(self._report, path)
        except Exception as exc:
            QMessageBox.critical(self, "Save failed", str(exc))
            return
        QMessageBox.information(self, "Saved", f"Report saved to:\n{path}")

    # ----------------------------------------------------------- site switch
    def _profile_changed(self, _profile):
        if self._running:
            return
        # Results belong to the previous site; clean-up must never act on them.
        self._report = None
        self._coverage_report = None
        self.results.clear()
        self.progress.setValue(0)
        set_status(self.status, "Pick a check above.", "good")
        self._update_buttons()

# ===== SNAPSMACK EOF =====
