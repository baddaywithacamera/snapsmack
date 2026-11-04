"""
UNZUCKER — unzucker_qt.py
Qt (PySide6) window over unzucker_core.Session — the Windows exe's UI. (SS-1191)

WHAT THIS IS
    A port of the tkinter window (main.py) onto Qt, feature for feature:
    the header with build/keyring/connection state, the collapsible
    CONFIGURATION area (CONNECTION box: site URL, API key, Connect; IMPORT
    SETTINGS box: export folder + Browse, copyright string, the eight server
    throttle choices, off-peak-only with its peak-hours pair), the 3-across
    post grid with lazy square thumbnails, click-to-open-detail, Ctrl+click
    trigram selection and the right-click menu, the post detail page with large
    preview, thumbnail strip, metadata, caption and extracted tags, the trigram
    slot dialog with drag-and-swap, the bottom action bar (Parse Export,
    Validate, Unload Job, TRANSFER & POST, progress, status), the job-name
    prompt, the resume-after-crash offer and the insecure-transport
    confirmation. Nothing was dropped.

    THE WORK IS NOT HERE. Every action calls unzucker_core.Session, the same
    tkinter-free engine the Linux port drives, so parse / connect / validate /
    trigram / migrate / checkpoint behave identically on both. This file is
    widgets and painting only. That is the whole reason the port is tractable:
    the engine was factored out before anyone asked for this.

    STRICTLY THREE ACROSS, as main.py's GRID_COLS says and as the trigram
    reorder arithmetic in the engine REQUIRES -- _reorder_posts_for_trigram
    computes a row start as (min_index // GRID_COLS) * GRID_COLS. A Qt icon
    view would happily fit four tiles on a wide window and silently break that,
    so the cell size is recomputed from the viewport width on every resize to
    keep exactly three. This is not a cosmetic choice.

WHAT CHANGED, AND IT IS ONLY WHAT QT MAKES FREE
    * No 600-cell render cap. main.py's PostGrid culls to a viewport window by
      hand; QListView paints only what is on screen, so a large export shows
      every tile with no bookkeeping.
    * Window size and position persist again. The ini has always had
      window_state and window_geometry; the Blink port could not read a Chrome
      window's geometry and left them blank (its own TODO says so). Qt can, so
      they are written and restored.
    Both are additions, not changes in behaviour, and neither touches the
    engine.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

from __future__ import annotations

import logging
import os
import queue
import sys
import threading
from collections import OrderedDict
from typing import List, Optional

from PySide6.QtCore import (QAbstractListModel, QEvent, QModelIndex, QObject, QPoint,
                            QRect, QSize, Qt, QTimer, Signal)
from PySide6.QtGui import QAction, QColor, QFont, QFontMetrics, QIcon, QPainter, QPixmap
from PySide6.QtWidgets import (QApplication, QCheckBox, QComboBox, QDialog, QFileDialog,
                               QFrame, QGridLayout, QHBoxLayout, QLabel, QLineEdit,
                               QListView, QMainWindow, QMenu, QMessageBox, QPlainTextEdit,
                               QProgressBar, QPushButton, QRadioButton, QScrollArea,
                               QSizePolicy, QStackedWidget, QStyledItemDelegate,
                               QVBoxLayout, QWidget)

import config as cfg_module
import unzucker_core as core

BUILD_VERSION = core.BUILD_VERSION
log = logging.getLogger('unzucker')


def _excepthook(exc_type, exc_value, exc_tb):
    if not issubclass(exc_type, KeyboardInterrupt):
        log.critical('Unhandled exception', exc_info=(exc_type, exc_value, exc_tb))
    sys.__excepthook__(exc_type, exc_value, exc_tb)


sys.excepthook = _excepthook

# ---------------------------------------------------------------------------
# Palette — main.py's MIDNIGHT LIME values, carried across unchanged so the
# ported window is the same window. Names match main.py's so a reader can put
# the two files side by side.
# ---------------------------------------------------------------------------

BG_DEEP   = '#141414'
BG_CARD   = '#1C1C1C'
BG_MID    = '#050505'
BG_HOVER  = '#252525'
ACCENT    = '#39FF14'
ACCENT2   = '#2ECC10'
BORDER    = '#2A2A2A'
FG_MAIN   = '#EEEEEE'
FG_DIM    = '#777777'
FG_OK     = '#4EC994'
FG_ERR    = '#FF3E3E'
FG_WARN   = '#D4872A'
TRIGRAM   = '#c8a96e'      # the brass trigram ring, main.py's literal

THUMB_DETAIL = 80
GRID_COLS    = core.GRID_COLS      # 3. Read from the engine, never re-declared.
CellRole     = Qt.UserRole + 1

STYLE = f"""
QWidget {{ background:{BG_DEEP}; color:{FG_MAIN}; font-family:'Segoe UI'; font-size:13px; }}
QFrame#Header {{ background:{BG_CARD}; border-bottom:1px solid {BORDER}; }}
QFrame#Card {{ background:{BG_CARD}; border:1px solid {BORDER}; border-radius:6px; }}
QFrame#Bar {{ background:{BG_CARD}; border-top:1px solid {BORDER}; }}
QFrame#Toggle {{ background:{BG_DEEP}; border-bottom:1px solid {BORDER}; }}
QFrame#Meta {{ background:{BG_CARD}; }}
QLabel {{ background:transparent; }}
QLabel#Title {{ color:{ACCENT}; font-size:19px; font-weight:800; }}
QLabel#Muted {{ color:{FG_DIM}; font-size:13px; }}
QLabel#Tiny {{ color:{FG_DIM}; font-size:11px; }}
QLabel#Eyebrow {{ color:{FG_DIM}; font-size:11px; font-weight:800; letter-spacing:2px; }}
QLabel#Accent {{ color:{ACCENT}; font-weight:700; }}
QLabel#Trigram {{ color:{TRIGRAM}; font-size:13px; }}
QLabel#Tags {{ background:{BG_MID}; color:{ACCENT2}; padding:6px 10px; }}
QLabel#Preview {{ background:#0A0A0E; }}
QLineEdit {{ background:{BG_MID}; border:1px solid {BORDER}; border-radius:4px;
    padding:5px 7px; min-height:20px; selection-background-color:{ACCENT}; selection-color:#000; }}
QLineEdit:focus {{ border-color:{ACCENT}; }}
QComboBox {{ background:{BG_MID}; border:1px solid {BORDER}; border-radius:4px; padding:3px 6px; }}
QComboBox QAbstractItemView {{ background:{BG_MID}; border:1px solid {BORDER};
    selection-background-color:{BG_HOVER}; }}
QPlainTextEdit {{ background:{BG_MID}; border:1px solid {BORDER}; border-radius:4px; }}
QPushButton {{ background:{BG_MID}; border:1px solid {BORDER}; border-radius:4px;
    padding:6px 12px; font-weight:650; min-height:18px; }}
QPushButton:hover {{ border-color:{ACCENT}; background:{BG_HOVER}; }}
QPushButton:disabled {{ color:{FG_DIM}; border-color:{BORDER}; }}
QPushButton#Primary {{ color:{BG_DEEP}; background:{ACCENT}; border-color:{ACCENT};
    font-size:14px; font-weight:850; padding:12px 18px; }}
QPushButton#Primary:hover {{ background:#5bff42; }}
QPushButton#Primary:disabled {{ color:#3a3a3a; background:#1f3d17; border-color:#1f3d17; }}
QPushButton#Link {{ background:transparent; border:0; color:{ACCENT}; font-weight:700; }}
QPushButton#Quiet {{ background:transparent; border:0; color:{FG_DIM}; }}
QPushButton#Quiet:hover {{ color:{FG_MAIN}; }}
QPushButton#Swap {{ background:{BG_MID}; font-size:17px; padding:2px 6px; }}
QCheckBox, QRadioButton {{ background:transparent; color:{FG_DIM}; spacing:6px; }}
QCheckBox::indicator, QRadioButton::indicator {{ width:13px; height:13px;
    border:1px solid {BORDER}; background:{BG_MID}; }}
QRadioButton::indicator {{ border-radius:7px; }}
QCheckBox::indicator:checked, QRadioButton::indicator:checked {{ background:{ACCENT};
    border-color:{ACCENT}; }}
QListView#Grid {{ background:{BG_DEEP}; border:0; outline:0; }}
QProgressBar {{ background:{BG_MID}; border:1px solid {BORDER}; border-radius:4px;
    height:14px; text-align:center; color:{FG_MAIN}; }}
QProgressBar::chunk {{ background:{ACCENT}; border-radius:3px; }}
QScrollArea {{ border:0; }}
QScrollBar:vertical {{ background:{BG_DEEP}; width:12px; }}
QScrollBar::handle:vertical {{ background:{BG_HOVER}; border-radius:5px; min-height:24px; }}
QScrollBar::add-line:vertical, QScrollBar::sub-line:vertical {{ height:0; }}
QMenu {{ background:{BG_CARD}; border:1px solid {BORDER}; }}
QMenu::item:selected {{ background:{BG_HOVER}; }}
"""


def _label(text: str, name: str = '') -> QLabel:
    w = QLabel(text)
    if name:
        w.setObjectName(name)
    return w


def _button(text: str, name: str = '', slot=None) -> QPushButton:
    b = QPushButton(text)
    if name:
        b.setObjectName(name)
    if slot is not None:
        b.clicked.connect(slot)
    return b


def _icon_path() -> str:
    """UNZUCKER HAS NO ICON ANYWHERE IN THE REPOSITORY.

    Measured: tools/hub/icons/ holds an .ico for eleven tools and none for this
    one, and the existing build.bat passes no --icon either, so the Tk exe has
    always shipped with the stock PyInstaller icon. Drawing one is a design
    decision in someone else's lane, so this looks for the file the rest of the
    family would use and returns '' when it is absent rather than inventing
    something. Reported, not quietly filled in.
    """
    here = os.path.dirname(os.path.abspath(__file__))
    for candidate in (os.path.join(here, 'assets', 'icon.ico'),
                      os.path.normpath(os.path.join(here, '..', 'hub', 'icons', 'unzucker.ico'))):
        if os.path.isfile(candidate):
            return candidate
    return ''


# ---------------------------------------------------------------------------
# The post grid
# ---------------------------------------------------------------------------

class _ThumbBridge(QObject):
    ready = Signal(int, int, bytes)      # generation, row, jpeg bytes


class PostModel(QAbstractListModel):
    """The engine's cell dicts, plus lazily decoded square thumbnails.

    A cell dict is exactly what Session._cell() returns: index, post_type,
    image_count, first_image, excluded, status, tg_group, tg_slot. This model
    adds ONE field of its own, 'selecting', because an in-progress Ctrl+click
    selection is a UI state the engine does not keep -- it returns the
    selection list and lets the window draw it.
    """

    def __init__(self, session: 'core.Session', parent=None):
        super().__init__(parent)
        self._s = session
        self._cells: list = []
        self._selecting: set = set()
        self._gen = 0
        self._px = 160                   # recomputed from the viewport
        self._cache: 'OrderedDict[int, QPixmap]' = OrderedDict()
        self._state: dict = {}
        self._q: queue.Queue = queue.Queue()
        self._bridge = _ThumbBridge()
        self._bridge.ready.connect(self._on_thumb_ready)
        # THE WORKER MUST BE STOPPABLE, and it was not until running the tests
        # crashed the interpreter. A daemon thread looping on queue.get()
        # forever holds a reference to the Session and to this model, so a
        # window that goes away leaves its worker running and Qt eventually
        # touches freed objects -- an access violation, not an exception. One
        # window never showed it; building a second one did, immediately.
        self._stop = threading.Event()
        self._thread = threading.Thread(target=self._worker, daemon=True)
        self._thread.start()

    # -- Qt model API --
    def rowCount(self, parent=QModelIndex()) -> int:
        return 0 if parent.isValid() else len(self._cells)

    def data(self, index, role=Qt.DisplayRole):
        if not index.isValid():
            return None
        cell = self._cells[index.row()]
        if role == CellRole:
            return cell
        if role == Qt.ToolTipRole:
            n = cell.get('image_count', 0)
            kind = cell.get('post_type', 'single')
            return f"#{cell['index'] + 1} — {kind}, {n} image{'' if n == 1 else 's'}"
        return None

    # -- population --
    def set_cells(self, cells: list) -> None:
        """Replace the whole list. Used after parse, lock and reorder.

        THE GENERATION COUNTER IS LOAD-BEARING. Locking a trigram REORDERS the
        posts in the engine, so row 7 is a different photograph afterwards. A
        thumbnail decode already in flight for row 7 would arrive and be painted
        on the wrong post. Bumping the generation makes the worker drop it.
        """
        self.beginResetModel()
        self._cells = [dict(c) for c in cells]
        self._gen += 1
        self._cache.clear()
        self._state.clear()
        self._selecting.clear()
        self.endResetModel()

    def cells(self) -> list:
        return self._cells

    def update_cell(self, index: int, **fields) -> None:
        if not (0 <= index < len(self._cells)):
            return
        self._cells[index].update(fields)
        idx = self.index(index)
        self.dataChanged.emit(idx, idx)

    def set_selecting(self, indices) -> None:
        changed = self._selecting.symmetric_difference(set(indices))
        self._selecting = set(indices)
        for i in changed:
            if 0 <= i < len(self._cells):
                idx = self.index(i)
                self.dataChanged.emit(idx, idx)

    def is_selecting(self, index: int) -> bool:
        return index in self._selecting

    # -- thumbnails --
    def set_thumb_px(self, px: int) -> None:
        """A cell-size change invalidates every cached pixmap."""
        px = max(48, int(px))
        if px == self._px:
            return
        self._px = px
        self._gen += 1
        self._cache.clear()
        self._state.clear()

    def thumb_px(self) -> int:
        return self._px

    def pixmap_for(self, row: int) -> Optional[QPixmap]:
        pm = self._cache.get(row)
        if pm is not None:
            self._cache.move_to_end(row)
            return pm
        if row not in self._state:
            path = self._cells[row].get('first_image') or ''
            if not path:
                self._state[row] = 'none'
            else:
                self._state[row] = 'loading'
                self._q.put((self._gen, row, path, self._px))
        return None

    def shutdown(self) -> None:
        """Stop the thumbnail worker and wait briefly for it to notice.

        Called from MainWindow.closeEvent. The None sentinel is what wakes the
        blocking get(); setting the event alone would leave the thread parked
        until the next thumbnail request, which may never come.
        """
        self._stop.set()
        self._q.put(None)
        if self._thread.is_alive():
            self._thread.join(timeout=2.0)

    def _worker(self):
        while not self._stop.is_set():
            item = self._q.get()
            if item is None:            # the shutdown sentinel
                break
            gen, row, path, px = item
            if gen != self._gen:
                continue
            try:
                raw = self._s.thumb_bytes(path, px)
            except Exception as error:   # a missing or refused file is not fatal
                log.debug('thumb failed for row %d (%s): %s', row, path, error)
                continue
            if raw:
                self._bridge.ready.emit(gen, row, raw)

    def _on_thumb_ready(self, gen: int, row: int, raw: bytes):
        if gen != self._gen or row >= len(self._cells):
            return
        pm = QPixmap()
        if not pm.loadFromData(raw, 'JPEG'):
            self._state[row] = 'none'
            return
        self._cache[row] = pm
        self._state[row] = 'loaded'
        # Bounded so a large export cannot grow without limit. Three screens of
        # a three-across grid is generous; evicted rows are simply re-requested
        # when scrolled back to.
        while len(self._cache) > 400:
            old, _ = self._cache.popitem(last=False)
            self._state.pop(old, None)
        idx = self.index(row)
        self.dataChanged.emit(idx, idx)


class PostDelegate(QStyledItemDelegate):
    """One cell, painted as main.py's PostGrid._draw_cell painted it."""

    _ICONS = {'ok': '✓', 'error': '✗', 'skip': '—'}
    _COLOURS = {'ok': FG_OK, 'error': FG_ERR, 'skip': FG_WARN}

    def __init__(self, model: PostModel, parent=None):
        super().__init__(parent)
        self._m = model

    def sizeHint(self, option, index) -> QSize:
        px = self._m.thumb_px()
        return QSize(px, px)

    def paint(self, painter: QPainter, option, index):
        cell = index.data(CellRole)
        if not cell:
            return
        r: QRect = option.rect
        painter.save()
        painter.fillRect(r, QColor(BG_HOVER if cell['excluded'] else BG_DEEP))

        pm = self._m.pixmap_for(index.row())
        if pm is not None:
            painter.drawPixmap(r.x() + (r.width() - pm.width()) // 2,
                               r.y() + (r.height() - pm.height()) // 2, pm)

        # Carousel badge, top right.
        if cell.get('post_type') == 'carousel':
            painter.setFont(QFont('Segoe UI', 10, QFont.Bold))
            painter.setPen(QColor('#ffffff'))
            painter.drawText(QRect(r.x(), r.y() + 6, r.width() - 8, 18),
                             Qt.AlignRight | Qt.AlignTop,
                             '▪ %d' % cell.get('image_count', 0))

        # Excluded wins over status, exactly as the Tk cell did: an excluded
        # post is not going to be uploaded, so its upload status is not the
        # thing to show.
        glyph = colour = None
        if cell['excluded']:
            glyph, colour = '⌀', FG_DIM
        elif cell.get('status') in self._ICONS:
            glyph = self._ICONS[cell['status']]
            colour = self._COLOURS[cell['status']]
        if glyph:
            painter.fillRect(r, QColor(20, 20, 20, 150))
            painter.setFont(QFont('Segoe UI', 22, QFont.Bold))
            painter.setPen(QColor(colour))
            painter.drawText(r, Qt.AlignCenter, glyph)

        # Trigram ring and its L/M/R slot badge, bottom right.
        if cell.get('tg_group', 0) > 0:
            slot = cell.get('tg_slot', 0)
            name = ('L', 'M', 'R')[slot - 1] if 1 <= slot <= 3 else str(slot)
            pen = painter.pen()
            pen.setColor(QColor(TRIGRAM))
            pen.setWidth(2)
            painter.setPen(pen)
            painter.drawRect(r.adjusted(1, 1, -2, -2))
            badge = QRect(r.right() - 24, r.bottom() - 16, 24, 16)
            painter.fillRect(badge, QColor(TRIGRAM))
            painter.setFont(QFont('Segoe UI', 8, QFont.Bold))
            painter.setPen(QColor('#000000'))
            painter.drawText(badge, Qt.AlignCenter, 'T%d%s' % (cell['tg_group'], name))

        # Dashed ring while a Ctrl+click group is being built.
        if self._m.is_selecting(index.row()):
            pen = painter.pen()
            pen.setColor(QColor(ACCENT))
            pen.setWidth(2)
            pen.setStyle(Qt.DashLine)
            painter.setPen(pen)
            painter.drawRect(r.adjusted(2, 2, -3, -3))

        painter.restore()


class PostGridView(QListView):
    """A QListView pinned to exactly GRID_COLS columns.

    Qt would otherwise fit as many tiles across as the window allows, and the
    engine's trigram reordering computes row starts with GRID_COLS. Four across
    would put a locked L/M/R run on two rows.
    """

    def __init__(self, model: PostModel, parent=None):
        super().__init__(parent)
        self.setObjectName('Grid')
        self.setModel(model)
        self._m = model
        self.setItemDelegate(PostDelegate(model, self))
        self.setViewMode(QListView.IconMode)
        self.setFlow(QListView.LeftToRight)
        self.setWrapping(True)
        self.setResizeMode(QListView.Adjust)
        self.setMovement(QListView.Static)
        self.setUniformItemSizes(True)
        self.setSpacing(0)
        self.setSelectionMode(QListView.NoSelection)
        self.setVerticalScrollMode(QListView.ScrollPerPixel)
        self.setHorizontalScrollBarPolicy(Qt.ScrollBarAlwaysOff)
        self.setContextMenuPolicy(Qt.CustomContextMenu)

    def resizeEvent(self, ev):
        super().resizeEvent(ev)
        self._refit()

    def _refit(self):
        width = max(1, self.viewport().width())
        px = max(48, width // GRID_COLS)
        self._m.set_thumb_px(px)
        self.setGridSize(QSize(px, px))
        self.scheduleDelayedItemsLayout()


# ---------------------------------------------------------------------------
# Dialogs
# ---------------------------------------------------------------------------

class JobNameDialog(QDialog):
    """Asks for a job name when one cannot be read from the folder name."""

    def __init__(self, parent, fallback: str):
        super().__init__(parent)
        self.setWindowTitle('Job name')
        self.setModal(True)
        v = QVBoxLayout(self)
        v.setContentsMargins(14, 12, 14, 12)
        v.addWidget(_label('Could not detect account name from folder.\n'
                           'Enter a job name:', 'Muted'))
        self._edit = QLineEdit(fallback)
        self._edit.selectAll()
        v.addWidget(self._edit)
        row = QHBoxLayout()
        row.addStretch()
        row.addWidget(_button('OK', 'Primary', self.accept))
        v.addLayout(row)
        self._fallback = fallback
        self._edit.returnPressed.connect(self.accept)

    def name(self) -> str:
        return self._edit.text().strip() or self._fallback

    @staticmethod
    def ask(parent, fallback: str) -> str:
        dlg = JobNameDialog(parent, fallback)
        dlg.exec()
        return dlg.name()


class _SlotThumb(QLabel):
    """One trigram slot. Press and release over another slot to swap them.

    main.py's TrigramPanel dragged on a canvas and worked out the target from
    the release x coordinate; this does the same thing with the mouse position
    mapped into the strip, so the gesture a user already knows still works.
    """

    def __init__(self, slot: int, side: int, on_drop):
        super().__init__()
        self.setObjectName('Preview')
        self.setFixedSize(side, side)
        self.setAlignment(Qt.AlignCenter)
        self.setCursor(Qt.SizeAllCursor)
        self._slot = slot
        self._on_drop = on_drop

    def mouseReleaseEvent(self, ev):
        self._on_drop(self._slot, self.mapToGlobal(ev.position().toPoint()))
        super().mouseReleaseEvent(ev)


class TrigramDialog(QDialog):
    """Assign L/M/R slots to three posts, then lock the group."""

    THUMB = 90

    def __init__(self, parent, session: 'core.Session', panel: list, indices: list):
        super().__init__(parent)
        self.setWindowTitle('Assign Trigram Slots')
        self.setModal(True)
        self._s = session
        self._order = [(p.get('first_image', ''), int(i)) for p, i in zip(panel, indices)]
        self._thumbs: List[_SlotThumb] = []
        self._titles: List[QLabel] = []
        self.locked_order: Optional[list] = None

        v = QVBoxLayout(self)
        v.setContentsMargins(14, 12, 14, 12)
        v.setSpacing(6)
        v.addWidget(_label('TRIGRAM SLOT ORDER', 'Eyebrow'), 0, Qt.AlignHCenter)
        v.addWidget(_label('Drag thumbnails to reorder  ·  or use ⇄ to swap adjacent.',
                           'Tiny'), 0, Qt.AlignHCenter)

        strip = QHBoxLayout()
        strip.setSpacing(4)
        for i in range(3):
            col = QVBoxLayout()
            col.setSpacing(2)
            name = _label(('L', 'M', 'R')[i], 'Accent')
            name.setAlignment(Qt.AlignHCenter)
            col.addWidget(name)
            thumb = _SlotThumb(i, self.THUMB, self._dropped)
            self._thumbs.append(thumb)
            col.addWidget(thumb)
            title = _label('', 'Tiny')
            title.setAlignment(Qt.AlignHCenter)
            title.setFixedWidth(self.THUMB)
            title.setWordWrap(True)
            self._titles.append(title)
            col.addWidget(title)
            strip.addLayout(col)
            if i < 2:
                swap = _button('⇄', 'Swap', lambda _=False, s=i: self._swap(s))
                swap.setFixedWidth(30)
                strip.addWidget(swap, 0, Qt.AlignVCenter)
        v.addLayout(strip)

        row = QHBoxLayout()
        row.addWidget(_button('Cancel', 'Quiet', self.reject))
        row.addStretch()
        row.addWidget(_button('LOCK', 'Primary', self._lock))
        v.addLayout(row)

        self._refresh()

    def _swap(self, i: int):
        self._order[i], self._order[i + 1] = self._order[i + 1], self._order[i]
        self._refresh()

    def _dropped(self, src: int, global_pos: QPoint):
        for dest, thumb in enumerate(self._thumbs):
            if dest == src:
                continue
            if thumb.rect().contains(thumb.mapFromGlobal(global_pos)):
                self._order[src], self._order[dest] = self._order[dest], self._order[src]
                self._refresh()
                return

    def _refresh(self):
        for slot, (path, index) in enumerate(self._order):
            pm = None
            if path:
                try:
                    raw = self._s.thumb_bytes(path, self.THUMB)
                    candidate = QPixmap()
                    if candidate.loadFromData(raw, 'JPEG'):
                        pm = candidate
                except Exception as error:
                    log.debug('trigram thumb failed (%s): %s', path, error)
            if pm is not None:
                self._thumbs[slot].setPixmap(pm)
            else:
                self._thumbs[slot].clear()
                self._thumbs[slot].setText('?')
            self._titles[slot].setText('#%d' % (index + 1))

    def _lock(self):
        self.locked_order = [index for _, index in self._order]
        self.accept()


# ---------------------------------------------------------------------------
# Main window
# ---------------------------------------------------------------------------

class _Bridge(QObject):
    done = Signal(object, object)      # callback, result


class MainWindow(QMainWindow):

    def __init__(self, session: 'core.Session'):
        super().__init__()
        self._s = session
        self._state = session.load_state()
        self._detail_index = 0
        self._posting = False
        self._bridge = _Bridge()
        self._bridge.done.connect(lambda cb, r: cb(r))

        self.setWindowTitle('UNZUCKER')
        self.resize(1000, 820)
        self.setMinimumSize(760, 620)
        self._build_ui()
        self._load_config_to_ui()
        self._restore_geometry()

        self._timer = QTimer(self)
        self._timer.setInterval(120)
        self._timer.timeout.connect(self._poll_events)

    # -- construction ---------------------------------------------------

    def _build_ui(self):
        root = QWidget()
        outer = QVBoxLayout(root)
        outer.setContentsMargins(0, 0, 0, 0)
        outer.setSpacing(0)

        outer.addWidget(self._build_header())
        outer.addWidget(self._build_config_toggle())

        self._cfg = self._build_config()
        outer.addWidget(self._cfg)

        self._grid_section = self._build_grid_section()
        self._grid_section.hide()          # config fills the window until a parse
        outer.addWidget(self._grid_section, 1)

        outer.addWidget(self._build_bottom_bar())
        self.setCentralWidget(root)

    def _build_header(self) -> QWidget:
        bar = QFrame()
        bar.setObjectName('Header')
        bar.setFixedHeight(59)
        h = QHBoxLayout(bar)
        h.setContentsMargins(16, 0, 14, 0)
        h.addWidget(_label('UNZUCKER', 'Title'))
        h.addStretch()

        keyring_ok = bool(self._state.get('keyring_ok'))
        kr = _label('\U0001f512 keyring' if keyring_ok else '⚠ no keyring', 'Tiny')
        kr.setStyleSheet('color:%s' % (FG_OK if keyring_ok else FG_WARN))
        h.addWidget(kr)

        h.addWidget(_label('build %s' % BUILD_VERSION, 'Tiny'))
        self._conn_lbl = _label('Not connected', 'Muted')
        h.addWidget(self._conn_lbl)
        self._conn_dot = _label('●')
        self._conn_dot.setStyleSheet('color:%s; font-size:15px;' % FG_DIM)
        h.addWidget(self._conn_dot)
        return bar

    def _build_config_toggle(self) -> QWidget:
        bar = QFrame()
        bar.setObjectName('Toggle')
        bar.setFixedHeight(35)
        h = QHBoxLayout(bar)
        h.setContentsMargins(10, 0, 10, 0)
        self._cfg_toggle = _button('▲  CONFIGURATION', 'Quiet', self._toggle_config)
        self._cfg_toggle.setCursor(Qt.PointingHandCursor)
        h.addWidget(self._cfg_toggle)
        h.addStretch()
        return bar

    def _card(self, title: str) -> tuple:
        card = QFrame()
        card.setObjectName('Card')
        v = QVBoxLayout(card)
        v.setContentsMargins(12, 10, 12, 12)
        v.setSpacing(6)
        v.addWidget(_label(title, 'Eyebrow'))
        return card, v

    def _field(self, parent_layout, label: str, widget: QWidget):
        parent_layout.addWidget(_label(label, 'Tiny'))
        parent_layout.addWidget(widget)

    def _build_config(self) -> QWidget:
        page = QWidget()
        v = QVBoxLayout(page)
        v.setContentsMargins(14, 10, 14, 10)
        v.setSpacing(8)

        # CONNECTION
        conn, cv = self._card('CONNECTION')
        self._url = QLineEdit()
        self._field(cv, 'SITE URL', self._url)
        self._key = QLineEdit()
        self._key.setEchoMode(QLineEdit.Password)
        self._field(cv, 'API KEY', self._key)
        row = QHBoxLayout()
        row.addStretch()
        self._connect_btn = _button('Connect', 'Primary', self._on_connect)
        row.addWidget(self._connect_btn)
        cv.addLayout(row)
        v.addWidget(conn)

        # IMPORT SETTINGS
        imp, iv = self._card('IMPORT SETTINGS')
        iv.addWidget(_label('INSTAGRAM EXPORT FOLDER', 'Tiny'))
        folder_row = QHBoxLayout()
        self._export = QLineEdit()
        folder_row.addWidget(self._export, 1)
        folder_row.addWidget(_button('Browse', '', self._browse_export))
        iv.addLayout(folder_row)

        self._copyright = QLineEdit()
        self._field(iv, 'COPYRIGHT STRING', self._copyright)

        iv.addWidget(_label('SERVER THROTTLE', 'Tiny'))
        grid = QGridLayout()
        grid.setHorizontalSpacing(10)
        grid.setVerticalSpacing(2)
        self._throttles: List[QRadioButton] = []
        for i, opt in enumerate(self._state.get('throttle_options', [])):
            rb = QRadioButton(opt['label'])
            rb.setProperty('delay', opt['value'])
            self._throttles.append(rb)
            grid.addWidget(rb, *divmod(i, 4))
        iv.addLayout(grid)

        peak_row = QHBoxLayout()
        self._offpeak = QCheckBox('OFF-PEAK ONLY')
        self._offpeak.toggled.connect(self._apply_offpeak_toggle)
        peak_row.addWidget(self._offpeak)
        self._peak_wrap = QWidget()
        pw = QHBoxLayout(self._peak_wrap)
        pw.setContentsMargins(0, 0, 0, 0)
        pw.addWidget(_label('Peak:', 'Tiny'))
        self._peak_start = QComboBox()
        self._peak_end = QComboBox()
        for box in (self._peak_start, self._peak_end):
            box.addItems([str(h) for h in range(24)])
            box.setFixedWidth(56)
        pw.addWidget(self._peak_start)
        pw.addWidget(_label('to', 'Tiny'))
        pw.addWidget(self._peak_end)
        peak_row.addWidget(self._peak_wrap)
        peak_row.addStretch()
        iv.addLayout(peak_row)
        iv.addStretch()
        v.addWidget(imp, 1)
        return page

    def _build_grid_section(self) -> QWidget:
        page = QWidget()
        v = QVBoxLayout(page)
        v.setContentsMargins(0, 0, 0, 0)
        v.setSpacing(0)

        hdr = QFrame()
        hdr.setFixedHeight(40)
        hh = QHBoxLayout(hdr)
        hh.setContentsMargins(14, 0, 14, 0)
        self._grid_lbl = _label('POSTS — 0', 'Muted')
        hh.addWidget(self._grid_lbl)
        self._tg_lbl = _label('', 'Trigram')
        hh.addWidget(self._tg_lbl)
        hh.addStretch()
        v.addWidget(hdr)

        self._stack = QStackedWidget()
        self._model = PostModel(self._s, self)
        self._grid = PostGridView(self._model, self)
        self._grid.clicked.connect(self._on_grid_clicked)
        self._grid.customContextMenuRequested.connect(self._on_grid_context)
        self._stack.addWidget(self._grid)
        self._stack.addWidget(self._build_detail())
        v.addWidget(self._stack, 1)
        return page

    def _build_detail(self) -> QWidget:
        page = QWidget()
        v = QVBoxLayout(page)
        v.setContentsMargins(0, 0, 0, 0)
        v.setSpacing(0)

        nav = QFrame()
        nav.setObjectName('Meta')
        nav.setFixedHeight(48)
        nh = QHBoxLayout(nav)
        nh.setContentsMargins(10, 0, 10, 0)
        nh.addWidget(_button('←  BACK TO GRID', 'Link', self._show_grid))
        nh.addStretch()
        self._detail_info = _label('', 'Muted')
        nh.addWidget(self._detail_info)
        nh.addWidget(_button('← PREV', 'Quiet', self._detail_prev))
        nh.addWidget(_button('NEXT →', 'Quiet', self._detail_next))
        v.addWidget(nav)

        scroll = QScrollArea()
        scroll.setWidgetResizable(True)
        body = QWidget()
        bv = QVBoxLayout(body)
        bv.setContentsMargins(10, 10, 10, 10)
        bv.setSpacing(6)

        self._preview = _label('', 'Preview')
        self._preview.setAlignment(Qt.AlignCenter)
        self._preview.setMinimumHeight(360)
        bv.addWidget(self._preview)

        self._strip = QWidget()
        self._strip_layout = QHBoxLayout(self._strip)
        self._strip_layout.setContentsMargins(0, 0, 0, 0)
        self._strip_layout.setSpacing(4)
        bv.addWidget(self._strip)

        meta = QFrame()
        meta.setObjectName('Meta')
        mh = QHBoxLayout(meta)
        mh.setContentsMargins(10, 6, 10, 6)
        self._d_type = _label('', 'Accent')
        self._d_count = _label('', 'Muted')
        self._d_date = _label('', 'Muted')
        mh.addWidget(self._d_type)
        mh.addWidget(self._d_count)
        mh.addStretch()
        mh.addWidget(self._d_date)
        bv.addWidget(meta)

        bv.addWidget(_label('CAPTION', 'Tiny'))
        self._d_caption = QPlainTextEdit()
        self._d_caption.setReadOnly(True)
        self._d_caption.setFixedHeight(120)
        bv.addWidget(self._d_caption)

        bv.addWidget(_label('EXTRACTED TAGS', 'Tiny'))
        self._d_tags = _label('', 'Tags')
        self._d_tags.setWordWrap(True)
        bv.addWidget(self._d_tags)
        bv.addStretch()

        scroll.setWidget(body)
        v.addWidget(scroll, 1)
        return page

    def _build_bottom_bar(self) -> QWidget:
        bar = QFrame()
        bar.setObjectName('Bar')
        bar.setFixedHeight(69)
        h = QHBoxLayout(bar)
        h.setContentsMargins(14, 8, 14, 8)
        h.setSpacing(6)
        self._parse_btn = _button('Parse Export', '', self._on_parse)
        self._validate_btn = _button('Validate', '', self._on_validate)
        self._unload_btn = _button('Unload Job', '', self._unload_job)
        self._unload_btn.setEnabled(False)
        self._post_btn = _button('TRANSFER & POST', 'Primary', self._on_post)
        self._post_btn.setMinimumWidth(240)
        for w in (self._parse_btn, self._validate_btn, self._unload_btn, self._post_btn):
            h.addWidget(w)
        h.addStretch()
        self._status_lbl = _label('Parse an export to begin.', 'Muted')
        h.addWidget(self._status_lbl)
        self._prog_lbl = _label('', 'Muted')
        h.addWidget(self._prog_lbl)
        self._progress = QProgressBar()
        self._progress.setFixedWidth(140)
        self._progress.setRange(0, 100)
        self._progress.setValue(0)
        h.addWidget(self._progress)
        return bar

    # -- config ---------------------------------------------------------

    def _load_config_to_ui(self):
        c = self._state.get('config', {})
        self._url.setText(c.get('url', ''))
        self._key.setText(c.get('api_key', ''))
        self._export.setText(c.get('export_folder', ''))
        self._copyright.setText(c.get('copyright_text', ''))
        delay = str(c.get('import_delay', '0.5'))
        for rb in self._throttles:
            rb.setChecked(str(rb.property('delay')) == delay)
        if not any(rb.isChecked() for rb in self._throttles) and self._throttles:
            self._throttles[0].setChecked(True)
        self._offpeak.setChecked(bool(c.get('offpeak_only')))
        self._peak_start.setCurrentText(str(c.get('peak_start', '9')))
        self._peak_end.setCurrentText(str(c.get('peak_end', '23')))
        self._apply_offpeak_toggle()

    def _form(self) -> dict:
        delay = '0.5'
        for rb in self._throttles:
            if rb.isChecked():
                delay = str(rb.property('delay'))
        return {
            'url': self._url.text().strip(),
            'api_key': self._key.text().strip(),
            'export_folder': self._export.text().strip(),
            'copyright_text': self._copyright.text().strip(),
            'import_delay': delay,
            'offpeak_only': self._offpeak.isChecked(),
            'peak_start': self._peak_start.currentText(),
            'peak_end': self._peak_end.currentText(),
        }

    def _save_config(self):
        self._s.save_config(self._form())
        self._state = self._s.load_state()

    def _apply_offpeak_toggle(self):
        self._peak_wrap.setVisible(self._offpeak.isChecked())

    def _toggle_config(self):
        showing = not self._cfg.isVisible()
        self._cfg.setVisible(showing)
        self._cfg_toggle.setText(('▲' if showing else '▼') + '  CONFIGURATION')

    def _collapse_config(self):
        self._cfg.hide()
        self._cfg_toggle.setText('▼  CONFIGURATION')
        self._grid_section.show()

    def _browse_export(self):
        start = self._export.text().strip() or os.path.expanduser('~')
        picked = QFileDialog.getExistingDirectory(self, 'Instagram export folder', start)
        if picked:
            self._export.setText(picked)

    # -- threading ------------------------------------------------------

    def _bg(self, fn, done):
        """Run fn() on a worker thread; call done(result) on the UI thread.
        An exception becomes the result so the handler can show it."""
        def _t():
            try:
                r = fn()
            except Exception as error:      # noqa: BLE001 — surfaced to the handler
                log.exception('background task failed')
                r = error
            self._bridge.done.emit(done, r)
        threading.Thread(target=_t, daemon=True).start()

    def _set_status(self, text: str, colour: str = FG_DIM):
        self._status_lbl.setText(text)
        self._status_lbl.setStyleSheet('color:%s' % colour)

    def _error(self, title: str, text: str):
        QMessageBox.critical(self, title, text)

    # -- connect --------------------------------------------------------

    def _on_connect(self):
        url = self._url.text().strip()
        key = self._key.text().strip()
        if not url or not key:
            self._error('Missing details', 'Fill in Site URL and API Key.')
            return
        self._connect_btn.setEnabled(False)
        self._set_status('Connecting…')
        self._bg(lambda: self._s.connect(url, key), self._on_connect_done)

    def _on_connect_done(self, result):
        self._connect_btn.setEnabled(True)
        if isinstance(result, Exception):
            self._set_conn('Not connected', FG_ERR)
            self._error('Connection failed', str(result))
            return
        if result.get('needs_confirm'):
            # SECAUDIT 041's transport guard, and it must stay a question. The
            # key is a scoped API key rather than an account password, so the
            # owner may legitimately be on a plain-http staging box -- but it
            # fails CLOSED until he says so.
            answer = QMessageBox.warning(
                self, 'Insecure connection',
                'This site is not https.\n\n'
                'Your API key would be sent in the clear and anyone on the '
                'network path could read it.\n\nSend it anyway?',
                QMessageBox.Yes | QMessageBox.No, QMessageBox.No)
            if answer != QMessageBox.Yes:
                self._set_conn('Not connected', FG_DIM)
                self._set_status('Connection cancelled.')
                return
            url, key = self._url.text().strip(), self._key.text().strip()
            self._connect_btn.setEnabled(False)
            self._bg(lambda: self._s.connect(url, key, allow_insecure=True),
                     self._on_connect_done)
            return
        self._set_conn('Connected', FG_OK)
        self._set_status('Connected — %d categories, %d albums.'
                         % (result.get('cats', 0), result.get('albums', 0)), FG_OK)
        self._state = self._s.load_state()

    def _set_conn(self, text: str, colour: str):
        self._conn_lbl.setText(text)
        self._conn_dot.setStyleSheet('color:%s; font-size:15px;' % colour)

    # -- parse ----------------------------------------------------------

    def _on_parse(self):
        folder = self._export.text().strip()
        if not folder:
            self._error('No folder', 'Select an Instagram export folder.')
            return
        self._save_config()
        self._parse_btn.setEnabled(False)
        self._set_status('Parsing export…')
        self._bg(lambda: self._s.parse_export(folder), self._on_parse_done)

    def _on_parse_done(self, result):
        self._parse_btn.setEnabled(True)
        if isinstance(result, Exception):
            self._set_status('Parse failed.', FG_ERR)
            self._error('Parse failed', str(result))
            return

        existing = result.get('existing_job')
        resume = False
        if existing:
            answer = QMessageBox.question(
                self, 'Resume job',
                'A job for this folder is already part-done:\n\n'
                '  %s — %d post(s) already uploaded\n\n'
                'Resume it? Choosing No starts over and discards that progress.'
                % (existing.get('job_name', '?'), existing.get('upload_count', 0)),
                QMessageBox.Yes | QMessageBox.No, QMessageBox.Yes)
            resume = answer == QMessageBox.Yes

        name = result.get('suggested_job_name') or ''
        if not resume and not name:
            folder = self._export.text().strip()
            name = JobNameDialog.ask(self, os.path.basename(folder.rstrip('/\\')) or 'job')

        errors = result.get('errors') or []
        if errors:
            QMessageBox.warning(self, 'Parsed with problems',
                                '\n'.join(str(e) for e in errors[:12])
                                + ('' if len(errors) <= 12
                                   else '\n…and %d more.' % (len(errors) - 12)))

        self._bg(lambda: self._s.begin_job(resume, name, self._url.text().strip()),
                 self._on_job_begun)

    def _on_job_begun(self, result):
        if isinstance(result, Exception):
            self._set_status('Could not start the job.', FG_ERR)
            self._error('Job failed', str(result))
            return
        self._model.set_cells(result.get('posts', []))
        self._grid._refit()
        self._collapse_config()
        self._unload_btn.setEnabled(True)
        self._update_grid_label()
        self._update_tg_label(result.get('trigrams', []))
        progress = result.get('progress', {})
        done, total = progress.get('done', 0), progress.get('total', 0)
        self._progress.setValue(int(done * 100 / total) if total else 0)
        self._prog_lbl.setText('%d / %d' % (done, total) if done else '')
        self._set_status('[%s] %d post(s)%s.'
                         % (result.get('job_name', '?'), total,
                            ' — resumed' if result.get('resumed') else ''),
                         FG_OK)

    def _update_grid_label(self):
        cells = self._model.cells()
        active = sum(1 for c in cells if not c['excluded'])
        self._grid_lbl.setText('POSTS — %d  (%d to migrate)' % (len(cells), active))

    def _update_tg_label(self, trigrams: list):
        n = len(trigrams)
        self._tg_lbl.setText('' if not n else
                             '%d trigram%s locked' % (n, '' if n == 1 else 's'))

    # -- grid interaction ----------------------------------------------

    def _on_grid_clicked(self, index: QModelIndex):
        mods = QApplication.keyboardModifiers()
        if mods & Qt.ControlModifier:
            self._on_ctrl_click(index.row())
            return
        self._show_detail(index.row())

    def _on_grid_context(self, pos: QPoint):
        index = self._grid.indexAt(pos)
        if not index.isValid():
            return
        row = index.row()
        cell = self._model.cells()[row]
        menu = QMenu(self)
        if cell.get('tg_group', 0) > 0:
            act = QAction('Remove from Trigram T%d' % cell['tg_group'], menu)
            act.triggered.connect(lambda: self._remove_trigram(row))
        else:
            act = QAction('Add to Trigram Group  (or Ctrl+click)', menu)
            act.triggered.connect(lambda: self._on_ctrl_click(row))
        menu.addAction(act)
        menu.addSeparator()
        toggle = QAction('Include' if cell['excluded'] else 'Exclude', menu)
        toggle.triggered.connect(lambda: self._toggle_exclude(row))
        menu.addAction(toggle)
        menu.exec(self._grid.viewport().mapToGlobal(pos))

    def _toggle_exclude(self, row: int):
        try:
            result = self._s.toggle_exclude(row)
        except Exception as error:
            self._error('Could not change that post', str(error))
            return
        self._model.update_cell(row, excluded=bool(result['excluded']))
        self._update_grid_label()

    def _on_ctrl_click(self, row: int):
        try:
            result = self._s.trigram_select(row)
        except Exception as error:
            self._error('Trigram', str(error))
            return
        if result.get('noop'):
            return
        if result.get('removed'):
            for i in result.get('cleared_indices', []):
                self._model.update_cell(i, tg_group=0, tg_slot=0)
            self._update_tg_label(result.get('trigrams', []))
            self._set_status('Trigram removed.')
            return
        if result.get('error'):
            self._model.set_selecting(result.get('selection', []))
            self._set_status(result['error'], FG_WARN)
            return
        if result.get('open_panel'):
            self._model.set_selecting([])
            self._open_trigram_dialog(result.get('panel', []), result.get('indices', []))
            return
        selection = result.get('selection', [])
        self._model.set_selecting(selection)
        self._set_status('Trigram: %d of 3 selected.' % len(selection))

    def _open_trigram_dialog(self, panel: list, indices: list):
        dlg = TrigramDialog(self, self._s, panel, indices)
        if dlg.exec() != QDialog.Accepted or not dlg.locked_order:
            self._set_status('Trigram cancelled.')
            return
        try:
            result = self._s.lock_trigram(dlg.locked_order)
        except Exception as error:
            self._error('Could not lock the trigram', str(error))
            return
        # LOCKING REORDERS THE POSTS, so the whole list is replaced rather than
        # patched. Patching would leave rows pointing at the wrong photographs.
        self._model.set_cells(result.get('posts', []))
        self._update_grid_label()
        self._update_tg_label(result.get('trigrams', []))
        self._set_status(result.get('status', 'Trigram locked.'), FG_OK)

    def _remove_trigram(self, row: int):
        try:
            result = self._s.remove_trigram(row)
        except Exception as error:
            self._error('Trigram', str(error))
            return
        for i in result.get('cleared_indices', []):
            self._model.update_cell(i, tg_group=0, tg_slot=0)
        self._update_tg_label(result.get('trigrams', []))

    # -- detail ---------------------------------------------------------

    def _show_grid(self):
        self._stack.setCurrentIndex(0)

    def _show_detail(self, row: int):
        try:
            d = self._s.detail(row)
        except Exception as error:
            self._error('No detail', str(error))
            return
        self._detail_index = row
        self._detail_info.setText('%d / %d' % (d['index'] + 1, d['total']))
        self._d_type.setText(str(d['post_type']).upper())
        n = d['image_count']
        self._d_count.setText('%d image%s' % (n, '' if n == 1 else 's'))
        self._d_date.setText(d['date'])
        self._d_caption.setPlainText(d['caption'] or '')
        tags = d.get('hashtags') or []
        self._d_tags.setText('  '.join('#%s' % t.lstrip('#') for t in tags)
                             if tags else 'none')

        images = d.get('images') or []
        self._set_preview(images[0] if images else '')
        self._fill_strip(images)
        self._stack.setCurrentIndex(1)

    def _set_preview(self, path: str):
        self._preview.clear()
        if not path:
            self._preview.setText('no image')
            return
        try:
            raw = self._s.preview_bytes(path, 900, 520)
        except Exception as error:
            log.debug('preview failed (%s): %s', path, error)
            self._preview.setText('preview unavailable')
            return
        pm = QPixmap()
        if pm.loadFromData(raw, 'JPEG'):
            self._preview.setPixmap(pm)
        else:
            self._preview.setText('preview unavailable')

    def _fill_strip(self, images: list):
        while self._strip_layout.count():
            item = self._strip_layout.takeAt(0)
            w = item.widget()
            if w is not None:
                w.deleteLater()
        if len(images) < 2:
            self._strip.hide()
            return
        self._strip.show()
        for path in images[:12]:
            cell = _label('', 'Preview')
            cell.setFixedSize(THUMB_DETAIL, THUMB_DETAIL)
            cell.setAlignment(Qt.AlignCenter)
            try:
                raw = self._s.thumb_bytes(path, THUMB_DETAIL)
                pm = QPixmap()
                if pm.loadFromData(raw, 'JPEG'):
                    cell.setPixmap(pm)
            except Exception as error:
                log.debug('strip thumb failed (%s): %s', path, error)
            cell.setCursor(Qt.PointingHandCursor)
            cell.mousePressEvent = (lambda _ev, p=path: self._set_preview(p))  # noqa: E731
            self._strip_layout.addWidget(cell)
        self._strip_layout.addStretch()

    def _detail_prev(self):
        if self._detail_index > 0:
            self._show_detail(self._detail_index - 1)

    def _detail_next(self):
        if self._detail_index + 1 < len(self._model.cells()):
            self._show_detail(self._detail_index + 1)

    # -- validate / migrate --------------------------------------------

    def _on_validate(self):
        try:
            result = self._s.validate()
        except Exception as error:
            self._error('Validate', str(error))
            return
        if result['ok']:
            QMessageBox.information(
                self, 'Validate',
                'All good. %d post(s) ready to migrate.' % result['active_count'])
            self._set_status('Validated — %d ready.' % result['active_count'], FG_OK)
            return
        issues = result['issues']
        QMessageBox.warning(
            self, 'Validate',
            '%d problem(s):\n\n%s' % (len(issues), '\n'.join(issues[:15]))
            + ('' if len(issues) <= 15 else '\n…and %d more.' % (len(issues) - 15)))
        self._set_status('%d problem(s) found.' % len(issues), FG_WARN)

    def _on_post(self):
        if self._posting:
            return
        self._save_config()
        preview = self._s.migration_preview()
        if preview.get('error'):
            self._error('Cannot migrate', preview['error'])
            return
        tg = preview.get('trigram_count', 0)
        answer = QMessageBox.question(
            self, 'Transfer & post',
            'Migrate %d post(s) to %s%s?\n\nThis uploads to a live site.'
            % (preview['count'], preview['dest'],
               '' if not tg else ' (%d trigram%s)' % (tg, '' if tg == 1 else 's')),
            QMessageBox.Yes | QMessageBox.No, QMessageBox.No)
        if answer != QMessageBox.Yes:
            return
        result = self._s.start_migration()
        if result.get('error'):
            self._error('Cannot migrate', result['error'])
            return
        self._set_posting(True)
        self._progress.setValue(0)
        self._prog_lbl.setText('0 / %d' % result['total'])
        self._set_status('Migrating…', ACCENT)
        self._timer.start()

    def _set_posting(self, posting: bool):
        self._posting = posting
        for w in (self._parse_btn, self._validate_btn, self._unload_btn,
                  self._post_btn, self._connect_btn):
            w.setEnabled(not posting)
        if not posting:
            self._unload_btn.setEnabled(bool(self._model.cells()))

    def _poll_events(self):
        try:
            result = self._s.poll()
        except Exception as error:
            log.exception('poll failed')
            self._set_status('Migration poll failed: %s' % error, FG_ERR)
            self._timer.stop()
            self._set_posting(False)
            return
        for ev in result.get('events', []):
            kind = ev.get('type')
            if kind == 'progress':
                self._model.update_cell(ev['index'], status=ev['status'])
                current, total = ev['current'], ev['total']
                self._progress.setValue(int(current * 100 / total) if total else 0)
                self._prog_lbl.setText('%d / %d' % (current, total))
                if not ev.get('success'):
                    self._set_status(ev.get('message') or 'A post failed.', FG_ERR)
            elif kind == 'waiting':
                self._set_status('Off-peak only — waiting until %s:00.'
                                 % ev.get('hour'), FG_WARN)
            elif kind == 'error':
                self._set_status(ev.get('message') or 'Migration error.', FG_ERR)
            elif kind == 'done':
                self._set_status('Migration finished — %d post(s).'
                                 % ev.get('total', 0), FG_OK)
        if not result.get('posting'):
            self._timer.stop()
            self._set_posting(False)
            self._update_grid_label()

    def _unload_job(self):
        answer = QMessageBox.question(
            self, 'Unload job',
            'Unload this job and forget its saved progress?\n\n'
            'The photographs already uploaded stay on the site; only the local '
            'record of which ones they were is deleted.',
            QMessageBox.Yes | QMessageBox.No, QMessageBox.No)
        if answer != QMessageBox.Yes:
            return
        try:
            self._s.unload_job()
        except Exception as error:
            self._error('Could not unload', str(error))
            return
        self._model.set_cells([])
        self._grid_section.hide()
        self._cfg.show()
        self._cfg_toggle.setText('▲  CONFIGURATION')
        self._unload_btn.setEnabled(False)
        self._progress.setValue(0)
        self._prog_lbl.setText('')
        self._tg_lbl.setText('')
        self._set_status('Job unloaded. Parse an export to begin.')

    # -- geometry / close ----------------------------------------------

    def _restore_geometry(self):
        """The ini has always had these two keys; the Blink port could not fill
        them (its own TODO says a Chrome --app window does not expose its
        geometry). Qt does, so the window opens where it was left."""
        try:
            geometry = (self._s.config.get('window_geometry') or '').strip()
            if geometry:
                parts = [int(p) for p in geometry.split(',')]
                if len(parts) == 4 and parts[2] > 200 and parts[3] > 200:
                    self.setGeometry(*parts)
            if (self._s.config.get('window_state') or '') == 'zoomed':
                self.showMaximized()
        except Exception as error:        # a corrupt value must never block start-up
            log.debug('could not restore window geometry: %s', error)

    def closeEvent(self, ev):
        if self._posting:
            answer = QMessageBox.question(
                self, 'Migration running',
                'A migration is still running. Close anyway?\n\n'
                'Progress already saved is kept and can be resumed.',
                QMessageBox.Yes | QMessageBox.No, QMessageBox.No)
            if answer != QMessageBox.Yes:
                ev.ignore()
                return
        try:
            data = self._form()
            geo = self.geometry()
            cfg_module.save({
                'url': data['url'], 'api_key': data['api_key'],
                'export_folder': data['export_folder'],
                'copyright_text': data['copyright_text'],
                'import_delay': data['import_delay'],
                'offpeak_only': 'true' if data['offpeak_only'] else 'false',
                'peak_start': str(data['peak_start']),
                'peak_end': str(data['peak_end']),
                'window_state': 'zoomed' if self.isMaximized() else 'normal',
                'window_geometry': '%d,%d,%d,%d' % (geo.x(), geo.y(),
                                                    geo.width(), geo.height()),
            })
        except Exception as error:        # closing must never fail on a save
            log.debug('could not save config on close: %s', error)
        # Stop the thumbnail worker before the window and its model go away.
        # Without this the thread outlives them both; see PostModel.shutdown.
        try:
            self._model.shutdown()
        except Exception as error:
            log.debug('could not stop the thumbnail worker: %s', error)
        super().closeEvent(ev)


def run() -> int:
    app = QApplication.instance() or QApplication(sys.argv)
    app.setApplicationName('UNZUCKER')
    icon = _icon_path()
    if icon:
        app.setWindowIcon(QIcon(icon))
    app.setStyleSheet(STYLE)
    win = MainWindow(core.Session())
    win.show()
    return app.exec()


if __name__ == '__main__':
    raise SystemExit(run())

# ===== SNAPSMACK EOF =====
