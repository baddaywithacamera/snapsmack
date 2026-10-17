"""TICKET WICKET — the window.

PySide6, dark, one obvious primary action per pane. Sean works this; Claude and
ChatGPT work the same engine from tw.py. Nothing is implemented here that the
command line cannot do — otherwise the night shift needs Sean awake, which
defeats the point of it.

Three things are visibly different from an ordinary ticket app, on purpose:

  * the private fields are behind a toggle and labelled NEVER POSTED
  * the forum preview shows exactly what would be published, and the button is
    disabled while anything identifying remains
  * there is no send button anywhere. Replies queue.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import os
import sys

from PySide6.QtCore import Qt
from PySide6.QtGui import QFont
from PySide6.QtWidgets import (
    QApplication, QCheckBox, QComboBox, QHBoxLayout, QLabel, QLineEdit,
    QMainWindow, QMessageBox, QPlainTextEdit, QPushButton, QSpinBox, QSplitter,
    QTabWidget, QTableWidget, QTableWidgetItem, QVBoxLayout, QWidget,
)

from ticketwicket import anonymise, forum as forummod, model, store as storemod

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
QPushButton:disabled { color:#555; border-color:#2a2a2a; background:#171717; }
QPlainTextEdit, QTableWidget, QLineEdit { background:#101010; border:1px solid #2a2a2a; }
QPlainTextEdit { font-family:Consolas,monospace; }
QHeaderView::section { background:#1b1b1b; border:none; padding:6px; color:#9a9a9a; }
QLabel#hint { color:#8a8a8a; }
QLabel#warn { color:#ffb347; }
QLabel#private { color:#ff8a8a; font-weight:600; }
"""


class Wicket(QMainWindow):
    def __init__(self, actor=model.SEAN):
        super().__init__()
        self.setWindowTitle("TICKET WICKET")
        self.resize(1180, 760)
        self.store = storemod.Store(actor=actor)
        self.current = None

        tabs = QTabWidget()
        tabs.addTab(self._queue_tab(), "Queue")
        tabs.addTab(self._new_tab(), "New ticket")
        tabs.addTab(self._outbox_tab(), "Outbox")
        self.setCentralWidget(tabs)
        self.setStyleSheet(STYLE)
        self.refresh()

    # ── queue ────────────────────────────────────────────────────────────────
    def _queue_tab(self):
        w = QWidget(); v = QVBoxLayout(w)
        row = QHBoxLayout()
        self.show_all = QCheckBox("include closed")
        self.show_all.stateChanged.connect(self.refresh)
        row.addWidget(self.show_all)
        self.stale_label = QLabel(""); self.stale_label.setObjectName("warn")
        row.addWidget(self.stale_label); row.addStretch()
        b = QPushButton("REFRESH"); b.clicked.connect(self.refresh); row.addWidget(b)
        v.addLayout(row)

        split = QSplitter(Qt.Orientation.Horizontal)
        self.table = QTableWidget(0, 5)
        self.table.setHorizontalHeaderLabels(["Ticket", "Urgency", "State", "Affected", "Title"])
        self.table.horizontalHeader().setStretchLastSection(True)
        self.table.itemSelectionChanged.connect(self.select_ticket)
        split.addWidget(self.table)

        right = QWidget(); rv = QVBoxLayout(right)
        self.detail = QPlainTextEdit(); self.detail.setReadOnly(True)
        rv.addWidget(self.detail, 2)

        self.private_toggle = QCheckBox("Show the private fields")
        self.private_toggle.stateChanged.connect(self.select_ticket)
        rv.addWidget(self.private_toggle)
        self.private_note = QLabel("The reporter and their original message are "
                                   "NEVER posted. They live here so Sean can reply "
                                   "to a human being.")
        self.private_note.setObjectName("private"); self.private_note.setWordWrap(True)
        rv.addWidget(self.private_note)

        rv.addWidget(QLabel("What would be posted to the tickets board:"))
        self.preview = QPlainTextEdit(); self.preview.setReadOnly(True)
        rv.addWidget(self.preview, 2)
        self.leak_label = QLabel(""); self.leak_label.setObjectName("warn")
        self.leak_label.setWordWrap(True)
        rv.addWidget(self.leak_label)

        brow = QHBoxLayout()
        self.post_btn = QPushButton("POST TO FORUM"); self.post_btn.setObjectName("primary")
        self.post_btn.clicked.connect(self.do_post)
        brow.addWidget(self.post_btn)
        esc = QPushButton("ESCALATE TO SEAN"); esc.clicked.connect(self.do_escalate)
        brow.addWidget(esc); brow.addStretch()
        rv.addLayout(brow)
        split.addWidget(right)
        split.setSizes([460, 720])
        v.addWidget(split)
        return w

    def refresh(self):
        tickets = self.store.queue(open_only=not self.show_all.isChecked())
        self.tickets = tickets
        t = self.table; t.setRowCount(0)
        for tk in tickets:
            r = t.rowCount(); t.insertRow(r)
            idc = QTableWidgetItem(tk.id)
            if tk.pulse:
                f = QFont(); f.setBold(True); idc.setFont(f)
            t.setItem(r, 0, idc)
            t.setItem(r, 1, QTableWidgetItem(tk.band))
            t.setItem(r, 2, QTableWidgetItem(tk.state))
            t.setItem(r, 3, QTableWidgetItem(
                forummod.Forum.public_affected(tk.affected)))
            t.setItem(r, 4, QTableWidgetItem(tk.title[:70]))
        stale = self.store.stale()
        self.stale_label.setText(
            f"{len(stale)} past the clock for their urgency" if stale else "")

    def select_ticket(self):
        rows = {i.row() for i in self.table.selectedItems()}
        if not rows:
            return
        tk = self.tickets[sorted(rows)[0]]
        self.current = tk
        lines = [f"{tk.id}  {tk.band}  {tk.state}" + ("  [PULSE]" if tk.pulse else ""),
                 tk.title, "", tk.body or "(no public description yet)", "",
                 f"opened {tk.created}", f"updated {tk.updated}"]
        if tk.links:
            lines.append("links: " + ", ".join(f"{k}={v}" for k, v in tk.links.items()))
        if self.private_toggle.isChecked():
            lines += ["", "PRIVATE — NEVER POSTED",
                      f"  reporter: {tk.reporter or '(none)'} via {tk.reporter_channel or '-'}",
                      f"  original: {tk.original or '(none)'}"]
        lines += ["", "History:"]
        lines += [f"  {e.at}  {e.author:<9} {e.kind:<9} {e.text[:60]}" for e in tk.entries]
        self.detail.setPlainText("\n".join(lines))

        post = anonymise.forum_post(tk)
        self.preview.setPlainText(post)
        problems = anonymise.check(post, [x for x in (tk.reporter, tk.reporter_channel) if x])
        if problems:
            self.leak_label.setText(
                "Will not post — this still identifies someone:\n  "
                + "\n  ".join(problems))
            self.post_btn.setEnabled(False)
        else:
            self.leak_label.setText("")
            self.post_btn.setEnabled(not tk.forum_thread)

    def do_post(self):
        tk = self.current
        if not tk:
            return
        url = os.environ.get("SNAPSMACK_FORUM_URL", "")
        key = os.environ.get("SNAPSMACK_FORUM_KEY", "")
        if not url or not key:
            QMessageBox.information(
                self, "No forum key",
                "Set SNAPSMACK_FORUM_URL and SNAPSMACK_FORUM_KEY. TICKET WICKET is "
                "inert without a key on purpose — a stolen copy is a dead thing.")
            return
        f = forummod.Forum(url, key)
        try:
            cat = f.ensure_board("Tickets", "Tickets. One per problem. Nobody's name.")
            thread = f.post_ticket(cat, f"{tk.id} · {tk.title}",
                                   anonymise.forum_post(tk))
        except forummod.ForumError as exc:
            QMessageBox.warning(self, "Forum", str(exc)); return
        self.store.update(tk.id, forum_thread=thread)
        self.store.add_entry(tk.id, "post", f"posted to forum thread {thread}", "forum")
        self.refresh()

    def do_escalate(self):
        if not self.current:
            return
        self.store.update(self.current.id, pulse=True)
        self.store.add_entry(self.current.id, "escalate", "escalated from the window")
        self.refresh()

    # ── new ──────────────────────────────────────────────────────────────────
    def _new_tab(self):
        w = QWidget(); v = QVBoxLayout(w)
        self.n_title = QLineEdit(); self.n_title.setPlaceholderText("Short title")
        v.addWidget(self.n_title)
        v.addWidget(QLabel("The ticket, written about the PROBLEM — not a reprint "
                           "of what arrived. This is what gets published."))
        self.n_body = QPlainTextEdit(); v.addWidget(self.n_body, 2)

        row = QHBoxLayout()
        self.n_security = QCheckBox("security hole"); row.addWidget(self.n_security)
        self.n_data = QCheckBox("data at risk"); row.addWidget(self.n_data)
        row.addWidget(QLabel("usability:"))
        self.n_use = QComboBox(); self.n_use.addItems(["cosmetic", "workaround", "stopped"])
        row.addWidget(self.n_use)
        row.addWidget(QLabel("affected:"))
        self.n_aff = QSpinBox(); self.n_aff.setRange(1, 9999); row.addWidget(self.n_aff)
        self.n_pulse = QCheckBox("has a pulse (never auto-answered)")
        row.addWidget(self.n_pulse); row.addStretch()
        v.addLayout(row)

        v.addWidget(QLabel("PRIVATE — kept so Sean can reply, never published:"))
        self.n_reporter = QLineEdit(); self.n_reporter.setPlaceholderText("reporter")
        v.addWidget(self.n_reporter)
        self.n_original = QPlainTextEdit()
        self.n_original.setPlaceholderText("their original message, verbatim")
        self.n_original.setMaximumHeight(90)
        v.addWidget(self.n_original)

        b = QPushButton("OPEN TICKET"); b.setObjectName("primary")
        b.clicked.connect(self.do_new); v.addWidget(b)
        return w

    def do_new(self):
        if not self.n_title.text().strip():
            return
        t = self.store.create(
            title=self.n_title.text().strip(), body=self.n_body.toPlainText(),
            reporter=self.n_reporter.text().strip(),
            original=self.n_original.toPlainText(),
            security=self.n_security.isChecked(), data_at_risk=self.n_data.isChecked(),
            usability=self.n_use.currentText(), affected=self.n_aff.value(),
            pulse=self.n_pulse.isChecked())
        QMessageBox.information(self, "Opened", f"{t.id} opened as {t.band}")
        for field in (self.n_title, self.n_reporter):
            field.clear()
        self.n_body.clear(); self.n_original.clear()
        self.refresh()

    # ── outbox ───────────────────────────────────────────────────────────────
    def _outbox_tab(self):
        w = QWidget(); v = QVBoxLayout(w)
        v.addWidget(QLabel(
            "Nothing here has been sent. This window cannot send, and neither can "
            "the command line — a separate armed sender holds the only credential, "
            "so a bad draft can be written and never delivered."))
        self.ob = QTableWidget(0, 5)
        self.ob.setHorizontalHeaderLabels(["#", "Ticket", "Channel", "By", "Queued"])
        self.ob.horizontalHeader().setStretchLastSection(True)
        v.addWidget(self.ob)
        b = QPushButton("REFRESH"); b.clicked.connect(self.refresh_outbox)
        v.addWidget(b)
        return w

    def refresh_outbox(self):
        rows = self.store.outbox()
        t = self.ob; t.setRowCount(0)
        for r in rows:
            i = t.rowCount(); t.insertRow(i)
            for c, val in enumerate([str(r["rowid_"]), r["ticket"], r["channel"],
                                     model.SIGNATURES.get(r["author"], r["author"]),
                                     r["queued"]]):
                t.setItem(i, c, QTableWidgetItem(val))


def run():
    app = QApplication(sys.argv)
    win = Wicket()
    win.show()
    sys.exit(app.exec())

# ===== SNAPSMACK EOF =====
