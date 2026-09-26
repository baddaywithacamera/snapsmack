"""SMACKPRESS Qt — main window.

    [ COLD SNAP ConnectPanel  — the SnapSmack site you are migrating INTO       ]
    [ SourcePane (WordPress)  |  COLD SNAP TakeMode (COLD TAKE editor + rail)  ]

Nothing here posts. The editor on the right is COLD SNAP's, unchanged; its
SEND is what publishes. This window only (1) hands a pulled WordPress post to
that editor as a draft in a batch called "WordPress import", and (2) reads
back from that batch what SEND posted, so MARK MIGRATED knows the URL.
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.

import os
import sys

from PySide6.QtGui import QIcon, QKeySequence, QShortcut
from PySide6.QtWidgets import QMainWindow, QWidget, QVBoxLayout, QHBoxLayout, QLabel, QMessageBox

import sumna_offline as O
from coldsnap_qt.connect_panel import ConnectPanel
from coldsnap_qt.mode_take import TakeMode
from coldsnap_qt.widgets import hint

from smackpress import __version__ as BUILD_VERSION
from . import SHELL_LABEL
from .source_pane import SourcePane

BATCH_NAME = "WordPress import"


class MainWindow(QMainWindow):
    def __init__(self):
        super().__init__()
        self.setWindowTitle(f"SMACKPRESS — build {BUILD_VERSION} · {SHELL_LABEL}")
        self.resize(1480, 900)
        self.setMinimumSize(1180, 700)
        icon = os.path.join(
            getattr(sys, "_MEIPASS", os.path.dirname(os.path.dirname(os.path.abspath(__file__)))),
            "assets", "smackpress.ico")
        if os.path.isfile(icon):
            self.setWindowIcon(QIcon(icon))

        central = QWidget()
        col = QVBoxLayout(central)
        col.setContentsMargins(0, 0, 0, 0)
        col.setSpacing(0)

        # The destination: COLD SNAP's own connection panel (site, keys, vault).
        self.connect_panel = ConnectPanel()
        col.addWidget(self.connect_panel)
        cfg = lambda: self.connect_panel.config

        row = QHBoxLayout()
        row.setContentsMargins(0, 0, 0, 0)
        row.setSpacing(0)
        self.source = SourcePane()
        self.take = TakeMode(cfg)                       # COLD SNAP's COLD TAKE, as-is
        row.addWidget(self.source, 0)
        row.addWidget(self.take, 1)
        host = QWidget()
        host.setLayout(row)
        col.addWidget(host, 1)

        foot = hint("Left: the WordPress you are leaving. Right: COLD SNAP's editor — the same one COLD TAKE uses. "
                    "PULL ACROSS downloads every picture and opens the post; QUEUE POST + SEND publishes it; "
                    "MARK MIGRATED records where it went.")
        foot.setContentsMargins(14, 4, 14, 6)
        col.addWidget(foot)
        self.setCentralWidget(central)

        self.source.draft_ready.connect(self._hand_to_editor)
        self.source.last_synced_lookup = self._posted_lookup
        self.source.workdir_provider = self._workdir
        QShortcut(QKeySequence("F1"), self).activated.connect(self._show_help)

    def _show_help(self):
        from coldsnap_qt.help_dialog import HelpDialog
        from .help_topics import TOPICS
        HelpDialog(self, topics=TOPICS, title="SMACKPRESS — Help").exec()

    # ── the batch every pulled post lands in ───────────────────────────────
    def _batch(self) -> "O.Session":
        rail = self.take.rail
        for s in rail._batches():
            if s.name == BATCH_NAME:
                rail.session = s
                rail.refresh_batches()
                return s
        s = rail.store.create(BATCH_NAME, rail.suite_mode)
        rail.session = s
        rail.refresh_batches()
        return s

    def _workdir(self) -> str:
        return os.path.join(os.path.dirname(self._batch().path.rstrip("/\\")), "wp-import")

    def _hand_to_editor(self, draft, wp_id: int, title: str):
        """A pulled post becomes a draft in the import batch and opens in the editor."""
        session = self._batch()
        session.save_draft(draft)
        self.take.rail.refresh_drafts()
        self.take._edit(draft)
        self.take.setFocus()

    def _posted_lookup(self, draft_id: str):
        """What COLD SNAP's SEND did with this draft: (post_id, url) or None."""
        try:
            d = self._batch().load_draft(draft_id)
        except Exception:  # noqa: BLE001
            return None
        if not d or not getattr(d, "remote_post_id", 0):
            return None
        base = (self.connect_panel.config.get("url") or "").rstrip("/")
        slug = getattr(d, "slug", "") or ""
        url = f"{base}/post/{slug}" if slug else f"{base}/?p={d.remote_post_id}"
        return int(d.remote_post_id), url

# ===== SNAPSMACK EOF =====
