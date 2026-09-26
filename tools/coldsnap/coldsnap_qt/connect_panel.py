"""COLD SNAP Qt — the connection header.

One line: pick your site, see a green light. The raw fields (URL, keys) live
behind a "Connection details" expander instead of greeting every launch with
four credential boxes. Reuses config.py + profile_manager.py untouched.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

import concurrent.futures
import threading

from PySide6.QtCore import QObject, Signal
from PySide6.QtWidgets import (
    QWidget, QVBoxLayout, QHBoxLayout, QLabel, QComboBox, QPushButton,
    QLineEdit, QMessageBox,
)

import config as cfg_module
import profile_manager
import snap_library
from sumna_post import SumnaConnection

from . import theme
from .widgets import hint, field_label


class _ModeProbeBridge(QObject):
    finished = Signal(dict)


class ConnectPanel(QWidget):
    """Owns the app-level connection config dict the modes read."""

    def __init__(self, parent=None):
        super().__init__(parent)
        self.config = cfg_module.load()
        self._suite_mode = None
        self._mode_label = ""
        self._selected_by_mode = {}
        self._discovered_modes = {}
        self._probing_modes = True
        self._mode_bridge = _ModeProbeBridge(self)
        self._mode_bridge.finished.connect(self._apply_discovered_modes)
        self.setObjectName("AppHeader")

        col = QVBoxLayout(self)
        col.setContentsMargins(18, 9, 14, 9)
        col.setSpacing(5)

        top = QHBoxLayout()
        site_label = QLabel("SITE")
        site_label.setObjectName("ChromeLabel")
        top.addWidget(site_label)
        self.profile_combo = QComboBox()
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
        col.addLayout(top)

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
        col.addWidget(self.details)

        self._startup_profile = self._match_profile(self.config.get("url", ""))
        self._rebuild_profile_combo()
        self._reflect_status()
        self._start_mode_discovery()

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

    @staticmethod
    def _profile_mode(profile: dict) -> str:
        """Return the install capability recorded by the shared profile."""
        return str((profile or {}).get("site_mode", "")).strip().lower()

    def set_suite_mode(self, suite_mode, label=""):
        """Show only sites that can accept the selected posting mode.

        ``None`` is used by COLD STORAGE, which can work with every site.
        """
        current = self.profile_combo.currentData()
        if self._suite_mode and current:
            self._selected_by_mode[self._suite_mode] = current
        self._suite_mode = suite_mode
        self._mode_label = label
        self._rebuild_profile_combo()

    def _compatible_profile_names(self):
        names = []
        for name in profile_manager.list_profiles():
            profile = profile_manager.load_profile(name) or {}
            mode = self._discovered_modes.get(name) or self._profile_mode(profile)
            if not mode and profile.get("url"):
                mode = str(snap_library.site_mode(profile["url"]) or "").strip().lower()
            if self._suite_mode is None or mode == self._suite_mode:
                names.append(name)
        return names

    def _start_mode_discovery(self):
        """Verify every saved destination without blocking the Qt interface."""
        profiles = []
        for name in profile_manager.list_profiles():
            profile = profile_manager.load_profile(name) or {}
            if profile.get("url") and profile.get("api_key"):
                profiles.append((name, profile.get("url"), profile.get("api_key")))

        def run():
            def probe(row):
                name, url, key = row
                try:
                    mode, _reachable, _note = SumnaConnection(url, key).probe_site_mode(
                        timeout=6)
                except Exception:  # one offline site must not break the picker
                    mode = ""
                return name, mode

            found = {}
            with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:
                for name, mode in pool.map(probe, profiles):
                    if mode in ("photoblog", "carousel", "smacktalk"):
                        found[name] = mode
            self._mode_bridge.finished.emit(found)

        threading.Thread(target=run, daemon=True, name="coldsnap-site-modes").start()

    def _apply_discovered_modes(self, modes):
        self._discovered_modes.update(dict(modes or {}))
        self._probing_modes = False
        self._rebuild_profile_combo()

    def _rebuild_profile_combo(self):
        names = self._compatible_profile_names()
        configured = self._match_profile(self.config.get("url", "")) or self._startup_profile
        preferred = self._selected_by_mode.get(self._suite_mode) or configured
        if preferred not in names:
            preferred = names[0] if names else ""

        self.profile_combo.blockSignals(True)
        self.profile_combo.clear()
        empty_text = "— pick a saved site —"
        if self._suite_mode is not None and not names:
            empty_text = ("— checking compatible saved sites… —" if self._probing_modes
                          else f"— no {self._mode_label or 'compatible'} sites saved —")
        self.profile_combo.addItem(empty_text, "")
        for name in names:
            self.profile_combo.addItem(name, name)
        self.profile_combo.setCurrentIndex(
            self.profile_combo.findData(preferred) if preferred else 0)
        self.profile_combo.blockSignals(False)

        if preferred:
            self._on_profile(self.profile_combo.currentIndex())
        else:
            # Do not leave an incompatible destination active merely because
            # it was selected on the previous tab.
            self.config["url"] = ""
            self.config["api_key"] = ""
            self.config["smackpress_key"] = ""
            self.url_edit.clear()
            self.key_edit.clear()
            self.press_edit.clear()
            self._reflect_status()

    def _on_profile(self, idx: int):
        name = self.profile_combo.itemData(idx)
        if not name:
            return
        prof = profile_manager.load_profile(name)
        if not prof:
            self.status_lbl.setText("Profile not found.")
            self.status_lbl.setStyleSheet(f"color: {theme.DANGER};")
            return
        self.url_edit.setText(prof.get("url", ""))
        self.key_edit.setText(prof.get("api_key", ""))
        self.press_edit.setText(prof.get("smackpress_key", ""))
        # Picking a site IS the intent — save immediately, no second APPLY step.
        self._save(silent=True)

    def select_site(self, url: str) -> bool:
        """Select a saved profile by URL for a sibling-app handoff."""
        name = self._match_profile(url)
        idx = self.profile_combo.findData(name) if name else -1
        if idx < 0:
            return False
        self.profile_combo.setCurrentIndex(idx)
        return True

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
