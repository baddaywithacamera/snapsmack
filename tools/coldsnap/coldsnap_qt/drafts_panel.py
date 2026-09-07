"""COLD SNAP Qt — the left rail every mode shares: the BATCH.

Owns the SessionStore for one suite mode, the batch picker (auto-creating —
the "No session, create one above" dead end is gone), the draft list, and the
SEND button with its named-site confirm. The mode plugs in:

    row_builder(session, refresh_cb) -> list[QWidget]   (how drafts render)
    make_poster() -> poster | None                       (network side)

Engine calls are the untouched sumna_offline ones.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import threading
from datetime import datetime

from PySide6.QtCore import QObject, Signal, Qt
from PySide6.QtWidgets import (
    QWidget, QVBoxLayout, QHBoxLayout, QLabel, QComboBox, QPushButton,
    QScrollArea, QFileDialog, QMessageBox, QInputDialog, QFrame, QDialog,
)

import sumna_offline as O

from . import theme
from .widgets import (Accordion, Card, hint, big_button, confirm_post,
                      status_badge, thumb_label)


class _SyncBridge(QObject):
    """Thread → UI marshalling for SyncEngine events."""
    progressed = Signal()
    finished = Signal(int, int)   # ok, total


class BatchRail(QWidget):
    def __init__(self, suite_mode: str, item_noun: str, connection_provider,
                 row_builder=None, parent=None):
        super().__init__(parent)
        self.suite_mode = suite_mode
        self.item_noun = item_noun                 # "photo" / "post"
        self.connection_provider = connection_provider  # () -> (poster|None, url)
        self.row_builder = row_builder             # optional custom renderer
        self.store = O.SessionStore()
        self.session = None

        # SNAP SLAPPER rail design (Sean, 2026-09-06): the batch is an accordion
        # SECTION on the right rail, opened when wanted; only SEND stays pinned.
        # This widget itself is never shown — modes place .section and .send_box
        # into build_rail(); it exists to own the logic and the Qt signals.
        self.section = Accordion("BATCH", expanded=False)
        manage_row = QHBoxLayout()
        manage_row.addStretch(1)
        manage = QPushButton("Manage…")
        manage.setObjectName("Quiet")
        manage.setToolTip("Pick another batch, start a new one, or move a "
                          "batch between machines")
        manage.clicked.connect(self._manage_batches)
        manage_row.addWidget(manage)
        self.section.add_layout(manage_row)

        # Kept (hidden) as the single source the modal drives.
        self.batch_combo = QComboBox()
        self.batch_combo.currentIndexChanged.connect(self._on_pick_batch)
        self.batch_combo.hide()

        # Draft list — no scroll of its own; the rail scrolls.
        self._list_host = QWidget()
        self._list_col = QVBoxLayout(self._list_host)
        self._list_col.setContentsMargins(0, 0, 0, 0)
        self._list_col.setSpacing(6)
        self._list_col.addStretch(1)
        self.section.add(self._list_host)

        # SEND — pinned under the rail, always visible.
        self._sending = False
        self.send_box = QWidget()
        sb = QVBoxLayout(self.send_box)
        sb.setContentsMargins(10, 8, 10, 8)
        sb.setSpacing(4)
        self.send_btn = big_button("SEND QUEUED POSTS ⇪")
        self.send_btn.setToolTip("Nothing reaches the site until you press this — "
                                 "and it always asks first, naming the site.")
        self.send_btn.clicked.connect(self._send)
        sb.addWidget(self.send_btn)
        self.sync_status = hint("")   # speaks only when something happens
        self.sync_status.setWordWrap(True)
        sb.addWidget(self.sync_status)

        self._bridge = _SyncBridge()
        self._bridge.progressed.connect(self.refresh_drafts)
        self._bridge.finished.connect(self._send_done)

        self.refresh_batches()

    # -- batches ------------------------------------------------------------
    def _batches(self):
        return [s for s in self.store.list() if s.mode == self.suite_mode]

    def ensure_session(self):
        """Always returns a live session — creates 'Batch <date>' silently on
        first use instead of blocking work behind a naming dialog."""
        if self.session is None:
            existing = self._batches()
            if existing:
                self.session = existing[0]
            else:
                self.session = self.store.create(
                    f"Batch {datetime.now():%b %d}", self.suite_mode)
                self.refresh_batches()
        return self.session

    def refresh_batches(self):
        batches = self._batches()
        self.batch_combo.blockSignals(True)
        self.batch_combo.clear()
        for s in batches:
            self.batch_combo.addItem(f"{s.name}  ·  {len(s.list_drafts())} item(s)", s.session_id)
        if self.session is None and batches:
            self.session = batches[0]
        if self.session is not None:
            for i in range(self.batch_combo.count()):
                if self.batch_combo.itemData(i) == self.session.session_id:
                    self.batch_combo.setCurrentIndex(i)
                    break
        self.batch_combo.blockSignals(False)
        if self.session is not None:
            n = len(self.session.list_drafts())
            self.section.header.setText(f"BATCH — {self.session.name} · {n} item(s)")
        else:
            self.section.header.setText("BATCH — starts automatically")
        self.refresh_drafts()

    def _manage_batches(self):
        dlg = QDialog(self)
        dlg.setWindowTitle("Batches")
        lay = QVBoxLayout(dlg)
        lay.addWidget(hint("A batch is a folder of queued posts. Pick one, start "
                           "another, or move a batch between machines."))
        combo = QComboBox()
        for i in range(self.batch_combo.count()):
            combo.addItem(self.batch_combo.itemText(i), self.batch_combo.itemData(i))
        combo.setCurrentIndex(self.batch_combo.currentIndex())
        lay.addWidget(combo)
        row = QHBoxLayout()
        new_btn = QPushButton("New batch…")
        exp_btn = QPushButton("Export to USB…")
        imp_btn = QPushButton("Import…")
        row.addWidget(new_btn)
        row.addWidget(exp_btn)
        row.addWidget(imp_btn)
        lay.addLayout(row)
        close_row = QHBoxLayout()
        close_row.addStretch(1)
        done = QPushButton("Done")
        done.setObjectName("Primary")
        close_row.addWidget(done)
        lay.addLayout(close_row)

        combo.currentIndexChanged.connect(self.batch_combo.setCurrentIndex)
        new_btn.clicked.connect(lambda: (dlg.accept(), self._new_batch()))
        exp_btn.clicked.connect(lambda: (dlg.accept(), self._export()))
        imp_btn.clicked.connect(lambda: (dlg.accept(), self._import()))
        done.clicked.connect(dlg.accept)
        dlg.exec()

    def _on_pick_batch(self, idx: int):
        sid = self.batch_combo.itemData(idx)
        if sid:
            picked = self.store.load(sid)
            if picked:
                self.session = picked
                self.refresh_drafts()

    def _new_batch(self):
        name, ok = QInputDialog.getText(self, "New batch", "Name this batch:")
        if not ok:
            return
        self.session = self.store.create(name.strip() or f"Batch {datetime.now():%b %d}",
                                         self.suite_mode)
        self.refresh_batches()

    def _export(self):
        if not self.session:
            return
        dest = QFileDialog.getExistingDirectory(self, "Export batch to (thumb drive / folder)")
        if dest:
            out = O.export_session(self.session, dest)
            QMessageBox.information(self, "Exported", f"Batch exported to:\n{out}")

    def _import(self):
        src = QFileDialog.getExistingDirectory(self, "Choose an exported batch folder")
        if not src:
            return
        try:
            self.session = O.import_session(src, self.store)
        except Exception as e:  # noqa: BLE001 — every import error shown plainly
            QMessageBox.critical(self, "Import failed", str(e))
            return
        self.refresh_batches()

    # -- draft list ------------------------------------------------------------
    def refresh_drafts(self):
        while self._list_col.count() > 1:      # keep the trailing stretch
            item = self._list_col.takeAt(0)
            w = item.widget()
            if w:
                w.deleteLater()
        if not self.session:
            self._list_col.insertWidget(0, hint("Nothing here yet — compose on the left."))
            self._reflect_send()
            return
        if self.row_builder:
            rows = self.row_builder(self.session, self.refresh_batches)
        else:
            rows = [default_draft_row(self.session, d, self.refresh_batches)
                    for d in self.session.list_drafts()]
        if not rows:
            self._list_col.insertWidget(0, hint(
                "Nothing here yet — compose on the left, then QUEUE POST."))
        for i, r in enumerate(rows):
            self._list_col.insertWidget(i, r)
        self._reflect_send()

    def _ready_count(self) -> int:
        if not self.session:
            return 0
        return sum(1 for d in self.session.list_drafts() if d.status == O.ST_READY)

    def _reflect_send(self):
        """The SEND button states its load and goes dark when there is none —
        a lit primary button over an empty queue is a lie about state."""
        if self._sending:
            return
        n = self._ready_count()
        noun = self.item_noun.upper()
        if n > 0:
            self.send_btn.setText(f"SEND {n} QUEUED {noun}{'' if n == 1 else 'S'} ⇪")
            self.send_btn.setEnabled(True)
        else:
            self.send_btn.setText("SEND QUEUED POSTS ⇪")
            self.send_btn.setEnabled(False)

    # -- send ------------------------------------------------------------------
    def _send(self):
        if not self.session:
            self.sync_status.setText("Nothing queued yet.")
            return
        poster, url = self.connection_provider()
        if poster is None:
            return
        ready = [d for d in self.session.list_drafts() if d.status == O.ST_READY]
        if not ready:
            self.sync_status.setText("Nothing queued yet — QUEUE POST puts a draft in line.")
            return
        # The destination CMS owns this rule.  Re-read it immediately before
        # sending so a setting changed after app launch cannot be bypassed by a
        # stale desktop profile. COLD SNAP does not currently own a verified
        # Drive session, so required-Drive sites fail closed here.
        try:
            policy = poster.publishing_policy()
        except Exception as exc:  # noqa: BLE001
            QMessageBox.warning(
                self, "Could not verify publishing rules",
                f"COLD SNAP could not confirm this site's live publishing rules:\n\n{exc}\n\n"
                "Nothing was uploaded.")
            return
        if policy.get("download_link_required"):
            QMessageBox.warning(
                self, "Google Drive required — posting blocked",
                "This site's CMS requires every published post to have a Drive "
                "download link. COLD SNAP has no verified Google Drive connection, "
                "so nothing was uploaded.\n\nUse SMACK YOUR BATCH UP with Drive connected.")
            self.sync_status.setText(
                "Posting blocked — this site requires a valid Google Drive connection.")
            self.sync_status.setStyleSheet(f"color: {theme.DANGER};")
            return
        if not confirm_post(self, url, len(ready), self.item_noun):
            self.sync_status.setText("Sending cancelled.")
            return
        self._sending = True
        self.send_btn.setEnabled(False)
        self.sync_status.setText(f"Sending {len(ready)} {self.item_noun}(s)…")
        self.sync_status.setStyleSheet(f"color: {theme.WARN};")

        session, bridge = self.session, self._bridge

        def worker():
            engine = O.SyncEngine(session, poster,
                                  on_event=lambda *_: bridge.progressed.emit())
            results = engine.sync_all(ready)
            ok = sum(1 for r in results.values() if r.ok)
            bridge.finished.emit(ok, len(results))

        threading.Thread(target=worker, daemon=True).start()

    def _send_done(self, ok: int, total: int):
        self._sending = False
        colour = theme.OK if ok == total else theme.DANGER
        self.sync_status.setStyleSheet(f"color: {colour};")
        self.sync_status.setText(
            f"Sent {ok} of {total} — each one verified against the live site."
            if ok == total else
            f"Sent {ok} of {total}. The red cards say exactly what went wrong.")
        self.refresh_batches()


def default_draft_row(session, draft, refresh_cb, *, edit_cb=None,
                      subtitle: str = "") -> QFrame:
    """One draft as a card row: thumb, title, plain status badge, Edit/Delete."""
    row = QFrame()
    row.setObjectName("Card")
    lay = QHBoxLayout(row)
    lay.setContentsMargins(8, 8, 8, 8)
    cover = draft.cover()
    lay.addWidget(thumb_label(cover.thumb_square if cover else "", 48))

    mid = QVBoxLayout()
    title = QLabel(draft.title or "(untitled)")
    title.setStyleSheet(f"color: {theme.INK}; font-weight: 700; background: transparent;")
    mid.addWidget(title)
    if subtitle:
        mid.addWidget(hint(subtitle))
    mid.addWidget(status_badge(draft.status))
    if draft.error:
        err = QLabel(draft.error)
        err.setWordWrap(True)
        err.setStyleSheet(f"color: {theme.DANGER}; font-size: 11px; background: transparent;")
        mid.addWidget(err)
    lay.addLayout(mid, 1)

    btns = QVBoxLayout()
    if edit_cb and draft.status != O.ST_SYNCED:
        e = QPushButton("Edit")
        e.clicked.connect(lambda _=False, d=draft: edit_cb(d))
        btns.addWidget(e)
    delete = QPushButton("Delete")
    delete.setObjectName("Danger")

    def _del(_=False, d=draft):
        if QMessageBox.question(row, "Delete", "Delete this from the batch?",
                                QMessageBox.Yes | QMessageBox.No,
                                QMessageBox.No) == QMessageBox.Yes:
            session.delete_draft(d.draft_id)
            refresh_cb()

    delete.clicked.connect(_del)
    btns.addWidget(delete)
    lay.addLayout(btns)
    return row

# ===== SNAPSMACK EOF =====
