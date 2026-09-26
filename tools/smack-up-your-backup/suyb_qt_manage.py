"""SMACK UP YOUR BACKUP — the Manage page (cloud backup manager) for the Qt window.

Port of the Tk `BackupManagerTab` and `CloudBrowserDialog` from main.py.

What it does for the user: shows every backup ZIP in the backup cloud
(Google Drive / Box) or in any Backblaze bucket a Cloud Sync job writes to,
lets them search, filter by date, sort (or group by blog with per-blog
totals), tick backups, download them, hand one to Restore, or delete them.
Deleting is permanent, so it is always confirmed with the exact count, the
space freed and the file names.

Exports:
    ManagePage(window)                    the page (see PAGE CONTRACT in suyb_qt_common)
        restoreRequested = Signal(str)        a LOCAL zip path (Backblaze backups are
                                              downloaded first, then handed over)
        cloudRestoreRequested = Signal(str, str)  (file_id, file_name) of a Google
                                              Drive / Box backup; Restore reads it
                                              straight from the cloud, as in Tk
    CloudBrowserDialog(parent, cloud_cfg, profile=None, backups=None)
        the "Browse cloud…" picker for the Restore page; see its docstring.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
"""

import os
import re
from datetime import datetime

from PySide6.QtCore import QSize, Qt, Signal, SIGNAL
from PySide6.QtWidgets import (
    QAbstractItemView, QCheckBox, QComboBox, QDialog, QFileDialog, QHBoxLayout,
    QHeaderView, QLineEdit, QMessageBox, QProgressBar, QPushButton, QTreeWidget,
    QTreeWidgetItem, QVBoxLayout, QWidget,
)

import cloud_client
import sync_manager
from suyb_qt_common import Relay, card, label, make_page, run_in_thread, set_status


# ---------------------------------------------------------------------------
# Sorting choices (same wording and order as the Tk dropdown)
# ---------------------------------------------------------------------------

SORT_NEWEST = "Newest first"
SORT_OLDEST = "Oldest first"
SORT_BLOG = "Blog name (A→Z)"
SORT_GROUPED = "Group by blog, newest first"
SORT_NAME = "File name (A→Z)"
SORT_LARGEST = "Largest first"
SORT_SMALLEST = "Smallest first"
SORT_CHOICES = [SORT_NEWEST, SORT_OLDEST, SORT_BLOG, SORT_GROUPED,
                SORT_NAME, SORT_LARGEST, SORT_SMALLEST]

# Tree columns. Column 0 holds only the tick box.
COL_TICK, COL_BLOG, COL_FILE, COL_DATE, COL_SIZE = range(5)
_COLUMN_SORT = {COL_BLOG: SORT_BLOG, COL_FILE: SORT_NAME,
                COL_DATE: SORT_NEWEST, COL_SIZE: SORT_LARGEST}

# How many file names the delete confirmation lists before "… and N more".
CONFIRM_NAME_LIMIT = 15
ROW_HEIGHT = 34          # tall rows: easier to hit with an unsteady hand
PROFILE_SOURCE_LABEL = "Backup cloud (Google Drive / Box)"
_DATE_RE = re.compile(r"^\d{4}-\d{2}-\d{2}$")
# Dialogs whose cloud listing is still running. Holding them here stops Python
# deleting a closed dialog while its worker thread is about to report back.
_LOADING_DIALOGS = set()


# ---------------------------------------------------------------------------
# Plain helpers (no widgets) — shared by the page and the dialog, and tested
# ---------------------------------------------------------------------------

def human_size(nbytes):
    n = float(nbytes or 0)
    for unit in ("B", "KB", "MB", "GB", "TB"):
        if n < 1024 or unit == "TB":
            return f"{n:.0f} {unit}" if unit in ("B", "KB") else f"{n:.1f} {unit}"
        n /= 1024
    return f"{n:.1f} TB"


def blog_of(name):
    """The blog a backup belongs to: the filename before '_backup_'."""
    i = (name or "").find("_backup_")
    return name[:i] if i > 0 else (name or "")


def date10(backup):
    """Best YYYY-MM-DD for a backup: cloud modified time, else the filename date."""
    d = (backup.get("date") or "")[:10]
    if len(d) == 10 and d[4] == "-":
        return d
    name = backup.get("name", "")
    marker = "_backup_"
    i = name.find(marker)
    if i > 0:
        cand = name[i + len(marker): i + len(marker) + 10]
        if len(cand) == 10 and cand[4] == "-":
            return cand
    return ""


def valid_date(text):
    """True for a real calendar date written YYYY-MM-DD."""
    if not _DATE_RE.match(text or ""):
        return False
    try:
        datetime.strptime(text, "%Y-%m-%d")
        return True
    except ValueError:
        return False


def filter_backups(backups, term="", date_from="", date_to=""):
    term = (term or "").strip().lower()
    out = []
    for b in backups:
        name = b.get("name", "")
        if term and term not in name.lower() and term not in blog_of(name).lower():
            continue
        d = date10(b)
        if date_from and (not d or d < date_from):
            continue
        if date_to and (not d or d > date_to):
            continue
        out.append(b)
    return out


def sort_backups(rows, choice):
    name = lambda b: b.get("name", "").lower()
    size = lambda b: int(b.get("size_bytes", 0) or 0)
    if choice == SORT_OLDEST:
        return sorted(rows, key=lambda b: (date10(b), name(b)))
    if choice == SORT_BLOG:
        return sorted(rows, key=lambda b: (blog_of(b.get("name", "")).lower(), date10(b)))
    if choice == SORT_NAME:
        return sorted(rows, key=name)
    if choice == SORT_LARGEST:
        return sorted(rows, key=size, reverse=True)
    if choice == SORT_SMALLEST:
        return sorted(rows, key=size)
    # Newest first, and the grouped view, both order by date, newest first.
    return sorted(rows, key=lambda b: (date10(b), name(b)), reverse=True)


def enumerate_sources():
    """Where backups can live: the backup cloud, plus every Backblaze bucket a
    Cloud Sync job reads or writes. Backblaze keys come from the sync jobs (a
    blog profile never stores B2 keys); a job that cannot be read (for example
    a locked vault) is skipped, exactly as in the Tk tab."""
    sources = [{"label": PROFILE_SOURCE_LABEL, "kind": "profile"}]
    seen = set()
    try:
        job_names = sync_manager.list_jobs()
    except Exception:
        job_names = []
    for job_name in job_names:
        try:
            job = sync_manager.load_job(job_name)
        except Exception:
            continue
        if not job:
            continue
        for side in ("source", "dest"):
            provider = (job.get(f"{side}_provider") or "").lower()
            if provider not in ("b2", "backblaze_b2"):
                continue
            key_id = (job.get(f"{side}_b2_key_id") or "").strip()
            app_key = (job.get(f"{side}_b2_app_key") or "").strip()
            bucket = (job.get(f"{side}_folder") or "").strip()
            if not (key_id and app_key and bucket) or (key_id, bucket) in seen:
                continue
            seen.add((key_id, bucket))
            sources.append({"label": f"Backblaze — {bucket}  (job: {job_name})",
                            "kind": "b2", "key_id": key_id,
                            "app_key": app_key, "bucket": bucket})
    return sources


def make_client(source, profile, global_cloud):
    """The cloud client for a source, or None when it cannot be opened."""
    try:
        if (source or {}).get("kind") == "b2":
            return cloud_client.B2Client(source["key_id"], source["app_key"], source["bucket"])
        return cloud_client.get_cloud_client(profile or {}, global_cloud=global_cloud or {})
    except Exception:
        return None


def list_backups(client):
    """Backup ZIPs in the cloud folder as [{id, name, size_bytes, date}].

    Same shape and filter as cloud_manifest.list_available_backups, but a
    failure RAISES instead of returning [] — otherwise a broken login would
    read "No backups found", which is a lie the user could act on."""
    files = client.list_files(name_filter="_backup_")
    return [{"id": f["id"], "name": f["name"],
             "size_bytes": int(f.get("size", 0) or 0),
             "date": f.get("modifiedTime", "")}
            for f in files if str(f.get("name", "")).endswith(".zip")]


def safe_file_name(name):
    """A cloud file name reduced to a plain file name (no folders, no '..')."""
    base = os.path.basename(str(name or "").replace("\\", "/")).strip()
    return "" if base in ("", ".", "..") else base


def download_to(client, backup, dest, on_progress=None):
    """Download via a .part file so a failed download never leaves a
    complete-looking ZIP behind."""
    part = dest + ".part"
    try:
        client.download_file(backup["id"], part, on_progress)
        os.replace(part, dest)
    except Exception:
        try:
            if os.path.exists(part):
                os.remove(part)
        except OSError:
            pass
        raise


def _tall(item, columns):
    for col in range(columns):
        item.setSizeHint(col, QSize(0, ROW_HEIGHT))


# ---------------------------------------------------------------------------
# Manage page
# ---------------------------------------------------------------------------

class ManagePage(QWidget):
    """Full manager for backup ZIPs in the cloud (Tk: BackupManagerTab)."""

    restoreRequested = Signal(str)
    cloudRestoreRequested = Signal(str, str)

    def __init__(self, window):
        super().__init__()
        self.window = window
        self.relay = Relay(self)
        self._all = []            # every backup dict from the chosen source
        self._rows = []           # [(QTreeWidgetItem, backup)] for tickable rows
        self._sources = []
        self._busy = False
        self._loaded = False
        self._closing = False
        self._date_from = ""      # the APPLIED date range (Apply dates)
        self._date_to = ""
        self._build()
        if hasattr(window, "profileChanged"):
            window.profileChanged.connect(self._profile_changed)

    # -- build ---------------------------------------------------------------

    def _build(self):
        layout = make_page(self, "Manage cloud backups",
                           "See, sort, download, restore and delete the backups saved in your "
                           "backup cloud or your Backblaze buckets.")

        where, col = card("Where to look")
        row = QHBoxLayout()
        row.addWidget(label("Source:"))
        self.source_combo = QComboBox(); self.source_combo.setMinimumWidth(420)
        self.source_combo.currentIndexChanged.connect(self._source_changed)
        row.addWidget(self.source_combo, 1)
        self.refresh_btn = QPushButton("Refresh list")
        self.refresh_btn.clicked.connect(self.refresh)
        row.addWidget(self.refresh_btn)
        col.addLayout(row)
        layout.addWidget(where)

        find, col = card("Find backups")
        row = QHBoxLayout()
        row.addWidget(label("Search:"))
        self.search_edit = QLineEdit(); self.search_edit.setPlaceholderText("Part of a blog or file name")
        self.search_edit.textChanged.connect(self._render)
        row.addWidget(self.search_edit, 1)
        row.addWidget(label("Sort by:"))
        self.sort_combo = QComboBox(); self.sort_combo.addItems(SORT_CHOICES)
        self.sort_combo.currentIndexChanged.connect(self._render)
        row.addWidget(self.sort_combo)
        col.addLayout(row)
        row = QHBoxLayout()
        row.addWidget(label("Date from:"))
        self.from_edit = QLineEdit(); self.from_edit.setPlaceholderText("YYYY-MM-DD")
        self.from_edit.returnPressed.connect(self._apply_dates)
        row.addWidget(self.from_edit)
        row.addWidget(label("to:"))
        self.to_edit = QLineEdit(); self.to_edit.setPlaceholderText("YYYY-MM-DD")
        self.to_edit.returnPressed.connect(self._apply_dates)
        row.addWidget(self.to_edit)
        self.apply_btn = QPushButton("Apply dates"); self.apply_btn.clicked.connect(self._apply_dates)
        row.addWidget(self.apply_btn)
        self.clear_btn = QPushButton("Clear filters"); self.clear_btn.clicked.connect(self._clear_filters)
        row.addWidget(self.clear_btn)
        row.addStretch(1)
        col.addLayout(row)
        layout.addWidget(find)

        table, col = card("Backups")
        self.tick_all = QCheckBox("Tick every backup shown")
        self.tick_all.clicked.connect(self._toggle_all)
        col.addWidget(self.tick_all)
        self.tree = QTreeWidget()
        self.tree.setColumnCount(5)
        self.tree.setHeaderLabels(["", "Blog", "File", "Date", "Size"])
        self.tree.setSelectionMode(QAbstractItemView.NoSelection)
        self.tree.setRootIsDecorated(False)
        self.tree.setMinimumHeight(380)
        header = self.tree.header()
        header.setSectionsClickable(True)
        header.setSectionResizeMode(COL_TICK, QHeaderView.Fixed); header.resizeSection(COL_TICK, 44)
        header.resizeSection(COL_BLOG, 190)
        header.setSectionResizeMode(COL_FILE, QHeaderView.Stretch)
        header.resizeSection(COL_DATE, 110); header.resizeSection(COL_SIZE, 100)
        header.setStretchLastSection(False)
        header.sectionClicked.connect(self._sort_by_column)
        self.tree.itemChanged.connect(self._item_changed)
        self.tree.itemClicked.connect(self._item_clicked)
        col.addWidget(self.tree)
        self.totals_lbl = label("", "Muted")
        col.addWidget(self.totals_lbl)
        self.selected_lbl = label("Nothing ticked.", "Muted")
        col.addWidget(self.selected_lbl)
        layout.addWidget(table, 1)

        act, col = card("Actions")
        self.status_lbl = label("")
        set_status(self.status_lbl, "Open this page to load the list.", "warn")
        col.addWidget(self.status_lbl)
        self.progress = QProgressBar(); self.progress.setRange(0, 100); self.progress.setVisible(False)
        col.addWidget(self.progress)
        row = QHBoxLayout()
        self.restore_btn = QPushButton("Restore selected"); self.restore_btn.setObjectName("Primary")
        self.restore_btn.clicked.connect(self._on_restore)
        row.addWidget(self.restore_btn)
        self.download_btn = QPushButton("Download selected…")
        self.download_btn.clicked.connect(self._on_download)
        row.addWidget(self.download_btn)
        row.addStretch(1)
        self.delete_btn = QPushButton("Delete selected…"); self.delete_btn.setObjectName("Danger")
        self.delete_btn.clicked.connect(self._on_delete)
        row.addWidget(self.delete_btn)
        col.addLayout(row)
        layout.addWidget(act)
        self._update_buttons()

    # -- page contract -------------------------------------------------------

    def help_topics(self):
        return HELP_TOPICS

    def shutdown(self):
        self._closing = True

    # -- sources -------------------------------------------------------------

    def _populate_sources(self):
        previous = self.source_combo.currentText()
        self._sources = enumerate_sources()
        labels = [s["label"] for s in self._sources]
        self.source_combo.blockSignals(True)
        self.source_combo.clear(); self.source_combo.addItems(labels)
        self.source_combo.setCurrentIndex(labels.index(previous) if previous in labels else 0)
        self.source_combo.blockSignals(False)

    def selected_source(self):
        i = self.source_combo.currentIndex()
        if 0 <= i < len(self._sources):
            return self._sources[i]
        return self._sources[0] if self._sources else {"kind": "profile", "label": PROFILE_SOURCE_LABEL}

    def _current_client(self):
        return make_client(self.selected_source(), self.window.current_profile,
                           self.window.global_cloud())

    def _source_changed(self, _index):
        self._all = []; self._loaded = False
        self._render()
        self._load()

    def _profile_changed(self, _profile):
        if self.isVisible() and self.selected_source().get("kind") == "profile":
            self.refresh()

    # -- loading -------------------------------------------------------------

    def refresh(self):
        """Called every time the page is shown and by Refresh list."""
        if self._busy:
            return
        self._populate_sources()
        self._load()

    def _load(self):
        if self._busy:
            return
        source = self.selected_source()
        if source.get("kind") == "profile" and not self.window.current_profile:
            self._all = []; self._loaded = False; self._render()
            set_status(self.status_lbl, "Pick a site at the top first (or pick a Backblaze source).", "warn")
            return
        client = self._current_client()
        if not client:
            self._all = []; self._loaded = False; self._render()
            set_status(self.status_lbl,
                       "Could not open that Backblaze bucket — check the Cloud Sync job."
                       if source.get("kind") == "b2"
                       else "No cloud storage is set up — choose one in Settings.", "bad")
            return
        self._set_busy(True)
        set_status(self.status_lbl, f"Loading backups from {source.get('label', 'the cloud')}…", "warn")
        run_in_thread(self.relay, lambda: list_backups(client), self._loaded_ok, self._loaded_fail)

    def _loaded_ok(self, items):
        if self._closing:
            return
        self._set_busy(False)
        self._all = list(items); self._loaded = True
        self._render()

    def _loaded_fail(self, exc):
        if self._closing:
            return
        self._set_busy(False)
        self._all = []; self._loaded = False
        self._render()
        set_status(self.status_lbl, f"Could not read the cloud: {exc}", "bad")

    # -- filters / sorting / table -------------------------------------------

    def _apply_dates(self):
        d_from = self.from_edit.text().strip()
        d_to = self.to_edit.text().strip()
        bad = [t for t in (d_from, d_to) if t and not valid_date(t)]
        if bad:
            set_status(self.status_lbl,
                       f"“{bad[0]}” is not a date. Type it as YYYY-MM-DD, for example 2026-08-01.", "bad")
            return
        if d_from and d_to and d_from > d_to:
            set_status(self.status_lbl, "The 'from' date is after the 'to' date — swap them.", "bad")
            return
        self._date_from, self._date_to = d_from, d_to
        self._render()

    def _clear_filters(self):
        for w in (self.search_edit, self.from_edit, self.to_edit, self.sort_combo):
            w.blockSignals(True)
        self.search_edit.clear(); self.from_edit.clear(); self.to_edit.clear()
        self.sort_combo.setCurrentText(SORT_NEWEST)
        for w in (self.search_edit, self.from_edit, self.to_edit, self.sort_combo):
            w.blockSignals(False)
        self._date_from = self._date_to = ""
        self._render()

    def _sort_by_column(self, column):
        if column in _COLUMN_SORT:
            self.sort_combo.setCurrentText(_COLUMN_SORT[column])  # triggers _render

    def visible_backups(self):
        """The backups currently on screen, in screen order."""
        rows = filter_backups(self._all, self.search_edit.text(), self._date_from, self._date_to)
        return sort_backups(rows, self.sort_combo.currentText())

    def _add_row(self, parent, backup):
        item = QTreeWidgetItem(["", blog_of(backup.get("name", "")), backup.get("name", ""),
                                date10(backup), human_size(backup.get("size_bytes", 0))])
        item.setFlags((item.flags() | Qt.ItemIsUserCheckable) & ~Qt.ItemIsSelectable)
        item.setCheckState(COL_TICK, Qt.Unchecked)
        item.setTextAlignment(COL_DATE, Qt.AlignCenter)
        item.setTextAlignment(COL_SIZE, Qt.AlignRight | Qt.AlignVCenter)
        item.setToolTip(COL_FILE, backup.get("name", ""))
        _tall(item, 5)
        if parent is None:
            self.tree.addTopLevelItem(item)
        else:
            parent.addChild(item)
        self._rows.append((item, backup))

    def _render(self, *_):
        self.tree.blockSignals(True)
        self.tree.clear(); self._rows = []
        rows = self.visible_backups()
        grouped = self.sort_combo.currentText() == SORT_GROUPED
        self.tree.setRootIsDecorated(grouped)
        # Grouped rows are indented; widen the tick column so the box stays visible.
        self.tree.header().resizeSection(COL_TICK, 44 + (self.tree.indentation() if grouped else 0))
        if grouped:
            by_blog = {}
            for b in rows:
                by_blog.setdefault(blog_of(b.get("name", "")), []).append(b)
            for blog in sorted(by_blog, key=str.lower):
                items = by_blog[blog]
                subtotal = sum(int(b.get("size_bytes", 0) or 0) for b in items)
                group = QTreeWidgetItem([f"{blog}   —   {len(items)} backup(s), {human_size(subtotal)}"])
                group.setFlags(Qt.ItemIsEnabled)
                _tall(group, 1)
                self.tree.addTopLevelItem(group)
                group.setFirstColumnSpanned(True)
                for b in items:
                    self._add_row(group, b)
                group.setExpanded(True)
        else:
            for b in rows:
                self._add_row(None, b)
        self.tree.blockSignals(False)
        self.tick_all.setChecked(False)

        total_all = sum(int(b.get("size_bytes", 0) or 0) for b in self._all)
        total_now = sum(int(b.get("size_bytes", 0) or 0) for b in rows)
        if not self._loaded:
            self.totals_lbl.setText("")
        elif not self._all:
            self.totals_lbl.setText("")
            set_status(self.status_lbl, "No backups found in this cloud folder.", "warn")
        else:
            msg = f"{len(rows)} backup(s) shown • {human_size(total_now)}"
            if len(rows) != len(self._all):
                msg += f"   (filtered from {len(self._all)} • {human_size(total_all)} total)"
            self.totals_lbl.setText(msg)
            if not self._busy:
                set_status(self.status_lbl, f"Loaded {len(self._all)} backup(s) from "
                           f"{self.selected_source().get('label', 'the cloud')}.", "good")
        self._update_buttons()

    # -- ticking -------------------------------------------------------------

    def _item_changed(self, _item, column):
        if column == COL_TICK:
            self._update_buttons()

    def _item_clicked(self, item, column):
        """A click anywhere on a row (not just the small box) ticks it."""
        if column == COL_TICK or not (item.flags() & Qt.ItemIsUserCheckable):
            return
        item.setCheckState(COL_TICK, Qt.Unchecked if item.checkState(COL_TICK) == Qt.Checked else Qt.Checked)

    def _toggle_all(self, checked):
        self.tree.blockSignals(True)
        for item, _b in self._rows:
            item.setCheckState(COL_TICK, Qt.Checked if checked else Qt.Unchecked)
        self.tree.blockSignals(False)
        self._update_buttons()

    def selected_backups(self):
        return [b for item, b in self._rows if item.checkState(COL_TICK) == Qt.Checked]

    def _update_buttons(self):
        sel = self.selected_backups()
        idle = not self._busy
        self.restore_btn.setEnabled(idle and len(sel) == 1)
        self.download_btn.setEnabled(idle and bool(sel))
        self.delete_btn.setEnabled(idle and bool(sel))
        if sel:
            size = sum(int(b.get("size_bytes", 0) or 0) for b in sel)
            self.selected_lbl.setText(f"{len(sel)} ticked • {human_size(size)}")
        else:
            self.selected_lbl.setText("Nothing ticked. Click a row to tick it.")

    def _set_busy(self, busy):
        self._busy = busy
        for w in (self.refresh_btn, self.source_combo):
            w.setEnabled(not busy)
        if not busy:
            self.progress.setVisible(False)
        self._update_buttons()

    def _progress(self, pct, text):
        self.progress.setVisible(True); self.progress.setValue(max(0, min(100, int(pct))))
        set_status(self.status_lbl, text, "warn")

    def _default_folder(self):
        folder = str((self.window.current_profile or {}).get("backup_dir", "") or "")
        return folder if os.path.isdir(folder) else os.path.expanduser("~")

    def _listening(self, signature):
        try:
            return self.receivers(SIGNAL(signature)) > 0
        except Exception:
            return True

    # -- restore -------------------------------------------------------------

    def _on_restore(self):
        sel = self.selected_backups()
        if len(sel) != 1 or self._busy:
            QMessageBox.information(self, "Pick one backup",
                                    "Tick exactly one backup to restore, then press Restore selected.")
            return
        backup = sel[0]
        if self.selected_source().get("kind") == "b2":
            self._restore_from_b2(backup)
            return
        name = backup.get("name", "")
        if not self._listening("cloudRestoreRequested(QString,QString)"):
            QMessageBox.information(self, "Open Restore yourself",
                                    f"Open the Restore page, choose Browse cloud and pick:\n\n{name}")
            return
        set_status(self.status_lbl, f"Sent {name} to Restore — press START RESTORE there.", "good")
        self.cloudRestoreRequested.emit(str(backup["id"]), name)

    def _restore_from_b2(self, backup):
        """Backblaze backups are downloaded first, then handed to Restore as a
        local ZIP (Restore reads Google Drive/Box or a local ZIP, not Backblaze).
        Choosing where to save IS the go-ahead; Cancel stops it."""
        name = safe_file_name(backup.get("name")) or "backup.zip"
        dest, _ = QFileDialog.getSaveFileName(
            self, "Backblaze backups are downloaded first — save it here, then it opens in Restore",
            os.path.join(self._default_folder(), name), "ZIP backup (*.zip);;All files (*.*)")
        if not dest:
            return
        self._start_download([(backup, dest)], restore_after=True)

    # -- download ------------------------------------------------------------

    def _on_download(self):
        sel = self.selected_backups()
        if not sel or self._busy:
            return
        folder = QFileDialog.getExistingDirectory(
            self, f"Choose where to save {len(sel)} backup(s)", self._default_folder())
        if not folder:
            return
        jobs, bad = [], []
        for b in sel:
            fname = safe_file_name(b.get("name"))
            if fname:
                jobs.append((b, os.path.join(folder, fname)))
            else:
                bad.append(b.get("name", "?"))
        if bad:
            QMessageBox.warning(self, "Some names cannot be saved",
                                "These cloud names are not usable as file names and will be skipped:\n\n"
                                + "\n".join(bad[:10]))
        existing = [dest for _b, dest in jobs if os.path.exists(dest)]
        if existing:
            box = QMessageBox(self)
            box.setIcon(QMessageBox.Warning); box.setWindowTitle("Replace files?")
            box.setText(f"{len(existing)} of these file(s) already exist in that folder.")
            box.setInformativeText("\n".join("  • " + os.path.basename(p) for p in existing[:CONFIRM_NAME_LIMIT]))
            replace = box.addButton(f"Replace {len(existing)} file(s)", QMessageBox.AcceptRole)
            keep = box.addButton("Cancel", QMessageBox.RejectRole)
            box.setDefaultButton(keep); box.setEscapeButton(keep)
            box._suyb_confirm = replace
            box.exec()
            if box.clickedButton() is not replace:
                return
        if jobs:
            self._start_download(jobs, restore_after=False)

    def _start_download(self, jobs, restore_after):
        client = self._current_client()
        if not client:
            QMessageBox.critical(self, "No cloud", "Could not open the cloud source to download from.")
            return
        self._set_busy(True)
        count = len(jobs)
        self._progress(0, f"Downloading {count} backup(s)…")

        def work():
            done, errors = [], []
            for i, (backup, dest) in enumerate(jobs):
                name = backup.get("name", "?")
                state = {"pct": -1}

                def on_progress(received, total, i=i, name=name, state=state):
                    if not total:
                        return
                    pct = int((i + received / total) * 100 / count)
                    if pct != state["pct"]:
                        state["pct"] = pct
                        self.relay.call.emit(lambda p=pct, n=name: self._progress(
                            p, f"Downloading {n} ({i + 1} of {count})… {p}%"))
                try:
                    download_to(client, backup, dest, on_progress)
                    done.append(dest)
                except Exception as exc:
                    errors.append(f"{name}: {exc}")
            return done, errors

        def finished(result):
            if self._closing:
                return
            done, errors = result
            self._set_busy(False)
            if errors:
                set_status(self.status_lbl, f"Downloaded {len(done)} of {count}. {len(errors)} failed.", "bad")
                QMessageBox.warning(self, "Download finished with errors",
                                    f"Downloaded {len(done)} of {count}.\n\n" + "\n".join(errors[:10]))
            else:
                where = done[0] if count == 1 else os.path.dirname(done[0])
                set_status(self.status_lbl, f"Downloaded {len(done)} backup(s) to {where}", "good")
            if restore_after and done and not errors:
                if self._listening("restoreRequested(QString)"):
                    self.restoreRequested.emit(done[0])
                else:
                    QMessageBox.information(self, "Open Restore yourself",
                                            f"Downloaded to:\n{done[0]}\n\nOpen the Restore page and choose this file.")

        def failed(exc):
            if self._closing:
                return
            self._set_busy(False)
            set_status(self.status_lbl, f"Download failed: {exc}", "bad")

        run_in_thread(self.relay, work, finished, failed)

    # -- delete --------------------------------------------------------------

    def delete_confirmation_text(self, selection):
        """(headline, details, full list) for the delete confirmation."""
        n = len(selection)
        total = sum(int(b.get("size_bytes", 0) or 0) for b in selection)
        source = self.selected_source()
        headline = f"Permanently delete {n} backup(s) from {source.get('label', 'the cloud')}?"
        names = [b.get("name", "") for b in selection]
        shown = "\n".join("  • " + nm for nm in names[:CONFIRM_NAME_LIMIT])
        if n > CONFIRM_NAME_LIMIT:
            shown += f"\n  … and {n - CONFIRM_NAME_LIMIT} more (Show Details lists every one)"
        provider = ((self.window.current_profile or {}).get("cloud_provider")
                    or (self.window.global_cloud() or {}).get("cloud_provider") or "")
        where_it_goes = ("On Box they go to the Box Trash."
                         if provider == "box" and source.get("kind") == "profile"
                         else "They are removed outright.")
        details = (f"This frees {human_size(total)} and CANNOT be undone. {where_it_goes}\n"
                   "Only the backups listed here are deleted. Your blogs and the backups on "
                   f"this computer are not touched.\n\n{shown}")
        return headline, details, "\n".join(names)

    def _on_delete(self):
        sel = list(self.selected_backups())
        if not sel or self._busy:
            return
        client = self._current_client()
        if not client:
            QMessageBox.critical(self, "No cloud", "No cloud source is available to delete from.")
            return
        headline, details, full = self.delete_confirmation_text(sel)
        box = QMessageBox(self)
        box.setIcon(QMessageBox.Warning)
        box.setWindowTitle("Delete backups — cannot be undone")
        box.setText(headline)
        box.setInformativeText(details)
        if len(sel) > CONFIRM_NAME_LIMIT:
            box.setDetailedText(full)
        confirm = box.addButton(f"Delete {len(sel)} backup(s)", QMessageBox.DestructiveRole)
        keep = box.addButton("Keep them", QMessageBox.RejectRole)
        box.setDefaultButton(keep); box.setEscapeButton(keep)
        box._suyb_confirm = confirm
        box.exec()
        if box.clickedButton() is not confirm:
            return

        self._set_busy(True)
        count = len(sel)
        self._progress(0, f"Deleting {count} backup(s)…")

        def work():
            deleted, errors = 0, []
            for i, b in enumerate(sel):
                name = b.get("name", "")
                self.relay.call.emit(lambda i=i, n=name: self._progress(
                    i * 100 / count, f"Deleting {i + 1} of {count}: {n}"))
                try:
                    client.delete_file(b["id"], name)
                    deleted += 1
                except Exception as exc:
                    errors.append(f"{name or '?'}: {exc}")
            return deleted, errors

        def finished(result):
            if self._closing:
                return
            deleted, errors = result
            self._set_busy(False)
            if errors:
                QMessageBox.warning(self, "Delete finished with errors",
                                    f"Deleted {deleted} of {count}.\n\n" + "\n".join(errors[:10]))
            self.refresh()
            set_status(self.status_lbl, f"Deleted {deleted} of {count} backup(s). Reloading the list…",
                       "bad" if errors else "good")

        def failed(exc):
            if self._closing:
                return
            self._set_busy(False)
            set_status(self.status_lbl, f"Delete stopped: {exc}", "bad")
            self.refresh()

        run_in_thread(self.relay, work, finished, failed)


# ---------------------------------------------------------------------------
# Cloud browser dialog (Restore page: "Browse cloud…")
# ---------------------------------------------------------------------------

class CloudBrowserDialog(QDialog):
    """Pick one backup ZIP from the backup cloud (Google Drive / Box).

    CloudBrowserDialog(parent, cloud_cfg, profile=None, backups=None)
        cloud_cfg  dict from window.global_cloud() (cloud_provider,
                   cloud_credentials_file, cloud_folder_id).
        profile    the current site profile; its own cloud settings win over
                   cloud_cfg, exactly as cloud_client.get_cloud_client does.
        backups    optional pre-loaded list [{id, name, size_bytes, date}];
                   when None the dialog loads the list itself on a worker
                   thread the first time it is shown.

    Use:
        dlg = CloudBrowserDialog(self, window.global_cloud(), window.current_profile)
        if dlg.exec() == QDialog.Accepted:
            file_id, file_name = dlg.result

    `result` is (file_id, file_name) — the two values the Tk dialog passed to
    its callback — or None when cancelled. NOTE: this instance attribute hides
    QDialog.result(); use the exec() return code for accepted/rejected.
    Double-clicking a row is the same as Select.
    """

    def __init__(self, parent, cloud_cfg, profile=None, backups=None):
        super().__init__(parent)
        self.setWindowTitle("Cloud backups")
        self.setModal(True)
        self.resize(760, 480); self.setMinimumSize(520, 320)
        self.result = None
        self._cloud_cfg = cloud_cfg or {}
        self._profile = profile or {}
        self._backups = []
        self._need_load = backups is None
        self._loading = False
        self.relay = Relay(self)

        col = QVBoxLayout(self); col.setContentsMargins(16, 14, 16, 14); col.setSpacing(10)
        col.addWidget(label("Select a backup package to restore:", "CardTitle"))
        self.status_lbl = label("", "Muted")
        col.addWidget(self.status_lbl)
        self.tree = QTreeWidget()
        self.tree.setHeaderLabels(["File", "Size", "Date"])
        self.tree.setRootIsDecorated(False)
        self.tree.setSelectionMode(QAbstractItemView.SingleSelection)
        self.tree.header().setSectionResizeMode(0, QHeaderView.Stretch)
        self.tree.header().setStretchLastSection(False)
        self.tree.itemSelectionChanged.connect(self._sel_changed)
        self.tree.itemDoubleClicked.connect(lambda *_: self._select())
        col.addWidget(self.tree, 1)
        row = QHBoxLayout(); row.addStretch(1)
        self.select_btn = QPushButton("Select"); self.select_btn.setObjectName("Primary")
        self.select_btn.clicked.connect(self._select)
        cancel = QPushButton("Cancel"); cancel.clicked.connect(self.reject)
        row.addWidget(self.select_btn); row.addWidget(cancel)
        col.addLayout(row)
        if backups is not None:
            self._fill(backups)
        self._sel_changed()

    def showEvent(self, event):
        super().showEvent(event)
        if self._need_load and not self._loading:
            self._need_load = False
            self.load()

    def load(self):
        client = make_client({"kind": "profile"}, self._profile, self._cloud_cfg)
        if not client:
            self.status_lbl.setText("No cloud storage is set up for this site or in Settings.")
            return
        self._loading = True
        _LOADING_DIALOGS.add(self)
        self.status_lbl.setText("Loading backups from the cloud…")
        run_in_thread(self.relay, lambda: list_backups(client), self._loaded, self._failed)

    def _loaded(self, items):
        self._loading = False
        _LOADING_DIALOGS.discard(self)
        self._fill(items)

    def _failed(self, exc):
        self._loading = False
        _LOADING_DIALOGS.discard(self)
        self.status_lbl.setText(f"Could not read the cloud: {exc}")

    def _fill(self, backups):
        self._backups = sort_backups(list(backups), SORT_NEWEST)
        self.tree.clear()
        for b in self._backups:
            item = QTreeWidgetItem([b.get("name", ""), f"{int(b.get('size_bytes', 0) or 0) / 1048576:.1f} MB",
                                    (b.get("date") or "")[:10] or date10(b)])
            item.setToolTip(0, b.get("name", ""))
            _tall(item, 3)
            self.tree.addTopLevelItem(item)
        self.status_lbl.setText(f"{len(self._backups)} backup(s) found." if self._backups
                                else "No backup ZIPs found in the cloud folder.")
        self._sel_changed()

    def _sel_changed(self):
        self.select_btn.setEnabled(bool(self.tree.selectedItems()))

    def _select(self):
        items = self.tree.selectedItems()
        if not items:
            return
        b = self._backups[self.tree.indexOfTopLevelItem(items[0])]
        self.result = (str(b["id"]), b.get("name", ""))
        self.accept()


# ---------------------------------------------------------------------------
# Help (ported from the Tk HELP_TOPICS "Manage tab" entry, updated for Qt)
# ---------------------------------------------------------------------------

HELP_TOPICS = [
    ("Manage (cloud backup manager)", """
Manage shows the backup ZIP files kept in your cloud folder. It shows every backup across every blog that shares that folder — not just the blog you have selected — so it is the one place to see and tidy all your cloud backups.

Opening the page loads the list from the cloud. Press Refresh list any time to pull a fresh list.

Source: the dropdown at the top chooses where to look. "Backup cloud (Google Drive / Box)" is where SUYB writes your backup ZIPs. If you also run a Cloud Sync job that copies backups into a Backblaze bucket, each such bucket appears here too (labelled "Backblaze — <bucket>"), so you can view, tidy and free space in Backblaze from the same screen. Backblaze logins are read from your Cloud Sync jobs — if the credential vault is locked, unlock SUYB first or the Backblaze buckets will not be listed.

Each row shows the Blog the backup belongs to, the File name, its Date, and its Size.

Finding a backup:
— Search: type any part of a blog name or file name to narrow the list as you type.
— Date from / to: type dates as YYYY-MM-DD (for example 2026-08-01) and press Apply dates (or Enter) to show only backups in that range. Leave one side blank for an open-ended range. A date that is not real is refused with a message.
— Clear filters: empties the search and date boxes and puts the sort back to Newest first.

Sorting (the "Sort by" dropdown, or click a column heading):
— Newest first / Oldest first — by date.
— Blog name (A→Z) — alphabetical by blog.
— Group by blog, newest first — the backups are grouped under each blog, and each blog heading shows how many backups it has and how much cloud space they use together.
— File name (A→Z) — alphabetical by file name.
— Largest first / Smallest first — by size, handy for finding what is eating your cloud space.

Ticking: click anywhere on a row to tick or untick it. "Tick every backup shown" ticks everything the current search and dates show. The line under the table shows how many backups are on screen and their total size (and, when a filter is on, how many there are in total), and how many you have ticked.

Restore selected: tick ONE backup and press Restore selected. For a Google Drive / Box backup this opens the Restore page with that backup already chosen; just press START RESTORE there. For a Backblaze backup SUYB first asks where to save the ZIP on your computer, downloads it, and then loads it into Restore as a local file — because Restore reads Google Drive/Box or a local ZIP, not Backblaze directly. Restoring never deletes anything.

Download selected: tick one or more backups, press Download selected and pick a folder. Each backup is saved there under its own name. If a file with that name is already there you are asked before it is replaced. A download that fails part-way leaves no half-finished ZIP behind.

Delete selected: tick one or more backups and press Delete selected. A confirmation box says exactly how many will go, how much space it frees, and lists them by name. Nothing is deleted unless you press the red "Delete N backup(s)" button; "Keep them" (or Esc, or closing the box) cancels. This permanently removes those ZIP files from the cloud and cannot be undone. It only ever deletes the backups you ticked — your blogs and your local backups are untouched. (On Google Drive and Backblaze the files are removed outright; on Box they go to the Box Trash.)
"""),
    ("Browse cloud (choosing a backup to restore)", """
Browse cloud on the Restore page opens a list of the backup ZIPs in your backup cloud (Google Drive or Box), newest first, with each one's size and date. Click one and press Select (or double-click it). Cancel closes the list without changing anything.
"""),
]

# ===== SNAPSMACK EOF =====
