"""Run the CMS mosaic engines in a local-only desktop browser."""

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the canonical Python EOF marker below.

from functools import lru_cache
import json
from pathlib import Path
import sys

from PySide6.QtCore import Qt, QUrl, Signal
from PySide6.QtGui import QImageIOHandler, QImageReader
from PySide6.QtWebEngineCore import QWebEngineSettings
from PySide6.QtWebEngineWidgets import QWebEngineView
from shiboken6 import isValid


_DOCUMENT = """<!doctype html><meta charset="utf-8">
<meta http-equiv="Content-Security-Policy" content="default-src 'none';
    script-src file:; style-src file: 'unsafe-inline'; img-src file: data:">
<link rel="stylesheet" href="css/ss-engine-mosaic.css">
<link rel="stylesheet" href="css/ss-engine-scroll-wall.css">
<style>body{margin:0; color:#ddd; background:#090b0a; overflow:hidden}
    .cold-mosaic-hint{font:14px sans-serif;text-align:center;padding:84px 0}
    .preview-grid{position:relative;margin:0}
    .preview-grid .ss-masonry-item{display:block}
    .preview-grid .ss-masonry-item img{width:100%;height:100%;object-fit:cover}
</style>
<main id="preview" aria-label="Mosaic preview"><p class="cold-mosaic-hint">Select photos to preview the mosaic.</p></main>
<script defer src="js/ss-engine-mosaic.js"></script>
<script defer src="js/ss-engine-columns.js"></script>
<script defer src="js/ss-engine-rows.js"></script>
"""
_MEASURE_PIXEL = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'


@lru_cache(maxsize=512)
def _photo_size(path, mtime_ns, size):
    reader = QImageReader(path)
    dimensions = reader.size()
    if dimensions.width() > 0 and dimensions.height() > 0:
        if reader.transformation() & QImageIOHandler.Transformation.TransformationRotate90:
            return dimensions.height(), dimensions.width()
        return dimensions.width(), dimensions.height()
    return 3, 2


class MosaicBrowser(QWebEngineView):
    geometry_ready = Signal(object)
    failed = Signal(str)

    def __init__(self, parent=None, *, measure_only=False):
        super().__init__(parent)
        self._measure_only = measure_only
        self._ready = False
        self._revision = 0
        self._pending = None
        self._visual_scale = 1.0
        self.setAttribute(Qt.WidgetAttribute.WA_TransparentForMouseEvents)
        self.setFocusPolicy(Qt.FocusPolicy.NoFocus)
        settings = self.settings()
        settings.setAttribute(QWebEngineSettings.WebAttribute.LocalContentCanAccessFileUrls, True)
        settings.setAttribute(QWebEngineSettings.WebAttribute.LocalContentCanAccessRemoteUrls, False)
        settings.setAttribute(QWebEngineSettings.WebAttribute.JavascriptCanOpenWindows, False)
        self.loadFinished.connect(self._loaded)
        self.renderProcessTerminated.connect(self._renderer_stopped)
        self.setHtml(_DOCUMENT, QUrl.fromLocalFile(str(self._assets()) + '/'))

    @staticmethod
    def _assets():
        if getattr(sys, 'frozen', False):
            return Path(sys._MEIPASS) / 'preview_engines'
        return Path(__file__).resolve().parents[3] / 'assets'

    @staticmethod
    @lru_cache(maxsize=1)
    def _adapter():
        root = Path(sys._MEIPASS) if getattr(sys, 'frozen', False) else Path(__file__).resolve().parents[1]
        return (root / 'assets' / 'coldsnap-mosaic-preview.js').read_text(encoding='utf-8')

    def set_mosaic(self, tiles, layout, gap, weights, width, mirrored_sections=(),
                   *, emphasis="natural", focus_positions=None):
        images = []
        focus_positions = focus_positions or {}
        for index, tile in enumerate(tiles, 1):
            source = Path(tile[1]).resolve()
            thumbnail = Path(tile[2]).resolve() if len(tile) > 2 and tile[2] else source
            if thumbnail != source and not thumbnail.is_file():
                thumbnail = source
            try:
                stat = source.stat()
                w, h = _photo_size(str(source), stat.st_mtime_ns, stat.st_size)
            except OSError:
                w, h = 3, 2
            focus = focus_positions.get(index) or {}
            images.append({
                'id': index, 'src': _MEASURE_PIXEL if self._measure_only
                else QUrl.fromLocalFile(str(thumbnail)).toString(),
                'full': '' if self._measure_only else QUrl.fromLocalFile(str(source)).toString(),
                'alt': source.name, 'width': w, 'height': h,
                'weight': weights.get(str(index), 0),
                'focusX': focus.get('x', 50), 'focusY': focus.get('y', 50),
            })
        self._revision += 1
        self._pending = {'images': images, 'layout': layout, 'gap': gap, 'width': width,
                         'mirroredSections': list(mirrored_sections), 'emphasis': emphasis}
        if self._ready:
            self._render()

    def _renderer_stopped(self, status, _exit_code):
        if status != self.page().RenderProcessTerminationStatus.NormalTerminationStatus:
            self._ready = False
            self._revision += 1
            self.failed.emit("The mosaic preview renderer stopped.")

    def _loaded(self, ok):
        if not ok:
            self.failed.emit('The local mosaic preview could not load.')
            return
        self._ready = True
        if self._pending is not None:
            self._render()

    def _render(self):
        revision = self._revision
        script = self._adapter() + '\n' + (
            "(() => { const preview = document.getElementById('preview');"
            "const previous = preview.style.transform; preview.style.transform = 'none';"
            "try { return JSON.stringify(coldSnapRenderMosaic(" + json.dumps(self._pending) + ")); }"
            "finally { preview.style.transform = previous; } })();")
        self.page().runJavaScript(script, lambda output: self._receive(revision, output))

    def set_visual_scale(self, scale):
        """Fit the painted preview without giving the compositor a different width."""
        scale = float(scale)
        if not self._ready or self._visual_scale == scale:
            return
        self._visual_scale = scale
        self.page().runJavaScript(
            "(() => { const preview = document.getElementById('preview');"
            "preview.style.transformOrigin = 'top left';"
            f"preview.style.transform = 'scale({scale})'; }})();")

    def _receive(self, revision, output):
        if not isValid(self) or revision != self._revision:
            return
        if not output:
            self.failed.emit('The shared mosaic engine is unavailable.')
            return
        self.geometry_ready.emit(json.loads(output))


# ===== SNAPSMACK EOF =====
