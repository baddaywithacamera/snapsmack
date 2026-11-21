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

from PySide6.QtCore import QObject, QTimer, Signal
from PySide6.QtWidgets import (
    QWidget, QVBoxLayout, QHBoxLayout, QLabel, QComboBox, QPushButton,
    QLineEdit, QMessageBox,
)

import config as cfg_module
import profile_manager

from . import theme
from .widgets import hint, field_label


class _ModeProbeBridge(QObject):
    finished = Signal(dict, list)


class _SitesLoadBridge(QObject):
    loaded = Signal(dict)
    failed = Signal(str)


def verify_site_mode(parent, conn, expected_mode: str, mode_label: str) -> bool:
    """Re-verify the live site mode before sending; refuse if unknown or mismatched."""
    try:
        mode, reachable, note = conn.probe_site_mode(timeout=6)
    except Exception as exc:  # noqa: BLE001
        mode, reachable, note = "", False, str(exc)
    mode = str(mode or "").strip().lower()
    if mode not in ("photoblog", "carousel", "smacktalk"):
        detail = f" ({note})" if note else ""
        QMessageBox.warning(
            parent,
            "Site mode unknown",
            f"COLD SNAP could not confirm this site's mode{detail}.\n\n"
            "Nothing was uploaded. Check the site connection and retry.",
        )
        return False
    if mode != expected_mode:
        QMessageBox.warning(
            parent,
            "Wrong site mode",
            f"This site reports mode '{mode}', which does not match {mode_label} "
            f"('{expected_mode}').\n\nNothing was uploaded.",
        )
        return False
    return True


class ConnectPanel(QWidget):
    """Owns the app-level connection config dict the modes read."""

    sitesLoaded = Signal()
    sitesLoadFailed = Signal(str)

    def __init__(self, parent=None):
        super().__init__(parent)
        self.config = {}
        self._suite_mode = None
        self._mode_label = ""
        self._selected_by_mode = {}
        self._discovered_modes = {}
        self._pending_probe_names = set()
        self._failed_probe_names = set()
        self._profile_cache = {}   # name -> {"url": ..., "api_key": ..., "mode": ...}
        self._profiles_loaded = False
        self._load_error = ""
        self._startup_profile = None
        self._probing_modes = False
        self._mode_bridge = _ModeProbeBridge(self)
        self._mode_bridge.finished.connect(self._apply_discovered_modes)
        self._sites_bridge = _SitesLoadBridge(self)
        self._sites_bridge.loaded.connect(self._apply_loaded_sites)
        self._sites_bridge.failed.connect(self._apply_load_failed)
        self.setObjectName("AppHeader")

        col = QVBoxLayout(self)
        col.setContentsMargins(18, 9, 14, 9)
        col.setSpacing(5)

        top = QHBoxLayout()
        self.header_row = top
        self.site_label = QLabel("SITE")
        self.site_label.setObjectName("ChromeLabel")
        top.addWidget(self.site_label)
        self.profile_combo = QComboBox()
        self.profile_combo.currentIndexChanged.connect(self._on_profile)
        top.addWidget(self.profile_combo, 1)

        self.status_lbl = QLabel("")
        self.status_lbl.setObjectName("Hint")
        top.addWidget(self.status_lbl, 2)

        self.retry_btn = QPushButton("Retry")
        self.retry_btn.setObjectName("Quiet")
        self.retry_btn.clicked.connect(self._retry_load_or_modes)
        self.retry_btn.setVisible(False)
        top.addWidget(self.retry_btn)

        self.details_btn = QPushButton("Connection details ▾")
        self.details_btn.setObjectName("Quiet")
        self.details_btn.setCheckable(True)
        self.details_btn.toggled.connect(self._toggle_details)
        top.addWidget(self.details_btn)

        self.help_btn = QPushButton("?  Help")
        self.help_btn.setObjectName("Quiet")
        self.help_btn.setToolTip("Open the help (F1)")
        self.help_btn.clicked.connect(self._show_help)
        top.addWidget(self.help_btn)
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

        self.profile_combo.blockSignals(True)
        self.profile_combo.addItem("loading sites…", "")
        self.profile_combo.setEnabled(False)
        self.profile_combo.blockSignals(False)
        self.status_lbl.setText("loading sites…")
        QTimer.singleShot(0, self._begin_async_load)

    def _begin_async_load(self):
        self._load_error = ""
        self.retry_btn.setVisible(False)
        self.profile_combo.blockSignals(True)
        self.profile_combo.clear()
        self.profile_combo.addItem("loading sites…", "")
        self.profile_combo.setEnabled(False)
        self.profile_combo.blockSignals(False)
        self.status_lbl.setText("loading sites…")
        self.status_lbl.setStyleSheet("")

        def _load():
            try:
                import snap_library
                cfg = cfg_module.load()
                cache = {}
                for name in profile_manager.list_profiles():
                    prof = profile_manager.load_profile(name) or {}
                    url = str(prof.get("url", "") or "").strip()
                    api_key = str(prof.get("api_key", "") or "").strip()
                    mode = str(prof.get("site_mode", "") or "").strip().lower()
                    if not mode and url:
                        mode = str(snap_library.site_mode(url) or "").strip().lower()
                    cache[name] = {"url": url, "api_key": api_key, "mode": mode}
                self._sites_bridge.loaded.emit({"config": cfg, "cache": cache})
            except Exception as exc:  # noqa: BLE001
                msg = str(exc).strip() or type(exc).__name__
                self._sites_bridge.failed.emit(msg)

        threading.Thread(target=_load, daemon=True, name="coldsnap-sites-load").start()

    def _apply_load_failed(self, reason: str):
        self._load_error = str(reason or "Unknown error")
        self._profiles_loaded = False
        self.profile_combo.blockSignals(True)
        self.profile_combo.clear()
        self.profile_combo.addItem("— couldn't load sites —", "")
        self.profile_combo.setEnabled(True)
        self.profile_combo.blockSignals(False)
        low = self._load_error.lower()
        if any(tok in low for tok in ("vault", "locked", "unlock", "passphrase", "keyring")):
            self.status_lbl.setText(f"Vault locked — unlock vault ({self._load_error})")
            self.retry_btn.setText("Unlock / Retry")
        else:
            self.status_lbl.setText(f"Couldn't load sites: {self._load_error}")
            self.retry_btn.setText("Retry")
        self.status_lbl.setStyleSheet(f"color: {theme.DANGER};")
        self.retry_btn.setVisible(True)
        self.sitesLoadFailed.emit(self._load_error)

    def _retry_load_or_modes(self):
        if not self._profiles_loaded or self._load_error:
            self._begin_async_load()
        else:
            self._failed_probe_names.clear()
            self._start_mode_discovery()

    def _apply_loaded_sites(self, payload: dict):
        self._load_error = ""
        self.retry_btn.setVisible(False)
        self.config = dict(payload.get("config") or {})
        self._profile_cache = dict(payload.get("cache") or {})
        self._profiles_loaded = True
        self.url_edit.setText(self.config.get("url", ""))
        self.key_edit.setText(self.config.get("api_key", ""))
        self.press_edit.setText(self.config.get("smackpress_key", ""))
        self._startup_profile = self._match_profile(self.config.get("url", ""))
        self.profile_combo.setEnabled(True)
        self._rebuild_profile_combo()
        self._reflect_status()
        self.sitesLoaded.emit()
        self._start_mode_discovery()

    def add_header_action(self, widget):
        """Place one shell-level action before Connection details and Help."""
        self.header_row.insertWidget(max(0, self.header_row.count() - 2), widget)

    def set_compact_window(self, compact: bool):
        compact = bool(compact)
        self.site_label.setVisible(not compact)
        self.details_btn.setVisible(not compact)
        self.status_lbl.setVisible(not compact)
        self.help_btn.setText("?" if compact else "?  Help")

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
        if self._profiles_loaded:
            for name, meta in self._profile_cache.items():
                if self._norm_url(meta.get("url", "")) == want:
                    return name
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
        if self._profiles_loaded:
            self._rebuild_profile_combo()

    def _resolved_mode(self, name: str) -> str:
        meta = self._profile_cache.get(name, {})
        return str(self._discovered_modes.get(name) or meta.get("mode", "") or "").strip().lower()

    def _compatible_profile_names(self):
        if not self._profiles_loaded:
            return []
        names = []
        for name in self._profile_cache:
            mode = self._resolved_mode(name)
            # Never hide a profile just because its mode isn't known yet.
            if self._suite_mode is None or mode == self._suite_mode or mode not in ("photoblog", "carousel", "smacktalk"):
                names.append(name)
        return names

    def _start_mode_discovery(self):
        """Probe only destinations whose mode is not already cached, off the UI thread."""
        from sumna_post import SumnaConnection
        profiles = []
        color_only_profiles = []
        self._pending_probe_names.clear()
        for name, meta in self._profile_cache.items():
            known = self._resolved_mode(name)
            url = str(meta.get("url", "") or "").strip()
            key = str(meta.get("api_key", "") or "").strip()
            if (not url or not key):
                prof = profile_manager.load_profile(name) or {}
                url = url or str(prof.get("url", "") or "").strip()
                key = key or str(prof.get("api_key", "") or prof.get("smackpress_key", "") or "").strip()
            if known in ("photoblog", "carousel", "smacktalk"):
                self._discovered_modes[name] = known
                if url:
                    try:
                        has_bg = any(
                            snap_library.meta(url, k, "")
                            for k in ("site_bg", "page_bg", "background_color", "bg_color")
                        )
                        has_fg = bool(snap_library.meta(url, "site_fg", ""))
                    except Exception:
                        has_bg, has_fg = False, False
                    if not has_bg or not has_fg:
                        color_only_profiles.append((name, url, key, known))
                continue
            if url and key:
                profiles.append((name, url, key))
                self._pending_probe_names.add(name)
            else:
                self._failed_probe_names.add(name)

        if not profiles and not color_only_profiles:
            self._probing_modes = False
            self._rebuild_profile_combo()
            return

        self._probing_modes = bool(profiles)
        self._rebuild_profile_combo()

        def run():
            def probe(row):
                name, url, key = row[:3]
                preset_mode = row[3] if len(row) > 3 else ""
                conn = SumnaConnection(url, key)
                mode = preset_mode
                if not mode:
                    try:
                        mode, _reachable, _note = conn.probe_site_mode(timeout=6)
                    except Exception:  # one offline site must not break the picker
                        mode = ""
                try:
                    has_bg = any(
                        snap_library.meta(url, k, "")
                        for k in ("site_bg", "page_bg", "background_color", "bg_color")
                    )
                    has_fg = bool(snap_library.meta(url, "site_fg", ""))
                    if not has_bg or not has_fg:
                        bg, fg = conn.fetch_site_colors(timeout=6)
                        if bg or fg:
                            snap_library.set_site_colors(url, bg_color=bg, fg_color=fg)
                except Exception:
                    pass
                return name, str(mode or "").strip().lower()

            found = {}
            failed = []
            all_rows = list(profiles) + list(color_only_profiles)
            with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:
                for name, mode in pool.map(probe, all_rows):
                    if mode in ("photoblog", "carousel", "smacktalk"):
                        found[name] = mode
                    else:
                        failed.append(name)
            self._mode_bridge.finished.emit(found, failed)

        threading.Thread(target=run, daemon=True, name="coldsnap-site-modes").start()

    def _apply_discovered_modes(self, modes, failed=None):
        modes = dict(modes or {})
        self._discovered_modes.update(modes)
        for name, mode in modes.items():
            if name in self._profile_cache:
                self._profile_cache[name]["mode"] = mode
            self._failed_probe_names.discard(name)
            try:
                profile_manager.cache_site_mode(name, mode)
            except Exception:  # noqa: BLE001
                pass
        for name in (failed or []):
            self._failed_probe_names.add(name)
        self._pending_probe_names.clear()
        self._probing_modes = False
        self._rebuild_profile_combo()
        self.sitesLoaded.emit()

    def _profile_display_label(self, name: str) -> str:
        mode = self._resolved_mode(name)
        if mode in ("photoblog", "carousel", "smacktalk"):
            return name
        if self._probing_modes and name in self._pending_probe_names:
            return f"{name} (checking…)"
        if name in self._failed_probe_names or not self._probing_modes:
            return f"{name} (mode unknown — retry)"
        return f"{name} (checking…)"

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
            self.profile_combo.addItem(self._profile_display_label(name), name)
        self.profile_combo.setCurrentIndex(
            self.profile_combo.findData(preferred) if preferred else 0)
        self.profile_combo.blockSignals(False)
        if self._failed_probe_names and not self._load_error:
            self.retry_btn.setText("Retry modes")
            self.retry_btn.setVisible(True)
        elif not self._load_error:
            self.retry_btn.setVisible(False)

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
        if self.config.get("vault_warning"):
            # SECAUDIT 054: vault is mandatory for keys — never written as base64.
            # Non-modal: the status line says it, no extra click.
            self.status_lbl.setText("Key not remembered: " + self.config["vault_warning"])
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
