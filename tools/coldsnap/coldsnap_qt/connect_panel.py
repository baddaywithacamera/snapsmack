"""COLD SNAP Qt — the connection header.

One line: pick your site, see a green light. The raw fields (URL, keys) live
behind a "Connection details" expander instead of greeting every launch with
four credential boxes. Reuses config.py + profile_manager.py untouched.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

from PySide6.QtWidgets import (
    QWidget, QVBoxLayout, QHBoxLayout, QLabel, QComboBox, QPushButton,
    QLineEdit, QMessageBox,
)

import config as cfg_module
import profile_manager

from . import theme
from .widgets import Card, hint, field_label


class ConnectPanel(QWidget):
    """Owns the app-level connection config dict the modes read."""

    def __init__(self, parent=None):
        super().__init__(parent)
        self.config = cfg_module.load()

        col = QVBoxLayout(self)
        col.setContentsMargins(0, 0, 0, 0)
        card = Card("SITE")
        col.addWidget(card)

        top = QHBoxLayout()
        self.profile_combo = QComboBox()
        self.profile_combo.addItem("— pick a saved site —", "")
        for name in profile_manager.list_profiles():
            self.profile_combo.addItem(name, name)
        # The combo must TELL THE TRUTH about which site is loaded: showing
        # "pick a saved site" next to "connected to X" was two contradicting
        # statements in one header. Select the profile the saved URL belongs to.
        current = self._match_profile(self.config.get("url", ""))
        if current:
            self.profile_combo.blockSignals(True)
            self.profile_combo.setCurrentIndex(
                max(0, self.profile_combo.findData(current)))
            self.profile_combo.blockSignals(False)
        self.profile_combo.currentIndexChanged.connect(self._on_profile)
        top.addWidget(self.profile_combo, 1)

        self.status_lbl = QLabel("")
        self.status_lbl.setObjectName("Hint")
        top.addWidget(self.status_lbl, 2)

        self.details_btn = QPushButton("Connection details ▾")
        self.details_btn.setObjectName("Quiet")
        self.details_btn.setCheckable(True)
        self.details_btn.toggled.connect(self._toggle_details)
        top.addWidget(self.details_btn)

        help_btn = QPushButton("?  Help")
        help_btn.setObjectName("Quiet")
        help_btn.setToolTip("Open the help (F1)")
        help_btn.clicked.connect(self._show_help)
        top.addWidget(help_btn)
        card.body.addLayout(top)

        # -- the expander ------------------------------------------------------
        self.details = QWidget()
        d = QVBoxLayout(self.details)
        d.setContentsMargins(0, 6, 0, 0)
        d.addWidget(field_label("Site URL"))
        self.url_edit = QLineEdit(self.config.get("url", ""))
        d.addWidget(self.url_edit)
        d.addWidget(field_label("API key"))
        self.key_edit = QLineEdit(self.config.get("api_key", ""))
        self.key_edit.setEchoMode(QLineEdit.Password)
        d.addWidget(self.key_edit)
        d.addWidget(field_label("Long-form key (only for essay sites)"))
        self.press_edit = QLineEdit(self.config.get("smackpress_key", ""))
        self.press_edit.setEchoMode(QLineEdit.Password)
        d.addWidget(self.press_edit)
        d.addWidget(hint("The long-form key is a separate 'smackpress' key from "
                         "SnapSmack Admin → API Access. Photo sites don't need it."))
        save_row = QHBoxLayout()
        save_row.addStretch(1)
        save_btn = QPushButton("Save connection")
        save_btn.clicked.connect(self._save)
        save_row.addWidget(save_btn)
        d.addLayout(save_row)
        self.details.setVisible(False)
        card.body.addWidget(self.details)

        self._reflect_status()

    # -- behaviour ------------------------------------------------------------
    def _show_help(self):
        from .help_dialog import HelpDialog
        HelpDialog(self).exec()

    @staticmethod
    def _norm_url(url: str) -> str:
        return (url or "").strip().lower().replace("https://", "") \
                                          .replace("http://", "").rstrip("/")

    def _match_profile(self, url: str):
        """Name of the saved profile whose URL matches, or None."""
        want = self._norm_url(url)
        if not want:
            return None
        for name in profile_manager.list_profiles():
            prof = profile_manager.load_profile(name) or {}
            if self._norm_url(prof.get("url", "")) == want:
                return name
        return None

    def _toggle_details(self, on: bool):
        self.details.setVisible(on)
        self.details_btn.setText("Connection details ▴" if on else "Connection details ▾")

    def _on_profile(self, idx: int):
        name = self.profile_combo.itemData(idx)
        if not name:
            return
        prof = profile_manager.load_profile(name)
        if not prof:
            self.status_lbl.setText("Profile not found.")
            self.status_lbl.setStyleSheet(f"color: {theme.DANGER};")
            return
        if prof.get("url"):
            self.url_edit.setText(prof.get("url", ""))
        if prof.get("api_key"):
            self.key_edit.setText(prof.get("api_key", ""))
        if prof.get("smackpress_key"):
            self.press_edit.setText(prof.get("smackpress_key", ""))
        # Picking a site IS the intent — save immediately, no second APPLY step.
        self._save(silent=True)

    def _save(self, _=False, silent: bool = False):
        url = self.url_edit.text().strip()
        key = self.key_edit.text().strip()
        if not url or not key:
            if not silent:
                QMessageBox.warning(self, "Missing details",
                                    "The site needs both a URL and an API key.")
            self._reflect_status()
            return
        self.config["url"] = url
        self.config["api_key"] = key
        self.config["smackpress_key"] = self.press_edit.text().strip()
        try:
            cfg_module.save(self.config)
        except Exception as e:  # noqa: BLE001
            self.status_lbl.setText(f"Save failed: {e}")
            self.status_lbl.setStyleSheet(f"color: {theme.DANGER};")
            return
        self._reflect_status()

    def _reflect_status(self):
        url = (self.config.get("url") or self.url_edit.text() or "").strip()
        key = (self.config.get("api_key") or "").strip()
        if url and key:
            # "will post to", not "connected to" — nothing has been verified
            # over the network yet, and this app is honest about state.
            shown = url.replace("https://", "").replace("http://", "").rstrip("/")
            self.status_lbl.setText(f"● will post to {shown}")
            self.status_lbl.setStyleSheet(f"color: {theme.OK};")
        else:
            self.status_lbl.setText("○ no site yet — pick one, or open Connection details")
            self.status_lbl.setStyleSheet(f"color: {theme.WARN};")

# ===== SNAPSMACK EOF =====
