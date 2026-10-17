"""LOG FLOGGER — the window.

PySide6, dark, laid out like SNAP SLAPPER: one obvious primary action, compact
row actions, no bolted-on boxes. Five tabs for Sean's five functions, in order,
because the order is the safety rule — summarize before clean, always.

Everything here calls the same engine the CLI calls. Nothing is implemented in
the window that the command line cannot do, so Claude and Codex can work the
logs unattended and Sean sees the same answers.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import os
import sys
from datetime import datetime

from PySide6.QtCore import Qt
from PySide6.QtGui import QFont
from PySide6.QtWidgets import (
    QApplication, QCheckBox, QComboBox, QFileDialog, QHBoxLayout, QLabel,
    QMainWindow, QMessageBox, QPlainTextEdit, QPushButton, QSpinBox,
    QTabWidget, QTableWidget, QTableWidgetItem, QVBoxLayout, QWidget,
)

from logflogger import analyze, gather, probes, report as reportmod, summarize

STYLE = """
QWidget { background:#141414; color:#e6e6e6; font-size:13px; }
QTabWidget::pane { border:1px solid #2a2a2a; }
QTabBar::tab { background:#1b1b1b; padding:8px 18px; border:1px solid #2a2a2a;
               border-bottom:none; color:#b9b9b9; }
QTabBar::tab:selected { background:#141414; color:#6ff0a0; }
QPushButton { background:#1f1f1f; border:1px solid #333; padding:7px 14px; }
QPushButton:hover { border-color:#6ff0a0; }
QPushButton#primary { background:#1d3a28; border:1px solid #6ff0a0; color:#6ff0a0;
                      font-weight:600; padding:9px 20px; }
QPushButton#danger { border-color:#a33; color:#ff8a8a; }
QPlainTextEdit, QTableWidget { background:#101010; border:1px solid #2a2a2a;
                               font-family:Consolas,monospace; }
QHeaderView::section { background:#1b1b1b; border:none; padding:6px; color:#9a9a9a; }
QLabel#hint { color:#8a8a8a; }
QLabel#band { font-weight:600; }
"""

_BAND_COLOUR = {
    analyze.LOSING_WORK: "#ff6b6b",
    analyze.DOOR_OPEN: "#ffb347",
    analyze.STOPPED: "#ffe066",
    analyze.WORKING_ROUND_IT: "#9a9a9a",
    analyze.WANTED: "#6a6a6a",
}


class Flogger(QMainWindow):
    def __init__(self):
        super().__init__()
        self.setWindowTitle("LOG FLOGGER")
        self.resize(1100, 720)
        self.harvest = None
        self.findings = []
        self.summary = None
        self.summary_path = ""

        tabs = QTabWidget()
        tabs.addTab(self._gather_tab(), "1 · Gather")
        tabs.addTab(self._analyze_tab(), "2 · Analyze")
        tabs.addTab(self._probe_tab(), "3 · Probes")
        tabs.addTab(self._summary_tab(), "4 · Summarize")
        tabs.addTab(self._clean_tab(), "5 · Clean up")
        tabs.addTab(self._report_tab(), "Report to Sean")
        self.setCentralWidget(tabs)
        self.setStyleSheet(STYLE)

    # ── 1 · gather ───────────────────────────────────────────────────────────
    def _gather_tab(self):
        w = QWidget(); v = QVBoxLayout(w)
        row = QHBoxLayout()
        self.days = QSpinBox(); self.days.setRange(0, 3650); self.days.setValue(0)
        self.days.setSpecialValueText("all time")
        row.addWidget(QLabel("Look back:")); row.addWidget(self.days)
        row.addStretch()
        btn = QPushButton("GATHER LOGS"); btn.setObjectName("primary")
        btn.clicked.connect(self.do_gather)
        row.addWidget(btn)
        v.addLayout(row)

        self.gather_table = QTableWidget(0, 5)
        self.gather_table.setHorizontalHeaderLabels(
            ["App", "Logs", "Size", "Kinds", "Newest"])
        self.gather_table.horizontalHeader().setStretchLastSection(True)
        v.addWidget(self.gather_table)
        self.gather_hint = QLabel("Reads C:\\snapsmack\\logs — every tool's run logs "
                                  "in one place."); self.gather_hint.setObjectName("hint")
        v.addWidget(self.gather_hint)
        return w

    def do_gather(self):
        since = None
        if self.days.value():
            from datetime import timedelta
            since = datetime.now() - timedelta(days=self.days.value())
        self.harvest = gather.gather(since=since)
        t = self.gather_table
        t.setRowCount(0)
        for app in sorted(self.harvest.apps):
            files = self.harvest.apps[app]
            newest = max((f.when for f in files if f.when), default=None)
            r = t.rowCount(); t.insertRow(r)
            for c, val in enumerate([
                app, str(len(files)),
                analyze._human_bytes(sum(f.size for f in files)),
                ",".join(sorted({f.kind for f in files})),
                f"{newest:%Y-%m-%d}" if newest else "—",
            ]):
                t.setItem(r, c, QTableWidgetItem(val))
        self.gather_hint.setText(
            f"{len(self.harvest.files)} logs, "
            f"{analyze._human_bytes(self.harvest.total_bytes)}, "
            f"{len(self.harvest.apps)} apps.")

    # ── 2 · analyze ──────────────────────────────────────────────────────────
    def _analyze_tab(self):
        w = QWidget(); v = QVBoxLayout(w)
        row = QHBoxLayout()
        self.quiet = QSpinBox(); self.quiet.setRange(1, 365); self.quiet.setValue(14)
        row.addWidget(QLabel("Call an app silent after (days):")); row.addWidget(self.quiet)
        row.addStretch()
        btn = QPushButton("ANALYZE"); btn.setObjectName("primary")
        btn.clicked.connect(self.do_analyze); row.addWidget(btn)
        v.addLayout(row)

        v.addWidget(QLabel("What stopped happening is listed first — it is the half "
                           "that has cost the most and that nothing else looks for."))
        self.findings_table = QTableWidget(0, 4)
        self.findings_table.setHorizontalHeaderLabels(["Band", "App", "What", "Count"])
        self.findings_table.horizontalHeader().setStretchLastSection(True)
        self.findings_table.itemSelectionChanged.connect(self._show_detail)
        v.addWidget(self.findings_table, 3)
        self.detail = QPlainTextEdit(); self.detail.setReadOnly(True)
        v.addWidget(self.detail, 1)
        return w

    def do_analyze(self):
        if not self.harvest:
            self.do_gather()
        self.findings = analyze.analyse(self.harvest, quiet_days=self.quiet.value())
        t = self.findings_table; t.setRowCount(0)
        for f in self.findings:
            r = t.rowCount(); t.insertRow(r)
            band = QTableWidgetItem(f.band)
            band.setForeground(Qt.GlobalColor.white)
            font = QFont(); font.setBold(True); band.setFont(font)
            t.setItem(r, 0, band)
            t.setItem(r, 1, QTableWidgetItem(f.app))
            t.setItem(r, 2, QTableWidgetItem(f.headline[:160]))
            t.setItem(r, 3, QTableWidgetItem(str(f.count)))

    def _show_detail(self):
        rows = {i.row() for i in self.findings_table.selectedItems()}
        if not rows:
            return
        f = self.findings[sorted(rows)[0]]
        body = [f"[{f.band}] {f.app}", "", f.headline]
        if f.detail:
            body += ["", f.detail]
        if f.evidence:
            body += ["", "Evidence (never leaves this machine in an anonymous report):"]
            body += ["  " + e for e in f.evidence]
        self.detail.setPlainText("\n".join(body))

    # ── 3 · probes ───────────────────────────────────────────────────────────
    def _probe_tab(self):
        w = QWidget(); v = QVBoxLayout(w)
        v.addWidget(QLabel(
            "Is anyone sharpening for SnapSmack? Generic scanner noise means nothing.\n"
            "A request for smack-api-keys.php means somebody knows what this software is."))
        row = QHBoxLayout()
        btn = QPushButton("CHOOSE ACCESS LOGS…"); btn.clicked.connect(self.do_probe)
        btn.setObjectName("primary")
        row.addWidget(btn); row.addStretch()
        v.addLayout(row)
        self.probe_out = QPlainTextEdit(); self.probe_out.setReadOnly(True)
        v.addWidget(self.probe_out)
        return w

    def do_probe(self):
        paths, _ = QFileDialog.getOpenFileNames(self, "Web server access logs")
        if not paths:
            return
        rep = probes.scan_paths(paths)
        self.probe_report = rep
        lines = [rep.verdict, ""]
        if rep.paths:
            lines.append("Paths probed:")
            for p, n in rep.paths.most_common(40):
                flag = "   <-- admin / keys / break-glass" if probes.SENSITIVE.search(p) else ""
                lines.append(f"  {n:>6}  {p}{flag}")
        self.probe_out.setPlainText("\n".join(lines))

    # ── 4 · summarize ────────────────────────────────────────────────────────
    def _summary_tab(self):
        w = QWidget(); v = QVBoxLayout(w)
        v.addWidget(QLabel(
            "The summary is what REPLACES the logs. Written and checked before "
            "anything is deleted — delete first and the knowledge dies with the data."))
        row = QHBoxLayout()
        btn = QPushButton("WRITE SUMMARY"); btn.setObjectName("primary")
        btn.clicked.connect(self.do_summary); row.addWidget(btn); row.addStretch()
        v.addLayout(row)
        self.summary_out = QPlainTextEdit(); self.summary_out.setReadOnly(True)
        v.addWidget(self.summary_out)
        return w

    def do_summary(self):
        if not self.findings:
            self.do_analyze()
        probe = {}
        rep = getattr(self, "probe_report", None)
        if rep:
            probe = {"sharpening": rep.sharpening, "snapsmack_hits": rep.snapsmack_hits,
                     "sensitive_hits": rep.sensitive_hits,
                     "distinct_sources": rep.distinct_sources,
                     "verdict": rep.verdict}
        self.summary = summarize.summarise(self.harvest, self.findings, probe=probe)
        self.summary_path = summarize.write_summary(self.summary)
        self.summary_out.setPlainText(
            f"Written: {self.summary_path}\n\n"
            f"Covers {len(self.summary.files)} logs across {len(self.summary.apps)} apps\n"
            f"{len(self.summary.findings)} findings kept\n\n"
            "Cleanup is now allowed for the logs this covers, and only those.")

    # ── 5 · clean ────────────────────────────────────────────────────────────
    def _clean_tab(self):
        w = QWidget(); v = QVBoxLayout(w)
        v.addWidget(QLabel(
            "Only logs the summary already covers. Anything newer, pinned, or "
            "unsummarised is kept. Dry run first, always."))
        row = QHBoxLayout()
        self.keep = QSpinBox(); self.keep.setRange(0, 3650); self.keep.setValue(30)
        row.addWidget(QLabel("Keep anything newer than (days):")); row.addWidget(self.keep)
        row.addStretch()
        dry = QPushButton("DRY RUN"); dry.setObjectName("primary")
        dry.clicked.connect(lambda: self.do_clean(False)); row.addWidget(dry)
        go = QPushButton("DELETE FOR REAL"); go.setObjectName("danger")
        go.clicked.connect(lambda: self.do_clean(True)); row.addWidget(go)
        v.addLayout(row)
        self.clean_out = QPlainTextEdit(); self.clean_out.setReadOnly(True)
        v.addWidget(self.clean_out)
        return w

    def do_clean(self, apply: bool):
        if not self.summary or not self.summary_path:
            QMessageBox.warning(self, "No summary",
                                "Write a summary first. Nothing is deleted without one — "
                                "the summary is what replaces the log.")
            return
        plan = summarize.plan_clean(self.harvest, self.summary, keep_days=self.keep.value())
        lines = [f"{len(plan.remove)} logs, "
                 f"{analyze._human_bytes(plan.bytes_freed)} would be freed",
                 f"{len(plan.kept)} kept", ""]
        reasons = {}
        for why in plan.kept.values():
            reasons[why] = reasons.get(why, 0) + 1
        for why, n in sorted(reasons.items(), key=lambda kv: -kv[1]):
            lines.append(f"  keep {n:>4}  {why}")

        if not apply:
            lines += ["", "DRY RUN — nothing deleted."]
            self.clean_out.setPlainText("\n".join(lines)); return

        if QMessageBox.question(
            self, "Delete logs",
            f"Delete {len(plan.remove)} logs, freeing "
            f"{analyze._human_bytes(plan.bytes_freed)}?\n\n"
            f"The summary at\n{self.summary_path}\nkeeps what they said."
        ) != QMessageBox.StandardButton.Yes:
            return
        result = summarize.apply_clean(plan, self.summary_path)
        lines += ["", f"Removed {len(result['removed'])}, freed "
                      f"{analyze._human_bytes(result['bytes_freed'])}."]
        self.clean_out.setPlainText("\n".join(lines))

    # ── report ───────────────────────────────────────────────────────────────
    def _report_tab(self):
        w = QWidget(); v = QVBoxLayout(w)
        v.addWidget(QLabel(
            "A report for Sean. ANONYMOUS BY DEFAULT — nothing identifies you, "
            "your site or your visitors. You see exactly what travels before it goes."))
        row = QHBoxLayout()
        self.identify = QCheckBox("Sign it with my site and contact (not anonymous)")
        row.addWidget(self.identify); row.addStretch()
        btn = QPushButton("BUILD REPORT"); btn.setObjectName("primary")
        btn.clicked.connect(self.do_report); row.addWidget(btn)
        v.addLayout(row)
        self.note = QPlainTextEdit(); self.note.setPlaceholderText(
            "Anything you want to say (optional)")
        self.note.setMaximumHeight(80)
        v.addWidget(self.note)
        self.report_out = QPlainTextEdit(); self.report_out.setReadOnly(True)
        v.addWidget(self.report_out)
        return w

    def do_report(self):
        if not self.summary:
            QMessageBox.warning(self, "No summary", "Write a summary first.")
            return
        rep = reportmod.build(self.summary, note=self.note.toPlainText(),
                              anonymous=not self.identify.isChecked())
        body = reportmod.preview(rep)
        problems = reportmod.leaks(rep)
        if problems:
            body += ("\n\nWITHHELD — this still contains:\n  "
                     + "\n  ".join(problems) + "\n\nNot sending.")
        self.report_out.setPlainText(body)


def run():
    app = QApplication(sys.argv)
    win = Flogger()
    win.show()
    sys.exit(app.exec())

# ===== SNAPSMACK EOF =====
