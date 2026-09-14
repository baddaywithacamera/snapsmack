"""Small, public-only SNAP SLAPPER publishing dialog for SMACKTHEMUP."""

import hashlib
import os
import threading
from urllib.parse import quote

from PySide6.QtCore import QObject, QUrl, Signal
from PySide6.QtGui import QDesktopServices
from PySide6.QtWidgets import (
    QApplication, QComboBox, QDialog, QFormLayout, QHBoxLayout, QLabel,
    QLineEdit, QMessageBox, QPushButton, QTextEdit, QVBoxLayout,
)

import snap_creds
from . import smackthemup_publish


class _Signals(QObject):
    finished = Signal(object)
    failed = Signal(str)


class SmackPublishDialog(QDialog):
    def __init__(self, parent, profile, jpeg_path, manifest):
        super().__init__(parent)
        self.profile = profile
        self.jpeg_path = jpeg_path
        self.manifest = manifest
        self.result_url = ""
        self.signals = _Signals()
        self.signals.finished.connect(self._finished)
        self.signals.failed.connect(self._failed)
        self.setWindowTitle("Publish to SMACKTHEMUP")
        self.resize(620, 500)

        outer = QVBoxLayout(self)
        heading = QLabel("PUBLISH ONE PUBLIC PHOTOGRAPH")
        heading.setObjectName("SectionTitle")
        outer.addWidget(heading)
        site = profile.get("name") or profile.get("site_url")
        note = QLabel(
            f"Destination: {site}\nSMACKTHEMUP is public-only and does not federate.")
        note.setWordWrap(True)
        outer.addWidget(note)

        form = QFormLayout()
        stem = os.path.splitext(manifest.get("source_name") or "Photograph")[0]
        self.title = QLineEdit(stem.replace("_", " "))
        self.caption = QTextEdit(); self.caption.setFixedHeight(90)
        self.alt = QLineEdit()
        self.tags = QLineEdit()
        self.album = QComboBox(); self.album.addItem("No album", 0)
        self.category = QComboBox(); self.category.addItem("No category", 0)
        form.addRow("Title", self.title)
        form.addRow("Caption", self.caption)
        form.addRow("Alt text", self.alt)
        form.addRow("Tags", self.tags)
        form.addRow("Album", self.album)
        form.addRow("Category", self.category)
        outer.addLayout(form)
        self.status = QLabel("Checking the publishing connection…")
        outer.addWidget(self.status)

        row = QHBoxLayout(); row.addStretch(1)
        self.cancel = QPushButton("CANCEL"); self.cancel.clicked.connect(self.reject)
        self.go = QPushButton("PUBLISH"); self.go.setObjectName("LayerAddBtn")
        self.go.setEnabled(False); self.go.clicked.connect(self._publish)
        row.addWidget(self.cancel); row.addWidget(self.go); outer.addLayout(row)
        self._load_capabilities()

    def _key(self):
        site = self.profile.get("site_url", "")
        return snap_creds.get_site(site, "api_key_smackthemup_publish", "")

    def _load_capabilities(self):
        def work():
            try:
                self.signals.finished.emit(("capabilities", smackthemup_publish.capabilities(
                    self.profile.get("site_url", ""), self._key())))
            except Exception as error:  # noqa: BLE001
                self.signals.failed.emit(str(error))
        threading.Thread(target=work, daemon=True).start()

    def _publish(self):
        if not self.title.text().strip():
            QMessageBox.information(self, "Title needed", "Give the photograph a title first.")
            return
        self.go.setEnabled(False); self.cancel.setEnabled(False)
        self.status.setText("Uploading and publishing…")
        album_ids = [self.album.currentData()] if self.album.currentData() else []
        category_ids = [self.category.currentData()] if self.category.currentData() else []
        seed = "|".join((self.profile.get("site_url", ""),
                         self.manifest.get("source_sha256", ""),
                         self.manifest.get("derivative_sha256", "")))
        idem = hashlib.sha256(seed.encode("utf-8")).hexdigest()
        values = dict(
            title=self.title.text().strip(), caption=self.caption.toPlainText().strip(),
            alt=self.alt.text().strip(),
            tags=[v.strip() for v in self.tags.text().split(",") if v.strip()],
            album_ids=album_ids, category_ids=category_ids,
            idempotency_key=idem)
        def work():
            try:
                result, _caps = smackthemup_publish.publish_photo(
                    self.profile.get("site_url", ""), self._key(), self.jpeg_path, **values)
                self.signals.finished.emit(("published", result))
            except Exception as error:  # noqa: BLE001
                self.signals.failed.emit(str(error))
        threading.Thread(target=work, daemon=True).start()

    def _finished(self, payload):
        kind, data = payload
        if kind == "capabilities":
            for album in data.get("albums", []):
                self.album.addItem(str(album.get("name", "Album")), int(album.get("id", 0)))
            for category in data.get("categories", []):
                self.category.addItem(str(category.get("name", "Category")), int(category.get("id", 0)))
            self.status.setText("Connected — ready to publish.")
            self.go.setEnabled(True)
            return
        self.result_url = str(data.get("url") or "")
        self.status.setText("Published. It is live on your site.")
        self.cancel.setText("DONE"); self.cancel.setEnabled(True)
        self.go.hide()
        actions = QHBoxLayout()
        view = QPushButton("VIEW"); view.clicked.connect(self._view)
        copy = QPushButton("COPY LINK"); copy.clicked.connect(self._copy)
        email = QPushButton("EMAIL"); email.clicked.connect(self._email)
        share = QPushButton("SHARE"); share.clicked.connect(self._share)
        for button in (view, copy, email, share): actions.addWidget(button)
        self.layout().insertLayout(self.layout().count() - 1, actions)

    def _failed(self, message):
        self.go.setEnabled(True); self.cancel.setEnabled(True)
        self.status.setText("Could not publish.")
        QMessageBox.warning(self, "SMACKTHEMUP could not publish", message)

    def _view(self):
        if self.result_url: QDesktopServices.openUrl(QUrl(self.result_url))

    def _copy(self):
        QApplication.clipboard().setText(self.result_url)
        self.status.setText("Link copied.")

    def _email(self):
        subject = quote(self.title.text().strip())
        body = quote(f"{self.title.text().strip()}\n{self.result_url}")
        QDesktopServices.openUrl(QUrl(f"mailto:?subject={subject}&body={body}"))

    def _share(self):
        text = quote(f"{self.title.text().strip()} {self.result_url}")
        QDesktopServices.openUrl(QUrl(f"https://bsky.app/intent/compose?text={text}"))


# ===== SNAPSMACK EOF =====
