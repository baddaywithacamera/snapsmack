"""
FLKR FCKR — flkrfckr_qt.py
Qt (PySide6) window over flkrfckr_core.Session — the Windows exe's UI.

WHAT THIS IS
    A straight port of the tkinter window (main.py) onto Qt, feature for
    feature: settings bar (site/key/Connect, export folder/Browse, throttle,
    off-peak hours, Private→, Load Export, Key / Logs / ? buttons), the photo
    grid with lazy square thumbnails and click-to-exclude, the album sidebar
    with All/Unalbumed filters, the run panel (summary, progress, Start/Pause/
    Resume), the 5-line log with Pop Out, the Key-security window, the
    passphrase prompts, the step-up (password + 2FA) dialog, the resume-after-
    crash offer, the help window. Nothing was dropped.

    The WORK is not here. Every action calls flkrfckr_core.Session, the same
    tkinter-free engine the Linux port drives, so parse / connect / authorize /
    import / checkpoint / vault behave identically to the old window. This file
    is widgets and dialogs only.

    Data safety (unchanged): comments attach to the image id, GPS/EXIF are
    preserved, there is no strip-location option, and the import reads the
    SAME filtered list the grid shows, so nothing invisible is ever uploaded.

    Two things the Tk window could not do and this one does:
      * no 600-tile render cap — Qt's list view paints only what is on screen,
        so a 9,000-photo export shows every tile (the cap was a Tk workaround).
      * "Default album" has a field. The help always said to enter it "in the
        settings" but the Tk bar never had the box; only a hand-edited ini
        could set it.

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

from PySide6.QtCore import (QAbstractListModel, QModelIndex, QObject, QRect, QSize, Qt,
                            QTimer, Signal)
from PySide6.QtGui import QColor, QFont, QFontMetrics, QIcon, QPainter, QPixmap
from PySide6.QtWidgets import (QApplication, QCheckBox, QComboBox, QDialog, QFileDialog,
                               QFrame, QGridLayout, QHBoxLayout, QLabel, QLineEdit,
                               QListView, QListWidget, QListWidgetItem, QMainWindow,
                               QMessageBox, QPlainTextEdit, QProgressBar, QPushButton,
                               QRadioButton, QSizePolicy, QSplitter, QStyledItemDelegate,
                               QTextBrowser, QVBoxLayout, QWidget)

import flkrfckr_core as core
import snap_stepup

BUILD_VERSION = core.BUILD_VERSION
log = logging.getLogger('flkrfckr')


def _excepthook(exc_type, exc_value, exc_tb):
    if not issubclass(exc_type, KeyboardInterrupt):
        log.critical('Unhandled exception', exc_info=(exc_type, exc_value, exc_tb))
    sys.__excepthook__(exc_type, exc_value, exc_tb)


sys.excepthook = _excepthook

# ---------------------------------------------------------------------------
# Palette — MIDNIGHT LIME, the SNAP SLAPPER / COLD SNAP / SYBU family look.
# Greys are the lifted COLD SNAP set so labels stay readable in a lit room
# (the reason the Tk window went mid-grey in the first place).
# ---------------------------------------------------------------------------

BG        = '#0d0e0e'
PANEL     = '#171817'
CELL      = '#222422'
CELL_HI   = '#303330'
BORDER    = '#343734'
INK       = '#f2f2f2'
DIM       = '#9a9a9a'
ACCENT    = '#39FF14'
ACCENT_HI = '#5bff42'
WARN      = '#ffc24d'
ERR       = '#ff6b6b'
THUMB_BG  = '#333333'

# core log levels → colours (same mapping the Tk palette used)
LEVEL_COLOUR = {core.OK: ACCENT, core.WARN: WARN, core.ERR: ERR, core.DIM: DIM, core.PRI: INK}

STYLE = f"""
QWidget {{ background:{BG}; color:{INK}; font-family:'Segoe UI'; font-size:13px; }}
QFrame#Bar {{ background:{PANEL}; border-bottom:1px solid {BORDER}; }}
QFrame#Panel {{ background:{PANEL}; border-top:1px solid {BORDER}; }}
QFrame#Side {{ background:{PANEL}; border:1px solid {BORDER}; }}
QFrame#FilterBar {{ background:{PANEL}; border:1px solid {BORDER}; }}
QLabel {{ background:transparent; }}
QLabel#Muted {{ color:{DIM}; font-size:12px; }}
QLabel#Eyebrow {{ color:{DIM}; font-size:11px; font-weight:800; letter-spacing:2px; }}
QLineEdit, QComboBox {{ background:{CELL}; border:1px solid {BORDER}; border-radius:6px;
    padding:5px 7px; min-height:20px; selection-background-color:{ACCENT}; selection-color:#000; }}
QLineEdit:focus, QComboBox:focus {{ border-color:{ACCENT}; }}
QComboBox::drop-down {{ border:0; width:22px; }}
QComboBox QAbstractItemView {{ background:{CELL}; border:1px solid {BORDER};
    selection-background-color:{CELL_HI}; }}
QPushButton {{ background:{CELL}; border:1px solid {BORDER}; border-radius:6px;
    padding:6px 12px; font-weight:650; min-height:18px; }}
QPushButton:hover {{ border-color:{ACCENT}; background:{CELL_HI}; }}
QPushButton:disabled {{ color:{DIM}; }}
QPushButton#Primary {{ color:#000; background:{ACCENT}; border-color:{ACCENT}; font-weight:850; }}
QPushButton#Primary:hover {{ background:{ACCENT_HI}; }}
QPushButton#Primary:disabled {{ color:#3a3a3a; background:#1f3d17; border-color:#1f3d17; }}
QPushButton#Pause {{ color:#000; background:{WARN}; border-color:{WARN}; font-weight:850; }}
QPushButton#Quiet {{ color:{DIM}; padding:4px 9px; }}
QPushButton#Danger {{ color:{WARN}; }}
QCheckBox, QRadioButton {{ background:transparent; spacing:6px; }}
QCheckBox::indicator, QRadioButton::indicator {{ width:14px; height:14px; border:1px solid {BORDER};
    background:{CELL}; }}
QRadioButton::indicator {{ border-radius:7px; }}
QCheckBox::indicator:checked, QRadioButton::indicator:checked {{ background:{ACCENT}; border-color:{ACCENT}; }}
QListWidget {{ background:{PANEL}; border:0; outline:0; }}
QListWidget::item {{ padding:4px 6px; }}
QListWidget::item:selected {{ background:#2f5130; color:{INK}; }}
QListView#Grid {{ background:{BG}; border:1px solid {BORDER}; }}
QPlainTextEdit, QTextBrowser {{ background:{BG}; border:1px solid {BORDER}; border-radius:6px; }}
QPlainTextEdit#Log {{ font-family:Consolas,'Courier New',monospace; font-size:12px; }}
QProgressBar {{ background:{CELL}; border:1px solid {BORDER}; border-radius:6px; height:14px; text-align:center; color:{INK}; }}
QProgressBar::chunk {{ background:{ACCENT}; border-radius:5px; }}
QScrollBar:vertical {{ background:{BG}; width:12px; }}
QScrollBar::handle:vertical {{ background:{CELL_HI}; border-radius:5px; min-height:24px; }}
QScrollBar::add-line:vertical, QScrollBar::sub-line:vertical {{ height:0; }}
QSplitter::handle {{ background:{BG}; }}
"""


def _e(text: str) -> str:
    """Truncate long strings for display (same rule as the Tk grid)."""
    text = text or ''
    return text if len(text) <= 60 else text[:57] + '...'


def _icon_path() -> str:
    base = getattr(sys, '_MEIPASS', os.path.dirname(os.path.abspath(__file__)))
    return os.path.join(base, 'assets', 'icon.ico')


def _label(text: str, name: str = '') -> QLabel:
    w = QLabel(text)
    if name:
        w.setObjectName(name)
    return w


def _button(text: str, name: str = '', slot=None) -> QPushButton:
    b = QPushButton(text)
    if name:
        b.setObjectName(name)
    b.setCursor(Qt.PointingHandCursor)
    if slot:
        b.clicked.connect(slot)
    return b


def _restyle(w: QWidget) -> None:
    """Re-apply the stylesheet after an objectName change (Qt caches by name)."""
    w.style().unpolish(w)
    w.style().polish(w)


# ---------------------------------------------------------------------------
# Dialogs
# ---------------------------------------------------------------------------

class PassphraseDialog(QDialog):
    """Modal passphrase prompt. `value` holds the passphrase after Accepted."""

    def __init__(self, parent, title: str, prompt: str, confirm: bool = False):
        super().__init__(parent)
        self.setWindowTitle(title)
        self.setModal(True)
        self.value: Optional[str] = None
        self._confirm = confirm

        lay = QVBoxLayout(self)
        lay.setContentsMargins(16, 14, 16, 14)
        msg = _label(prompt)
        msg.setWordWrap(True)
        msg.setMaximumWidth(400)
        lay.addWidget(msg)

        self._e1 = QLineEdit()
        self._e1.setEchoMode(QLineEdit.Password)
        lay.addWidget(self._e1)
        self._e2 = QLineEdit()
        self._e2.setEchoMode(QLineEdit.Password)
        if confirm:
            lay.addWidget(_label('Type it again', 'Muted'))
            lay.addWidget(self._e2)
        self._err = _label('')
        self._err.setStyleSheet(f'color:{ERR};')
        self._err.setWordWrap(True)
        lay.addWidget(self._err)

        row = QHBoxLayout()
        row.addStretch()
        row.addWidget(_button('OK', 'Primary', self._ok))
        row.addWidget(_button('Cancel', '', self.reject))
        lay.addLayout(row)
        self._e1.returnPressed.connect(self._ok)
        self._e2.returnPressed.connect(self._ok)
        self._e1.setFocus()

    def _ok(self):
        p = self._e1.text()
        if not p:
            self._err.setText('Passphrase must not be empty.')
            return
        if self._confirm and p != self._e2.text():
            self._err.setText('The two entries do not match.')
            return
        self.value = p
        self.accept()

    @staticmethod
    def ask(parent, title: str, prompt: str, confirm: bool = False) -> Optional[str]:
        dlg = PassphraseDialog(parent, title, prompt, confirm)
        return dlg.value if dlg.exec() == QDialog.Accepted else None


class StepUpDialog(QDialog):
    """Username + password + authenticator code for the step-up window. Qt twin
    of snap_stepup.prompt_stepup_dialog (which is tkinter-only). Password and
    code are used once by the caller and never stored."""

    def __init__(self, parent, site_url: str = '', username_default: str = '',
                 error: str = '', title: str = 'Authorize Import'):
        super().__init__(parent)
        self.setWindowTitle(title)
        self.setModal(True)
        self.values = None

        lay = QVBoxLayout(self)
        lay.setContentsMargins(16, 14, 16, 14)
        lay.addWidget(_label('Imports need a fresh password + 2FA check'))
        lay.addWidget(_label('Your key keeps you connected, but writing requires step-up auth.', 'Muted'))
        if site_url:
            u = _label(site_url)
            u.setStyleSheet(f'color:{ACCENT};')
            lay.addWidget(u)

        lay.addWidget(_label('Username', 'Muted'))
        self._user = QLineEdit(username_default)
        lay.addWidget(self._user)
        lay.addWidget(_label('Password', 'Muted'))
        self._pass = QLineEdit()
        self._pass.setEchoMode(QLineEdit.Password)
        lay.addWidget(self._pass)
        lay.addWidget(_label('Authenticator code (6 digits)', 'Muted'))
        self._totp = QLineEdit()
        lay.addWidget(self._totp)

        self._err = _label(error)
        self._err.setStyleSheet(f'color:{ERR};')
        self._err.setWordWrap(True)
        self._err.setMaximumWidth(340)
        lay.addWidget(self._err)

        row = QHBoxLayout()
        row.addStretch()
        row.addWidget(_button('Authorize', 'Primary', self._submit))
        row.addWidget(_button('Cancel', '', self.reject))
        lay.addLayout(row)
        for e in (self._user, self._pass, self._totp):
            e.returnPressed.connect(self._submit)
        (self._pass if username_default else self._user).setFocus()

    def _submit(self):
        u, p, t = self._user.text().strip(), self._pass.text(), self._totp.text().strip()
        if not u or not p or not t:
            self._err.setText('Username, password and code are all required.')
            return
        self.values = (u, p, t)
        self.accept()


class KeySecurityDialog(QDialog):
    """Encryption status + on/off/re-key, in one small window."""

    def __init__(self, parent, session: 'core.Session', log_write):
        super().__init__(parent)
        self.setWindowTitle('Key security')
        self._s = session
        self._log_write = log_write

        lay = QVBoxLayout(self)
        lay.setContentsMargins(16, 14, 16, 14)
        self._status = _label('')
        f = self._status.font()
        f.setBold(True)
        self._status.setFont(f)
        lay.addWidget(self._status)
        self._detail = _label('', 'Muted')
        self._detail.setWordWrap(True)
        self._detail.setMaximumWidth(440)
        lay.addWidget(self._detail)
        self._btn_row = QHBoxLayout()
        lay.addLayout(self._btn_row)
        lay.addSpacing(10)
        close_row = QHBoxLayout()
        close_row.addStretch()
        close_row.addWidget(_button('Close', '', self.accept))
        close_row.addStretch()
        lay.addLayout(close_row)
        self._refresh()

    def _refresh(self):
        st = self._s.vault_status()
        on = st['enabled']
        self._status.setText('Encryption: ON' if on else 'Encryption: OFF')
        self._status.setStyleSheet(f'color:{ACCENT if on else WARN}; font-weight:700;')
        if on:
            extra = (' The passphrase is also stored on this machine, so you are '
                     'not asked for it on every launch.'
                     if st['has_machine_key'] else
                     ' You will be asked for the passphrase each launch.')
            self._detail.setText(
                'Your API key is sealed with a passphrase-derived key. '
                'The passphrase itself is never saved, so a copy of this '
                'folder is not enough to read the key.' + extra)
        else:
            self._detail.setText(
                'Your API key is stored base64-encoded. That is an '
                'encoding, not encryption — anyone who can read '
                'flkrfckr.ini can recover the key with one command. '
                'Turning encryption on fixes that.')
        while self._btn_row.count():
            item = self._btn_row.takeAt(0)
            if item.widget():
                item.widget().deleteLater()
        if not on:
            self._btn_row.addWidget(_button('Turn encryption on', 'Primary', self._enable))
        else:
            self._btn_row.addWidget(_button('Change passphrase', '', self._rekey))
            self._btn_row.addWidget(_button('Turn off', 'Danger', self._disable))
        self._btn_row.addStretch()

    def _enable(self):
        p = PassphraseDialog.ask(
            self, 'Turn encryption on',
            'Choose a passphrase. It is never saved anywhere — if you lose it, '
            'you will have to paste your API key in again (which is harmless; '
            'the key is not the only copy).', confirm=True)
        if p is None:
            return
        remember = QMessageBox.question(
            self, 'Remember on this machine?',
            'Store the unlock key in this computer\'s credential manager, so '
            'you are not asked for the passphrase every launch?\n\n'
            'It never travels with the folder — a copy of this folder taken to '
            'another machine still needs the passphrase.',
            QMessageBox.Yes | QMessageBox.No, QMessageBox.No) == QMessageBox.Yes
        try:
            self._s.vault_enable(p, remember=remember)
        except Exception as e:
            QMessageBox.critical(self, 'Key security', f'Could not turn encryption on:\n\n{e}')
            return
        self._refresh()

    def _disable(self):
        if QMessageBox.warning(
                self, 'Turn encryption off',
                'Your API key will go back to base64 in flkrfckr.ini — readable '
                'by anyone who can read the file.\n\nContinue?',
                QMessageBox.Ok | QMessageBox.Cancel, QMessageBox.Cancel) != QMessageBox.Ok:
            return
        try:
            self._s.vault_disable()
        except Exception as e:
            QMessageBox.critical(self, 'Key security', f'Could not turn encryption off:\n\n{e}')
            return
        self._refresh()

    def _rekey(self):
        old = PassphraseDialog.ask(self, 'Change passphrase', 'Current passphrase')
        if old is None:
            return
        new = PassphraseDialog.ask(self, 'Change passphrase', 'New passphrase', confirm=True)
        if new is None:
            return
        try:
            ok = self._s.vault_rekey(old, new)
        except Exception as e:
            QMessageBox.critical(self, 'Key security', str(e))
            return
        if not ok:
            QMessageBox.critical(self, 'Key security', 'That passphrase did not work.')
            return
        self._refresh()


HELP_HTML = f"""
<style>
 body {{ color:{INK}; font-family:'Segoe UI'; font-size:13px; }}
 h1 {{ color:{ACCENT}; font-size:17px; margin-bottom:6px; }}
 h2 {{ color:{INK}; font-size:14px; margin-top:14px; margin-bottom:4px; }}
 h3 {{ color:{INK}; font-size:13px; margin-top:10px; margin-bottom:2px; }}
 p {{ margin:0 0 6px 0; }}
 .dim {{ color:{DIM}; }}
 code {{ color:{ACCENT}; background:{CELL}; font-family:Consolas,monospace; }}
</style>
<h1>FLKR FCKR — Flickr → SnapSmack Migration Tool</h1>
<p>Migrates your Flickr photo archive to a self-hosted SnapSmack photoblog.
Runs on your computer, not on your server — because a large Flickr archive
(thousands of photos, gigabytes of data) cannot run inside a PHP request.
FLKR FCKR handles the heavy work locally and talks to your server at a
throttled rate you control.</p>

<h2>Quick Start</h2>
<p>1. Download and unzip your Flickr data export (Account Settings → Your Flickr Data → Request your archive).</p>
<p>2. In your SnapSmack admin panel, go to Boring Ass Stuff → API Keys and generate a new key with type "FLKR FCKR Import". Copy it — shown only once.</p>
<p>3. Enter your site URL and API key in the settings bar above. Click Connect to verify.</p>
<p>4. Browse to your unzipped Flickr export folder and click Load Export.</p>
<p>5. Review the photo grid. Click any tile to exclude it. Use the album sidebar to filter by album.</p>
<p>6. Click Start Import. Pause and resume at any time. If interrupted, FLKR FCKR offers to resume on next launch.</p>

<h2>Settings</h2>
<h3>Site URL</h3>
<p>The full URL of your SnapSmack install, e.g. https://myphotoblog.com — no trailing slash.</p>
<h3>API Key</h3>
<p>The FLKR FCKR Import key generated from your SnapSmack admin panel. Revoke it when your import is done.</p>
<h3>Key security (the "Key" button)</h3>
<p>By default your API key is stored base64-encoded in flkrfckr.ini next to
the app. Base64 is an ENCODING, not encryption — anyone who can read that
file can recover the key with one command, so treat it like a password
file. Turn encryption on and the key is sealed with a passphrase instead:
the passphrase is never saved, so a copy of the folder is no longer enough
to read the key. You can optionally let this computer remember the unlock
key so you are not asked on every launch — that never travels with the
folder. Lost the passphrase? No harm done: paste the key in again, or
generate a fresh one in your admin panel.</p>
<h3>Export Folder</h3>
<p>The root of your unzipped Flickr export. Should contain albums.json and
many photo_XXXXXXXX.json sidecar files.</p>
<h3>Throttle</h3>
<p>Delay between API calls after each photo is imported. Default "Easy Does It (1s)" is safe
for shared hosting. Lower it carefully on a fast VPS; raise it if your host
is slow or rate-limits aggressively.</p>
<h3>Off-peak only</h3>
<p>Tick it and the import pauses itself during the peak hours you set (Peak … to …),
resuming automatically when the quiet hours start.</p>
<h3>Private →</h3>
<p>What to do with photos marked private or friends-only on Flickr.
"draft" imports them as unpublished drafts (recommended).
"published" makes everything live regardless of Flickr privacy.</p>

<h2>Photo Grid</h2>
<p>Each tile shows the photo title, date, and any warnings (MISSING IMAGE, PRIVATE).
Click a tile to toggle it excluded — excluded photos are skipped during import.
The album sidebar lets you filter by album or see unalbumed photos only.</p>

<h2>Unalbumed Photos</h2>
<p>Photos that belong to no Flickr album. Choose "feed" to import them to the
main image feed with no album assignment, or "default_album" to put them
all into a named album (enter the album name in the Default album box).</p>

<h2>Comments &amp; Commenter Names</h2>
<p>Comments left on your Flickr photos are imported automatically and appear
in each photo's comment thread. Flickr only stores the commenter's ID
(e.g. 196612229@N08), not their name, so by default that ID is shown. To
give the people who matter their real names, create a file named
<code>flkrfckr-names.json</code> in your export folder (or next to this app) mapping
IDs (or profile slugs) to names, e.g. <code>{{"196612229@N08": "Ray van der Woning"}}</code>.
FLKR FCKR reads it on Load Export. Entries whose key starts with _ are notes.</p>

<h2>Resume After Interruption</h2>
<p>If FLKR FCKR is closed or crashes mid-import, a checkpoint file is written
after every successfully imported photo. On next launch, FLKR FCKR detects
the checkpoint and offers to resume. Already-imported Flickr IDs are skipped
instantly — no duplicates, no re-processing.</p>

<h2>How Images Are Transferred</h2>
<p>FLKR FCKR resizes each photo locally, then uploads it to your site over
the same secure HTTPS connection it uses for the API — no FTP, no separate
credentials. The server stores the image and generates thumbnails for you.</p>

<h2>After the Import</h2>
<p>Go back to your SnapSmack admin panel and revoke the FLKR FCKR API key —
you do not need it again. If any photos failed (usually a dropped
connection), re-run FLKR FCKR against the same export folder with the same
settings. Duplicates are detected and skipped automatically.</p>

<p class="dim">FLKR FCKR is part of the SnapSmack companion tool family alongside
Smack Your Batch Up (batch posting), Smack Up Your Backup (backups),
and SmackPress (WordPress migration). All upload over HTTPS with an API
key and ship as standalone Windows executables.</p>
"""


class HelpDialog(QDialog):
    def __init__(self, parent):
        super().__init__(parent)
        self.setWindowTitle(f'FLKR FCKR  v{BUILD_VERSION} — Help')
        self.resize(640, 620)
        lay = QVBoxLayout(self)
        lay.setContentsMargins(12, 10, 12, 10)
        txt = QTextBrowser()
        txt.setOpenExternalLinks(False)
        txt.setHtml(HELP_HTML)
        lay.addWidget(txt, 1)
        row = QHBoxLayout()
        row.addStretch()
        row.addWidget(_button('Close', '', self.accept))
        row.addStretch()
        lay.addLayout(row)


class LogWindow(QWidget):
    """Large, resizable mirror of the inline log (the Tk 'Pop Out ↗')."""

    def __init__(self, lines: List[tuple], on_close):
        super().__init__(None)
        self.setWindowTitle('FLKR FCKR — Import Log')
        self.setWindowIcon(QIcon(_icon_path()))
        self.resize(900, 600)
        self.setMinimumSize(420, 240)
        self._on_close = on_close
        lay = QVBoxLayout(self)
        lay.setContentsMargins(8, 8, 8, 8)
        self.text = QPlainTextEdit()
        self.text.setObjectName('Log')
        self.text.setReadOnly(True)
        self.text.setLineWrapMode(QPlainTextEdit.NoWrap)
        lay.addWidget(self.text)
        for t, c in lines:
            self.append(t, c)

    def append(self, text: str, colour: str):
        self.text.appendHtml(f'<span style="color:{colour}">{_html(text)}</span>')
        self.text.verticalScrollBar().setValue(self.text.verticalScrollBar().maximum())

    def closeEvent(self, ev):
        self._on_close()
        super().closeEvent(ev)


def _html(text: str) -> str:
    return (text.replace('&', '&amp;').replace('<', '&lt;').replace('>', '&gt;')
            .replace(' ', '&nbsp;'))


# ---------------------------------------------------------------------------
# Photo grid — model + delegate + lazy thumbnail worker
# ---------------------------------------------------------------------------

THUMB_PX  = 120
TILE_W    = 150
TILE_H    = THUMB_PX + 58
THUMB_CAP = 800          # decoded thumbnails kept in memory (bounds RAM on 9k exports)
PhotoRole = Qt.UserRole + 1


class _ThumbBridge(QObject):
    ready = Signal(int, int, bytes)     # gen, row, jpeg bytes


class PhotoModel(QAbstractListModel):
    """The filtered photo list the grid shows. Thumbnails are requested by the
    delegate as tiles are painted — i.e. only for what is on screen — decoded on
    a worker thread through Session.thumbnail_bytes, and cached with a cap."""

    excluded_changed = Signal()

    def __init__(self, session: 'core.Session', parent=None):
        super().__init__(parent)
        self._s = session
        self._photos: list = []
        self._gen = 0
        self._cache: 'OrderedDict[int, QPixmap]' = OrderedDict()
        self._state: dict = {}
        self._q: queue.Queue = queue.Queue()
        self._bridge = _ThumbBridge()
        self._bridge.ready.connect(self._on_thumb_ready)
        self.private_status = 'draft'
        threading.Thread(target=self._worker, daemon=True).start()

    # -- Qt model API --
    def rowCount(self, parent=QModelIndex()) -> int:
        return 0 if parent.isValid() else len(self._photos)

    def data(self, index, role=Qt.DisplayRole):
        if not index.isValid():
            return None
        p = self._photos[index.row()]
        if role == PhotoRole:
            return p
        if role == Qt.DisplayRole:
            return p.title
        if role == Qt.ToolTipRole:
            return p.title
        return None

    # -- population / state --
    def set_photos(self, photos: list) -> None:
        self.beginResetModel()
        self._photos = list(photos)
        self._gen += 1
        self._cache.clear()
        self._state.clear()
        self.endResetModel()

    @property
    def photos(self) -> list:
        return self._photos

    def toggle(self, row: int) -> None:
        if not (0 <= row < len(self._photos)):
            return
        p = self._photos[row]
        if p.missing_image:
            return
        p.excluded = not p.excluded
        idx = self.index(row)
        self.dataChanged.emit(idx, idx)
        self.excluded_changed.emit()

    # -- thumbnails --
    def pixmap_for(self, row: int) -> Optional[QPixmap]:
        pm = self._cache.get(row)
        if pm is not None:
            self._cache.move_to_end(row)
            return pm
        if row not in self._state:
            p = self._photos[row]
            if p.missing_image or not p.image_path:
                self._state[row] = 'none'
            else:
                self._state[row] = 'loading'
                self._q.put((self._gen, row, p.flickr_id))
        return None

    def _worker(self):
        decoded = 0
        while True:
            gen, row, fid = self._q.get()
            if gen != self._gen:
                continue
            raw = self._s.thumbnail_bytes(fid)
            if raw:
                self._bridge.ready.emit(gen, row, raw)
                decoded += 1
                if decoded == 1 or decoded % 50 == 0:
                    log.debug('thumb worker decoded %d image(s) (latest row=%d)', decoded, row)

    def _on_thumb_ready(self, gen: int, row: int, raw: bytes):
        if gen != self._gen or row >= len(self._photos):
            return
        pm = QPixmap()
        if not pm.loadFromData(raw, 'JPEG'):
            self._state[row] = 'none'
            return
        self._cache[row] = pm
        self._state[row] = 'loaded'
        while len(self._cache) > THUMB_CAP:
            old, _ = self._cache.popitem(last=False)
            self._state.pop(old, None)      # may be re-requested when scrolled back
        idx = self.index(row)
        self.dataChanged.emit(idx, idx)


class PhotoDelegate(QStyledItemDelegate):
    def __init__(self, model: PhotoModel, parent=None):
        super().__init__(parent)
        self._m = model
        self._f_title = QFont('Segoe UI', 9)
        self._f_small = QFont('Segoe UI', 8)

    def sizeHint(self, option, index) -> QSize:
        return QSize(TILE_W, TILE_H)

    def paint(self, painter: QPainter, option, index):
        p = index.data(PhotoRole)
        r: QRect = option.rect
        painter.save()
        painter.fillRect(r, QColor(BG if p.excluded else CELL))
        painter.setPen(QColor(BORDER))
        painter.drawRect(r.adjusted(0, 0, -1, -1))

        # thumbnail (or grey placeholder) — requesting it here is what makes
        # the load lazy: paint() only runs for tiles on screen.
        tr = QRect(r.x() + 1, r.y() + 1, r.width() - 2, THUMB_PX)
        pm = self._m.pixmap_for(index.row())
        if pm is not None:
            x = tr.x() + (tr.width() - pm.width()) // 2
            painter.drawPixmap(x, tr.y(), pm)
            if p.excluded:
                painter.fillRect(tr, QColor(0, 0, 0, 140))
        else:
            painter.fillRect(tr, QColor(THUMB_BG))

        fm = QFontMetrics(self._f_title)
        painter.setFont(self._f_title)
        painter.setPen(QColor(DIM if p.excluded else INK))
        title_r = QRect(r.x() + 5, tr.bottom() + 3, r.width() - 10, fm.height())
        painter.drawText(title_r, Qt.AlignLeft | Qt.AlignVCenter,
                         fm.elidedText(_e(p.title), Qt.ElideRight, title_r.width()))

        painter.setFont(self._f_small)
        painter.setPen(QColor(DIM))
        date_r = QRect(r.x() + 5, title_r.bottom() + 1, r.width() - 10, fm.height())
        painter.drawText(date_r, Qt.AlignLeft | Qt.AlignVCenter,
                         p.date_taken.strftime('%Y-%m-%d') if p.date_taken else '?')

        badge, colour = self.badge_for(p, self._m.private_status)
        if badge:
            painter.setPen(QColor(colour))
            badge_r = QRect(r.x() + 5, date_r.bottom() + 1, r.width() - 10, fm.height())
            painter.drawText(badge_r, Qt.AlignLeft | Qt.AlignVCenter,
                             QFontMetrics(self._f_small).elidedText(badge, Qt.ElideRight, badge_r.width()))
        painter.restore()

    @staticmethod
    def badge_for(p, private_status: str):
        if p.missing_image:
            return 'MISSING IMAGE', ERR
        if p.privacy != 'public':
            return f'PRIVATE → {private_status.upper()}', WARN
        if p.excluded:
            return 'EXCLUDED', DIM
        return '', DIM


# ---------------------------------------------------------------------------
# Main window
# ---------------------------------------------------------------------------

class _Bridge(QObject):
    done = Signal(object, object)      # callback, result


class MainWindow(QMainWindow):

    def __init__(self, session: 'core.Session'):
        super().__init__()
        self._s = session
        self.setWindowTitle(f'FLKR FCKR  v{BUILD_VERSION}')
        self.setWindowIcon(QIcon(_icon_path()))
        self.resize(1000, 780)
        self.setMinimumSize(700, 600)

        self._filter = 'all'            # 'all' | 'unalbumed' | 'album'
        self._album_id = ''
        self._albums: list = []
        self._log_lines: List[tuple] = []
        self._log_popup: Optional[LogWindow] = None
        self._bridge = _Bridge()
        self._bridge.done.connect(lambda cb, r: cb(r))

        self._build_ui()
        self._load_settings()

        self._timer = QTimer(self)
        self._timer.timeout.connect(self._poll_events)
        self._timer.start(80)
        QTimer.singleShot(0, self._check_resume)

    # ------------------------------------------------------------------
    # UI construction
    # ------------------------------------------------------------------

    def _build_ui(self):
        root = QWidget()
        self.setCentralWidget(root)
        col = QVBoxLayout(root)
        col.setContentsMargins(0, 0, 0, 0)
        col.setSpacing(0)
        col.addWidget(self._build_settings_bar())
        col.addWidget(self._build_main_area(), 1)
        col.addWidget(self._build_run_panel())

    def _build_settings_bar(self) -> QWidget:
        bar = QFrame()
        bar.setObjectName('Bar')
        g = QGridLayout(bar)
        g.setContentsMargins(10, 8, 10, 8)
        g.setHorizontalSpacing(8)
        g.setVerticalSpacing(6)

        # Row 0: Site URL + API key + Connect + status | Key / Logs / ?
        g.addWidget(_label('Site URL', 'Muted'), 0, 0)
        self._url = QLineEdit()
        g.addWidget(self._url, 0, 1)
        g.addWidget(_label('API Key', 'Muted'), 0, 2)
        self._key = QLineEdit()
        self._key.setEchoMode(QLineEdit.Password)
        g.addWidget(self._key, 0, 3)
        self._btn_connect = _button('Connect', '', self._test_connection)
        self._btn_connect.setStyleSheet(f'color:{ACCENT};')
        g.addWidget(self._btn_connect, 0, 4)
        self._lbl_conn = _label('', 'Muted')
        g.addWidget(self._lbl_conn, 0, 5, 1, 3)

        tools = QHBoxLayout()
        tools.setSpacing(6)
        tools.addStretch()
        tools.addWidget(_button('Key', 'Quiet', self._show_key_security))
        tools.addWidget(_button('Logs', 'Quiet', self._open_logs))
        tools.addWidget(_button('?', 'Quiet', self._show_help))
        g.addLayout(tools, 0, 8)

        # Row 1: Export folder + Browse + Throttle + Private → + Load Export
        g.addWidget(_label('Export Folder', 'Muted'), 1, 0)
        self._folder = QLineEdit()
        g.addWidget(self._folder, 1, 1)
        g.addWidget(_button('Browse…', '', self._browse_folder), 1, 2)
        g.addWidget(_label('Throttle', 'Muted'), 1, 3, Qt.AlignRight)
        self._throttle = QComboBox()
        g.addWidget(self._throttle, 1, 4, 1, 2)
        g.addWidget(_label('Private →', 'Muted'), 1, 6, Qt.AlignRight)
        self._private = QComboBox()
        self._private.addItems(['draft', 'published'])
        self._private.currentTextChanged.connect(self._on_private_changed)
        g.addWidget(self._private, 1, 7)
        self._btn_load = _button('Load Export', 'Primary', self._load_export)
        g.addWidget(self._btn_load, 1, 8, Qt.AlignRight)

        # Row 2: Off-peak only + Peak hours
        peak = QHBoxLayout()
        peak.setSpacing(6)
        self._offpeak = QCheckBox('Off-peak only')
        peak.addWidget(self._offpeak)
        peak.addSpacing(8)
        peak.addWidget(_label('Peak', 'Muted'))
        self._peak_start = QComboBox()
        self._peak_end = QComboBox()
        for h in range(24):
            self._peak_start.addItem(str(h))
            self._peak_end.addItem(str(h))
        peak.addWidget(self._peak_start)
        peak.addWidget(_label('to', 'Muted'))
        peak.addWidget(self._peak_end)
        peak.addStretch()
        g.addLayout(peak, 2, 0, 1, 6)

        g.setColumnStretch(1, 3)
        g.setColumnStretch(3, 2)
        g.setColumnStretch(5, 1)
        return bar

    def _build_main_area(self) -> QWidget:
        split = QSplitter(Qt.Horizontal)
        split.setContentsMargins(6, 4, 6, 4)
        split.setHandleWidth(4)

        # Photo grid + filter bar
        left = QWidget()
        ll = QVBoxLayout(left)
        ll.setContentsMargins(0, 0, 0, 0)
        ll.setSpacing(4)
        fbar = QFrame()
        fbar.setObjectName('FilterBar')
        fl = QHBoxLayout(fbar)
        fl.setContentsMargins(8, 4, 8, 4)
        fl.addWidget(_label('Show:', 'Muted'))
        self._rb_all = QRadioButton('All')
        self._rb_unalbumed = QRadioButton('Unalbumed')
        for rb in (self._rb_all, self._rb_unalbumed):
            rb.setAutoExclusive(False)     # an album pick unchecks both, like the Tk radios
            fl.addWidget(rb)
        self._rb_all.setChecked(True)
        self._rb_all.clicked.connect(lambda: self._set_filter('all'))
        self._rb_unalbumed.clicked.connect(lambda: self._set_filter('unalbumed'))
        fl.addStretch()
        self._lbl_grid_count = _label('No export loaded', 'Muted')
        fl.addWidget(self._lbl_grid_count)
        ll.addWidget(fbar)

        self._model = PhotoModel(self._s, self)
        self._model.excluded_changed.connect(self._update_summary)
        self._grid = QListView()
        self._grid.setObjectName('Grid')
        self._grid.setModel(self._model)
        self._grid.setItemDelegate(PhotoDelegate(self._model, self._grid))
        self._grid.setViewMode(QListView.IconMode)
        self._grid.setFlow(QListView.LeftToRight)
        self._grid.setWrapping(True)
        self._grid.setResizeMode(QListView.Adjust)
        self._grid.setMovement(QListView.Static)
        self._grid.setUniformItemSizes(True)
        self._grid.setSpacing(4)
        self._grid.setSelectionMode(QListView.NoSelection)
        self._grid.setVerticalScrollMode(QListView.ScrollPerPixel)
        self._grid.setHorizontalScrollBarPolicy(Qt.ScrollBarAlwaysOff)
        self._grid.clicked.connect(lambda idx: self._model.toggle(idx.row()))
        ll.addWidget(self._grid, 1)
        split.addWidget(left)

        # Album sidebar
        side = QFrame()
        side.setObjectName('Side')
        sl = QVBoxLayout(side)
        sl.setContentsMargins(0, 6, 0, 4)
        sl.setSpacing(4)
        hdr = _label('ALBUMS', 'Eyebrow')
        hdr.setAlignment(Qt.AlignCenter)
        sl.addWidget(hdr)
        self._album_list = QListWidget()
        self._album_list.itemSelectionChanged.connect(self._on_album_select)
        sl.addWidget(self._album_list, 1)
        opts = QGridLayout()
        opts.setContentsMargins(6, 0, 6, 0)
        opts.setHorizontalSpacing(4)
        opts.addWidget(_label('Unalbumed:', 'Muted'), 0, 0)
        self._unalbumed = QComboBox()
        self._unalbumed.addItems(['feed', 'default_album'])
        self._unalbumed.currentTextChanged.connect(
            lambda t: self._default_album.setEnabled(t == 'default_album'))
        opts.addWidget(self._unalbumed, 0, 1)
        opts.addWidget(_label('Default album:', 'Muted'), 1, 0)
        self._default_album = QLineEdit()
        self._default_album.setPlaceholderText('album name')
        self._default_album.setEnabled(False)
        opts.addWidget(self._default_album, 1, 1)
        sl.addLayout(opts)
        split.addWidget(side)

        side.setMinimumWidth(230)
        split.setStretchFactor(0, 5)
        split.setStretchFactor(1, 1)
        return split

    def _build_run_panel(self) -> QWidget:
        panel = QFrame()
        panel.setObjectName('Panel')
        v = QVBoxLayout(panel)
        v.setContentsMargins(10, 6, 10, 6)
        v.setSpacing(4)

        row = QHBoxLayout()
        row.setSpacing(8)
        self._lbl_summary = _label('Load an export to begin.', 'Muted')
        row.addWidget(self._lbl_summary)
        self._progress = QProgressBar()
        self._progress.setRange(0, 100)
        self._progress.setValue(0)
        self._progress.setTextVisible(False)
        row.addWidget(self._progress, 1)
        self._btn_run = _button('Start Import', 'Primary', self._toggle_run)
        self._btn_run.setEnabled(False)
        self._btn_run.setMinimumWidth(130)
        row.addWidget(self._btn_run)
        v.addLayout(row)

        hdr = QHBoxLayout()
        hdr.addWidget(_label('LOG', 'Eyebrow'))
        hdr.addStretch()
        hdr.addWidget(_button('Pop Out ↗', 'Quiet', self._open_log_popup))
        v.addLayout(hdr)

        self._log = QPlainTextEdit()
        self._log.setObjectName('Log')
        self._log.setReadOnly(True)
        self._log.setLineWrapMode(QPlainTextEdit.NoWrap)
        self._log.setFixedHeight(112)      # ~5 lines, same as the Tk box
        self._log.mouseDoubleClickEvent = lambda ev: self._open_log_popup()
        v.addWidget(self._log)
        return panel

    # ------------------------------------------------------------------
    # Settings
    # ------------------------------------------------------------------

    def _load_settings(self):
        s = self._s.get_settings()
        self._url.setText(s['site_url'])
        self._key.setText(s['api_key'])
        self._folder.setText(s['export_folder'])
        self._throttle.clear()
        self._throttle.addItems(s['throttle_options'])
        self._throttle.setCurrentText(s['throttle_label'])
        self._offpeak.setChecked(s['offpeak_only'])
        self._peak_start.setCurrentText(str(s['peak_start']))
        self._peak_end.setCurrentText(str(s['peak_end']))
        self._private.setCurrentText(s['private_status'])
        self._model.private_status = s['private_status']
        self._unalbumed.setCurrentText(s['unalbumed_action'])
        self._default_album.setText(s['default_album'])
        self._default_album.setEnabled(s['unalbumed_action'] == 'default_album')

    def _form(self) -> dict:
        return {
            'site_url': self._url.text().strip(),
            'api_key': self._key.text().strip(),
            'export_folder': self._folder.text().strip(),
            'throttle': self._throttle.currentText(),
            'offpeak_only': self._offpeak.isChecked(),
            'peak_start': int(self._peak_start.currentText() or 9),
            'peak_end': int(self._peak_end.currentText() or 23),
            'private_status': self._private.currentText(),
            'unalbumed_action': self._unalbumed.currentText(),
            'default_album': self._default_album.text().strip(),
        }

    def _save_settings(self):
        try:
            self._s.save_settings(self._form())
        except Exception as e:
            log.exception('save settings failed')
            self._log_write(f'Could not save settings: {e}', ERR)

    def _on_private_changed(self, text: str):
        self._model.private_status = text
        self._grid.viewport().update()

    # ------------------------------------------------------------------
    # Actions
    # ------------------------------------------------------------------

    def _browse_folder(self):
        folder = QFileDialog.getExistingDirectory(self, 'Select Flickr export folder',
                                                  self._folder.text().strip() or '')
        if folder:
            self._folder.setText(folder)

    def _load_export(self):
        folder = self._folder.text().strip()
        if not folder or not os.path.isdir(folder):
            QMessageBox.critical(self, 'FLKR FCKR', 'Please select a valid export folder first.')
            return
        self._save_settings()
        self._progress.setValue(0)
        r = self._s.load_export(folder)
        if not r.get('ok'):
            QMessageBox.critical(self, 'FLKR FCKR', r.get('message', 'Could not load the export.'))

    def _confirm_insecure(self, url: str, what: str) -> bool:
        """Qt twin of snap_stepup.confirm_insecure_transport (SECAUDIT 040):
        warn BEFORE the Bearer key crosses a plaintext wire. True = proceed."""
        if not snap_stepup.insecure_transport_reason(url):
            return True
        return QMessageBox.warning(
            self, 'Unencrypted connection',
            f'This site URL is not https://, so {what} will be sent across the '
            f'network in the clear:\n\n{url.strip()}\n\n'
            'Anyone between you and your server could read it. If this is a live '
            'site, cancel and switch the URL to https://.\n\n'
            'Continue anyway?',
            QMessageBox.Ok | QMessageBox.Cancel, QMessageBox.Cancel) == QMessageBox.Ok

    def _set_conn(self, text: str, colour: str = DIM):
        self._lbl_conn.setText(text)
        self._lbl_conn.setStyleSheet(f'color:{colour}; font-size:12px;')

    def _bg(self, fn, done):
        """Run fn() on a worker thread; call done(result) back on the UI thread.
        An exception becomes the result so the handler can show it."""
        def _t():
            try:
                r = fn()
            except Exception as e:      # noqa: BLE001 — surfaced to the handler
                log.exception('background task failed')
                r = e
            self._bridge.done.emit(done, r)
        threading.Thread(target=_t, daemon=True).start()

    def _test_connection(self):
        url = self._url.text().strip()
        key = self._key.text().strip()
        if not url or not key:
            self._set_conn('URL and key required', ERR)
            return
        if not self._confirm_insecure(url, 'your API key'):
            self._set_conn('Cancelled — use https://', ERR)
            return
        self._set_conn('Testing…')
        self._save_settings()
        self._btn_connect.setEnabled(False)

        def _done(r):
            self._btn_connect.setEnabled(True)
            if isinstance(r, Exception):
                self._set_conn(f'Error: {r}', ERR)
                return
            self._set_conn(r.get('message', ''), ACCENT if r.get('ok') else ERR)

        self._bg(lambda: self._s.test_connection(url, key), _done)

    def _toggle_run(self):
        if not self._s.running:
            self._start_import()
        elif self._s.paused:
            self._s.resume_import()
            self._set_run_button('Pause')
        else:
            self._s.pause_import()
            self._set_run_button('Resume')

    def _set_run_button(self, text: str):
        self._btn_run.setText(text)
        self._btn_run.setObjectName('Pause' if text == 'Pause' else 'Primary')
        _restyle(self._btn_run)

    def _start_import(self):
        if not self._s.parse_result:
            return
        if self._s.summary(self._filter, self._album_id)['selected'] == 0:
            QMessageBox.warning(self, 'FLKR FCKR', 'No photos to import.')
            return
        self._save_settings()
        url = self._url.text().strip()
        key = self._key.text().strip()
        if not url or not key:
            QMessageBox.critical(self, 'FLKR FCKR', 'Site URL and API key are required.')
            return
        # SECAUDIT 040 — gate the run itself, not just the credential step.
        if not self._confirm_insecure(url, 'your API key and everything you import'):
            self._log_write('Import cancelled — site URL is not https://.', WARN)
            return
        self._set_conn('Checking authorization…')
        self._btn_run.setEnabled(False)

        def _after_preflight(r):
            self._btn_run.setEnabled(True)
            if isinstance(r, Exception):
                QMessageBox.critical(self, 'FLKR FCKR', str(r))
                self._set_conn('')
                return
            action = r.get('action')
            if action in ('error', 'regenerate_key'):
                self._set_conn('')
                QMessageBox.critical(self, 'FLKR FCKR', r.get('message', 'Could not reach the site.'))
                return
            if action == 'stepup' and not self._stepup_loop(url, key):
                self._set_conn('')
                return
            self._set_conn('')
            self._launch_import()

        self._bg(lambda: self._s.preflight_import(url, key), _after_preflight)

    def _stepup_loop(self, url: str, key: str) -> bool:
        """Prompt → authorize → re-prompt with the server's reason until it opens
        or the operator cancels (same loop as snap_stepup.authorize_interactive)."""
        username = self._s.get_settings().get('auth_username', '')
        error = ''
        while True:
            dlg = StepUpDialog(self, site_url=url, username_default=username, error=error)
            if dlg.exec() != QDialog.Accepted:
                return False                       # cancelled = silent
            u, p, t = dlg.values
            username = u
            self._set_conn('Authorizing…')
            QApplication.processEvents()
            res = self._s.authorize_import(url, key, u, p, t)
            if res.get('ok'):
                return True
            if res.get('needs_enrollment'):
                QMessageBox.critical(self, 'FLKR FCKR', res.get('message', ''))
                return False
            error = res.get('message', '')

    def _launch_import(self):
        r = self._s.start_import(self._filter, self._album_id)
        if not r.get('ok'):
            QMessageBox.warning(self, 'FLKR FCKR', r.get('message', 'Could not start.'))
            return
        self._set_run_button('Pause')
        self._progress.setValue(0)

    def _on_import_done(self):
        self._set_run_button('Start Import')
        self._progress.setValue(100)

    # ------------------------------------------------------------------
    # Grid / filter / sidebar
    # ------------------------------------------------------------------

    def _on_parse_done(self, ev: dict):
        self._albums = ev.get('albums', [])
        self._album_list.blockSignals(True)
        self._album_list.clear()
        total = len(self._s.parse_result.photos) if self._s.parse_result else 0
        first = QListWidgetItem(f'All ({total})')
        first.setForeground(QColor(ACCENT))
        self._album_list.addItem(first)
        for a in self._albums:
            self._album_list.addItem(QListWidgetItem(f"  {a['title'][:28]} ({a['count']})"))
        self._album_list.setCurrentRow(0)
        self._album_list.blockSignals(False)
        self._filter, self._album_id = 'all', ''
        self._rb_all.setChecked(True)
        self._rb_unalbumed.setChecked(False)
        self._apply_filter()
        self._btn_run.setEnabled(True)

    def _set_filter(self, flt: str):
        self._filter = flt
        self._rb_all.setChecked(flt == 'all')
        self._rb_unalbumed.setChecked(flt == 'unalbumed')
        self._album_list.blockSignals(True)
        if flt == 'all':
            self._album_list.setCurrentRow(0)
        else:
            self._album_list.clearSelection()
            self._album_list.setCurrentRow(-1)
        self._album_list.blockSignals(False)
        self._apply_filter()

    def _on_album_select(self):
        row = self._album_list.currentRow()
        if row < 0:
            return
        if row == 0:
            self._filter, self._album_id = 'all', ''
            self._rb_all.setChecked(True)
            self._rb_unalbumed.setChecked(False)
        else:
            self._filter = 'album'
            self._album_id = self._albums[row - 1]['flickr_id']
            self._rb_all.setChecked(False)
            self._rb_unalbumed.setChecked(False)
        self._apply_filter()

    def _apply_filter(self):
        if not self._s.parse_result:
            return
        photos = self._s.current_photos(self._filter, self._album_id)
        self._model.set_photos(photos)
        self._grid.scrollToTop()
        excluded = sum(1 for p in photos if p.excluded)
        self._lbl_grid_count.setText(
            f'{len(photos)} photos' + (f'  ({excluded} excluded)' if excluded else ''))
        self._update_summary()

    def _update_summary(self):
        if not self._s.parse_result:
            return
        s = self._s.summary(self._filter, self._album_id)
        self._lbl_summary.setText(
            f"{s['selected']} of {s['total']} photos selected for import"
            + (f"  ({s['missing']} missing image files)" if s['missing'] else ''))
        self._lbl_summary.setStyleSheet(f'color:{INK};')
        photos = self._model.photos
        excluded = sum(1 for p in photos if p.excluded)
        self._lbl_grid_count.setText(
            f'{len(photos)} photos' + (f'  ({excluded} excluded)' if excluded else ''))

    # ------------------------------------------------------------------
    # Resume from checkpoint
    # ------------------------------------------------------------------

    def _check_resume(self):
        info = self._s.check_resume()
        if not info:
            return
        answer = QMessageBox.question(
            self, 'FLKR FCKR',
            f"A previous import was interrupted.\n"
            f"{info['imported']} photos already imported.\n\n"
            f"Resume from where it left off?",
            QMessageBox.Yes | QMessageBox.No, QMessageBox.Yes)
        if answer == QMessageBox.Yes:
            r = self._s.resume_accept()
            if r.get('export_folder'):
                self._folder.setText(r['export_folder'])
        else:
            self._s.resume_decline()

    # ------------------------------------------------------------------
    # Event pump (Session → UI, main thread)
    # ------------------------------------------------------------------

    def _poll_events(self):
        for ev in self._s.poll_events():
            # One broken handler must never stop the pump (the 0.7.15 lesson).
            try:
                self._handle_event(ev)
            except Exception:
                log.exception('event handler failed for %r', ev.get('type'))

    def _handle_event(self, ev: dict):
        kind = ev.get('type')
        if kind == 'log':
            self._log_write(ev['text'], LEVEL_COLOUR.get(ev.get('level'), INK))
        elif kind == 'parse_progress':
            done, total = ev['done'], ev['total']
            self._progress.setValue(int(done / total * 100) if total else 0)
            self._lbl_grid_count.setText(f'Parsing… {done:,} / {total:,}')
        elif kind == 'parse_done':
            self._on_parse_done(ev)
        elif kind == 'parse_failed':
            self._lbl_grid_count.setText('No export loaded')
        elif kind == 'progress':
            self._progress.setValue(int(ev['pct']))
            self._log_write(ev['text'], LEVEL_COLOUR.get(ev.get('level'), INK))
        elif kind == 'done':
            self._on_import_done()
        # 'started' / 'auth_expired' need nothing beyond the log line core emits.

    # ------------------------------------------------------------------
    # Log
    # ------------------------------------------------------------------

    def _log_write(self, text: str, colour: str = INK):
        self._log_lines.append((text, colour))
        self._log.appendHtml(f'<span style="color:{colour}">{_html(text)}</span>')
        self._log.verticalScrollBar().setValue(self._log.verticalScrollBar().maximum())
        if self._log_popup is not None:
            self._log_popup.append(text, colour)

    def _open_log_popup(self):
        if self._log_popup is not None:
            self._log_popup.showNormal()
            self._log_popup.raise_()
            self._log_popup.activateWindow()
            return

        def _closed():
            self._log_popup = None

        self._log_popup = LogWindow(self._log_lines, _closed)
        self._log_popup.show()

    # ------------------------------------------------------------------
    # Small windows
    # ------------------------------------------------------------------

    def _show_help(self):
        HelpDialog(self).exec()

    def _open_logs(self):
        r = self._s.open_logs()
        if not r.get('ok'):
            QMessageBox.critical(self, 'Open Logs',
                                 f"Could not open the log folder:\n{r.get('path', '')}\n\n{r.get('message', '')}")

    def _show_key_security(self):
        if not self._s.vault_status()['available']:
            QMessageBox.information(
                self, 'Key security',
                'Encryption is not available in this build — the "cryptography" '
                'package is missing.\n\nYour API key is stored base64-encoded, '
                'which is NOT encryption: anyone who can read flkrfckr.ini can '
                'recover the key with one command. Treat that file as a password.')
            return
        KeySecurityDialog(self, self._s, self._log_write).exec()

    # ------------------------------------------------------------------

    def closeEvent(self, ev):
        if self._s.running:
            self._s.stop_import()
        if self._log_popup is not None:
            self._log_popup.close()
        super().closeEvent(ev)


# ---------------------------------------------------------------------------
# Startup — vault unlock before the window (order matters: a sealed key loads
# as empty if the config is read first)
# ---------------------------------------------------------------------------

def unlock_vault(session: 'core.Session', parent=None) -> None:
    """Tries this machine's cached key first, then asks, re-prompting on a wrong
    passphrase until it opens or the operator cancels. Declining is allowed and
    non-fatal: the key field simply comes up blank."""
    if session.vault_try_machine_key():
        return
    while True:
        p = PassphraseDialog.ask(
            parent, 'Unlock saved API key',
            'Your API key is encrypted. Enter the passphrase to unlock it.\n\n'
            'Cancel to continue without it — you can still load an export, '
            'but you will need to paste the key again to connect.')
        if p is None:
            log.info('Vault left locked by the operator.')
            return
        if session.vault_unlock(p):
            return
        QMessageBox.critical(parent, 'FLKR FCKR', 'That passphrase did not work.')


def run() -> int:
    app = QApplication.instance() or QApplication(sys.argv)
    app.setApplicationName('FLKR FCKR')
    app.setWindowIcon(QIcon(_icon_path()))
    app.setStyleSheet(STYLE)
    session = core.Session()
    unlock_vault(session)
    win = MainWindow(session)
    win.show()
    return app.exec()


if __name__ == '__main__':
    raise SystemExit(run())

# ===== SNAPSMACK EOF =====
