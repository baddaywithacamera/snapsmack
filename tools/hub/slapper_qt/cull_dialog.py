"""PICK THE KEEPERS: run the burst measurement and act on what it nominates.

Every outcome is reversible and none of them touches a file: the nominations
are selected in the grid, or marked favourite, or given a rating. SNAP SLAPPER
does not delete, move or rename a photograph on the strength of a measurement.
"""

import os

from PySide6.QtCore import QObject, QRunnable, QThreadPool, Signal
from PySide6.QtWidgets import (
    QComboBox, QDialog, QDoubleSpinBox, QHBoxLayout, QLabel, QProgressBar,
    QPushButton, QVBoxLayout,
)

from . import cull

OUTCOMES = (
    ("select", "Select them in the grid — change nothing else"),
    ("favorite", "Mark each keeper ♥ Favorite"),
    ("rate", "Give each keeper ★★★★★"),
)


class _Signals(QObject):
    progress = Signal(int, int, str)
    finished = Signal(object)
    failed = Signal(str)


class _PickJob(QRunnable):
    def __init__(self, paths, store, seconds):
        super().__init__()
        self.paths = list(paths)
        self.store = store
        self.seconds = float(seconds)
        self.signals = _Signals()
        self._stop = False

    def stop(self):
        self._stop = True

    def run(self):
        try:
            def progress(done, total, path):
                self.signals.progress.emit(done, total, os.path.basename(path))
                return not self._stop

            picks = cull.pick_bursts(self.paths, store=self.store,
                                     seconds=self.seconds, progress=progress)
            self.signals.finished.emit(picks)
        except Exception as error:  # noqa: BLE001
            self.signals.failed.emit(str(error))


class CullDialog(QDialog):
    def __init__(self, host, paths, store=None):
        super().__init__(host)
        self.host = host
        self.paths = list(paths)
        self.store = store
        self.picks = []
        self._job = None
        self._pool = QThreadPool(self)
        self._pool.setMaxThreadCount(1)
        self.setWindowTitle("Pick the Keepers")
        self.resize(560, 320)
        layout = QVBoxLayout(self)
        heading = QLabel("PICK THE KEEPERS")
        heading.setObjectName("SectionTitle")
        layout.addWidget(heading)
        explain = QLabel(
            "Frames taken within a few seconds of each other are treated as "
            "one burst. SNAP SLAPPER measures each frame and nominates the "
            "sharpest usable one per burst. It cannot tell you which "
            "photograph is best — that is yours — only which of these is "
            "sharpest and which are badly exposed."
            + ("\nFaces are known here, so sharpness is measured on the face."
               if store is not None and getattr(store, "photos", None)
               else "\nInstall PEOPLE and find faces first to have sharpness "
                    "measured on the face instead of the whole frame."))
        explain.setWordWrap(True)
        self.explain = explain
        layout.addWidget(explain)
        row = QHBoxLayout()
        row.addWidget(QLabel("One burst = frames within"))
        self.seconds = QDoubleSpinBox()
        self.seconds.setRange(0.5, 60.0)
        self.seconds.setSingleStep(0.5)
        self.seconds.setValue(cull.BURST_SECONDS)
        self.seconds.setSuffix(" seconds")
        row.addWidget(self.seconds)
        row.addStretch(1)
        layout.addLayout(row)
        outcome_row = QHBoxLayout()
        outcome_row.addWidget(QLabel("With the keepers"))
        self.outcome = QComboBox()
        for key, label in OUTCOMES:
            self.outcome.addItem(label, key)
        outcome_row.addWidget(self.outcome, 1)
        layout.addLayout(outcome_row)
        self.progress = QProgressBar()
        self.progress.setVisible(False)
        layout.addWidget(self.progress)
        self.result = QLabel()
        self.result.setWordWrap(True)
        layout.addWidget(self.result)
        actions = QHBoxLayout()
        actions.addStretch(1)
        self.close_button = QPushButton("CLOSE")
        self.close_button.clicked.connect(self.reject)
        actions.addWidget(self.close_button)
        self.stop_button = QPushButton("STOP")
        self.stop_button.setVisible(False)
        self.stop_button.clicked.connect(self._stop)
        actions.addWidget(self.stop_button)
        self.go = QPushButton("MEASURE AND PICK")
        self.go.setObjectName("LayerAddBtn")
        self.go.setMinimumHeight(32)
        self.go.clicked.connect(self._run)
        actions.addWidget(self.go)
        layout.addLayout(actions)
        self.go.setEnabled(bool(self.paths))

    def _stop(self):
        if self._job:
            self._job.stop()
        self.stop_button.setEnabled(False)

    def _run(self):
        self._job = _PickJob(self.paths, self.store, self.seconds.value())
        self._job.signals.progress.connect(self._progress)
        self._job.signals.finished.connect(self._finished)
        self._job.signals.failed.connect(self._failed)
        self.go.setEnabled(False)
        self.stop_button.setVisible(True)
        self.stop_button.setEnabled(True)
        self.progress.setVisible(True)
        self.progress.setRange(0, len(self.paths))
        self.result.setText("Measuring…")
        self._pool.start(self._job)

    def _progress(self, done, total, name):
        self.progress.setValue(done)
        self.result.setText(f"Measuring {done:,} of {total:,} — {name}")

    def _failed(self, message):
        self._job = None
        self.go.setEnabled(True)
        self.stop_button.setVisible(False)
        self.progress.setVisible(False)
        self.result.setText("Could not finish: " + message)

    def _finished(self, picks):
        self._job = None
        self.picks = picks
        self.go.setEnabled(True)
        self.stop_button.setVisible(False)
        self.progress.setVisible(False)
        keepers = [row["picked"] for row in picks]
        bursts = [row for row in picks if row["others"]]
        rest = sum(len(row["others"]) for row in picks)
        if not keepers:
            self.result.setText("No photographs could be measured.")
            return
        outcome = self.outcome.currentData()
        self.host.apply_cull_picks(keepers, outcome)
        did = {"select": "selected in the grid",
               "favorite": "marked ♥ Favorite",
               "rate": "rated ★★★★★"}[outcome]
        example = picks[0]["reason"] if picks else ""
        self.result.setText(
            f"{len(picks):,} burst(s) — {len(bursts):,} with more than one "
            f"frame. {len(keepers):,} keeper(s) {did}; {rest:,} other frame(s) "
            f"left alone.\n\nFirst nomination: {example}.\n\nNothing was "
            "deleted, moved or renamed.")


# ===== SNAPSMACK EOF =====
