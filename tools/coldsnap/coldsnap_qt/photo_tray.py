"""Collapsible right-hand PHOTOS tray + zoomable thumbnail tiles for COLD TAKE."""

import json

from PySide6.QtCore import QMimeData, QPoint, Qt, QRect, Signal
from PySide6.QtGui import QColor, QDrag, QFont, QPainter, QPen, QPixmap
from PySide6.QtWidgets import (
    QApplication, QFrame, QHBoxLayout, QLabel, QPushButton, QSizePolicy,
    QStackedLayout, QVBoxLayout, QWidget,
)

TRAY_PHOTO_MIME = "application/x-coldsnap-tray-photo"

from . import theme


class RightPhotosTray(QWidget):
    """Collapsible right-hand rail wrapper for COLD TAKE (Ctrl+2).

    Collapsed by default to a thin `PHOTOS · CTRL+2` strip so the 840px page
    fits cleanly on 1366x768 and 1024x768 laptops; expands to the full 360px
    photos + batch rail when toggled open.
    """

    stateChanged = Signal(bool)
    deletePressed = Signal()

    def __init__(self, rail_widget: QWidget, parent=None):
        super().__init__(parent)
        self.setFocusPolicy(Qt.ClickFocus)
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

    def keyPressEvent(self, event):
        if event.key() == Qt.Key_Delete and self._opened:
            self.deletePressed.emit()
            event.accept()
            return
        super().keyPressEvent(event)


def photo_orientation_badge(width: int, height: int, path: str = "") -> tuple:
    """Return (badge_code, ratio) where badge_code is 'P', 'L', or 'SQ'."""
    w, h = int(width or 0), int(height or 0)
    if (w <= 0 or h <= 0) and path:
        try:
            from PySide6.QtGui import QImageReader, QImageIOHandler
            reader = QImageReader(path)
            sz = reader.size()
            if sz.isValid():
                w, h = sz.width(), sz.height()
                rot_bit = int(QImageIOHandler.Transformation.TransformationRotate90.value)
                flags = int(getattr(reader.transformation(), "value", reader.transformation()))
                if flags & rot_bit:
                    w, h = h, w
        except Exception:
            pass
    if w <= 0 or h <= 0:
        return ("", 1.0)
    ratio = w / float(max(1, h))
    if ratio > 1.08:
        return ("L", ratio)
    if ratio < 0.92:
        return ("P", ratio)
    return ("SQ", ratio)


class PhotoThumbTile(QPushButton):
    """Square thumbnail tile with letterboxed true-aspect preview and status badges."""

    selectionClicked = Signal(int, bool)  # (bucket_index, toggle_multi)
    setFeaturedRequested = Signal(int)    # (bucket_index)
    removeRequested = Signal(int)         # (bucket_index)

    def __init__(self, index: int, pixmap: QPixmap, selected: bool = False,
                 is_cover: bool = False, edited: bool = False,
                 in_slapper: bool = False, tile_size: int = 92,
                 asset_uuid: str = "", orientation: str = "",
                 ratio_text: str = "", parent=None):
        super().__init__(parent)
        self._index = index
        self._asset_uuid = str(asset_uuid or "")
        self._pixmap = pixmap
        self._selected = selected
        self._is_cover = is_cover
        self._edited = edited
        self._in_slapper = in_slapper
        self._orientation = str(orientation or "")
        self._ratio_text = str(ratio_text or "")
        self._tile_size = max(56, int(tile_size))
        self._drag_start: QPoint | None = None
        self.setObjectName("PhotoThumbTile")
        self.setCheckable(True)
        self.setChecked(selected)
        self.setFixedSize(self._tile_size, self._tile_size)
        self.setCursor(Qt.PointingHandCursor)
        self.setFocusPolicy(Qt.NoFocus)

    def _check_rect(self) -> QRect:
        return QRect(4, 22 if self._edited else 4, 18, 18)

    def mousePressEvent(self, event):
        w = self.parentWidget()
        while w is not None:
            if isinstance(w, RightPhotosTray):
                w.setFocus(Qt.MouseFocusReason)
                break
            w = w.parentWidget()
        if event.button() == Qt.LeftButton:
            self._drag_start = event.position().toPoint()
            mods = event.modifiers()
            in_check = self._check_rect().contains(self._drag_start)
            toggle = bool((mods & (Qt.ControlModifier | Qt.ShiftModifier)) or in_check)
            self.selectionClicked.emit(self._index, toggle)
        super().mousePressEvent(event)

    def mouseMoveEvent(self, event):
        if ((event.buttons() & Qt.LeftButton) and self._drag_start is not None
                and (event.position().toPoint() - self._drag_start).manhattanLength()
                >= QApplication.startDragDistance()):
            self._drag_start = None
            drag = QDrag(self)
            mime = QMimeData()
            payload = json.dumps({"asset_uuid": self._asset_uuid, "index": self._index}).encode("utf-8")
            mime.setData(TRAY_PHOTO_MIME, payload)
            drag.setMimeData(mime)
            if not self._pixmap.isNull():
                drag.setPixmap(self._pixmap.scaled(64, 64, Qt.KeepAspectRatio, Qt.SmoothTransformation))
            drag.exec(Qt.CopyAction)
            return
        super().mouseMoveEvent(event)

    def contextMenuEvent(self, event):
        from PySide6.QtWidgets import QMenu
        menu = QMenu(self)
        act_feat = menu.addAction("★ Set as featured (landing tile)")
        act_feat.setEnabled(not self._is_cover)
        menu.addSeparator()
        act_rem = menu.addAction("Remove from tray")
        chosen = menu.exec(event.globalPos())
        if chosen is act_feat:
            self.setFeaturedRequested.emit(self._index)
        elif chosen is act_rem:
            self.removeRequested.emit(self._index)

    def paintEvent(self, _event):
        p = QPainter(self)
        p.setRenderHint(QPainter.Antialiasing, True)
        p.setRenderHint(QPainter.SmoothPixmapTransform, True)

        w, h = self.width(), self.height()
        rect = QRect(1, 1, w - 2, h - 2)
        p.fillRect(self.rect(), QColor(theme.FIELD))

        if not self._pixmap.isNull():
            box_w, box_h = max(1, w - 4), max(1, h - 4)
            scaled = self._pixmap.scaled(
                box_w, box_h, Qt.KeepAspectRatio, Qt.SmoothTransformation)
            dx = 2 + (box_w - scaled.width()) // 2
            dy = 2 + (box_h - scaled.height()) // 2
            p.drawPixmap(dx, dy, scaled)

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

        # Top-right labelled featured badge
        if self._is_cover:
            bw = min(64, max(44, w - 10))
            star_rect = QRect(w - bw - 4, 5, bw, 15)
            p.setPen(Qt.NoPen)
            p.setBrush(QColor(22, 18, 8, 225))
            p.drawRoundedRect(star_rect, 3, 3)
            p.setPen(QColor("#f5b82e"))
            f_feat = QFont(f_badge)
            f_feat.setPointSize(7)
            p.setFont(f_feat)
            p.drawText(star_rect, Qt.AlignCenter, "★ FEATURED")

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

            ver_rect = QRect(w - 27, h - 38 if self._orientation else h - 20, 22, 15)
            p.setPen(Qt.NoPen)
            p.setBrush(QColor(10, 12, 10, 210))
            p.drawRoundedRect(ver_rect, 3, 3)
            p.setPen(QColor("#ffffff"))
            p.drawText(ver_rect, Qt.AlignCenter, "v2")

        # Bottom-right P / L / SQ orientation badge (+ optional ratio)
        if self._orientation:
            ori_txt = f"{self._orientation} · {self._ratio_text}" if self._ratio_text else self._orientation
            badge_w = max(22, len(ori_txt) * 6 + 10)
            ori_rect = QRect(w - badge_w - 5, h - 20, badge_w, 15)
            p.setPen(Qt.NoPen)
            p.setBrush(QColor(10, 12, 10, 220))
            p.drawRoundedRect(ori_rect, 3, 3)
            ori_col = (
                "#6ecbff" if self._orientation == "P"
                else ("#39FF14" if self._orientation == "L" else "#f5b82e")
            )
            p.setPen(QColor(ori_col))
            p.setFont(f_badge)
            p.drawText(ori_rect, Qt.AlignCenter, ori_txt)

        # Multi-select check badge (top-left, or below EDITED badge)
        chk_rect = self._check_rect()
        p.setPen(QPen(QColor(theme.ACCENT if self._selected else "#9aa39b"), 1))
        p.setBrush(QColor(theme.ACCENT) if self._selected else QColor(10, 12, 10, 185))
        p.drawRoundedRect(chk_rect, 3, 3)
        if self._selected:
            p.setPen(QColor("#0a1407"))
            p.setFont(f_badge)
            p.drawText(chk_rect, Qt.AlignCenter, "✓")

        # Selection / hover border
        border_col = QColor(theme.ACCENT if self._selected else theme.BORDER)
        border_w = 2 if self._selected else 1
        p.setPen(QPen(border_col, border_w))
        p.setBrush(Qt.NoBrush)
        p.drawRoundedRect(1, 1, w - 2, h - 2, 4, 4)
        p.end()


# ===== SNAPSMACK EOF =====
