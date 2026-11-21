from __future__ import annotations

import json
import os
import sys
import time
import urllib.error
import urllib.request
from datetime import datetime
from pathlib import Path

from PySide6.QtCore import QEvent, QLockFile, QTimer, Qt, Signal
from PySide6.QtGui import QAction, QColor, QFont, QIcon, QPainter, QPixmap
from PySide6.QtWidgets import (
    QApplication,
    QCheckBox,
    QComboBox,
    QDialog,
    QDialogButtonBox,
    QFileDialog,
    QFormLayout,
    QFrame,
    QHBoxLayout,
    QInputDialog,
    QLabel,
    QLineEdit,
    QListWidget,
    QListWidgetItem,
    QMainWindow,
    QMenu,
    QMessageBox,
    QPlainTextEdit,
    QPushButton,
    QSplitter,
    QStackedWidget,
    QSystemTrayIcon,
    QTextBrowser,
    QVBoxLayout,
    QWidget,
)

from . import __version__
from .history import open_conversation_store
from .secret_config import public_mariadb_settings, save_mariadb_settings


ROOT = Path(os.environ.get("TROLL_BOOTH_HOME", Path.home() / ".troll-booth"))
AGREEMENT = Path(os.environ.get("TROLL_BOOTH_AGREEMENT", ROOT / "working-agreement.md"))
APPS = Path(os.environ.get("TROLL_BOOTH_APPS", ROOT / "apps.json"))
SERVER = os.environ.get("TROLL_BOOTH_SERVER", "https://trollbooth.photoblogs.fyi").rstrip("/")
WORKER_TOKEN_FILE = Path(os.environ.get("TROLL_BOOTH_WORKER_TOKEN_FILE", ROOT / "worker-token"))

PAIR_TTL_SECONDS = 300


def _icon() -> QIcon:
    pixmap = QPixmap(64, 64)
    pixmap.fill(QColor("#0a0f0b"))
    painter = QPainter(pixmap)
    painter.setPen(QColor("#72ff48"))
    font = QFont("Segoe UI", 27, QFont.Weight.Black)
    painter.setFont(font)
    painter.drawText(pixmap.rect(), Qt.AlignmentFlag.AlignCenter, "BT")
    painter.end()
    return QIcon(pixmap)


def _read(path: Path, default: str) -> str:
    try:
        return path.read_text(encoding="utf-8")
    except FileNotFoundError:
        return default


def _atomic_save(path: Path, text: str) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    if path.exists():
        backup = path.with_suffix(path.suffix + ".bak")
        backup.write_bytes(path.read_bytes())
    temporary = path.with_suffix(path.suffix + ".tmp")
    temporary.write_text(text, encoding="utf-8")
    os.replace(temporary, path)


class NewRoomDialog(QDialog):
    def __init__(self, parent: QWidget | None = None) -> None:
        super().__init__(parent)
        self.setWindowTitle("New room")
        self.room_id = QLineEdit()
        self.title = QLineEdit()
        form = QFormLayout(self)
        form.addRow("Room ID", self.room_id)
        form.addRow("Title", self.title)
        buttons = QDialogButtonBox(QDialogButtonBox.StandardButton.Ok | QDialogButtonBox.StandardButton.Cancel)
        buttons.accepted.connect(self.accept)
        buttons.rejected.connect(self.reject)
        form.addRow(buttons)


class BoothWindow(QMainWindow):
    def __init__(self) -> None:
        super().__init__()
        ROOT.mkdir(parents=True, exist_ok=True)
        self.store, self.storage_label = open_conversation_store()
        self.current_room = ""
        self._rendered_room = ""
        self._rendered_message_count = -1
        self._rendered_first_message_id = ""
        self._rendered_last_message_id = ""
        self._rendered_query = ""
        self.seen_message_ids: set[str] = set()
        self.unread_by_room: dict[str, int] = {}
        self.pair_expires_at: float = 0.0
        self._pair_active_code: str = ""
        self._pair_active_actor: str = ""
        self.setWindowTitle(f"BRAIN TRUST - build {__version__}")
        self.setWindowIcon(_icon())
        self.resize(1320, 820)
        self.setMinimumSize(960, 620)
        self._build_ui()
        self._build_tray()
        self._prime_message_notifications()
        self.refresh_rooms()
        self.refresh_apps()
        self.refresh_status()
        self.timer = QTimer(self)
        self.timer.timeout.connect(self.refresh_visible_data)
        self.timer.start(2500)
        self.pair_timer = QTimer(self)
        self.pair_timer.timeout.connect(self._tick_pair_countdown)
        self.pair_timer.start(1000)

    def _build_ui(self) -> None:
        root = QWidget()
        layout = QVBoxLayout(root)
        layout.setContentsMargins(0, 0, 0, 0)
        header = QFrame()
        header.setObjectName("header")
        header_layout = QHBoxLayout(header)
        brand = QLabel(f"BRAIN TRUST · v{__version__}")
        brand.setObjectName("brand")
        self.status = QLabel("LOCAL · STARTING")
        self.status.setObjectName("status")
        quit_button = QPushButton("QUIT")
        quit_button.setToolTip("Close BRAIN TRUST completely")
        quit_button.clicked.connect(QApplication.instance().quit)
        header_layout.addWidget(brand)
        header_layout.addStretch()
        header_layout.addWidget(self.status)
        header_layout.addWidget(quit_button)
        layout.addWidget(header)

        split = QSplitter(Qt.Orientation.Horizontal)
        split.addWidget(self._rooms_panel())
        split.addWidget(self._conversation_panel())
        split.addWidget(self._control_panel())
        split.setSizes([250, 650, 390])
        layout.addWidget(split, 1)
        self.setCentralWidget(root)
        self.setStyleSheet(STYLE)

    def _rooms_panel(self) -> QWidget:
        panel = QWidget()
        box = QVBoxLayout(panel)
        title = QLabel("ROOMS")
        title.setObjectName("sectionTitle")
        box.addWidget(title)
        self.room_search = QLineEdit()
        self.room_search.setPlaceholderText("Filter rooms")
        self.room_search.textChanged.connect(lambda: self.refresh_rooms(preserve=True))
        box.addWidget(self.room_search)
        filter_row = QHBoxLayout()
        self.show_archived_cb = QCheckBox("Show archived")
        self.show_archived_cb.toggled.connect(lambda: self.refresh_rooms(preserve=True))
        self.show_removed_cb = QCheckBox("Show removed")
        self.show_removed_cb.toggled.connect(lambda: self.refresh_rooms(preserve=True))
        filter_row.addWidget(self.show_archived_cb)
        filter_row.addWidget(self.show_removed_cb)
        box.addLayout(filter_row)
        self.rooms = QListWidget()
        self.rooms.setContextMenuPolicy(Qt.ContextMenuPolicy.CustomContextMenu)
        self.rooms.customContextMenuRequested.connect(self._show_room_context_menu)
        self.rooms.currentItemChanged.connect(self._room_selected)
        box.addWidget(self.rooms, 1)
        room_actions = QHBoxLayout()
        new_room = QPushButton("+ NEW ROOM")
        new_room.clicked.connect(self.create_room)
        self.room_menu_button = QPushButton("ROOM ACTIONS ▾")
        self.room_menu_button.clicked.connect(self._show_current_room_menu)
        room_actions.addWidget(new_room, 1)
        room_actions.addWidget(self.room_menu_button, 1)
        box.addLayout(room_actions)
        return panel

    def _conversation_panel(self) -> QWidget:
        panel = QWidget()
        box = QVBoxLayout(panel)
        top = QHBoxLayout()
        self.room_title = QLabel("NO ROOM SELECTED")
        self.room_title.setObjectName("sectionTitle")
        self.message_search = QLineEdit()
        self.message_search.setPlaceholderText("Search conversation")
        self.message_search.returnPressed.connect(self.refresh_messages)
        top.addWidget(self.room_title, 1)
        top.addWidget(self.message_search)
        box.addLayout(top)
        self.transcript = QTextBrowser()
        self.transcript.setOpenExternalLinks(False)
        box.addWidget(self.transcript, 1)
        self.composer = QPlainTextEdit()
        self.composer.setPlaceholderText("Write a request or message…")
        self.composer.setMaximumHeight(110)
        box.addWidget(self.composer)
        self.send_button = QPushButton("POST AS SEAN")
        self.send_button.clicked.connect(self.post_message)
        box.addWidget(self.send_button, 0, Qt.AlignmentFlag.AlignRight)
        return panel

    def _control_panel(self) -> QWidget:
        panel = QWidget()
        box = QVBoxLayout(panel)
        tabs = QComboBox()
        tabs.addItems(["CONNECTED APPS", "WORKING AGREEMENT", "AGENT CONNECTIONS"])
        self.control_stack = QStackedWidget()
        tabs.currentIndexChanged.connect(self.control_stack.setCurrentIndex)
        box.addWidget(tabs)
        self.control_tabs = tabs

        apps_page = QWidget()
        apps_box = QVBoxLayout(apps_page)
        self.apps_list = QListWidget()
        apps_box.addWidget(self.apps_list, 1)
        edit_apps = QPushButton("EDIT APP CONNECTIONS")
        edit_apps.clicked.connect(self.edit_apps)
        apps_box.addWidget(edit_apps)
        database = QPushButton("DATABASE CONNECTION")
        database.clicked.connect(self.configure_database)
        apps_box.addWidget(database)
        self.control_stack.addWidget(apps_page)

        agreement_page = QWidget()
        agreement_box = QVBoxLayout(agreement_page)
        self.agreement = QPlainTextEdit(_read(AGREEMENT, "# Working Agreement\n\nRequests, boundaries, and collaboration preferences live here.\n"))
        agreement_box.addWidget(self.agreement, 1)
        save_agreement = QPushButton("SAVE AGREEMENT")
        save_agreement.clicked.connect(self.save_agreement)
        agreement_box.addWidget(save_agreement)
        self.control_stack.addWidget(agreement_page)

        access_page = QWidget()
        access_box = QVBoxLayout(access_page)
        access_box.addWidget(QLabel("Pair Claude, Codex, Muse, Gemini, or another MCP agent"))
        self.pair_actor = QComboBox()
        self.pair_actor.setEditable(True)
        # gemini is Google Antigravity, the seat Sean approved on 2026-11-16:
        # read room history, post, and read/claim/release work scopes. The
        # combo stays editable, so this is discoverability, not enforcement --
        # the server decides what any actor may do, and refuses a name it does
        # not know.
        self.pair_actor.addItems(["claude", "codex", "muse", "gemini"])
        self.pair_actor.activated.connect(self._on_pair_actor_selected)
        self.pair_actor.currentTextChanged.connect(self._on_pair_actor_text_changed)
        access_box.addWidget(self.pair_actor)
        self.pair_button = QPushButton("REGENERATE 5-MINUTE PAIRING CODE")
        self.pair_button.clicked.connect(self.generate_pair_code)
        access_box.addWidget(self.pair_button)
        self.pair_code = QLineEdit()
        self.pair_code.setReadOnly(True)
        self.pair_code.setPlaceholderText("Select an agent to generate a 5-minute pairing code")
        access_box.addWidget(self.pair_code)
        self.pair_countdown = QLabel("No active pairing code. Select an agent or click Regenerate.")
        self.pair_countdown.setObjectName("status")
        access_box.addWidget(self.pair_countdown)
        cli = QPlainTextEdit()
        cli.setReadOnly(True)
        cli.setPlainText(
            "MCP: https://trollbooth.photoblogs.fyi/mcp\n\n"
            "Local CLI:\n"
            "troll-booth history rooms\n"
            "troll-booth history messages --room ROOM\n"
            "troll-booth history search QUERY\n"
            "troll-booth history add --room ROOM --sender AGENT --body MESSAGE\n\n"
            "Wait for another agent:\n"
            "troll-booth history watch --actor AGENT --room ROOM --timeout 300\n\n"
            "Coordinate work:\n"
            "troll-booth history claim --actor AGENT --scope PATH --task TASK\n"
            "troll-booth history release --actor AGENT --scope PATH\n\n"
            "The CLI and MCP broker use declared capabilities only. No general shell is exposed."
        )
        access_box.addWidget(cli, 1)
        open_data = QPushButton("OPEN LOCAL DATA FOLDER")
        open_data.clicked.connect(lambda: os.startfile(ROOT))
        access_box.addWidget(open_data)
        self.control_stack.addWidget(access_page)

        box.addWidget(self.control_stack, 1)
        return panel

    # -- SS-1475: Pairing code lifecycle (blank + invalidate + auto-gen + countdown) --

    def _worker_token(self) -> str:
        token = os.environ.get("TROLL_BOOTH_WORKER_TOKEN", "").strip()
        if not token:
            try:
                token = WORKER_TOKEN_FILE.read_text(encoding="utf-8").strip()
            except FileNotFoundError:
                token = ""
        return token

    def _invalidate_active_pair_code(self) -> None:
        """SS-1475: Blank current pairing code in UI and invalidate it server-side."""
        self.pair_code.clear()
        self.pair_expires_at = 0.0
        self._pair_active_code = ""
        self._pair_active_actor = ""
        token = self._worker_token()
        if not token:
            return
        payload = json.dumps({"invalidate": True}).encode("utf-8")
        request = urllib.request.Request(
            f"{SERVER}/owner/pair-code",
            data=payload,
            headers={"Authorization": f"Bearer {token}", "Content-Type": "application/json"},
            method="POST",
        )
        try:
            with urllib.request.urlopen(request, timeout=5):
                pass
        except Exception:
            pass

    def _on_pair_actor_selected(self, _index: int) -> None:
        """SS-1475: Selecting an agent blanks & invalidates the old code and
        automatically generates a fresh 5-minute single-use code."""
        self.generate_pair_code()

    def _on_pair_actor_text_changed(self, text: str) -> None:
        """If the actor text is edited after a code was shown, blank it immediately
        so a code for one agent is never displayed next to another agent's name."""
        if self._pair_active_code and text.strip().lower() != self._pair_active_actor:
            self.pair_code.clear()
            self.pair_expires_at = 0.0
            self._pair_active_code = ""
            self._pair_active_actor = ""
            self.pair_countdown.setText("Agent changed — previous code cleared.")

    def generate_pair_code(self) -> None:
        actor = self.pair_actor.currentText().strip().lower()
        self._invalidate_active_pair_code()
        if not actor:
            self.pair_countdown.setText("Choose an agent first.")
            return
        token = self._worker_token()
        if not token:
            self.pair_countdown.setText("Worker token not configured.")
            QMessageBox.warning(
                self,
                "Pairing is not configured",
                f"BRAIN TRUST needs its worker token at {WORKER_TOKEN_FILE} before it can create pairing codes.",
            )
            return
        payload = json.dumps({"actor": actor, "write": True}).encode("utf-8")
        request = urllib.request.Request(
            f"{SERVER}/owner/pair-code",
            data=payload,
            headers={"Authorization": f"Bearer {token}", "Content-Type": "application/json"},
            method="POST",
        )
        try:
            with urllib.request.urlopen(request, timeout=15) as response:
                result = json.loads(response.read().decode("utf-8"))
        except (OSError, ValueError, urllib.error.URLError) as exc:
            self.pair_countdown.setText("Pairing code request failed.")
            QMessageBox.critical(self, "Pairing failed", f"Could not reach the BRAIN TRUST connector: {exc}")
            return
        code = str(result["code"])
        ttl = int(result.get("expires_in") or PAIR_TTL_SECONDS)
        self._pair_active_code = code
        self._pair_active_actor = actor
        self.pair_expires_at = time.monotonic() + ttl
        self.pair_code.setText(code)
        self.pair_code.selectAll()
        self.pair_code.setFocus()
        self._tick_pair_countdown()

    def _tick_pair_countdown(self) -> None:
        if not self._pair_active_code or self.pair_expires_at <= 0:
            return
        remaining = int(round(self.pair_expires_at - time.monotonic()))
        if remaining <= 0:
            self._invalidate_active_pair_code()
            self.pair_code.setPlaceholderText("Pairing code expired — select agent or click Regenerate")
            self.pair_countdown.setText("Code expired and cleared.")
            return
        mins, secs = divmod(remaining, 60)
        actor = self.pair_actor.currentText().strip().lower()
        self.pair_countdown.setText(
            f"Single-use code for {actor} expires in {mins}:{secs:02d} — select to copy."
        )

    def mark_pair_code_consumed(self) -> None:
        """SS-1475: Once an agent pairs with the active code, clear it from screen."""
        self.pair_code.clear()
        self.pair_expires_at = 0.0
        self._pair_active_code = ""
        self.pair_code.setPlaceholderText("Pairing code consumed (single-use)")
        self.pair_countdown.setText("Code used — single-use code spent and cleared.")

    # -- SS-1474: Per-room management (rename, archive, soft remove, export text/CSV) --

    def _room_meta(self, room_id: str) -> dict | None:
        if hasattr(self.store, "room_info"):
            return self.store.room_info(room_id)
        for r in self.store.rooms(include_archived=True, include_removed=True):
            if r["id"] == room_id:
                return r
        return None

    def _build_room_menu(self, room_id: str) -> QMenu:
        menu = QMenu(self)
        meta = self._room_meta(room_id) or {"id": room_id, "title": room_id}
        is_archived = bool(meta.get("archived_at"))
        is_removed = bool(meta.get("removed_at"))

        rename_act = QAction("Rename display title…", menu)
        rename_act.triggered.connect(lambda: self.rename_room_dialog(room_id))
        menu.addAction(rename_act)

        arch_label = "Restore from archive" if is_archived else "Archive room (read-only)"
        arch_act = QAction(arch_label, menu)
        arch_act.triggered.connect(lambda: self.toggle_archive_room(room_id, not is_archived))
        menu.addAction(arch_act)

        rem_label = "Restore removed room" if is_removed else "Remove room (soft hide, keeps hash chain)"
        rem_act = QAction(rem_label, menu)
        rem_act.triggered.connect(lambda: self.toggle_remove_room(room_id, not is_removed))
        menu.addAction(rem_act)

        menu.addSeparator()
        exp_txt = QAction("Export room as Text (.txt)…", menu)
        exp_txt.triggered.connect(lambda: self.export_room_dialog(room_id, fmt="text"))
        menu.addAction(exp_txt)

        exp_csv = QAction("Export room as CSV (.csv)…", menu)
        exp_csv.triggered.connect(lambda: self.export_room_dialog(room_id, fmt="csv"))
        menu.addAction(exp_csv)
        return menu

    def _show_room_context_menu(self, pos) -> None:
        item = self.rooms.itemAt(pos)
        if not item:
            return
        room_id = str(item.data(Qt.ItemDataRole.UserRole))
        menu = self._build_room_menu(room_id)
        menu.exec(self.rooms.viewport().mapToGlobal(pos))

    def _show_current_room_menu(self) -> None:
        room_id = self.current_room
        if not room_id:
            return
        menu = self._build_room_menu(room_id)
        menu.exec(self.room_menu_button.mapToGlobal(self.room_menu_button.rect().bottomLeft()))

    def rename_room_dialog(self, room_id: str) -> None:
        meta = self._room_meta(room_id) or {"id": room_id, "title": room_id}
        new_title, ok = QInputDialog.getText(
            self,
            "Rename room",
            f"Display name for room '{room_id}' (room_id stays unchanged):",
            text=str(meta.get("title") or room_id),
        )
        if not ok or not new_title.strip():
            return
        self.store.rename_room(room_id, new_title.strip())
        self.refresh_rooms(preserve=True)
        if self.current_room == room_id:
            self._update_room_header(room_id)

    def toggle_archive_room(self, room_id: str, archived: bool) -> None:
        self.store.archive_room(room_id, archived)
        self.refresh_rooms(preserve=True)
        if self.current_room == room_id:
            self._update_room_header(room_id)

    def toggle_remove_room(self, room_id: str, removed: bool) -> None:
        if removed:
            answer = QMessageBox.question(
                self,
                "Remove room (soft hide)",
                f"Hide room '{room_id}' from the room list?\n\n"
                "All messages and their cryptographic hash chain remain intact in the database "
                "and can be restored at any time via 'Show removed'.",
                QMessageBox.StandardButton.Yes | QMessageBox.StandardButton.Cancel,
            )
            if answer != QMessageBox.StandardButton.Yes:
                return
        self.store.soft_remove_room(room_id, removed)
        if removed and self.current_room == room_id and not self.show_removed_cb.isChecked():
            self.current_room = ""
        self.refresh_rooms(preserve=True)
        self.refresh_messages()

    def export_room_dialog(self, room_id: str, *, fmt: str) -> None:
        warn = QMessageBox.warning(
            self,
            "Security notice before export",
            "This room may contain unfixed security or DOOR OPEN details.\n\n"
            "Do not share or publish exported files outside authorized review.\n"
            "Continue with export?",
            QMessageBox.StandardButton.Yes | QMessageBox.StandardButton.Cancel,
        )
        if warn != QMessageBox.StandardButton.Yes:
            return
        ext = "csv" if fmt == "csv" else "txt"
        filt = "CSV files (*.csv)" if fmt == "csv" else "Text files (*.txt)"
        default_name = str(ROOT / f"{room_id}-export.{ext}")
        target, _ = QFileDialog.getSaveFileName(self, f"Export room '{room_id}'", default_name, filt)
        if not target:
            return
        content = self.store.export_csv(room_id) if fmt == "csv" else self.store.export_text(room_id)
        Path(target).write_text(content, encoding="utf-8", newline="")
        self.statusBar().showMessage(f"Exported {room_id} to {target}", 4000)

    def _update_room_header(self, room_id: str) -> None:
        meta = self._room_meta(room_id) or {"id": room_id, "title": room_id}
        title = str(meta.get("title") or room_id).upper()
        is_archived = bool(meta.get("archived_at"))
        is_removed = bool(meta.get("removed_at"))
        badges = []
        if is_archived:
            badges.append("ARCHIVED · READ-ONLY")
        if is_removed:
            badges.append("REMOVED")
        suffix = f"  [{', '.join(badges)}]" if badges else ""
        self.room_title.setText(f"{title}{suffix}")
        read_only = is_archived or is_removed
        self.composer.setReadOnly(read_only)
        self.send_button.setEnabled(not read_only)
        self.composer.setPlaceholderText(
            "Room is archived/read-only — restore it from Room Actions to post."
            if read_only
            else "Write a request or message…"
        )

    def _build_tray(self) -> None:
        # The notification area is not always there -- it can be missing or
        # not yet started at login. Qt returns a QSystemTrayIcon that simply
        # never appears, and with setQuitOnLastWindowClosed(False) closing the
        # window then hides it to nowhere: running, invisible, unreachable.
        self.tray = None
        if not QSystemTrayIcon.isSystemTrayAvailable():
            return
        self.tray = QSystemTrayIcon(_icon(), self)
        menu = QMenu()
        show = QAction("Show BRAIN TRUST", self)
        show.triggered.connect(self.show_from_tray)
        quit_action = QAction("Quit", self)
        quit_action.triggered.connect(QApplication.instance().quit)
        menu.addAction(show)
        menu.addSeparator()
        menu.addAction(quit_action)
        self.tray.setContextMenu(menu)
        self.tray.activated.connect(self._tray_activated)
        self.tray.show()

    def _tray_activated(self, reason) -> None:
        # Windows normally reports a single left-click as Trigger. Requiring a
        # double-click left a minimized BRAIN TRUST running but apparently
        # unreachable from its notification-area icon.
        if reason in (
            QSystemTrayIcon.ActivationReason.Trigger,
            QSystemTrayIcon.ActivationReason.DoubleClick,
        ):
            self.show_from_tray()

    def closeEvent(self, event) -> None:  # noqa: N802
        if self.tray is None:
            # No notification area to minimise into, so closing means closing.
            event.accept()
            QApplication.instance().quit()
            return
        event.ignore()
        self.hide()
        self.tray.showMessage("BRAIN TRUST", "Still running in the notification area.", QSystemTrayIcon.MessageIcon.Information, 2000)

    def show_from_tray(self) -> None:
        if self.isMinimized():
            self.setWindowState(self.windowState() & ~Qt.WindowState.WindowMinimized)
        self.showNormal()
        self.show()
        self.raise_()
        self.activateWindow()

    def changeEvent(self, event) -> None:
        super().changeEvent(event)
        if event.type() == QEvent.Type.ActivationChange and self.isActiveWindow():
            self.refresh_visible_data()

    def refresh_visible_data(self) -> None:
        self.notify_new_messages()
        self.refresh_status()
        if self.isVisible():
            self.refresh_rooms(preserve=True)
            self.refresh_messages()

    def refresh_status(self) -> None:
        count = sum(item["message_count"] for item in self.store.rooms())
        self.status.setText(f"{self.storage_label} · READY · {count} MESSAGES")

    def refresh_rooms(self, *_args, preserve: bool = False) -> None:
        selected = self.current_room if preserve else (self.current_room or "")
        needle = self.room_search.text().strip().lower() if hasattr(self, "room_search") else ""
        inc_arch = hasattr(self, "show_archived_cb") and self.show_archived_cb.isChecked()
        inc_rem = hasattr(self, "show_removed_cb") and self.show_removed_cb.isChecked()
        self.rooms.blockSignals(True)
        self.rooms.clear()
        chosen = None
        try:
            room_rows = self.store.rooms(include_archived=inc_arch, include_removed=inc_rem)
        except TypeError:
            room_rows = self.store.rooms()
        for room in room_rows:
            if needle and needle not in room["title"].lower() and needle not in room["id"].lower():
                continue
            unread = self.unread_by_room.get(room["id"], 0)
            suffix = f" · {unread} NEW" if unread else ""
            flags = []
            if room.get("archived_at"):
                flags.append("ARCHIVED")
            if room.get("removed_at"):
                flags.append("REMOVED")
            flag_str = f" [{', '.join(flags)}]" if flags else ""
            item = QListWidgetItem(f'{room["title"]}{flag_str}\n{room["message_count"]} messages{suffix}')
            item.setData(Qt.ItemDataRole.UserRole, room["id"])
            self.rooms.addItem(item)
            if room["id"] == selected:
                chosen = item
        self.rooms.blockSignals(False)
        if chosen:
            self.rooms.setCurrentItem(chosen)
        elif self.rooms.count() and not self.current_room:
            self.rooms.setCurrentRow(0)

    def _room_selected(self, current: QListWidgetItem | None, _previous: QListWidgetItem | None) -> None:
        if current:
            self.current_room = str(current.data(Qt.ItemDataRole.UserRole))
            self.unread_by_room.pop(self.current_room, None)
            self._update_room_header(self.current_room)
            self.refresh_messages()

    def _prime_message_notifications(self) -> None:
        for room in self.store.rooms():
            for message in self.store.messages(room["id"], 1000):
                self.seen_message_ids.add(message["id"])

    def notify_new_messages(self) -> None:
        notices = []
        for room in self.store.rooms():
            for message in self.store.messages(room["id"], 100):
                if message["id"] in self.seen_message_ids:
                    continue
                self.seen_message_ids.add(message["id"])
                if message["sender"] == "sean":
                    continue
                notices.append((room, message))
                if room["id"] != self.current_room or not self.isVisible():
                    self.unread_by_room[room["id"]] = self.unread_by_room.get(room["id"], 0) + 1
        if notices:
            room, message = notices[-1]
            preview = str(message["body"]).replace("\n", " ")[:180]
            if self.tray is not None:
                self.tray.showMessage(
                    f"BRAIN TRUST · {message['sender']}",
                    f"{room['title']}: {preview}",
                    QSystemTrayIcon.MessageIcon.Information,
                    8000,
                )

    def refresh_messages(self) -> None:
        if not self.current_room:
            self.transcript.setHtml("<p>No room selected.</p>")
            return
        query = self.message_search.text().strip()
        try:
            messages = self.store.search(query, self.current_room, 200) if query else self.store.messages(self.current_room, 500)
        except Exception as exc:
            self.transcript.setHtml(f"<p>Search error: {str(exc)}</p>")
            return
        scrollbar = self.transcript.verticalScrollBar()
        room_changed = self._rendered_room != self.current_room
        was_at_bottom = scrollbar.value() >= scrollbar.maximum() - 4
        previous_value = scrollbar.value()
        message_count = len(messages)
        first_message_id = str(messages[0]["id"]) if messages else ""
        last_message_id = str(messages[-1]["id"]) if messages else ""
        if (
            not room_changed
            and query == self._rendered_query
            and message_count == self._rendered_message_count
            and first_message_id == self._rendered_first_message_id
            and last_message_id == self._rendered_last_message_id
        ):
            return
        chunks = []
        for message in messages:
            stamp = datetime.fromtimestamp(message["created_at"]).strftime("%Y-%m-%d %H:%M")
            body = (message["body"].replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;").replace("\n", "<br>"))
            sender = message["sender"].replace("&", "&amp;").replace("<", "&lt;")
            chunks.append(f"<div class='message'><b>{sender}</b><span>{stamp}</span><p>{body}</p></div>")
        self.transcript.setHtml("".join(chunks) or "<p>No messages here yet.</p>")
        self._rendered_room = self.current_room
        self._rendered_message_count = message_count
        self._rendered_first_message_id = first_message_id
        self._rendered_last_message_id = last_message_id
        self._rendered_query = query
        if room_changed or was_at_bottom:
            # QTextBrowser finalises its scrollbar range on the next event-loop
            # turn. Setting maximum synchronously uses the old range and strands
            # long rooms partway up the transcript.
            QTimer.singleShot(0, self._scroll_transcript_to_bottom)
        else:
            QTimer.singleShot(0, lambda value=previous_value: self.transcript.verticalScrollBar().setValue(value))

    def _scroll_transcript_to_bottom(self) -> None:
        scrollbar = self.transcript.verticalScrollBar()
        scrollbar.setValue(scrollbar.maximum())

    def post_message(self) -> None:
        body = self.composer.toPlainText().strip()
        if not body:
            return
        if not self.current_room:
            self.current_room = "general"
        meta = self._room_meta(self.current_room)
        if meta and (meta.get("archived_at") or meta.get("removed_at")):
            QMessageBox.information(
                self,
                "Room is read-only",
                "This room is archived or removed. Restore it from Room Actions before posting.",
            )
            return
        self.store.append(self.current_room, "sean", body, title=self.current_room)
        self.composer.clear()
        self.refresh_rooms(preserve=True)
        self.refresh_messages()

    def create_room(self) -> None:
        dialog = NewRoomDialog(self)
        if dialog.exec() != QDialog.DialogCode.Accepted:
            return
        room_id = dialog.room_id.text().strip().lower().replace(" ", "-")
        if not room_id:
            return
        self.store.ensure_room(room_id, dialog.title.text().strip() or room_id)
        self.current_room = room_id
        self.refresh_rooms(preserve=True)

    def save_agreement(self) -> None:
        _atomic_save(AGREEMENT, self.agreement.toPlainText())
        self.statusBar().showMessage("Working agreement saved", 3000)

    def refresh_apps(self) -> None:
        self.apps_list.clear()
        try:
            data = json.loads(_read(APPS, '{"apps": []}'))
            apps = data.get("apps", [])
        except (json.JSONDecodeError, AttributeError):
            apps = []
        if not apps:
            self.apps_list.addItem("No app connectors configured")
            return
        for app in apps:
            command = app.get("capabilities_command", [])
            executable = Path(command[0]) if command else None
            state = "CONFIGURED" if command and (executable.exists() or executable.name == command[0]) else "CHECK PATH"
            self.apps_list.addItem(f'{app.get("name", app.get("slug", "Unknown"))}\n{state}')

    def edit_apps(self) -> None:
        dialog = QDialog(self)
        dialog.setWindowTitle("App connections")
        dialog.resize(720, 540)
        box = QVBoxLayout(dialog)
        box.addWidget(QLabel("Declared CLI capability connections (JSON). No passwords or tokens."))
        editor = QPlainTextEdit(_read(APPS, '{\n  "apps": []\n}\n'))
        box.addWidget(editor, 1)
        buttons = QDialogButtonBox(QDialogButtonBox.StandardButton.Save | QDialogButtonBox.StandardButton.Cancel)
        box.addWidget(buttons)
        buttons.rejected.connect(dialog.reject)

        def save() -> None:
            try:
                parsed = json.loads(editor.toPlainText())
                if not isinstance(parsed, dict) or not isinstance(parsed.get("apps"), list):
                    raise ValueError("The configuration must contain an apps array.")
                _atomic_save(APPS, json.dumps(parsed, indent=2, ensure_ascii=False) + "\n")
            except (ValueError, json.JSONDecodeError) as exc:
                QMessageBox.warning(dialog, "Invalid configuration", str(exc))
                return
            dialog.accept()

        buttons.accepted.connect(save)
        if dialog.exec() == QDialog.DialogCode.Accepted:
            self.refresh_apps()

    def configure_database(self) -> None:
        current = public_mariadb_settings()
        dialog = QDialog(self)
        dialog.setWindowTitle("MariaDB connection")
        form = QFormLayout(dialog)
        host = QLineEdit(str(current.get("host") or "192.168.1.20"))
        port = QLineEdit(str(current.get("port") or 3306))
        database = QLineEdit(str(current.get("database") or "troll_booth"))
        user = QLineEdit(str(current.get("user") or "trollbooth"))
        password = QLineEdit(); password.setEchoMode(QLineEdit.EchoMode.Password)
        form.addRow("Host", host); form.addRow("Port", port); form.addRow("Database", database)
        form.addRow("User", user); form.addRow("Password", password)
        note = QLabel("The password is encrypted for this Windows account and is never written to Git.")
        note.setWordWrap(True); form.addRow(note)
        buttons = QDialogButtonBox(QDialogButtonBox.StandardButton.Save | QDialogButtonBox.StandardButton.Cancel)
        form.addRow(buttons); buttons.rejected.connect(dialog.reject); buttons.accepted.connect(dialog.accept)
        if dialog.exec() != QDialog.DialogCode.Accepted:
            return
        try:
            save_mariadb_settings(host.text().strip(), int(port.text()), database.text().strip(), user.text().strip(), password.text())
            new_store, label = open_conversation_store(allow_offline_cache=False)
        except Exception as exc:
            QMessageBox.critical(self, "Database connection failed", str(exc))
            return
        self.store, self.storage_label = new_store, label
        self.refresh_rooms(); self.refresh_status()
        QMessageBox.information(self, "Database connected", "BRAIN TRUST is now using the central MariaDB store.")


STYLE = """
QWidget { background: #0a0f0b; color: #edf7ee; font: 10.5pt 'Segoe UI'; }
QFrame#header { background: #101b13; border-bottom: 1px solid #29432e; }
QLabel#brand { color: #72ff48; font-size: 18pt; font-weight: 900; letter-spacing: 2px; }
QLabel#status { color: #93a894; font-size: 9pt; }
QLabel#sectionTitle { color: #72ff48; font-weight: 800; letter-spacing: 1px; }
QLineEdit, QPlainTextEdit, QTextBrowser, QListWidget, QComboBox {
  background: #071009; border: 1px solid #29432e; border-radius: 5px; padding: 7px;
  selection-background-color: #315b31;
}
QListWidget::item { padding: 10px; border-bottom: 1px solid #182a1d; }
QListWidget::item:selected { background: #15261a; color: #72ff48; }
QPushButton { background: #72ff48; color: #071009; border: 0; border-radius: 5px; padding: 9px 14px; font-weight: 800; }
QPushButton:hover { background: #94ff73; }
QPushButton:disabled { background: #263328; color: #738075; }
QCheckBox { color: #93a894; font-size: 9pt; }
QSplitter::handle { background: #29432e; width: 1px; }
QTextBrowser { padding: 12px; }
"""


def main() -> None:
    app = QApplication(sys.argv)
    app.setApplicationName("BRAIN TRUST")
    app.setWindowIcon(_icon())
    app.setQuitOnLastWindowClosed(False)
    ROOT.mkdir(parents=True, exist_ok=True)
    instance_lock = QLockFile(str(ROOT / "desktop.lock"))
    # A second launch used to end at a bare `return`: no window, no tray
    # icon, no message. Sean launched TROLL BOOTH, nothing appeared, and
    # nothing said why.
    #
    # Measured before changing anything, because the obvious story was wrong:
    # Qt DOES reclaim a lock whose owning process has died, even with
    # setStaleLockTime(0) -- a child process was given the lock and hard
    # killed, and the next tryLock succeeded. So a leftover desktop.lock is
    # not what blocks a launch. A genuinely running instance is, which is the
    # case to report rather than swallow. The stale handling below stays for
    # the cases Qt cannot detect, such as a reused PID.
    instance_lock.setStaleLockTime(30_000)
    if not instance_lock.tryLock(250):
        instance_lock.removeStaleLockFile()
        if not instance_lock.tryLock(250):
            pid, host, appname = instance_lock.getLockInfo()
            where = f" (process {pid} on {host})" if pid else ""
            QMessageBox.information(
                None,
                "BRAIN TRUST",
                "BRAIN TRUST is already running" + where + ".\n\n"
                "Look for it in the notification area. If it is not there, "
                "quit it from Task Manager and start it again.",
            )
            return
    window = BoothWindow()
    window.show()
    raise SystemExit(app.exec())


if __name__ == "__main__":
    main()

# ===== SNAPSMACK EOF =====
