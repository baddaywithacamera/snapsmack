from __future__ import annotations

import json
import os
import socket
import subprocess
import sys
import urllib.request
from datetime import datetime
from pathlib import Path

from PySide6.QtCore import QLockFile, QTimer, Qt, Signal
from PySide6.QtGui import QAction, QColor, QFont, QIcon, QPainter, QPixmap
from PySide6.QtWidgets import (
    QApplication,
    QComboBox,
    QDialog,
    QDialogButtonBox,
    QFormLayout,
    QFrame,
    QHBoxLayout,
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

from .history import open_conversation_store
from .secret_config import public_mariadb_settings, save_mariadb_settings


ROOT = Path(os.environ.get("TROLL_BOOTH_HOME", Path.home() / ".troll-booth"))
AGREEMENT = Path(os.environ.get("TROLL_BOOTH_AGREEMENT", ROOT / "working-agreement.md"))
APPS = Path(os.environ.get("TROLL_BOOTH_APPS", ROOT / "apps.json"))
SERVER = os.environ.get("TROLL_BOOTH_SERVER", "https://trollbooth.photoblogs.fyi").rstrip("/")
WORKER_TOKEN = ROOT / "worker-token"


def _icon() -> QIcon:
    pixmap = QPixmap(64, 64)
    pixmap.fill(QColor("#0a0f0b"))
    painter = QPainter(pixmap)
    painter.setPen(QColor("#72ff48"))
    font = QFont("Segoe UI", 27, QFont.Weight.Black)
    painter.setFont(font)
    painter.drawText(pixmap.rect(), Qt.AlignmentFlag.AlignCenter, "TB")
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
        self.seen_message_ids: set[str] = set()
        self.unread_by_room: dict[str, int] = {}
        self.setWindowTitle("TROLL BOOTH")
        self.setWindowIcon(_icon())
        self.resize(1320, 820)
        self.setMinimumSize(960, 620)
        self._build_ui()
        self._build_tray()
        self._ensure_worker()
        self._prime_message_notifications()
        self.refresh_rooms()
        self.refresh_apps()
        self.refresh_status()
        self.timer = QTimer(self)
        self.timer.timeout.connect(self.refresh_visible_data)
        self.timer.start(2500)

    def _build_ui(self) -> None:
        root = QWidget()
        layout = QVBoxLayout(root)
        layout.setContentsMargins(0, 0, 0, 0)
        header = QFrame()
        header.setObjectName("header")
        header_layout = QHBoxLayout(header)
        brand = QLabel("TROLL BOOTH")
        brand.setObjectName("brand")
        self.status = QLabel("LOCAL · STARTING")
        self.status.setObjectName("status")
        quit_button = QPushButton("QUIT")
        quit_button.setToolTip("Close TROLL BOOTH completely")
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
        self.room_search.textChanged.connect(self.refresh_rooms)
        box.addWidget(self.room_search)
        self.rooms = QListWidget()
        self.rooms.currentItemChanged.connect(self._room_selected)
        box.addWidget(self.rooms, 1)
        new_room = QPushButton("+ NEW ROOM")
        new_room.clicked.connect(self.create_room)
        box.addWidget(new_room)
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
        send = QPushButton("POST AS SEAN")
        send.clicked.connect(self.post_message)
        box.addWidget(send, 0, Qt.AlignmentFlag.AlignRight)
        return panel

    def _control_panel(self) -> QWidget:
        panel = QWidget()
        box = QVBoxLayout(panel)
        tabs = QComboBox()
        tabs.addItems(["CONNECTED APPS", "LIVE AGENTS", "WORKING AGREEMENT", "AGENT / CLI ACCESS"])
        self.control_stack = QStackedWidget()
        tabs.currentIndexChanged.connect(self.control_stack.setCurrentIndex)
        box.addWidget(tabs)

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

        agents_page = QWidget()
        agents_box = QVBoxLayout(agents_page)
        agents_box.addWidget(QLabel("Authenticated agent activity"))
        self.agents_list = QListWidget()
        agents_box.addWidget(self.agents_list, 1)
        agents_box.addWidget(QLabel("LIVE means the agent used TROLL BOOTH within the last 90 seconds."))
        self.control_stack.addWidget(agents_page)

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
        access_box.addWidget(QLabel("Agent-facing interfaces"))
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
        self.pairing_code = QLineEdit()
        self.pairing_code.setReadOnly(True)
        self.pairing_code.setPlaceholderText("Choose an agent to create a five-minute pairing code")
        access_box.addWidget(self.pairing_code)
        pair_buttons = QHBoxLayout()
        pair_codex = QPushButton("CONNECT CODEX")
        pair_claude = QPushButton("CONNECT CLAUDE")
        pair_codex.clicked.connect(lambda: self.issue_pair_code("codex"))
        pair_claude.clicked.connect(lambda: self.issue_pair_code("claude"))
        pair_buttons.addWidget(pair_codex); pair_buttons.addWidget(pair_claude)
        access_box.addLayout(pair_buttons)
        open_data = QPushButton("OPEN LOCAL DATA FOLDER")
        open_data.clicked.connect(lambda: os.startfile(ROOT))
        access_box.addWidget(open_data)
        self.control_stack.addWidget(access_page)

        box.addWidget(self.control_stack, 1)
        return panel

    def _ensure_worker(self) -> None:
        if not WORKER_TOKEN.exists():
            return
        worker_id = f"{socket.gethostname().lower()}-desktop"
        packaged = Path(sys.executable).with_name("TROLL BOOTH WORKER.exe")
        if packaged.exists():
            command = [str(packaged)]
        else:
            command = [sys.executable, "-m", "troll_booth.worker"]
        command += ["--server", SERVER, "--token-file", str(WORKER_TOKEN), "--config", str(APPS), "--worker-id", worker_id]
        flags = 0
        if os.name == "nt":
            flags = subprocess.CREATE_NO_WINDOW | subprocess.DETACHED_PROCESS
        try:
            subprocess.Popen(command, close_fds=True, creationflags=flags)
        except OSError as exc:
            self.statusBar().showMessage(f"Worker could not start: {exc}", 8000)

    def issue_pair_code(self, actor: str) -> None:
        try:
            token = WORKER_TOKEN.read_text(encoding="utf-8").strip()
            payload = json.dumps({"actor": actor, "write": True}).encode("utf-8")
            request = urllib.request.Request(
                f"{SERVER}/owner/pair-code", data=payload,
                headers={"Authorization": f"Bearer {token}", "Content-Type": "application/json"}, method="POST",
            )
            with urllib.request.urlopen(request, timeout=15) as response:
                result = json.loads(response.read().decode("utf-8"))
            code = str(result["code"])
            self.pairing_code.setText(code)
            QApplication.clipboard().setText(code)
            self.statusBar().showMessage(f"{actor.title()} pairing code copied; it expires in five minutes", 8000)
        except Exception as exc:
            QMessageBox.critical(self, "Pairing failed", str(exc))

    def _build_tray(self) -> None:
        self.tray = QSystemTrayIcon(_icon(), self)
        menu = QMenu()
        show = QAction("Show TROLL BOOTH", self)
        show.triggered.connect(self.show_from_tray)
        quit_action = QAction("Quit", self)
        quit_action.triggered.connect(QApplication.instance().quit)
        menu.addAction(show)
        menu.addSeparator()
        menu.addAction(quit_action)
        self.tray.setContextMenu(menu)
        self.tray.activated.connect(lambda reason: self.show_from_tray() if reason == QSystemTrayIcon.ActivationReason.DoubleClick else None)
        self.tray.show()

    def closeEvent(self, event) -> None:  # noqa: N802
        event.ignore()
        self.hide()
        self.tray.showMessage("TROLL BOOTH", "Still running in the notification area.", QSystemTrayIcon.MessageIcon.Information, 2000)

    def show_from_tray(self) -> None:
        self.showNormal()
        self.raise_()
        self.activateWindow()

    def refresh_visible_data(self) -> None:
        self.notify_new_messages()
        self.refresh_status()
        if self.isVisible():
            self.refresh_rooms(preserve=True)
            self.refresh_messages()

    def refresh_status(self) -> None:
        count = sum(item["message_count"] for item in self.store.rooms())
        agents = self._remote_agents()
        live = [agent["actor"] for agent in agents if agent.get("online")]
        suffix = f" · LIVE: {', '.join(live)}" if live else " · NO LIVE AGENTS"
        self.status.setText(f"{self.storage_label} · READY · {count} MESSAGES{suffix}")
        if hasattr(self, "agents_list"):
            self.agents_list.clear()
            if not agents:
                self.agents_list.addItem("No authenticated agent activity yet")
            for agent in agents:
                state = "LIVE" if agent.get("online") else "AWAY"
                age = int(agent.get("age_seconds", 0))
                self.agents_list.addItem(f'{agent["actor"]}\n{state} · last activity {age}s ago')

    def _remote_agents(self) -> list[dict]:
        try:
            token = WORKER_TOKEN.read_text(encoding="utf-8").strip()
            request = urllib.request.Request(
                f"{SERVER}/owner/status", headers={"Authorization": f"Bearer {token}"}
            )
            with urllib.request.urlopen(request, timeout=2) as response:
                return list(json.loads(response.read().decode("utf-8")).get("agents", []))
        except Exception:
            return []

    def refresh_rooms(self, *_args, preserve: bool = False) -> None:
        selected = self.current_room if preserve else (self.current_room or "")
        needle = self.room_search.text().strip().lower() if hasattr(self, "room_search") else ""
        self.rooms.blockSignals(True)
        self.rooms.clear()
        chosen = None
        for room in self.store.rooms():
            if needle and needle not in room["title"].lower() and needle not in room["id"].lower():
                continue
            unread = self.unread_by_room.get(room["id"], 0)
            suffix = f" · {unread} NEW" if unread else ""
            item = QListWidgetItem(f'{room["title"]}\n{room["message_count"]} messages{suffix}')
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
            self.room_title.setText(current.text().splitlines()[0].upper())
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
            self.tray.showMessage(
                f"TROLL BOOTH · {message['sender']}",
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
        chunks = []
        for message in messages:
            stamp = datetime.fromtimestamp(message["created_at"]).strftime("%Y-%m-%d %H:%M")
            body = (message["body"].replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;").replace("\n", "<br>"))
            sender = message["sender"].replace("&", "&amp;").replace("<", "&lt;")
            chunks.append(f"<div class='message'><b>{sender}</b><span>{stamp}</span><p>{body}</p></div>")
        self.transcript.setHtml("".join(chunks) or "<p>No messages here yet.</p>")
        self.transcript.verticalScrollBar().setValue(self.transcript.verticalScrollBar().maximum())

    def post_message(self) -> None:
        body = self.composer.toPlainText().strip()
        if not body:
            return
        if not self.current_room:
            self.current_room = "general"
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
        QMessageBox.information(self, "Database connected", "TROLL BOOTH is now using the central MariaDB store.")


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
QSplitter::handle { background: #29432e; width: 1px; }
QTextBrowser { padding: 12px; }
"""


def main() -> None:
    app = QApplication(sys.argv)
    app.setApplicationName("TROLL BOOTH")
    app.setQuitOnLastWindowClosed(False)
    ROOT.mkdir(parents=True, exist_ok=True)
    instance_lock = QLockFile(str(ROOT / "desktop.lock"))
    instance_lock.setStaleLockTime(0)
    if not instance_lock.tryLock(100):
        return
    window = BoothWindow()
    window.show()
    raise SystemExit(app.exec())


if __name__ == "__main__":
    main()
