"""SMACK UP YOUR BACKUP — shared Qt look and helpers for every page module.

Each page of the Qt window lives in its own suyb_qt_<page>.py file and builds
on this module so the pages look and behave as one application.

PAGE CONTRACT (what suyb_qt.SuybWindow gives a page, and what it expects back)
-----------------------------------------------------------------------------
A page is a QWidget subclass constructed as `SomePage(window)`. It must build
itself with `make_page()` so it scrolls like the others, and must NEVER start
network or disk work in __init__ (the window builds every page at startup).

The window provides:
    window.current_profile          dict | None  — the site picked in the header
    window.profileChanged           Signal(object) — emitted with the new profile
    window.global_cloud()           dict — cloud_provider / cloud_credentials_file /
                                    cloud_folder_id, read from config.ini
    window.reload_profiles(name="") re-read profiles from disk, optionally select one
    window.busy()                   True while a backup or restore owns the engine
    window.run_backup_for(names, force_full=False, unattended=False)
                                    start a backup of these site names on the
                                    Overview page; returns False if one is already
                                    running. unattended=True is the scheduler's
                                    silent run: no prompts, skipped when busy.
    window.notify(title, message, critical=False)  tray balloon (no-op without tray)

The page may define (all optional):
    refresh()        called every time the page is shown
    help_topics()    list of (title, text) merged into the F1 help window
    shutdown()       called on quit; stop worker threads, return quickly

Threading: engines run on plain threads. NEVER touch a widget from a worker
thread. Emit a Signal (see Relay / run_in_thread) and update widgets in the
slot, which Qt runs on the main thread.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
"""

import threading

from PySide6.QtCore import QEvent, QObject, Qt, QTimer, Signal
from PySide6.QtWidgets import (
    QFrame, QLabel, QMessageBox, QScrollArea, QSizePolicy, QVBoxLayout, QWidget,
)


INK = "#f4f7f2"
BODY = "#bac3b8"
DIM = "#778176"
VOID = "#090c0a"
BASE = "#0d120f"
PANEL = "#121a15"
CARD = "#18231c"
BORDER = "#28372d"
GREEN = "#73f04b"
GREEN_DARK = "#3ba525"
AMBER = "#ffbf47"
RED = "#ff6b6b"

# Object names a page may use on its widgets (styled below, never inline):
#   QLabel:      Eyebrow, Title, PageTitle, CardTitle, Muted, StatusGood,
#                StatusWarn, StatusBad
#   QPushButton: Primary (the one loud action on a page), Danger (destructive),
#                Nav (sidebar only)
#   QFrame:      Card
STYLE = f"""
QWidget {{ background: {BASE}; color: {INK}; font-family: 'Segoe UI'; font-size: 14px; }}
QMainWindow {{ background: {VOID}; }}
QFrame#Sidebar {{ background: {VOID}; border-right: 1px solid {BORDER}; }}
QFrame#Header {{ background: {PANEL}; border-bottom: 1px solid {BORDER}; }}
QFrame#Card {{ background: {CARD}; border: 1px solid {BORDER}; border-radius: 12px; }}
QLabel#Eyebrow {{ color: {GREEN}; font-size: 11px; font-weight: 800; letter-spacing: 2px; }}
QLabel#Title {{ color: {INK}; font-size: 29px; font-weight: 750; }}
QLabel#Brand {{ color: {INK}; font-size: 20px; font-weight: 850; }}
QLabel#PageTitle {{ color: {INK}; font-size: 24px; font-weight: 700; }}
QLabel#CardTitle {{ color: {INK}; font-size: 16px; font-weight: 700; }}
QLabel#Muted {{ color: {DIM}; }}
QLabel#StatusGood {{ color: {GREEN}; background: #142611; border: 1px solid #315d25; border-radius: 10px; padding: 5px 10px; font-weight: 700; }}
QLabel#StatusWarn {{ color: {AMBER}; background: #271f0f; border: 1px solid #5f4821; border-radius: 10px; padding: 5px 10px; font-weight: 700; }}
QLabel#StatusBad {{ color: {RED}; background: #2a1414; border: 1px solid #6b2a2a; border-radius: 10px; padding: 5px 10px; font-weight: 700; }}
QPushButton {{ background: #202c24; border: 1px solid #34463a; border-radius: 8px; padding: 9px 14px; font-weight: 600; }}
QPushButton:hover {{ border-color: {GREEN_DARK}; background: #26372b; }}
QPushButton:disabled {{ color: #596259; background: #151a16; border-color: #232a24; }}
QPushButton#Primary {{ color: #071006; background: {GREEN}; border-color: {GREEN}; font-weight: 800; padding: 11px 18px; }}
QPushButton#Primary:hover {{ background: #8cff65; }}
QPushButton#Primary:disabled {{ color: #596259; background: #151a16; border-color: #232a24; }}
QPushButton#Danger {{ color: {RED}; border-color: #6b2a2a; }}
QPushButton#Danger:hover {{ background: #2a1414; border-color: {RED}; }}
QPushButton#Nav {{ text-align: left; background: transparent; border: 0; color: {BODY}; padding: 11px 14px; }}
QPushButton#Nav:checked {{ color: {GREEN}; background: #152219; border-left: 3px solid {GREEN}; }}
QLineEdit, QComboBox, QTextEdit, QPlainTextEdit, QListWidget, QTreeWidget, QTableWidget, QSpinBox, QTimeEdit, QDateEdit {{ background: #0c110e; border: 1px solid {BORDER}; border-radius: 7px; padding: 8px; selection-background-color: {GREEN_DARK}; }}
QLineEdit:focus, QComboBox:focus, QTextEdit:focus, QPlainTextEdit:focus, QSpinBox:focus {{ border-color: {GREEN}; }}
QHeaderView::section {{ background: {PANEL}; color: {BODY}; border: 0; border-bottom: 1px solid {BORDER}; padding: 6px 8px; font-weight: 700; }}
QProgressBar {{ background: #0b100d; border: 1px solid {BORDER}; border-radius: 7px; height: 13px; text-align: center; }}
QProgressBar::chunk {{ background: {GREEN}; border-radius: 6px; }}
QScrollArea {{ border: 0; }}
QCheckBox, QRadioButton {{ spacing: 8px; }}
QCheckBox::indicator, QTreeView::indicator, QListView::indicator, QTableView::indicator {{ width: 18px; height: 18px; border: 2px solid {BODY}; border-radius: 4px; background: #0c110e; }}
QCheckBox::indicator:checked, QTreeView::indicator:checked, QListView::indicator:checked, QTableView::indicator:checked {{ background: {GREEN}; border-color: {GREEN}; }}
QCheckBox::indicator:disabled {{ border-color: #3a453c; }}
"""


def label(text, name=""):
    widget = QLabel(text)
    if name:
        widget.setObjectName(name)
    widget.setWordWrap(True)
    return widget


def card(title, body=""):
    frame = QFrame(); frame.setObjectName("Card")
    layout = QVBoxLayout(frame); layout.setContentsMargins(18, 16, 18, 16); layout.setSpacing(9)
    layout.addWidget(label(title, "CardTitle"))
    if body:
        layout.addWidget(label(body, "Muted"))
    return frame, layout


def set_status(widget, text, kind="good"):
    """Recolour a status label: kind is good / warn / bad."""
    widget.setText(text)
    widget.setObjectName({"good": "StatusGood", "warn": "StatusWarn"}.get(kind, "StatusBad"))
    widget.style().unpolish(widget); widget.style().polish(widget)


class FitScrollArea(QScrollArea):
    """Fill the viewport until the page reaches its genuine minimum height."""
    def __init__(self):
        super().__init__(); self.setWidgetResizable(False)
        self.setHorizontalScrollBarPolicy(Qt.ScrollBarAlwaysOff)

    def setWidget(self, widget):
        super().setWidget(widget); self._fit_widget()
        # Sections that appear later (a backup method's fields, a restore source)
        # raise the page's minimum height; refit then, or they are crushed flat.
        widget.installEventFilter(self)

    def eventFilter(self, obj, event):
        if obj is self.widget() and event.type() == QEvent.LayoutRequest:
            QTimer.singleShot(0, self._fit_widget)
        return super().eventFilter(obj, event)

    def resizeEvent(self, event):
        super().resizeEvent(event); self._fit_widget()

    def _fit_widget(self):
        widget = self.widget()
        if widget:
            width = self.viewport().width()
            # The PREFERRED height at this width, not the bare minimum: sized to
            # the minimum, a short window squeezed buttons and two-line labels flat.
            layout = widget.layout()
            wanted = widget.minimumSizeHint().height()
            if layout is not None:
                preferred = (layout.totalHeightForWidth(width) if layout.hasHeightForWidth()
                             else layout.totalSizeHint().height())
                wanted = max(wanted, preferred)
            widget.resize(width, max(self.viewport().height(), wanted))


def make_page(owner, title, subtitle):
    """Give `owner` (a QWidget page) the standard scrolling page body.

    Returns the QVBoxLayout that page content goes into.
    """
    outer = QVBoxLayout(owner); outer.setContentsMargins(0, 0, 0, 0)
    scroll = FitScrollArea()
    host = QWidget(); host.setSizePolicy(QSizePolicy.Expanding, QSizePolicy.Ignored)
    layout = QVBoxLayout(host); layout.setContentsMargins(28, 25, 28, 28); layout.setSpacing(16)
    layout.addWidget(label(title, "PageTitle")); layout.addWidget(label(subtitle, "Muted"))
    scroll.setWidget(host)
    outer.addWidget(scroll)
    return layout


class Relay(QObject):
    """Carries worker-thread callbacks to the main thread.

    `call` runs any zero-argument function on the main thread:
        relay.call.emit(lambda: self.status.setText("done"))
    """
    call = Signal(object)

    def __init__(self, parent=None):
        super().__init__(parent)
        self.call.connect(lambda fn: fn())


def run_in_thread(relay, work, on_done=None, on_error=None):
    """Run work() on a daemon thread; deliver its result or exception on the main thread."""
    def target():
        try:
            result = work()
        except Exception as exc:  # reported, never swallowed
            if on_error:
                relay.call.emit(lambda e=exc: on_error(e))
            return
        if on_done:
            relay.call.emit(lambda r=result: on_done(r))
    thread = threading.Thread(target=target, daemon=True)
    thread.start()
    return thread


def ask_continue(parent, title, message, abort_text):
    """The engines' "a file failed — abort or continue?" question.

    Returns True to continue. Closing the box counts as abort, as in the Tk window.
    """
    box = QMessageBox(parent)
    box.setWindowTitle(title); box.setIcon(QMessageBox.Warning); box.setText(message)
    abort = box.addButton(abort_text, QMessageBox.RejectRole)
    cont = box.addButton("Continue anyway", QMessageBox.AcceptRole)
    box.setDefaultButton(abort); box.setEscapeButton(abort)
    box.exec()
    return box.clickedButton() is cont


def engine_asker(relay, parent, title, abort_text, get_engine):
    """Build an on_ask callback for BackupEngine / CloudSyncEngine.

    The engine calls on_ask(message) from its worker thread and then blocks
    until prompt_continue() or cancel() is called. This shows the question on
    the main thread and answers the engine from there.
    """
    def on_ask(message):
        def show():
            engine = get_engine()
            if not engine:
                return
            if ask_continue(parent, title, str(message), abort_text):
                engine.prompt_continue()
            else:
                engine.cancel()
        relay.call.emit(show)
    return on_ask

# ===== SNAPSMACK EOF =====
