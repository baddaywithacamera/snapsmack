"""Bounded, background photo decoding for desktop lists and previews.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""
from collections import OrderedDict
from concurrent.futures import ThreadPoolExecutor
import os

from PySide6.QtCore import QObject, QTimer, QSize, Qt
from PySide6.QtGui import QImageReader, QPixmap, QIcon


class PhotoLoader(QObject):
    """Decode QImages off-thread; create QPixmaps only on the GUI thread.

    Requests are bounded to four. Callers retry visible items on a timer, so
    scrolling never queues thousands of photographs behind obsolete requests.
    """
    def __init__(self, parent):
        super().__init__(parent)
        self._pool = ThreadPoolExecutor(max_workers=2)
        self._pending = {}
        self._cache = OrderedDict()
        self._timer = QTimer(self)
        self._timer.timeout.connect(self._collect)
        self._timer.start(40)
        parent.destroyed.connect(lambda: self._pool.shutdown(wait=False, cancel_futures=True))

    @staticmethod
    def _decode(path, size):
        reader = QImageReader(path)
        reader.setAutoTransform(True)
        original = reader.size()
        if original.isValid():
            reader.setScaledSize(original.scaled(QSize(size, size), Qt.KeepAspectRatio))
        return reader.read()

    def request(self, path, size, callback):
        try:
            key = (path, os.stat(path).st_mtime_ns, size)
        except OSError:
            callback(None)
            return True
        if key in self._cache:
            value = self._cache[key]
            self._cache.move_to_end(key)
            callback(value)
            return True
        if key in self._pending:
            self._pending[key][1].append(callback)
            return True
        if len(self._pending) >= 4:
            return False
        self._pending[key] = (self._pool.submit(self._decode, path, size), [callback])
        return True

    def _collect(self):
        for key, (future, callbacks) in list(self._pending.items()):
            if not future.done():
                continue
            del self._pending[key]
            try:
                image = future.result()
                pixmap = None if image.isNull() else QPixmap.fromImage(image)
            except Exception:
                pixmap = None
            self._cache[key] = pixmap
            while len(self._cache) > 256:
                self._cache.popitem(last=False)
            for callback in callbacks:
                callback(pixmap)


class ListPhotos(QObject):
    """Load only visible rows; UserRole retains the photograph's identity."""
    def __init__(self, widget, path_for_item):
        super().__init__(widget)
        self.widget = widget
        self.path_for_item = path_for_item
        self.loader = PhotoLoader(widget)
        self.generation = 0
        self._requested = set()
        self._timer = QTimer(self)
        self._timer.timeout.connect(self.refresh)
        self._timer.start(80)

    def reset(self):
        self.generation += 1
        self._requested.clear()

    def refresh(self):
        widget = self.widget
        if not widget.isVisible():
            return
        generation = self.generation
        viewport = widget.viewport().rect()
        for row in range(widget.count()):
            item = widget.item(row)
            if not widget.visualItemRect(item).intersects(viewport):
                continue
            path = self.path_for_item(item)
            identity = (id(item), path)
            if identity in self._requested:
                continue
            def apply(pixmap, item=item, identity=identity):
                if generation != self.generation:
                    return
                try:
                    item.setIcon(QIcon(pixmap) if pixmap else QIcon())
                    if pixmap is None:
                        item.setToolTip("Photo unavailable: " + str(identity[1]))
                except RuntimeError:  # row deleted while decoding
                    pass
            if not self.loader.request(path, widget.iconSize().width(), apply):
                break
            self._requested.add(identity)

# ===== SNAPSMACK EOF =====
