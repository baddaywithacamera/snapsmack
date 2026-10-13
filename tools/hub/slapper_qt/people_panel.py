"""The PEOPLE panel: find faces, name a person once, filter by them after.

Picasa's People, on this computer only. Names and fingerprints are numbers in
SNAP SLAPPER's own store; no photograph and no face ever leaves the machine,
there is no account and no key, and the photographs themselves are never
written to.

The work runs on a worker thread with a STOP that means stop. Naming is one
group at a time with large buttons, because a mis-click here attaches the
wrong name to a person's face.
"""

import os

from PySide6.QtCore import Qt, QObject, QRunnable, QThreadPool, Signal
from PySide6.QtWidgets import (
    QDockWidget, QHBoxLayout, QInputDialog, QLabel, QListWidget,
    QListWidgetItem, QMessageBox, QProgressBar, QPushButton, QVBoxLayout,
    QWidget,
)

import slapper_faces


class _ScanSignals(QObject):
    progress = Signal(int, int, str)
    finished = Signal(int, int)
    failed = Signal(str)


class _ScanJob(QRunnable):
    """Scan photographs for faces without blocking the window."""

    def __init__(self, store, paths):
        super().__init__()
        self.store = store
        self.paths = list(paths)
        self.signals = _ScanSignals()
        self._engine = None
        self._stop = False

    def stop(self):
        self._stop = True

    def run(self):
        from . import faces_component
        try:
            self._engine = faces_component.engine(allow_download=True)
            if self._engine is None:
                self.signals.failed.emit(
                    "People is not installed on this computer.")
                return

            def progress(done, total, path):
                self.signals.progress.emit(done, total, os.path.basename(path))

            def stop():
                # scan_paths swallows a per-file failure so one bad photograph
                # cannot end a scan. That would also quietly record "no faces"
                # for every remaining file if the component died, so a dead
                # component stops the scan instead of writing thousands of
                # empty results.
                healthy = getattr(self._engine, "healthy", None)
                if healthy is not None and not healthy():
                    self._died = True
                    return True
                return self._stop

            scanned, skipped = slapper_faces.scan_paths(
                self._engine, self.store, self.paths,
                progress=progress, stop=stop)
            if getattr(self, "_died", False):
                self.signals.failed.emit(
                    "The People component stopped running, so the scan was "
                    "ended. What it had already found is saved.")
                return
            self.signals.finished.emit(scanned, skipped)
        except Exception as error:  # noqa: BLE001
            self.signals.failed.emit(str(error))
        finally:
            closer = getattr(self._engine, "close", None)
            if closer:
                closer()


class PeoplePanel(QDockWidget):
    """A dock in the library: scan, name, and filter the grid by person."""

    def __init__(self, host):
        super().__init__("PEOPLE", host)
        self.host = host
        self.setObjectName("PeoplePanel")
        self.setMinimumWidth(230)
        self.store = slapper_faces.FaceStore(host.catalog.directory)
        self._job = None
        self._pool = QThreadPool(self)
        self._pool.setMaxThreadCount(1)

        panel = QWidget()
        layout = QVBoxLayout(panel)
        self.explain = QLabel()
        self.explain.setWordWrap(True)
        layout.addWidget(self.explain)
        self.install_button = QPushButton("INSTALL PEOPLE")
        self.install_button.setObjectName("LayerAddBtn")
        self.install_button.clicked.connect(self._install)
        layout.addWidget(self.install_button)

        self.scan_button = QPushButton("FIND FACES IN THIS FOLDER")
        self.scan_button.setObjectName("LayerAddBtn")
        self.scan_button.setMinimumHeight(34)
        self.scan_button.clicked.connect(self._scan)
        layout.addWidget(self.scan_button)
        self.stop_button = QPushButton("STOP")
        self.stop_button.setMinimumHeight(30)
        self.stop_button.clicked.connect(self._stop)
        self.stop_button.setVisible(False)
        layout.addWidget(self.stop_button)
        self.progress = QProgressBar()
        self.progress.setVisible(False)
        layout.addWidget(self.progress)

        people_label = QLabel("Click a name to show only their photographs")
        people_label.setObjectName("ControlName")
        people_label.setWordWrap(True)
        layout.addWidget(people_label)
        self.people = QListWidget()
        self.people.setMinimumHeight(120)
        self.people.itemClicked.connect(self._person_clicked)
        layout.addWidget(self.people, 1)
        self.show_all = QPushButton("Show every photograph")
        self.show_all.clicked.connect(lambda: self._filter_by(None))
        layout.addWidget(self.show_all)

        self.group_label = QLabel()
        self.group_label.setWordWrap(True)
        layout.addWidget(self.group_label)
        name_row = QHBoxLayout()
        self.name_button = QPushButton("NAME THIS GROUP…")
        self.name_button.setMinimumHeight(34)
        self.name_button.clicked.connect(self._name_group)
        name_row.addWidget(self.name_button)
        layout.addLayout(name_row)

        self.suggestion_label = QLabel()
        self.suggestion_label.setWordWrap(True)
        layout.addWidget(self.suggestion_label)
        confirm_row = QHBoxLayout()
        self.confirm_button = QPushButton("YES, THAT IS THEM")
        self.confirm_button.setMinimumHeight(34)
        self.confirm_button.clicked.connect(self._confirm_suggestions)
        confirm_row.addWidget(self.confirm_button)
        self.reject_button = QPushButton("NO")
        self.reject_button.setMinimumHeight(34)
        self.reject_button.clicked.connect(self._reject_suggestions)
        confirm_row.addWidget(self.reject_button)
        layout.addLayout(confirm_row)

        self.privacy = QLabel(
            "Faces and names stay on this computer. Nothing is uploaded, and "
            "your photographs are never altered.")
        self.privacy.setWordWrap(True)
        self.privacy.setObjectName("TargetLabel")
        layout.addWidget(self.privacy)
        layout.addStretch(1)
        self.setWidget(panel)
        self.refresh()

    # --- state -----------------------------------------------------------
    def state(self):
        from . import faces_component
        return faces_component.state()

    def available(self):
        return self.state() == "ready"

    def refresh(self):
        state = self.state()
        self.install_button.setVisible(state != "ready")
        self.install_button.setText(
            "INSTALL PEOPLE" if state == "component"
            else "DOWNLOAD THE FACE MODELS (37 MB)")
        self.scan_button.setEnabled(state == "ready" and bool(self.host._paths))
        self.explain.setText({
            "ready":
                "Find the faces in your photographs, name each person once, "
                "and SNAP SLAPPER will recognise them from then on.",
            "models":
                "People is ready apart from its two face models, about 37 MB, "
                "downloaded once from OpenCV's model zoo and checked against "
                "pinned checksums. Nothing else is fetched and nothing is "
                "uploaded.",
            "component":
                "People finds faces so you can name each person once. It "
                "needs a separate component — about 120 MB, downloaded once "
                "— because bundling it would more than double SNAP "
                "SLAPPER's download for everybody. It runs entirely on this "
                "computer.",
        }[state])
        self._refresh_people()
        self._refresh_group()
        self._refresh_suggestions()

    def _refresh_people(self):
        self.people.clear()
        counts = self.store.counts()
        for name in sorted(counts):
            item = QListWidgetItem(f"{name} — {counts[name]:,}")
            item.setData(Qt.UserRole, name)
            self.people.addItem(item)
        if not counts:
            self.people.addItem(QListWidgetItem("No one named yet"))

    def _refresh_group(self):
        groups = self.store.cluster_unnamed()
        self._group = groups[0] if groups else []
        if self._group:
            self.group_label.setText(
                f"{len(groups):,} group(s) of faces are not named yet. The "
                f"largest has {len(self._group):,} face(s).")
        else:
            self.group_label.setText(
                "Every face found has been named or dismissed.")
        self.name_button.setEnabled(bool(self._group))

    def _refresh_suggestions(self):
        self._suggested = self.store.pending()
        if self._suggested:
            names = sorted({(self.store.face(ref) or {}).get("suggested")
                            for ref in self._suggested} - {None})
            self.suggestion_label.setText(
                f"{len(self._suggested):,} face(s) look like "
                f"{', '.join(names)}. Is that right?")
        else:
            self.suggestion_label.setText("No faces are waiting to be checked.")
        for button in (self.confirm_button, self.reject_button):
            button.setEnabled(bool(self._suggested))

    # --- actions ---------------------------------------------------------
    def _install(self):
        from . import faces_component
        if self.state() == "models":
            answer = QMessageBox.question(
                self, "Download the face models?",
                "This downloads two model files, about 37 MB in total, once, "
                "from OpenCV's model zoo. Each is checked against a pinned "
                "checksum and refused if it does not match. Nothing is "
                "uploaded. Continue?",
                QMessageBox.Yes | QMessageBox.Cancel, QMessageBox.Cancel)
            if answer != QMessageBox.Yes:
                return
            try:
                slapper_faces.ensure_models()
            except Exception as error:  # noqa: BLE001
                QMessageBox.warning(self, "The models could not be fetched",
                                    str(error))
            self.refresh()
            return
        answer = QMessageBox.question(
            self, "Install People?",
            "This installs a separate component of about 120 MB: OpenCV and "
            "two small face models. It is downloaded once and runs entirely "
            "on this computer — no account, no key, nothing uploaded." +
            chr(10) * 2 +
            "It can be removed without removing SNAP SLAPPER. Continue?",
            QMessageBox.Yes | QMessageBox.Cancel, QMessageBox.Cancel)
        if answer != QMessageBox.Yes:
            return
        try:
            faces_component.start_installer()
        except Exception as error:  # noqa: BLE001
            QMessageBox.warning(self, "The installer could not start", str(error))
            return
        QMessageBox.information(
            self, "Installer started",
            "Finish in the separate installer window, then close and reopen "
            "the library.")

    def _scan(self):
        paths = self.host._paths
        if not paths:
            QMessageBox.information(
                self, "Find faces", "Open a folder of photographs first.")
            return
        self._job = _ScanJob(self.store, paths)
        self._job.signals.progress.connect(self._scan_progress)
        self._job.signals.finished.connect(self._scan_finished)
        self._job.signals.failed.connect(self._scan_failed)
        self.scan_button.setEnabled(False)
        self.stop_button.setVisible(True)
        self.progress.setVisible(True)
        self.progress.setRange(0, len(paths))
        self.progress.setValue(0)
        self._pool.start(self._job)

    def _stop(self):
        if self._job:
            self._job.stop()
        self.stop_button.setEnabled(False)
        self.stop_button.setText("Stopping…")

    def _scan_progress(self, done, total, name):
        self.progress.setValue(done)
        self.host.status.showMessage(f"Faces: {done:,} of {total:,} — {name}")

    def _reset_scan_controls(self):
        self._job = None
        self.scan_button.setEnabled(True)
        self.stop_button.setVisible(False)
        self.stop_button.setEnabled(True)
        self.stop_button.setText("STOP")
        self.progress.setVisible(False)

    def _scan_finished(self, scanned, skipped):
        self._reset_scan_controls()
        faces = sum(len(row) for row in
                    (entry.get("faces", []) for entry in self.store.photos.values()))
        already = f", {skipped:,} already done" if skipped else ""
        self.host.status.showMessage(
            f"Looked at {scanned:,} photograph(s){already}. {faces:,} face(s) "
            "known in this library. Your photographs were not changed.", 12000)
        self.refresh()

    def _scan_failed(self, message):
        self._reset_scan_controls()
        QMessageBox.warning(self, "Finding faces stopped", message)
        self.refresh()

    def _name_group(self):
        if not self._group:
            return
        name, accepted = QInputDialog.getText(
            self, "Who is this?",
            f"These {len(self._group):,} face(s) look like the same person.\n"
            "What is their name?")
        if not accepted or not name.strip():
            return
        self.store.name_faces(self._group, name.strip())
        self.store.suggest_all()
        self.store.save()
        self.refresh()

    def _confirm_suggestions(self):
        for ref in list(self._suggested):
            self.store.confirm(ref)
        self.store.save()
        self.refresh()

    def _reject_suggestions(self):
        for ref in list(self._suggested):
            self.store.reject(ref)
        self.store.save()
        self.refresh()

    def _person_clicked(self, item):
        name = item.data(Qt.UserRole)
        if name:
            self._filter_by(name)

    def _filter_by(self, name):
        self.host.set_people_filter(name)


# ===== SNAPSMACK EOF =====
