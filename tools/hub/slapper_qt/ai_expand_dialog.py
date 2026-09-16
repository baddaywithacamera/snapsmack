"""Bounded generative canvas expansion controls."""

from PySide6.QtCore import Qt
from PySide6.QtWidgets import (
    QDialog, QGridLayout, QHBoxLayout, QLabel, QPushButton, QSlider, QVBoxLayout,
)


class AIExpandDialog(QDialog):
    """Choose the edge allowance before a generative outpaint is submitted.

    The interface and accounting enforce the provenance specification's 20%
    boundary.  Generation is deliberately not started with an empty border.
    """

    def __init__(self, host):
        super().__init__(host)
        self.host = host
        self.edges = {edge: 0 for edge in ("left", "top", "right", "bottom")}
        self.setWindowTitle("Generative " + "Expand")
        self.resize(620, 330)
        layout = QVBoxLayout(self)
        title = QLabel("EXTEND THE CANVAS")
        title.setObjectName("SectionTitle")
        layout.addWidget(title)
        note = QLabel(
            "Choose how far to continue each edge. The captured photograph remains "
            "unchanged in the centre; generated border pixels are recorded separately.")
        note.setWordWrap(True)
        layout.addWidget(note)
        grid = QGridLayout()
        self.sliders = {}
        for row, edge in enumerate(("left", "top", "right", "bottom")):
            grid.addWidget(QLabel(edge.title()), row, 0)
            slider = QSlider(Qt.Horizontal)
            slider.setRange(0, 20)
            slider.setValue(0)
            slider.valueChanged.connect(
                lambda value, key=edge: self._one_edge_changed(key, value))
            grid.addWidget(slider, row, 1)
            self.sliders[edge] = slider
        layout.addLayout(grid)
        self.measure = QLabel()
        layout.addWidget(self.measure)
        actions = QHBoxLayout()
        actions.addStretch(1)
        cancel = QPushButton("CANCEL")
        cancel.clicked.connect(self.reject)
        actions.addWidget(cancel)
        self.go = QPushButton("GENERATE BORDER")
        self.go.setObjectName("LayerAddBtn")
        self.go.clicked.connect(self._start)
        actions.addWidget(self.go)
        layout.addLayout(actions)
        self._refresh()

    def _one_edge_changed(self, edge, value):
        self.edges[edge] = max(0, min(20, int(value)))
        self._refresh()

    def _edges_changed(self, edges):
        for edge in self.edges:
            self.edges[edge] = max(0, min(20, int(edges.get(edge, 0))))
            slider = self.sliders[edge]
            slider.blockSignals(True)
            slider.setValue(self.edges[edge])
            slider.blockSignals(False)
        self._refresh()

    def _refresh(self):
        largest = max(self.edges.values())
        _original, _current, _used, remaining = self.host.generative_expand_budget()
        self.measure.setText(
            f"{largest:.1f}% of original this time · {remaining:,} generated pixels remain")
        self.go.setEnabled(any(self.edges.values()) and remaining > 0)

    def _start(self):
        # The provider transaction is kept on the host so it can atomically add
        # the generated border and its provenance record to document history.
        handler = getattr(self.host, "apply_generative_expand", None)
        if handler is None:
            return
        if handler(dict(self.edges)):
            self.accept()


# ===== SNAPSMACK EOF =====
