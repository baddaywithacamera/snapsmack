"""FED UP — Qt cockpit. Fediverse profile backup, restore into SnapSmack, and
the @you@photoblogs.fyi alias. Spec: _spec/fed-up-spec-v0_1.md + v0_2.md.

Three tabs, one per function. The look copies SNAP HQ / SUYB (dark, lime
accent). Every long job runs on a worker thread and reports into the tab's
own log; the window never freezes and nothing happens without a button.
"""
# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
from __future__ import annotations

import os
import re
import subprocess
import sys
import traceback
from pathlib import Path

from PySide6.QtCore import QObject, Qt, QThread, QSettings, QTimer, Signal
from PySide6.QtGui import QIcon
from PySide6.QtWidgets import (QApplication, QCheckBox, QComboBox, QFileDialog, QFrame, QGridLayout,
                               QHBoxLayout, QLabel, QLineEdit, QMainWindow, QMessageBox, QPlainTextEdit,
                               QProgressBar, QPushButton, QTabWidget, QVBoxLayout, QWidget)

from _version import BUILD_VERSION

from . import archive as archive_mod
from . import cloud, config
from . import restore as restore_mod
from .fetch import HANDLE_RE, Fetcher

STYLE = """
QWidget { background:#090b0a; color:#eef2ed; font-family:'Segoe UI'; font-size:13px; }
QMainWindow { background:#090b0a; }
QFrame#header { background:#0e1310; border-bottom:1px solid #243028; }
QFrame#section { background:#101511; border:1px solid #29342c; border-radius:14px; }
QLabel#brand { color:#63ef3d; font-size:27px; font-weight:900; }
QLabel#tag { color:#94a098; font-size:13px; }
QLabel#sectionTitle { color:#63ef3d; font-size:12px; font-weight:800; }
QLabel#muted { color:#8d9a90; }
QLabel#status { color:#ffb454; font-weight:700; }
QLabel#ok { color:#63ef3d; font-weight:700; }
QPushButton { background:#1b241e; border:1px solid #334238; border-radius:8px; padding:9px 14px; font-weight:700; min-height:18px; }
QPushButton:hover { border-color:#63ef3d; background:#243129; }
QPushButton#primary { background:#63ef3d; color:#071006; border-color:#63ef3d; padding:11px 20px; font-size:14px; }
QPushButton#primary:hover { background:#7dff57; }
QPushButton#danger { background:#3a1a1a; border-color:#7a2e2e; color:#ffb4b4; }
QPushButton:disabled { color:#5c665f; border-color:#243028; background:#121714; }
QLineEdit, QComboBox { background:#0b0f0c; border:1px solid #334238; border-radius:8px; padding:9px; min-height:16px; }
QComboBox::drop-down { border:0; width:26px; }
QComboBox QAbstractItemView { background:#0e1310; selection-background-color:#243129; }
QCheckBox { spacing:8px; padding:4px 0; }
QCheckBox::indicator { width:18px; height:18px; border:1px solid #334238; border-radius:4px; background:#0b0f0c; }
QCheckBox::indicator:checked { background:#63ef3d; border-color:#63ef3d; }
QPlainTextEdit { background:#070907; border:1px solid #243028; border-radius:8px; font-family:Consolas,'Courier New'; font-size:12px; color:#c8d3c9; }
QProgressBar { background:#0b0f0c; border:1px solid #243028; border-radius:6px; height:14px; text-align:center; color:#eef2ed; }
QProgressBar::chunk { background:#63ef3d; border-radius:5px; }
QTabWidget::pane { border:1px solid #29342c; border-radius:12px; background:#101511; }
QTabBar::tab { background:#0e1310; color:#94a098; padding:10px 22px; border:1px solid #243028; border-bottom:0;
               border-top-left-radius:9px; border-top-right-radius:9px; font-weight:800; }
QTabBar::tab:selected { background:#101511; color:#63ef3d; }
"""


def _asset(name: str) -> str:
    root = getattr(sys, "_MEIPASS", str(Path(__file__).resolve().parents[1]))
    return os.path.join(root, "assets", name)


def _section(title: str, body: str, status: str = "") -> QFrame:
    frame = QFrame(); frame.setObjectName("section")
    lay = QVBoxLayout(frame); lay.setContentsMargins(18, 14, 18, 14); lay.setSpacing(8)
    head = QLabel(title); head.setObjectName("sectionTitle"); lay.addWidget(head)
    text = QLabel(body); text.setWordWrap(True); lay.addWidget(text)
    if status:
        state = QLabel(status); state.setObjectName("status"); lay.addWidget(state)
    return frame


def _open_folder(path: str) -> None:
    if not path or not os.path.isdir(path):
        return
    if sys.platform.startswith("win"):
        os.startfile(path)  # noqa: S606 — opening the user's own folder in Explorer
    elif sys.platform == "darwin":
        subprocess.Popen(["open", path])
    else:
        subprocess.Popen(["xdg-open", path])


class Worker(QObject):
    """Runs ``fn(log, progress)`` on a thread; fn returns a dict."""
    log = Signal(str)
    progress = Signal(int, int, str)
    done = Signal(dict)
    failed = Signal(str)

    def __init__(self, fn):
        super().__init__()
        self._fn = fn

    def run(self):
        try:
            out = self._fn(self.log.emit, self.progress.emit)
            self.done.emit(out if isinstance(out, dict) else {})
        except Exception as e:  # report, never crash the window
            self.failed.emit(f"{e}\n{traceback.format_exc(limit=3)}")


class JobPanel(QWidget):
    """Progress bar + log + run/finish plumbing shared by the two working tabs."""

    def __init__(self, parent=None):
        super().__init__(parent)
        lay = QVBoxLayout(self); lay.setContentsMargins(0, 0, 0, 0); lay.setSpacing(6)
        self.bar = QProgressBar(); self.bar.setRange(0, 0); self.bar.hide(); lay.addWidget(self.bar)
        self.state = QLabel(""); self.state.setObjectName("muted"); self.state.setWordWrap(True); lay.addWidget(self.state)
        self.logbox = QPlainTextEdit(); self.logbox.setReadOnly(True); self.logbox.setMinimumHeight(140); lay.addWidget(self.logbox, 1)
        self._thread = None; self._worker = None

    def busy(self) -> bool:
        return self._thread is not None and self._thread.isRunning()

    def log(self, s: str):
        self.logbox.appendPlainText(s)

    def start(self, fn, on_done, on_fail=None, buttons=()):
        if self.busy():
            return
        for b in buttons:
            b.setEnabled(False)
        self.bar.setRange(0, 0); self.bar.show(); self.state.setText("Working…")
        self._thread = QThread(); self._worker = Worker(fn); self._worker.moveToThread(self._thread)
        self._thread.started.connect(self._worker.run)
        self._worker.log.connect(self.log)
        self._worker.progress.connect(self._on_progress)

        def finish():
            self.bar.hide()
            for b in buttons:
                b.setEnabled(True)
            self._thread.quit()

        def done(d):
            finish(); on_done(d)

        def fail(msg):
            finish(); self.state.setText("FAILED — see the log."); self.log("ERROR: " + msg)
            if on_fail:
                on_fail(msg)

        self._worker.done.connect(done); self._worker.failed.connect(fail)
        self._thread.start()

    def _on_progress(self, done: int, total: int, what: str):
        if total > 0:
            self.bar.setRange(0, total); self.bar.setValue(done)
        else:
            self.bar.setRange(0, 0)
        self.state.setText(what)


# ── BACK UP ──────────────────────────────────────────────────────────────────

class BackupTab(QWidget):
    def __init__(self, cfg: dict, parent=None):
        super().__init__(parent)
        self.cfg = cfg
        lay = QVBoxLayout(self); lay.setContentsMargins(16, 16, 16, 16); lay.setSpacing(12)
        lay.addWidget(_section(
            "BACK UP YOUR FEDIVERSE PROFILE",
            "Profile, every public post with its original date, media, like/boost/reply counts, and your "
            "follower and following lists — from Mastodon, Pixelfed, GoToSocial, or another SnapSmack site — "
            "into a folder of plain files you keep. Full the first time, then only what is new. This reads what "
            "the server shows a stranger: followers-only and private posts are not in it, and the summary says so."))

        grid = QGridLayout(); grid.setHorizontalSpacing(10); grid.setVerticalSpacing(8)
        grid.addWidget(QLabel("ACCOUNT"), 0, 0)
        self.handle = QComboBox(); self.handle.setEditable(True)
        self.handle.lineEdit().setPlaceholderText("@you@your.instance")
        for a in cfg.get("accounts") or []:
            self.handle.addItem(a)
        if cfg.get("last_account"):
            self.handle.setCurrentText(cfg["last_account"])
        else:
            self.handle.setCurrentText("")
        self.handle.editTextChanged.connect(self._check)
        grid.addWidget(self.handle, 0, 1, 1, 2)
        self.note = QLabel(""); self.note.setObjectName("muted"); grid.addWidget(self.note, 1, 1, 1, 2)

        grid.addWidget(QLabel("ARCHIVE FOLDER"), 2, 0)
        self.root = QLineEdit(cfg.get("archive_root") or config.default_archive_root())
        grid.addWidget(self.root, 2, 1)
        b = QPushButton("BROWSE…"); b.clicked.connect(lambda: self._pick(self.root)); grid.addWidget(b, 2, 2)

        opts = QHBoxLayout()
        self.want_media = QCheckBox("Download media"); self.want_media.setChecked(bool(cfg.get("want_media", True)))
        self.want_graph = QCheckBox("Followers + following"); self.want_graph.setChecked(bool(cfg.get("want_graph", True)))
        self.want_replies = QCheckBox("Public replies under each post"); self.want_replies.setChecked(bool(cfg.get("want_replies", True)))
        for w in (self.want_media, self.want_graph, self.want_replies):
            opts.addWidget(w)
        opts.addStretch(1)
        grid.addLayout(opts, 3, 1, 1, 2)
        lay.addLayout(grid)

        row = QHBoxLayout()
        self.go = QPushButton("BACK UP NOW"); self.go.setObjectName("primary"); self.go.setEnabled(False)
        self.go.clicked.connect(self._backup); row.addWidget(self.go)
        self.verify = QPushButton("VERIFY ARCHIVE"); self.verify.clicked.connect(self._verify); row.addWidget(self.verify)
        self.open = QPushButton("OPEN FOLDER"); self.open.clicked.connect(lambda: _open_folder(self._archive().dir if self._handle_ok() else self.root.text())); row.addWidget(self.open)
        row.addStretch(1)
        lay.addLayout(row)

        # second copies
        copies = QFrame(); copies.setObjectName("section")
        cl = QGridLayout(copies); cl.setContentsMargins(18, 12, 18, 12); cl.setHorizontalSpacing(10); cl.setVerticalSpacing(8)
        t = QLabel("SECOND COPY (OPTIONAL)"); t.setObjectName("sectionTitle"); cl.addWidget(t, 0, 0, 1, 4)
        cl.addWidget(QLabel("MIRROR FOLDER"), 1, 0)
        self.mirror = QLineEdit(cfg.get("mirror_folder") or ""); self.mirror.setPlaceholderText("a synced Dropbox / OneDrive / Drive folder, or an external drive")
        cl.addWidget(self.mirror, 1, 1, 1, 2)
        mb = QPushButton("BROWSE…"); mb.clicked.connect(lambda: self._pick(self.mirror)); cl.addWidget(mb, 1, 3)
        cl.addWidget(QLabel("BACKBLAZE B2"), 2, 0)
        kid, akey = cloud.load_b2_credentials()
        self.b2_key_id = QLineEdit(kid); self.b2_key_id.setPlaceholderText("Key ID"); cl.addWidget(self.b2_key_id, 2, 1)
        self.b2_app_key = QLineEdit(""); self.b2_app_key.setPlaceholderText("Application key" + (" (saved — leave blank to keep)" if akey else "")); self.b2_app_key.setEchoMode(QLineEdit.Password); cl.addWidget(self.b2_app_key, 2, 2)
        self.b2_bucket = QLineEdit(cfg.get("b2_bucket") or ""); self.b2_bucket.setPlaceholderText("bucket name"); cl.addWidget(self.b2_bucket, 2, 3)
        b2row = QHBoxLayout()
        self.b2_test = QPushButton("TEST B2"); self.b2_test.clicked.connect(self._test_b2); b2row.addWidget(self.b2_test)
        self.copy_now = QPushButton("COPY NOW"); self.copy_now.setToolTip("Mirror folder and/or B2, from the archive already on disk."); self.copy_now.clicked.connect(self._copies_now); b2row.addWidget(self.copy_now)
        self.copy_after = QCheckBox("Copy after every backup"); self.copy_after.setChecked(bool(cfg.get("mirror_folder") or cfg.get("b2_bucket"))); b2row.addWidget(self.copy_after)
        b2row.addStretch(1)
        cl.addLayout(b2row, 3, 1, 1, 3)
        lay.addWidget(copies)

        self.job = JobPanel(); lay.addWidget(self.job, 1)
        self._check(self.handle.currentText())

    # helpers
    def _pick(self, field: QLineEdit):
        d = QFileDialog.getExistingDirectory(self, "Choose a folder", field.text() or os.path.expanduser("~"))
        if d:
            field.setText(d)

    def _handle_ok(self) -> bool:
        return bool(HANDLE_RE.match(self.handle.currentText().strip()))

    def _check(self, text: str):
        m = HANDLE_RE.match(text.strip())
        self.go.setEnabled(bool(m) and not self.job.busy())
        if m:
            arc = self._archive()
            if arc.exists():
                mf = arc.manifest()
                self.note.setText(f"Archive exists — {mf.get('counts', {}).get('posts', 0)} posts, last run {str(mf.get('last_snapshot', ''))[:16]}. Next run adds only what is new.")
            else:
                self.note.setText(f"Looks like a handle on {m.group(2)}. No archive yet — the first run is a full one.")
        else:
            self.note.setText("" if not text.strip() else "A handle looks like @name@instance.")

    def _archive(self) -> archive_mod.Archive:
        name, host = Fetcher.split_handle(self.handle.currentText())
        return archive_mod.Archive(self.root.text().strip() or config.default_archive_root(), f"{name}@{host}")

    def _remember(self):
        h = self.handle.currentText().strip().lstrip("@")
        accts = [a for a in (self.cfg.get("accounts") or []) if a != h]
        self.cfg["accounts"] = ([h] + accts)[:20] if h else accts
        self.cfg["last_account"] = h
        self.cfg["archive_root"] = self.root.text().strip()
        self.cfg["mirror_folder"] = self.mirror.text().strip()
        self.cfg["b2_bucket"] = self.b2_bucket.text().strip()
        self.cfg["want_media"] = self.want_media.isChecked()
        self.cfg["want_graph"] = self.want_graph.isChecked()
        self.cfg["want_replies"] = self.want_replies.isChecked()
        config.save(self.cfg)
        if self.b2_key_id.text().strip() or self.b2_app_key.text().strip():
            try:
                cloud.save_b2_credentials(self.b2_key_id.text(), self.b2_app_key.text())
            except Exception as e:
                self.job.log(f"B2 key not saved to the credential store: {e}")
        if h and self.handle.findText(h) < 0:
            self.handle.insertItem(0, h)

    # actions
    def _backup(self):
        if not self._handle_ok():
            return
        self._remember()
        arc = self._archive()
        media, graph, replies = self.want_media.isChecked(), self.want_graph.isChecked(), self.want_replies.isChecked()
        copy_after = self.copy_after.isChecked()
        mirror = self.mirror.text().strip()
        b2 = self._b2_params()

        def job(log, progress):
            f = Fetcher(log=log)
            summary = arc.run(f, want_media=media, want_graph=graph, want_replies=replies, log=log, progress=progress)
            if copy_after:
                self._do_copies(arc, mirror, b2, log, progress)
            return summary

        buttons = (self.go, self.verify, self.copy_now)
        self.job.logbox.clear()
        self.job.start(job, self._backup_done, buttons=buttons)

    def _backup_done(self, s: dict):
        limits = "\n".join("  • " + x for x in (s.get("could_not_fetch") or []))
        self.job.state.setText(f"Done — {s.get('new_posts', 0)} new post(s), {s.get('total_posts', 0)} in the archive.")
        self.job.log("Not in this archive:\n" + limits)
        self._check(self.handle.currentText())

    def _verify(self):
        if not self._handle_ok():
            self.job.log("Enter the account whose archive you want to verify."); return
        arc = self._archive()
        if not arc.exists():
            self.job.log("No archive for that account here yet."); return

        def job(log, progress):
            log(f"Verifying {arc.dir}…")
            return arc.verify()

        def done(r):
            if r.get("ok"):
                self.job.state.setText(f"Archive intact — {r.get('files', 0)} files match the manifest.")
            else:
                self.job.state.setText(f"PROBLEM — {len(r.get('missing', []))} missing, {len(r.get('changed', []))} changed.")
                for x in r.get("missing", []):
                    self.job.log("missing: " + x)
                for x in r.get("changed", []):
                    self.job.log("changed: " + x)
        self.job.start(job, done, buttons=(self.go, self.verify, self.copy_now))

    def _b2_params(self):
        kid = self.b2_key_id.text().strip(); bucket = self.b2_bucket.text().strip()
        akey = self.b2_app_key.text().strip() or cloud.load_b2_credentials()[1]
        return (kid, akey, bucket) if kid and akey and bucket else None

    def _test_b2(self):
        p = self._b2_params()
        if not p:
            self.job.log("B2: fill in Key ID, Application key and bucket first."); return
        ok, msg = cloud.test_b2(*p)
        self.job.log(("B2 OK — " if ok else "B2 FAILED — ") + msg)
        if ok:
            self._remember()

    def _copies_now(self):
        if not self._handle_ok():
            self.job.log("Enter the account first."); return
        arc = self._archive()
        if not arc.exists():
            self.job.log("Nothing to copy — run a backup first."); return
        self._remember()
        mirror = self.mirror.text().strip(); b2 = self._b2_params()
        if not mirror and not b2:
            self.job.log("Set a mirror folder and/or B2 first."); return
        self.job.start(lambda log, progress: self._do_copies(arc, mirror, b2, log, progress) or {},
                       lambda d: self.job.state.setText("Copies done."), buttons=(self.go, self.verify, self.copy_now))

    @staticmethod
    def _do_copies(arc, mirror, b2, log, progress):
        if mirror:
            arc.mirror_to_folder(mirror, log=log)
        if b2:
            cloud.push_b2(arc.dir, arc.handle, *b2, log=log, progress=progress)


# ── RESTORE ──────────────────────────────────────────────────────────────────

class RestoreTab(QWidget):
    def __init__(self, cfg: dict, parent=None):
        super().__init__(parent)
        self.cfg = cfg
        self.profiles = []
        lay = QVBoxLayout(self); lay.setContentsMargins(16, 16, 16, 16); lay.setSpacing(12)
        lay.addWidget(_section(
            "BITCHSLAP IT INTO SNAPSMACK",
            "Read a FED UP archive and rebuild the posts on your SnapSmack site with their real dates, captions "
            "and tags, photos at full size. Rides UNZUCKER's poster, so a post already on the site is skipped, "
            "not doubled. Your site's name, bio and avatar are left alone. Followers do not copy across: if the "
            "old server is alive, set MOVING FROM in your site's Fediverse Config → PROFILE and trigger Move from "
            "the old account; if it is dead, FOLLOWERS TO TELL gives you the list."))

        grid = QGridLayout(); grid.setHorizontalSpacing(10); grid.setVerticalSpacing(8)
        grid.addWidget(QLabel("ARCHIVE"), 0, 0)
        self.archive = QComboBox(); self.archive.setEditable(True)
        self.archive.lineEdit().setPlaceholderText("folder holding manifest.json")
        grid.addWidget(self.archive, 0, 1)
        b = QPushButton("BROWSE…"); b.clicked.connect(self._pick_archive); grid.addWidget(b, 0, 2)
        grid.addWidget(QLabel("SITE"), 1, 0)
        self.site = QComboBox(); grid.addWidget(self.site, 1, 1)
        rb = QPushButton("RELOAD SITES"); rb.clicked.connect(self._load_sites); grid.addWidget(rb, 1, 2)
        self.site_note = QLabel(""); self.site_note.setObjectName("muted"); grid.addWidget(self.site_note, 2, 1, 1, 2)
        grid.addWidget(QLabel("CATEGORY"), 3, 0)
        self.category = QLineEdit(cfg.get("restore_category") or ""); self.category.setPlaceholderText("existing category name, optional"); grid.addWidget(self.category, 3, 1, 1, 2)
        grid.addWidget(QLabel("ALBUM"), 4, 0)
        self.album = QLineEdit(cfg.get("restore_album") or ""); self.album.setPlaceholderText("existing album name, optional"); grid.addWidget(self.album, 4, 1, 1, 2)
        grid.addWidget(QLabel("COPYRIGHT"), 5, 0)
        self.copyright = QLineEdit(cfg.get("restore_copyright") or ""); self.copyright.setPlaceholderText("written into each photo's EXIF, optional"); grid.addWidget(self.copyright, 5, 1, 1, 2)
        self.include_replies = QCheckBox("Include my replies to other people's posts"); grid.addWidget(self.include_replies, 6, 1, 1, 2)
        lay.addLayout(grid)

        row = QHBoxLayout()
        self.preview = QPushButton("PREVIEW"); self.preview.clicked.connect(self._preview); row.addWidget(self.preview)
        self.go = QPushButton("RESTORE TO SITE"); self.go.setObjectName("primary"); self.go.setEnabled(False); self.go.clicked.connect(self._restore); row.addWidget(self.go)
        self.tell = QPushButton("FOLLOWERS TO TELL"); self.tell.clicked.connect(self._followers); row.addWidget(self.tell)
        row.addStretch(1)
        lay.addLayout(row)
        self.job = JobPanel(); lay.addWidget(self.job, 1)

        self._load_archives()
        self._load_sites()
        self.archive.editTextChanged.connect(lambda _t: self.go.setEnabled(False))
        self.site.currentIndexChanged.connect(self._site_changed)

    def _load_archives(self):
        root = self.cfg.get("archive_root") or config.default_archive_root()
        self.archive.clear()
        if os.path.isdir(root):
            for name in sorted(os.listdir(root)):
                if os.path.isfile(os.path.join(root, name, "manifest.json")):
                    self.archive.addItem(os.path.join(root, name))
        if self.archive.count() == 0:
            self.archive.setCurrentText("")

    def _pick_archive(self):
        d = QFileDialog.getExistingDirectory(self, "Choose the archive folder (holds manifest.json)",
                                             self.cfg.get("archive_root") or os.path.expanduser("~"))
        if d:
            self.archive.setCurrentText(d)

    def _load_sites(self):
        self.site.clear(); self.profiles = []
        try:
            import snap_profiles
            self.profiles = snap_profiles.list_profiles()
        except Exception as e:
            self.site_note.setText(f"Shared profiles unavailable: {e}")
        for p in self.profiles:
            self.site.addItem(f"{p.get('name') or p.get('site_url')}  —  {p.get('site_url')}")
        want = self.cfg.get("restore_site") or ""
        for i, p in enumerate(self.profiles):
            if p.get("site_url") == want:
                self.site.setCurrentIndex(i)
        if not self.profiles:
            self.site_note.setText("No SnapSmack sites in the shared profiles yet — set one up in SNAP HQ first.")
        self._site_changed()

    def _site_changed(self, *_):
        p = self._profile()
        if not p:
            return
        key = restore_mod.site_key_for(p)
        self.site_note.setText("Key ready (unzucker-type or main key)." if key
                               else "This site has no key FED UP can post with — issue an unzucker-type key in SNAP HQ.")

    def _profile(self):
        i = self.site.currentIndex()
        return self.profiles[i] if 0 <= i < len(self.profiles) else None

    def _archive_dir(self) -> str:
        d = self.archive.currentText().strip()
        return d if d and os.path.isfile(os.path.join(d, "manifest.json")) else ""

    def _preview(self):
        d = self._archive_dir()
        if not d:
            self.job.log("Pick an archive folder that holds manifest.json."); return
        inc = self.include_replies.isChecked()

        def job(log, progress):
            r = restore_mod.parse(d, include_replies=inc)
            return {"stats": r.stats, "first": r.posts[0].ig_timestamp if r.posts else 0, "last": r.posts[-1].ig_timestamp if r.posts else 0}

        def done(r):
            s = r.get("stats", {})
            self.job.state.setText(f"{s.get('posts', 0)} post(s) would be restored; skipped: {s.get('skipped_no_image', 0)} without a photo, "
                                   f"{s.get('skipped_replies', 0)} replies, {s.get('skipped_private', 0)} not public.")
            self.go.setEnabled(bool(s.get("posts")) and bool(self._profile()) and bool(restore_mod.site_key_for(self._profile() or {})))
        self.job.start(job, done, buttons=(self.preview, self.go))

    def _restore(self):
        d = self._archive_dir(); p = self._profile()
        if not d or not p:
            return
        key = restore_mod.site_key_for(p)
        if not key:
            self.job.log("No key for that site."); return
        if QMessageBox.question(self, "Restore to site",
                                f"Post the archive in\n{d}\n\nto\n{p.get('site_url')}\n\nPosts already on the site are skipped. Go ahead?",
                                QMessageBox.Yes | QMessageBox.No, QMessageBox.No) != QMessageBox.Yes:
            return
        self.cfg["restore_site"] = p.get("site_url") or ""
        self.cfg["restore_category"] = self.category.text().strip()
        self.cfg["restore_album"] = self.album.text().strip()
        self.cfg["restore_copyright"] = self.copyright.text().strip()
        config.save(self.cfg)
        cat, alb, cpy, inc, url = self.category.text().strip(), self.album.text().strip(), self.copyright.text().strip(), self.include_replies.isChecked(), p.get("site_url")

        def job(log, progress):
            def on_progress(done, total, result):
                progress(done, total, f"{done}/{total}: {result.message}")
                if not result.success:
                    log(f"post {result.post_index}: {result.message}")
            out = restore_mod.run(d, url, key, default_category=cat, default_album=alb, copyright_text=cpy,
                                  include_replies=inc, log=log, on_progress=on_progress)
            out.pop("results", None)
            return out

        def done(r):
            self.job.state.setText(f"Restore finished — {r.get('posted', 0)} posted, {r.get('skipped', 0)} already there, {r.get('failed', 0)} failed.")
        self.job.logbox.clear()
        self.job.start(job, done, buttons=(self.preview, self.go))

    def _followers(self):
        d = self._archive_dir()
        if not d:
            self.job.log("Pick an archive folder first."); return
        handles = restore_mod.followers_to_tell(d)
        if not handles:
            self.job.log("The archive has no follower list (the server hid it, or you unticked it)."); return
        box = QMessageBox(self); box.setWindowTitle(f"{len(handles)} followers to tell")
        box.setText(f"{len(handles)} accounts followed the old profile. Copied to the clipboard — paste into a post that says where you went.")
        box.setDetailedText("\n".join(handles))
        QApplication.clipboard().setText("\n".join(handles))
        box.exec()


# ── ALIAS ────────────────────────────────────────────────────────────────────

class AliasTab(QWidget):
    def __init__(self, parent=None):
        super().__init__(parent)
        lay = QVBoxLayout(self); lay.setContentsMargins(16, 16, 16, 16); lay.setSpacing(12)
        lay.addWidget(_section(
            "@YOU@PHOTOBLOGS.FYI",
            "A second fediverse address for your blog on a domain the network keeps alive, pointing at your "
            "blog's own actor. Nothing moves and nothing here needs running — it is a switch on your site.",
            "BUILT — SnapSmack 0.7.719D. Set it on your site, not here."))
        steps = _section(
            "HOW TO TURN IT ON",
            "1. On your blog: Fediverse → Federation. Choose your handle and enable federation if you have not.\n"
            "2. Press JOIN NETWORK. The hub claims @your-handle@photoblogs.fyi for you the moment you are admitted "
            "(first come, first served — REVIEW FLEET on the hub flags a clash).\n"
            "3. In the ALIAS box on the same page, tick INTRODUCE THIS BLOG AS… and SAVE ALIAS. Your blog checks "
            "with the hub before saving and tells you in plain words if the name is not yours.\n\n"
            "Servers that already follow you show the new name after they next refresh your profile. Your own "
            "address keeps working the whole time. Full notes: your site's Help → Fediverse → Alias.")
        lay.addWidget(steps)
        lay.addStretch(1)


# ── WINDOW ───────────────────────────────────────────────────────────────────

class Window(QMainWindow):
    def __init__(self):
        super().__init__()
        self.cfg = config.load()
        self.setWindowTitle(f"FED UP — fed up with your server  ·  {BUILD_VERSION}")
        self.resize(980, 760); self.setMinimumSize(780, 600)
        self._window_settings = QSettings("SnapSmack", "FED UP")
        geometry = self._window_settings.value("window/normal_geometry")
        if geometry:
            self.restoreGeometry(geometry)
        self._restore_maximized = self._window_settings.value("window/maximized", False, type=bool)
        root = QWidget(); self.setCentralWidget(root)
        outer = QVBoxLayout(root); outer.setContentsMargins(0, 0, 0, 0); outer.setSpacing(0)

        header = QFrame(); header.setObjectName("header")
        hl = QHBoxLayout(header); hl.setContentsMargins(22, 14, 22, 14)
        brand = QLabel("FED UP"); brand.setObjectName("brand"); hl.addWidget(brand)
        tag = QLabel("fediverse backup · migration · identity"); tag.setObjectName("tag"); hl.addWidget(tag)
        hl.addStretch(1)
        outer.addWidget(header)

        tabs = QTabWidget()
        self.backup = BackupTab(self.cfg); self.restore = RestoreTab(self.cfg)
        tabs.addTab(self.backup, "BACK UP")
        tabs.addTab(self.restore, "RESTORE")
        tabs.addTab(AliasTab(), "ALIAS")
        tabs.currentChanged.connect(lambda i: self.restore._load_archives() if i == 1 else None)
        body = QWidget(); bl = QVBoxLayout(body); bl.setContentsMargins(18, 18, 18, 18); bl.addWidget(tabs)
        outer.addWidget(body, 1)

        foot = QLabel("Your identity should not depend on someone else's server. FED UP reads public data only, "
                      "writes to the folders you name, and posts to your site only when you press RESTORE.")
        foot.setObjectName("muted"); foot.setContentsMargins(22, 0, 22, 14); foot.setWordWrap(True)
        outer.addWidget(foot)
        if self._restore_maximized:
            QTimer.singleShot(0, self.showMaximized)

    def closeEvent(self, ev):
        if self.backup.job.busy() or self.restore.job.busy():
            if QMessageBox.question(self, "A job is running", "A backup or restore is still running. Close anyway?",
                                    QMessageBox.Yes | QMessageBox.No, QMessageBox.No) != QMessageBox.Yes:
                ev.ignore(); return
        if not self.isMinimized():
            self._window_settings.setValue("window/maximized", self.isMaximized())
            if not self.isMaximized():
                self._window_settings.setValue("window/normal_geometry", self.saveGeometry())
            self._window_settings.sync()
        ev.accept()


def run() -> int:
    app = QApplication.instance() or QApplication(sys.argv)
    app.setStyle("Fusion"); app.setStyleSheet(STYLE)
    app.setApplicationName("FED UP")
    icon = _asset("fed-up.png")
    if os.path.isfile(icon):
        app.setWindowIcon(QIcon(icon))
    window = Window(); window.show()
    return app.exec()

# ===== SNAPSMACK EOF =====
