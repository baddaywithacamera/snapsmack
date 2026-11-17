"""Collapsible right-hand PHOTOS tray + zoomable thumbnail tiles for COLD TAKE."""

from PySide6.QtCore import Qt, QRect, Signal
from PySide6.QtGui import QColor, QFont, QPainter, QPen, QPixmap
from PySide6.QtWidgets import (
    QFrame, QHBoxLayout, QLabel, QPushButton, QSizePolicy, QStackedLayout,
    QVBoxLayout, QWidget,
)

from . import theme


class RightPhotosTray(QWidget):
    """Collapsible right-hand rail wrapper for COLD TAKE (Ctrl+2).

    Collapsed by default to a thin `PHOTOS · CTRL+2` strip so the 840px page
    fits cleanly on 1366x768 and 1024x768 laptops; expands to the full 360px
    photos + batch rail when toggled open.
    """

    stateChanged = Signal(bool)

    def __init__(self, rail_widget: QWidget, parent=None):
        super().__init__(parent)
        self._opened = False
        self._pinned = True
        self._compact = False
        self._rail = rail_widget
        self._expanded_width = rail_widget.maximumWidth() or 360

        self._stack = QStackedLayout(self)
        self._stack.setContentsMargins(0, 0, 0, 0)
        self._stack.setSpacing(0)

        # Collapsed 30px vertical strip (mirrors BlockTray on the left)
        self._collapsed_bar = QFrame()
        self._collapsed_bar.setObjectName("RightTrayCollapsed")
        self._collapsed_bar.setFixedWidth(30)
        c_col = QVBoxLayout(self._collapsed_bar)
        c_col.setContentsMargins(2, 8, 2, 8)
        c_col.setSpacing(6)
        self._open_btn = QPushButton("◀")
        self._open_btn.setObjectName("TrayToggle")
        self._open_btn.setFixedSize(26, 26)
        self._open_btn.setFocusPolicy(Qt.NoFocus)
        self._open_btn.setToolTip("Open the photos tray (Ctrl+2)")
        self._open_btn.clicked.connect(lambda: self.set_open(True))
        c_col.addWidget(self._open_btn, 0, Qt.AlignHCenter)

        self._strip_label = QPushButton("P\nH\nO\nT\nO\nS\n\n·\n\nC\nT\nR\nL\n+\n2")
        self._strip_label.setObjectName("RightTrayStripBtn")
        self._strip_label.setFocusPolicy(Qt.NoFocus)
        self._strip_label.setToolTip("Open the photos tray (Ctrl+2)")
        self._strip_label.clicked.connect(lambda: self.set_open(True))
        c_col.addWidget(self._strip_label, 1, Qt.AlignTop | Qt.AlignHCenter)
        c_col.addStretch(1)

        self._stack.addWidget(self._collapsed_bar)
        self._stack.addWidget(self._rail)
        self._apply_state()

    def is_open(self) -> bool:
        return self._opened

    def set_open(self, opened: bool):
        opened = bool(opened)
        if self._opened == opened:
            return
        self._opened = opened
        self._apply_state()
        self.stateChanged.emit(self._opened)

    def toggle(self):
        self.set_open(not self._opened)

    def set_pinned(self, pinned: bool):
        self._pinned = bool(pinned)

    def is_pinned(self) -> bool:
        return self._pinned

    def set_compact_window(self, compact: bool):
        self._compact = bool(compact)
        if not self._opened:
            self.setFixedWidth(30)

    def _apply_state(self):
        if self._opened:
            self._stack.setCurrentWidget(self._rail)
            self.setFixedWidth(self._expanded_width)
        else:
            self._stack.setCurrentWidget(self._collapsed_bar)
            self.setFixedWidth(30)


class PhotoThumbTile(QPushButton):
    """Square thumbnail tile with selection border and status badges."""

    def __init__(self, index: int, pixmap: QPixmap, selected: bool = False,
                 is_cover: bool = False, edited: bool = False,
                 in_slapper: bool = False, tile_size: int = 92, parent=None):
        super().__init__(parent)
        self._index = index
        self._pixmap = pixmap
        self._selected = selected
        self._is_cover = is_cover
        self._edited = edited
        self._in_slapper = in_slapper
        self._tile_size = max(56, int(tile_size))
        self.setObjectName("PhotoThumbTile")
        self.setCheckable(True)
        self.setChecked(selected)
        self.setFixedSize(self._tile_size, self._tile_size)
        self.setCursor(Qt.PointingHandCursor)
        self.setFocusPolicy(Qt.NoFocus)

    def paintEvent(self, _event):
        p = QPainter(self)
        p.setRenderHint(QPainter.Antialiasing, True)
        p.setRenderHint(QPainter.SmoothPixmapTransform, True)

        w, h = self.width(), self.height()
        rect = QRect(1, 1, w - 2, h - 2)
        p.fillRect(self.rect(), QColor(theme.FIELD_BG))

        if not self._pixmap.isNull():
            scaled = self._pixmap.scaled(
                w - 4, h - 4, Qt.KeepAspectRatioByExpanding, Qt.SmoothTransformation)
            sx = max(0, (scaled.width() - (w - 4)) // 2)
            sy = max(0, (scaled.height() - (h - 4)) // 2)
            p.drawPixmap(2, 2, scaled, sx, sy, w - 4, h - 4)

        if self._in_slapper:
            p.fillRect(rect, QColor(10, 14, 10, 185))
            p.setPen(QColor(theme.ACCENT))
            f = QFont(self.font())
            f.setPointSize(8)
            f.setBold(True)
            p.setFont(f)
            p.drawText(rect, Qt.AlignCenter, "⟳\nIn SNAP\nSLAPPER")

        # Bottom-left number badge
        num_txt = f"{self._index + 1:02d}"
        f_badge = QFont(self.font())
        f_badge.setPointSize(8)
        f_badge.setBold(True)
        p.setFont(f_badge)
        num_rect = QRect(5, h - 20, 22, 15)
        p.setPen(Qt.NoPen)
        p.setBrush(QColor(10, 12, 10, 210))
        p.drawRoundedRect(num_rect, 3, 3)
        p.setPen(QColor("#ffffff"))
        p.drawText(num_rect, Qt.AlignCenter, num_txt)

        # Top-right lead star
        if self._is_cover:
            star_rect = QRect(w - 22, 5, 17, 17)
            p.setPen(Qt.NoPen)
            p.setBrush(QColor(10, 12, 10, 210))
            p.drawRoundedRect(star_rect, 3, 3)
            p.setPen(QColor("#f5b82e"))
            p.drawText(star_rect, Qt.AlignCenter, "★")

        # Top-left EDITED badge + bottom-right version count badge
        if self._edited:
            ed_rect = QRect(5, 5, 48, 15)
            p.setPen(Qt.NoPen)
            p.setBrush(QColor(theme.ACCENT))
            p.drawRoundedRect(ed_rect, 3, 3)
            p.setPen(QColor("#0a1407"))
            f_sm = QFont(f_badge)
            f_sm.setPointSize(7)
            p.setFont(f_sm)
            p.drawText(ed_rect, Qt.AlignCenter, "EDITED")

            ver_rect = QRect(w - 27, h - 20, 22, 15)
            p.setPen(Qt.NoPen)
            p.setBrush(QColor(10, 12, 10, 210))
            p.drawRoundedRect(ver_rect, 3, 3)
            p.setPen(QColor("#ffffff"))
            p.drawText(ver_rect, Qt.AlignCenter, "v2")

        # Selection / hover border
        border_col = QColor(theme.ACCENT if self._selected else theme.FIELD_EDGE)
        border_w = 2 if self._selected else 1
        p.setPen(QPen(border_col, border_w))
        p.setBrush(Qt.NoBrush)
        p.drawRoundedRect(1, 1, w - 2, h - 2, 4, 4)
        p.end()


# ===== SNAPSMACK EOF =====
