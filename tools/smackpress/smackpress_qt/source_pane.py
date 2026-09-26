"""SMACKPRESS Qt — the WordPress side.

One pane: where the old posts are (WordPress URL, user, application password —
kept in the shared vault), the list of them, and one big button that pulls
the highlighted post across into COLD SNAP's editor with every picture
downloaded. After the editor's SEND has posted it, MARK MIGRATED records what
became what and offers to hide the original on WordPress.

Everything after "pull across" is COLD SNAP's code. This pane never posts.
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.

import os
import threading

from PySide6.QtCore import QObject, Qt, Signal
from PySide6.QtWidgets import (
    QWidget, QVBoxLayout, QHBoxLayout, QLabel, QLineEdit, QPushButton,
    QListWidget, QListWidgetItem, QComboBox, QMessageBox,
)

from coldsnap_qt import theme
from coldsnap_qt.widgets import Card, hint, field_label, big_button

from smackpress import config as sp_config
from smackpress import db as sp_db
from smackpress import wp_client
from smackpress import wp_source


class _Bridge(QObject):
    listed   = Signal(object, str)      # (payload | Exception, kind)
    pulled   = Signal(object, int)      # (Draft | Exception, wp_id)
    progress = Signal(str)
    tested   = Signal(object)


class SourcePane(QWidget):
    """Emits `draft_ready(draft, wp_id, title)` when a post has been pulled across."""
    draft_ready = Signal(object, int, str)

    def __init__(self, parent=None):
        super().__init__(parent)
        self.setMinimumWidth(360)
        self.setMaximumWidth(460)
        self._bridge = _Bridge()
        self._bridge.listed.connect(self._on_listed)
        self._bridge.pulled.connect(self._on_pulled)
        self._bridge.progress.connect(self._say)
        self._bridge.tested.connect(self._on_tested)
        self._page = 1
        self._pages = 1
        self._kind = "post"
        self._busy = False

        col = QVBoxLayout(self)
        col.setContentsMargins(10, 10, 0, 10)
        col.setSpacing(10)

        # ── where the old posts are ────────────────────────────────────────
        src = Card("WORDPRESS — where the old posts are")
        src.body.addWidget(field_label("WordPress site URL"))
        self.wp_url = QLineEdit(sp_config.get("wp_url") or "")
        self.wp_url.setPlaceholderText("https://myoldblog.com")
        src.body.addWidget(self.wp_url)
        row = QHBoxLayout()
        u = QVBoxLayout(); u.addWidget(field_label("Username"))
        self.wp_user = QLineEdit(sp_config.get("wp_user") or ""); u.addWidget(self.wp_user)
        p = QVBoxLayout(); p.addWidget(field_label("Application password"))
        self.wp_pass = QLineEdit(sp_config.get("wp_app_password") or "")
        self.wp_pass.setEchoMode(QLineEdit.Password); p.addWidget(self.wp_pass)
        row.addLayout(u, 1); row.addLayout(p, 1)
        src.body.addLayout(row)
        src.body.addWidget(hint("WP Admin → Users → Profile → Application Passwords. "
                                "The SMACKPRESS companion plugin must be active on the WordPress site."))
        brow = QHBoxLayout()
        self.test_btn = QPushButton("TEST + LOAD POSTS")
        self.test_btn.clicked.connect(self._test_and_load)
        brow.addWidget(self.test_btn)
        brow.addStretch(1)
        src.body.addLayout(brow)
        col.addWidget(src)

        # ── the list ───────────────────────────────────────────────────────
        lst = Card("OLD POSTS")
        frow = QHBoxLayout()
        self.kind_combo = QComboBox()
        self.kind_combo.addItem("Posts", "post")
        self.kind_combo.addItem("Pages", "page")
        self.kind_combo.currentIndexChanged.connect(lambda _i: self._reload(1))
        frow.addWidget(self.kind_combo)
        self.show_combo = QComboBox()
        self.show_combo.addItem("All", "all")
        self.show_combo.addItem("Not migrated yet", "todo")
        self.show_combo.addItem("Migrated", "done")
        self.show_combo.currentIndexChanged.connect(lambda _i: self._render())
        frow.addWidget(self.show_combo)
        frow.addStretch(1)
        lst.body.addLayout(frow)

        self.list = QListWidget()
        self.list.setObjectName("SourceList")
        self.list.itemSelectionChanged.connect(self._reflect_selection)
        lst.body.addWidget(self.list, 1)

        prow = QHBoxLayout()
        self.prev_btn = QPushButton("◀"); self.prev_btn.clicked.connect(lambda: self._reload(self._page - 1))
        self.next_btn = QPushButton("▶"); self.next_btn.clicked.connect(lambda: self._reload(self._page + 1))
        self.page_lbl = hint("")
        prow.addWidget(self.prev_btn); prow.addWidget(self.page_lbl, 1); prow.addWidget(self.next_btn)
        lst.body.addLayout(prow)
        col.addWidget(lst, 1)

        # ── the one loud button ────────────────────────────────────────────
        self.pull_btn = big_button("PULL ACROSS →")
        self.pull_btn.setToolTip("Download every picture, convert the post, open it in the editor on the right.")
        self.pull_btn.clicked.connect(self._pull)
        self.pull_btn.setEnabled(False)
        col.addWidget(self.pull_btn)

        mrow = QHBoxLayout()
        self.migrated_btn = QPushButton("MARK MIGRATED")
        self.migrated_btn.setToolTip("After SEND on the right has posted it: record where it went and offer to hide it on WordPress.")
        self.migrated_btn.clicked.connect(self._mark_migrated)
        self.migrated_btn.setEnabled(False)
        mrow.addWidget(self.migrated_btn)
        mrow.addStretch(1)
        col.addLayout(mrow)

        self.status = hint("")
        self.status.setWordWrap(True)
        col.addWidget(self.status)

        self._posts = []
        self._draft_by_wp = {}      # wp_id -> draft_id handed to the editor
        self.last_synced_lookup = None   # main window sets: (draft_id) -> (post_id, url) | None

    # ── plumbing ───────────────────────────────────────────────────────────
    def _say(self, text: str, colour: str = ""):
        self.status.setText(text)
        self.status.setStyleSheet(f"color: {colour};" if colour else "")

    def _save_creds(self) -> bool:
        url = self.wp_url.text().strip().rstrip("/")
        if not url.lower().startswith("https://"):
            QMessageBox.warning(self, "WordPress URL", "The WordPress address must start with https:// — "
                                "the application password would otherwise cross the network in the clear.")
            return False
        sp_config.set("wp_url", url)
        sp_config.set("wp_user", self.wp_user.text().strip())
        try:
            sp_config.set("wp_app_password", self.wp_pass.text().strip())
        except sp_config.VaultRequired as e:
            # Works for this session; not written to disk unsealed (SECAUDIT 054).
            self._say(f"Password kept for this session only — {e}", theme.WARN)
        return True

    def _test_and_load(self):
        if self._busy or not self._save_creds():
            return
        self._busy = True
        self.test_btn.setEnabled(False)
        self._say("Talking to WordPress…", theme.WARN)

        def work():
            try:
                self._bridge.tested.emit(wp_client.test_connection())
            except Exception as e:  # noqa: BLE001
                self._bridge.tested.emit(e)
        threading.Thread(target=work, daemon=True).start()

    def _on_tested(self, result):
        self._busy = False
        self.test_btn.setEnabled(True)
        if isinstance(result, Exception):
            self._say(f"WordPress said no: {result}", theme.DANGER)
            return
        self._say("WordPress answered. Loading posts…", theme.OK)
        self._reload(1)

    def _reload(self, page: int):
        if self._busy:
            return
        page = max(1, page)
        kind = self.kind_combo.currentData() or "post"
        self._busy = True
        self._kind = kind

        def work():
            try:
                fn = wp_client.get_pages if kind == "page" else wp_client.get_posts
                self._bridge.listed.emit(fn(page=page, per_page=25, status="publish,private,draft"), kind)
            except Exception as e:  # noqa: BLE001
                self._bridge.listed.emit(e, kind)
        threading.Thread(target=work, daemon=True).start()

    def _on_listed(self, payload, kind):
        self._busy = False
        if isinstance(payload, Exception):
            self._say(f"Could not list {kind}s: {payload}", theme.DANGER)
            return
        self._posts = list(payload.get("posts") or payload.get("pages") or [])
        self._page = int(payload.get("page") or 1)
        self._pages = int(payload.get("total_pages") or 1)
        self.page_lbl.setText(f"page {self._page} of {self._pages} · {payload.get('total', len(self._posts))} {kind}s")
        self.prev_btn.setEnabled(self._page > 1)
        self.next_btn.setEnabled(self._page < self._pages)
        self._render()
        self._say("")

    def _render(self):
        show = self.show_combo.currentData() or "all"
        self.list.clear()
        for p in self._posts:
            rec = sp_db.get_post(int(p["id"]))
            done = bool(rec and rec["snap_post_id"])
            if show == "todo" and done:
                continue
            if show == "done" and not done:
                continue
            date = (p.get("date") or "")[:10]
            n_img = len(p.get("images") or []) or int(p.get("image_count") or 0)
            mark = "✓ " if done else ""
            hidden = " · hidden on WP" if (rec and rec["hidden_at"]) else ""
            text = f"{mark}{date}  {p.get('title') or '(untitled)'}"
            if n_img:
                text += f"   · {n_img} picture{'s' if n_img != 1 else ''}"
            item = QListWidgetItem(text + hidden)
            item.setData(Qt.UserRole, p)
            if done:
                item.setForeground(Qt.gray)
            self.list.addItem(item)
        self._reflect_selection()

    def _selected(self):
        items = self.list.selectedItems()
        return items[0].data(Qt.UserRole) if items else None

    def _reflect_selection(self):
        p = self._selected()
        self.pull_btn.setEnabled(bool(p) and not self._busy)
        can_mark = bool(p) and int(p["id"]) in self._draft_by_wp
        self.migrated_btn.setEnabled(can_mark)

    # ── PULL ACROSS ────────────────────────────────────────────────────────
    def _pull(self, workdir_for=None):
        p = self._selected()
        if not p or self._busy:
            return
        wp_id = int(p["id"])
        self._busy = True
        self.pull_btn.setEnabled(False)
        self._say(f"Pulling “{p.get('title') or wp_id}” across…", theme.WARN)
        workdir = (self.workdir_provider() if hasattr(self, "workdir_provider") else None) \
            or os.path.join(sp_config._app_dir(), "wp-import", str(wp_id))

        def work():
            try:
                full = wp_client.get_post(wp_id)
                draft = wp_source.draft_from_wp(full, workdir, on_progress=self._bridge.progress.emit)
                self._bridge.pulled.emit(draft, wp_id)
            except Exception as e:  # noqa: BLE001
                self._bridge.pulled.emit(e, wp_id)
        threading.Thread(target=work, daemon=True).start()

    def _on_pulled(self, result, wp_id):
        self._busy = False
        self._reflect_selection()
        if isinstance(result, Exception):
            self._say(f"Not pulled: {result}", theme.DANGER)
            return
        self._draft_by_wp[wp_id] = result.draft_id
        sp_db.upsert_post(wp_id, wp_title=result.title, wp_date=result.post_date,
                          notes=f"draft:{result.draft_id}")
        self._say(f"Pulled: {len(result.images)} picture(s) are now local. Finish it on the right, then SEND.", theme.OK)
        self.draft_ready.emit(result, wp_id, result.title)
        self._reflect_selection()

    # ── MARK MIGRATED ──────────────────────────────────────────────────────
    def _mark_migrated(self):
        p = self._selected()
        if not p:
            return
        wp_id = int(p["id"])
        draft_id = self._draft_by_wp.get(wp_id)
        posted = self.last_synced_lookup(draft_id) if (draft_id and self.last_synced_lookup) else None
        if not posted:
            QMessageBox.information(self, "Not posted yet",
                                    "SEND on the right hasn't posted this one yet. Queue it and send, then come back.")
            return
        post_id, url = posted
        sp_db.mark_migrated(wp_id, post_id, url)
        self._say(f"Recorded: WordPress #{wp_id} → {url}", theme.OK)
        if QMessageBox.question(self, "Hide on WordPress?",
                                f"Set the WordPress original to private and note that it moved to\n{url}?",
                                QMessageBox.Yes | QMessageBox.No, QMessageBox.No) == QMessageBox.Yes:
            try:
                wp_client.hide_post(wp_id, url)
                sp_db.mark_hidden(wp_id)
                self._say(f"Recorded and hidden on WordPress: #{wp_id} → {url}", theme.OK)
            except Exception as e:  # noqa: BLE001
                self._say(f"Recorded, but WordPress would not hide it: {e}", theme.WARN)
        self._render()

# ===== SNAPSMACK EOF =====
