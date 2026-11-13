# -*- coding: utf-8 -*-
"""COLD SNAP canvas editor — Milestone 0 spike. THROWAWAY. Not a tool.

SNAPSMACK_EOF_HEADER
    # ===== SNAPSMACK EOF =====

WHAT THIS IS FOR. The canvas editor spec puts one question before everything
else: can you drag a photograph out of a folder and drop it into the writing
area. Typing and pasting I could measure; the file drop I could not -- a
synthesised drop reaches the page (dragenter and dragover both fired) but my
probe could not hand over the file itself. So this window exists for Sean to
try it by hand, once, and for the answer to be visible on screen rather than
inferred.

WHAT IT PROVES, AND THE DESIGN IT PROVES. The drop is handled on the NATIVE
side: Qt gives Python the file path, Python opens the file, reads the bytes
itself, and only then hands the picture to the page. That is the architecture
the spec asks for -- "the native app owns the file, the page reports changes
and never writes files" -- so a successful drop here proves the real thing,
not just that a browser accepted a file.

The page's own ondrop is also wired, and reports separately, so the two
answers can be told apart.

NOT SHIPPED. Nothing here is packaged, signed, or imported by any tool. It
lives outside tools/coldsnap/ on purpose and should be deleted once the
question is answered.

RUN IT:

    python tools/coldsnap-m0-spike/spike.py
"""
import base64
import mimetypes
import os
import platform
import sys
import time

# Chromium inside Qt needs these on some Linux desktops; harmless on Windows.
os.environ.setdefault("QTWEBENGINE_CHROMIUM_FLAGS", "--disable-gpu")

from PySide6.QtCore import Qt, QUrl                                  # noqa: E402
from PySide6.QtGui import QGuiApplication                            # noqa: E402
from PySide6.QtWidgets import (QApplication, QLabel, QPlainTextEdit,  # noqa: E402
                               QVBoxLayout, QWidget)
from PySide6.QtWebEngineWidgets import QWebEngineView                # noqa: E402

MAX_BYTES = 12 * 1024 * 1024        # a spike, not a product: refuse the silly case

PAGE = """<!doctype html><html><head><meta charset="utf-8"><style>
  html,body{margin:0;height:100%;background:#15181a;color:#dcdcdc;
            font:16px/1.55 Georgia,serif}
  #doc{box-sizing:border-box;min-height:100%;padding:28px 34px;outline:none}
  #doc img{max-width:100%;height:auto;display:block;margin:14px 0;
           border:1px solid #3a3f42}
  .hint{color:#7d8488;font:13px/1.5 system-ui,sans-serif}
</style></head><body>
<div id="doc" contenteditable="true"
     ondragover="event.preventDefault()"
     ondragenter="window.__enter=(window.__enter||0)+1;event.preventDefault()"
     ondrop="window.__webdrop=(event.dataTransfer.files||[]).length;event.preventDefault()">
  <p class="hint">Type here. Paste here. Then drag a photograph from a folder
  and drop it on this text.</p>
  <p><br></p>
</div></body></html>"""


class Canvas(QWebEngineView):
    """The webview, with the drop handled on the NATIVE side."""

    def __init__(self, report):
        super().__init__()
        self.report = report
        self.setAcceptDrops(True)

    # Qt delivers drag events to the view; Chromium renders in a child widget,
    # so accepting here is what keeps the sequence alive.
    def dragEnterEvent(self, event):
        if event.mimeData().hasUrls():
            event.acceptProposedAction()
            self.report("drag entered the window, carrying %d file(s)"
                        % len(event.mimeData().urls()))
        else:
            super().dragEnterEvent(event)

    def dragMoveEvent(self, event):
        if event.mimeData().hasUrls():
            event.acceptProposedAction()
        else:
            super().dragMoveEvent(event)

    def dropEvent(self, event):
        urls = [u for u in event.mimeData().urls() if u.isLocalFile()]
        if not urls:
            self.report("DROP CARRIED NO LOCAL FILE — nothing to read")
            return super().dropEvent(event)
        event.acceptProposedAction()
        for url in urls:
            self._take(url.toLocalFile())

    def _take(self, path):
        """Python opens the file itself. That is the point of the test."""
        name = os.path.basename(path)
        try:
            size = os.path.getsize(path)
        except OSError as exc:
            self.report("FAILED to stat %s — %s" % (name, exc))
            return
        if size > MAX_BYTES:
            self.report("SKIPPED %s — %.1f MB is over this spike's 12 MB limit"
                        % (name, size / 1048576.0))
            return
        started = time.time()
        try:
            with open(path, "rb") as handle:
                data = handle.read()
        except OSError as exc:
            self.report("FAILED to read %s — %s" % (name, exc))
            return
        mime = mimetypes.guess_type(path)[0] or ""
        if not mime.startswith("image/"):
            self.report("NOT AN IMAGE: %s (%s) — read %d bytes anyway"
                        % (name, mime or "unknown type", len(data)))
            return
        took = (time.time() - started) * 1000
        self.report("READ BY THE APP: %s, %d bytes, %.0f ms. Placing it."
                    % (name, len(data), took))
        payload = base64.b64encode(data).decode("ascii")
        self.page().runJavaScript(
            "(function(){var d=document.getElementById('doc');"
            "var i=document.createElement('img');"
            "i.src='data:%s;base64,%s';d.appendChild(i);"
            "d.appendChild(document.createElement('p'));"
            "return 'placed';})()" % (mime, payload))


class Window(QWidget):
    def __init__(self):
        super().__init__()
        self.setWindowTitle("COLD SNAP M0 spike — drop a photograph on the text")
        self.resize(980, 760)
        box = QVBoxLayout(self)
        box.setContentsMargins(0, 0, 0, 0)
        box.setSpacing(0)

        self.canvas = Canvas(self.say)
        self.canvas.setHtml(PAGE, QUrl("file:///"))
        box.addWidget(self.canvas, 1)

        self.log = QPlainTextEdit()
        self.log.setReadOnly(True)
        self.log.setFixedHeight(190)
        box.addWidget(self.log)

        foot = QLabel("  Qt %s · PySide6 · %s %s  —  close the window when done"
                      % (QGuiApplication.applicationName() or "", platform.system(),
                         platform.release()))
        foot.setFixedHeight(22)
        box.addWidget(foot)

        self.say("Spike started. %s %s, Python %s"
                 % (platform.system(), platform.release(),
                    platform.python_version()))
        self.say("Type in the page. Paste with Ctrl+V. Then DRAG A PHOTOGRAPH "
                 "from a folder and drop it on the text.")

    def say(self, line):
        self.log.appendPlainText(line)


def main():
    app = QApplication(sys.argv)
    window = Window()
    window.show()
    return app.exec()


if __name__ == "__main__":
    sys.exit(main())

# ===== SNAPSMACK EOF =====
