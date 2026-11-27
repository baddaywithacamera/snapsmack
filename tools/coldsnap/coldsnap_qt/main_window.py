"""COLD SNAP Qt — the main window: SITE header + three plain-words modes.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import os
import sys

from PySide6.QtCore import QSettings, QTimer
from PySide6.QtGui import QAction, QIcon, QKeySequence, QShortcut
from PySide6.QtWidgets import (
    QMainWindow, QWidget, QVBoxLayout, QTabWidget, QLabel, QHBoxLayout,
    QPushButton, QMessageBox, QMenu,
)

from _version import BUILD_VERSION
import config as cfg_module
import sumna_offline as O
import snap_desktop_handoff

from . import SHELL_LABEL
from .cold_storage import StorageMode
from .connect_panel import ConnectPanel
from .mode_solo import SoloMode
from .mode_stack import StackMode
from .mode_take import TakeMode
from .widgets import hint


class MainWindow(QMainWindow):
    def __init__(self):
        super().__init__()
        self.setWindowTitle(f"COLD SNAP — build {BUILD_VERSION} · {SHELL_LABEL}")
        self.resize(1240, 860)
        self.setMinimumSize(980, 680)
        self._window_settings = QSettings("SnapSmack", "COLD SNAP")
        geometry = self._window_settings.value("window/normal_geometry")
        if geometry:
            self.restoreGeometry(geometry)
        self._restore_maximized = self._window_settings.value("window/maximized", False, type=bool)

        icon_path = os.path.join(
            getattr(sys, "_MEIPASS", os.path.dirname(os.path.dirname(os.path.abspath(__file__)))),
            "assets", "coldsnap.ico")
        if os.path.isfile(icon_path):
            self.setWindowIcon(QIcon(icon_path))

        central = QWidget()
        col = QVBoxLayout(central)
        col.setContentsMargins(0, 0, 0, 0)
        col.setSpacing(0)

        self.connect_panel = ConnectPanel()
        col.addWidget(self.connect_panel)

        self.header_strip = QPushButton("")
        self.header_strip.setObjectName("HeaderCollapsedStrip")
        self.header_strip.setFixedHeight(22)
        self.header_strip.setStyleSheet(
            "QPushButton#HeaderCollapsedStrip { background: #141714; color: #b7d9b0;"
            " border: none; border-bottom: 1px solid #2a302a; text-align: left;"
            " padding: 0 14px; font-size: 11px; }"
            "QPushButton#HeaderCollapsedStrip:hover { color: #76ff03; }"
        )
        self.header_strip.setToolTip("Click to expand site & mode bar (Ctrl+Shift+H)")
        self.header_strip.clicked.connect(lambda: self.set_header_collapsed(False))
        self.header_strip.setVisible(False)
        col.addWidget(self.header_strip)

        cfg = lambda: self.connect_panel.config

        self.tabs = QTabWidget()
        self.tabs.setObjectName("ModeTabs")
        self.solo_mode = SoloMode(cfg)
        self.tabs.addTab(self.solo_mode, "COLD ONE")
        self.tabs.addTab(StackMode(cfg), "COLD STACK")
        self.take_mode = TakeMode(cfg)
        self.tabs.addTab(self.take_mode, "COLD TAKE")
        self.tabs.addTab(StorageMode(cfg), "COLD STORAGE")
        self.tabs.setTabToolTip(0, "One photo, one post — solo photoblog sites")
        self.tabs.setTabToolTip(1, "Carousels, stacks and trigrams — gram grid sites")
        self.tabs.setTabToolTip(2, "An essay with photos — long-form sites")
        self.tabs.setTabToolTip(3, "This blog's images, offline — browse, sync more "
                              "down, AI-describe, save metadata back")
        col.addWidget(self.tabs, 1)

        self._focus_mode = False
        self._pre_focus_panels = None

        self.blog_btn = QPushButton()
        self.blog_btn.setObjectName("ShellAction")
        self.blog_btn.setCheckable(True)
        self.blog_btn.setToolTip("Show or hide connection details (Ctrl+1)")
        self.blog_btn.setAccessibleName("Show or hide connection details")
        self.blog_btn.clicked.connect(self.connect_panel.details_btn.setChecked)
        self.connect_panel.add_header_action(self.blog_btn)
        self.connect_panel.details_btn.toggled.connect(self._sync_blog_action)
        self._sync_blog_action(self.connect_panel.details_btn.isChecked())

        self.photos_btn = QPushButton()
        self.photos_btn.setObjectName("ShellAction")
        self.photos_btn.setCheckable(True)
        self.photos_btn.setToolTip("Open or close the COLD TAKE photos tray (Ctrl+2)")
        self.photos_btn.setAccessibleName("Open or close the photos tray")
        self.photos_btn.clicked.connect(self.take_mode.set_photos_open)
        self.connect_panel.add_header_action(self.photos_btn)
        self.take_mode.photosStateChanged.connect(self._sync_photos_action)
        self._sync_photos_action(self.take_mode.photos_open())

        self.post_btn = QPushButton()
        self.post_btn.setObjectName("ShellAction")
        self.post_btn.setCheckable(True)
        self.post_btn.setToolTip("Show or hide Tags & Status (Ctrl+3)")
        self.post_btn.setAccessibleName("Show or hide post details")
        self.post_btn.clicked.connect(self.take_mode.set_details_open)
        self.connect_panel.add_header_action(self.post_btn)
        self.take_mode.detailsStateChanged.connect(self._sync_post_action)
        self._sync_post_action(self.take_mode.details_open())

        self._blocks_label = "BLOCKS"
        self.blocks_btn = QPushButton()
        self.blocks_btn.setObjectName("ShellAction")
        self.blocks_btn.setCheckable(True)
        self.blocks_btn.setToolTip("Open or close the COLD TAKE blocks tray (Ctrl+4)")
        self.blocks_btn.setAccessibleName("Open or close the blocks tray")
        self.blocks_btn.clicked.connect(self.take_mode.set_blocks_open)
        self.connect_panel.add_header_action(self.blocks_btn)
        self.take_mode.blocksStateChanged.connect(self._sync_blocks_action)
        self._sync_blocks_action(
            self.take_mode.blocks_open(), self.take_mode.blocks_header_label())

        self.collapse_header_btn = QPushButton("▲")
        self.collapse_header_btn.setObjectName("Quiet")
        self.collapse_header_btn.setToolTip("Collapse site & mode header (Ctrl+Shift+H)")
        self.collapse_header_btn.setAccessibleName("Collapse site and mode header")
        self.collapse_header_btn.clicked.connect(lambda: self.set_header_collapsed(True))
        self.connect_panel.header_row.addWidget(self.collapse_header_btn)

        self.focus_btn = QPushButton("⛶")
        self.focus_btn.setObjectName("ShellAction")
        self.focus_btn.setCheckable(True)
        self.focus_btn.setToolTip("Focus mode — hide panels and header (F11)")
        self.focus_btn.setAccessibleName("Toggle focus mode")
        self.focus_btn.clicked.connect(self.set_focus_mode)
        self.connect_panel.header_row.addWidget(self.focus_btn)
        self.take_mode.exitFocusRequested.connect(lambda: self.set_focus_mode(False))

        self.tools_menu = QMenu(self)
        self.import_backup_action = QAction("Import drafts from backup…", self)
        self.import_backup_action.triggered.connect(lambda: self.rerun_backup_import())
        self.tools_menu.addAction(self.import_backup_action)
        self.clear_published_cache_action = QAction("Clear published photo cache for this site…", self)
        self.clear_published_cache_action.triggered.connect(self.take_mode.clear_published_photo_cache)
        self.tools_menu.addAction(self.clear_published_cache_action)
        self.tools_menu_btn = QPushButton("⋯")
        self.tools_menu_btn.setObjectName("Quiet")
        self.tools_menu_btn.setToolTip("More actions")
        self.tools_menu_btn.setMenu(self.tools_menu)
        self.connect_panel.header_row.addWidget(self.tools_menu_btn)

        self._tab_modes = (
            (O.MODE_SOLO, "COLD ONE-compatible"),
            (O.MODE_GRAM, "COLD STACK-compatible"),
            (O.MODE_SMACKTALK, "COLD TAKE-compatible"),
            (None, ""),
        )
        self.tabs.currentChanged.connect(self._on_mode_changed)
        self.connect_panel.profile_combo.currentIndexChanged.connect(
            lambda _index: QTimer.singleShot(0, self.solo_mode.refresh_taxonomy))
        self.connect_panel.profile_combo.currentIndexChanged.connect(
            lambda _index: self._sync_site_context())
        self.connect_panel.sitesLoaded.connect(self.solo_mode.refresh_taxonomy)
        self.connect_panel.sitesLoaded.connect(self._sync_site_context)
        saved_tab = int(self._window_settings.value("window/active_tab", 0))
        if 0 <= saved_tab < self.tabs.count():
            self.tabs.setCurrentIndex(saved_tab)
        self._on_mode_changed(self.tabs.currentIndex())

        self.setCentralWidget(central)

        QShortcut(QKeySequence("F1"), self).activated.connect(
            self.connect_panel._show_help)
        self._blog_shortcut = QShortcut(QKeySequence("Ctrl+1"), self)
        self._blog_shortcut.activated.connect(self._toggle_blog)
        self._photos_shortcut = QShortcut(QKeySequence("Ctrl+2"), self)
        self._photos_shortcut.activated.connect(self._toggle_photos)
        self._blocks_shortcut = QShortcut(QKeySequence("Ctrl+4"), self)
        self._blocks_shortcut.activated.connect(self._toggle_blocks)
        self._focus_shortcut = QShortcut(QKeySequence("F11"), self)
        self._focus_shortcut.activated.connect(self.toggle_focus_mode)
        self._header_shortcut = QShortcut(QKeySequence("Ctrl+Shift+H"), self)
        self._header_shortcut.activated.connect(
            lambda: self.set_header_collapsed(not self._header_collapsed))
        self._esc_focus_shortcut = QShortcut(QKeySequence("Escape"), self)
        self._esc_focus_shortcut.activated.connect(self._exit_focus_if_active)

        self._header_collapsed = bool(self._window_settings.value("window/header_collapsed", False, type=bool))
        if self._header_collapsed:
            self.set_header_collapsed(True)
        self._sync_site_context()

        self._handoff_timer = QTimer(self)
        self._handoff_timer.setInterval(600)
        self._handoff_timer.timeout.connect(self._consume_handoff)
        self._handoff_timer.start()
        QTimer.singleShot(0, self._consume_handoff)
        QTimer.singleShot(0, self._run_startup_session_migration)

        if self._restore_maximized:
            QTimer.singleShot(0, self.showMaximized)

    def set_header_collapsed(self, collapsed: bool):
        collapsed = bool(collapsed)
        self._header_collapsed = collapsed
        self._window_settings.setValue("window/header_collapsed", collapsed)
        if not self._focus_mode:
            self.connect_panel.setVisible(not collapsed)
            self.tabs.tabBar().setVisible(not collapsed)
            self.header_strip.setVisible(collapsed)
        self._sync_header_strip()

    def _sync_header_strip(self):
        site_name = (
            self.connect_panel.profile_combo.currentData()
            or self.connect_panel.config.get("url")
            or "no site selected"
        )
        mode_name = self.tabs.tabText(self.tabs.currentIndex()) if self.tabs.count() else ""
        self.header_strip.setText(f"{site_name}  ·  {mode_name}    ▾")

    def _sync_site_context(self):
        self._sync_header_strip()
        url = str(self.connect_panel.config.get("url") or "").strip()
        if hasattr(self, "take_mode"):
            self.take_mode.apply_canvas_background(url)

    def _on_mode_changed(self, index):
        suite_mode, label = self._tab_modes[index]
        self.connect_panel.set_suite_mode(suite_mode, label)
        self._window_settings.setValue("window/active_tab", int(index))
        in_take = index == 2
        self.photos_btn.setVisible(in_take)
        self.post_btn.setVisible(in_take)
        self.blocks_btn.setVisible(in_take)
        self.focus_btn.setVisible(in_take)
        if not in_take and self._focus_mode:
            self.set_focus_mode(False)
        if index == 0:
            QTimer.singleShot(0, self.solo_mode.refresh_taxonomy)
        self._sync_header_strip()

    def _toggle_blog(self):
        self.connect_panel.details_btn.toggle()

    def _toggle_photos(self):
        if self.tabs.currentIndex() == 2:
            self.take_mode.toggle_photos()

    def _toggle_blocks(self):
        if self.tabs.currentIndex() == 2:
            self.take_mode.toggle_blocks()

    def is_focus_mode(self) -> bool:
        return self._focus_mode

    def toggle_focus_mode(self):
        if self.tabs.currentIndex() == 2 or self._focus_mode:
            self.set_focus_mode(not self._focus_mode)

    def _exit_focus_if_active(self):
        if self._focus_mode:
            self.set_focus_mode(False)

    def set_focus_mode(self, enabled: bool):
        enabled = bool(enabled)
        if self._focus_mode == enabled:
            return
        self._focus_mode = enabled
        self.focus_btn.blockSignals(True)
        self.focus_btn.setChecked(enabled)
        self.focus_btn.blockSignals(False)
        if enabled:
            self._pre_focus_panels = {
                "blog": self.connect_panel.details_btn.isChecked(),
                "photos": self.take_mode.photos_open(),
                "post": self.take_mode.details_open(),
                "blocks": self.take_mode.blocks_open(),
            }
            self.connect_panel.details_btn.setChecked(False)
            self.take_mode.set_photos_open(False)
            self.take_mode.set_details_open(False)
            self.take_mode.set_blocks_open(False)
            self.connect_panel.setVisible(False)
            self.tabs.tabBar().setVisible(False)
            self.take_mode.focus_exit_btn.setVisible(True)
        else:
            self.connect_panel.setVisible(True)
            self.tabs.tabBar().setVisible(True)
            self.take_mode.focus_exit_btn.setVisible(False)
            prev = self._pre_focus_panels or {}
            self._pre_focus_panels = None
            if prev:
                self.connect_panel.details_btn.setChecked(bool(prev.get("blog", False)))
                self.take_mode.set_photos_open(bool(prev.get("photos", False)))
                self.take_mode.set_details_open(bool(prev.get("post", False)))
                self.take_mode.set_blocks_open(bool(prev.get("blocks", False)))

    def _sync_blog_action(self, opened: bool):
        suffix = "" if self.width() <= 1366 else "   Ctrl+1"
        self.blog_btn.blockSignals(True)
        self.blog_btn.setText("BLOG" + suffix)
        self.blog_btn.setChecked(bool(opened))
        self.blog_btn.blockSignals(False)

    def _sync_photos_action(self, opened: bool):
        suffix = "" if self.width() <= 1366 else "   Ctrl+2"
        self.photos_btn.blockSignals(True)
        self.photos_btn.setText("PHOTOS" + suffix)
        self.photos_btn.setChecked(bool(opened))
        self.photos_btn.blockSignals(False)

    def _sync_post_action(self, opened: bool):
        suffix = "" if self.width() <= 1366 else "   Ctrl+3"
        self.post_btn.blockSignals(True)
        self.post_btn.setText("POST" + suffix)
        self.post_btn.setChecked(bool(opened))
        self.post_btn.blockSignals(False)

    def _sync_blocks_action(self, opened: bool, label: str):
        self._blocks_label = str(label or "BLOCKS")
        suffix = "" if self.width() <= 1366 else "   Ctrl+4"
        self.blocks_btn.blockSignals(True)
        self.blocks_btn.setText(self._blocks_label + suffix)
        self.blocks_btn.setChecked(bool(opened))
        self.blocks_btn.blockSignals(False)

    def resizeEvent(self, event):
        super().resizeEvent(event)
        compact = self.width() <= 1366
        if hasattr(self, "connect_panel"):
            self.connect_panel.set_compact_window(compact)
        if hasattr(self, "blog_btn"):
            self._sync_blog_action(self.connect_panel.details_btn.isChecked())
        if hasattr(self, "take_mode") and hasattr(self, "photos_btn"):
            self._sync_photos_action(self.take_mode.photos_open())
        if hasattr(self, "take_mode") and hasattr(self, "post_btn"):
            self._sync_post_action(self.take_mode.details_open())
        if hasattr(self, "take_mode") and hasattr(self, "blocks_btn"):
            self._sync_blocks_action(
                self.take_mode.blocks_open(),
                self.take_mode.blocks_header_label())

    def closeEvent(self, event):
        if hasattr(self, "_handoff_timer"):
            self._handoff_timer.stop()
        self._window_settings.setValue("window/maximized", self.isMaximized())
        if not self.isMaximized():
            self._window_settings.setValue("window/normal_geometry", self.saveGeometry())
        self._window_settings.setValue("window/active_tab", self.tabs.currentIndex())
        super().closeEvent(event)

    def _consume_handoff(self):
        request = snap_desktop_handoff.consume_request("coldsnap")
        if not request:
            return
        site_url = str(request.get("site_url") or "").strip()
        mode = str(request.get("site_mode") or "").strip().lower()
        if site_url and not self.connect_panel.select_site(site_url):
            self.statusBar().showMessage(
                "GYSS requested a site that is not saved in COLD SNAP.", 8000)
            return
        tab_for_mode = {"photoblog": 0, "carousel": 1, "smacktalk": 2}
        if mode in tab_for_mode:
            self.tabs.setCurrentIndex(tab_for_mode[mode])
        selected = list(request.get("selected_images") or [])
        if selected and mode == "smacktalk":
            self.tabs.widget(2).accept_handoff(selected)
        self.showNormal()
        self.raise_()
        self.activateWindow()
        self.statusBar().showMessage("Opened from GET YOUR SHIT SORTED", 5000)

    def _refresh_session_rails(self):
        for i in range(self.tabs.count()):
            w = self.tabs.widget(i)
            rail = getattr(w, "rail", None)
            if rail is not None and hasattr(rail, "refresh"):
                try:
                    rail.refresh()
                except Exception:
                    pass

    def rerun_backup_import(
        self,
        legacy_root: str = None,
        dest_root: str = None,
        backup_parent: str = None,
        ask_fn=None,
    ):
        """Re-run the backup draft import check on demand from the menu."""
        try:
            cfg_module.clear_dismissed_backup_folders()
        except Exception:
            pass
        return self._run_startup_session_migration(
            legacy_root=legacy_root,
            dest_root=dest_root,
            backup_parent=backup_parent,
            ask_fn=ask_fn,
            manual=True,
        )

    def _run_startup_session_migration(
        self,
        legacy_root: str = None,
        dest_root: str = None,
        backup_parent: str = None,
        ask_fn=None,
        manual: bool = False,
    ):
        """Migrate install-relative drafts to per-user storage and offer backup imports."""
        dest = dest_root or O.default_sessions_root()
        legacy = legacy_root or O.legacy_sessions_root()
        try:
            import snap_home
            log_file = snap_home.log_path("coldsnap", "session-migration")
        except Exception:
            log_file = None

        changed = False
        mig = O.migrate_sessions_dir(legacy, dest, log_path=log_file)
        if mig.get("migrated"):
            changed = True
            self.statusBar().showMessage(
                f"Migrated {len(mig['migrated'])} draft file(s) to {dest}", 8000)

        parent = backup_parent if backup_parent is not None else os.path.dirname(O._app_dir())
        backups = O.discover_backup_sessions(parent, dest)
        dismissed = set()
        if not manual:
            try:
                dismissed.update(cfg_module.get_dismissed_backup_folders())
            except Exception:
                pass
            backups = [
                b for b in backups
                if b["backup_name"] not in dismissed
                and f"{b['backup_name']}::{b['session_id']}" not in dismissed
            ]
        elif not backups and ask_fn is None:
            QMessageBox.information(
                self,
                "Import drafts from COLD SNAP backup",
                "No unimported drafts were found in COLD SNAP backup folders.",
            )
            return False

        if backups:
            lines = []
            for b in backups[:10]:
                titles = ", ".join(b["draft_titles"][:3])
                if len(b["draft_titles"]) > 3:
                    titles += f" (+{len(b['draft_titles']) - 3} more)"
                lines.append(
                    f"• {b['backup_name']} → {b['session_name']} "
                    f"({b['draft_count']} draft(s): {titles})"
                )
            if len(backups) > 10:
                lines.append(f"• … and {len(backups) - 10} more session(s)")
            prompt = (
                "Found unimported drafts in previous COLD SNAP backup folder(s):\n\n"
                + "\n".join(lines)
                + "\n\nCopy these drafts into your persistent COLD SNAP library?\n"
                "(Originals in the backup folders are never modified or deleted.)"
            )
            if ask_fn is not None:
                confirmed = bool(ask_fn(prompt))
            else:
                ans = QMessageBox.question(
                    self,
                    "Import drafts from COLD SNAP backup?",
                    prompt,
                    QMessageBox.Yes | QMessageBox.No,
                    QMessageBox.Yes,
                )
                confirmed = (ans == QMessageBox.Yes)
            if confirmed:
                seen_roots = set()
                for b in backups:
                    sr = b["sessions_root"]
                    if sr in seen_roots:
                        continue
                    seen_roots.add(sr)
                    imp = O.migrate_sessions_dir(sr, dest, log_path=log_file, mark_imported=True)
                    if imp.get("migrated"):
                        changed = True
                if changed:
                    self.statusBar().showMessage(
                        "Imported drafts from COLD SNAP backup folder(s)", 8000)
            else:
                # Answering NO remembers the backup folder in config.ini so the
                # startup prompt never asks again for that folder unless re-run from the menu.
                new_keys = []
                for b in backups:
                    new_keys.append(b["backup_name"])
                    new_keys.append(f"{b['backup_name']}::{b['session_id']}")
                try:
                    cfg_module.add_dismissed_backup_folders(new_keys)
                except Exception:
                    pass

        if changed:
            self._refresh_session_rails()
        return changed

    def closeEvent(self, event):
        if not self.isMinimized():
            self._window_settings.setValue("window/maximized", self.isMaximized())
            if not self.isMaximized():
                self._window_settings.setValue("window/normal_geometry", self.saveGeometry())
            self._window_settings.sync()
        super().closeEvent(event)

# ===== SNAPSMACK EOF =====
