"""Tests for COLD SNAP 0.7.44 repairs: 28-profile mode discovery, load-failure recovery, and send-path mode check."""

import os
import sys
import time

os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")

from PySide6.QtWidgets import QApplication, QMessageBox

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

import config as cfg_module
import profile_manager
from coldsnap_qt.connect_panel import ConnectPanel, verify_site_mode


def _app():
    return QApplication.instance() or QApplication([])


def _pump(ms=150):
    app = _app()
    deadline = time.perf_counter() + ms / 1000.0
    while time.perf_counter() < deadline:
        app.processEvents()
        time.sleep(0.01)


def test_28_profiles_with_23_modeless_stay_visible_and_resolve(tmp_path, monkeypatch):
    _app()
    monkeypatch.setattr(profile_manager, "PROFILES_DIR", str(tmp_path))
    monkeypatch.setattr(cfg_module, "load", lambda: {"url": "", "api_key": "", "smackpress_key": ""})
    monkeypatch.setattr(cfg_module, "save", lambda _c: None)

    profiles = {}
    for i in range(28):
        name = f"site-{i:02d}"
        mode = ("photoblog", "photoblog", "carousel", "carousel", "smacktalk")[i] if i < 5 else ""
        profiles[name] = {
            "name": name,
            "url": f"https://example{i}.com",
            "api_key": f"key-{i}",
            "site_mode": mode,
        }

    monkeypatch.setattr(profile_manager, "list_profiles", lambda: list(profiles.keys()))
    monkeypatch.setattr(
        profile_manager,
        "load_profile",
        lambda n: dict(profiles[n], site_mode=profiles[n]["site_mode"] or profile_manager._cached_site_mode(n)),
    )

    import snap_library
    monkeypatch.setattr(snap_library, "site_mode", lambda _u: "")

    class _FakeConn:
        def __init__(self, url, key):
            self.url = url

        def probe_site_mode(self, timeout=6):
            if "example27" in self.url:
                return "", False, "timeout"
            return "photoblog", True, "ok"

    import sumna_post
    monkeypatch.setattr(sumna_post, "SumnaConnection", _FakeConn)

    panel = ConnectPanel()
    panel.set_suite_mode("photoblog", "SOLO")

    # Wait for async load + background mode discovery to finish
    for _ in range(40):
        _pump(50)
        if panel._profiles_loaded and not panel._probing_modes:
            break

    assert panel._profiles_loaded is True
    # 28 profiles total: 2 photoblog + 2 carousel + 1 smacktalk + 23 modeless ->
    # 22 resolved photoblog + 2 original photoblog + 1 failed/unknown (visible!) = 25 items (+1 placeholder)
    # Wait: during initial load before probes finish, all 23 modeless + 2 photoblog = 25 were visible;
    # and the 1 failed probe (site-27) MUST still remain visible with "mode unknown — retry"!
    labels = [panel.profile_combo.itemText(i) for i in range(panel.profile_combo.count())]
    data = [panel.profile_combo.itemData(i) for i in range(panel.profile_combo.count()) if panel.profile_combo.itemData(i)]
    assert "site-27" in data
    assert any("site-27" in lbl and "mode unknown" in lbl for lbl in labels)
    assert not panel.retry_btn.isHidden()

    # In ALL-sites mode (COLD STORAGE), all 28 profiles are visible
    panel.set_suite_mode(None)
    all_data = [panel.profile_combo.itemData(i) for i in range(panel.profile_combo.count()) if panel.profile_combo.itemData(i)]
    assert len(all_data) == 28

    # Discovered modes were cached to disk so next launch knows site-05 immediately
    assert profile_manager._cached_site_mode("site-05") == "photoblog"


def test_load_failures_show_clear_error_and_retry(monkeypatch):
    _app()

    # 1. Config load failure
    monkeypatch.setattr(cfg_module, "load", lambda: (_ for _ in ()).throw(RuntimeError("config corrupt")))
    p1 = ConnectPanel()
    for _ in range(20):
        _pump(25)
        if p1._load_error:
            break
    assert "Couldn't load sites: config corrupt" in p1.status_lbl.text()
    assert p1.profile_combo.isEnabled()
    assert not p1.retry_btn.isHidden()

    # 2. Vault locked failure
    monkeypatch.setattr(cfg_module, "load", lambda: (_ for _ in ()).throw(RuntimeError("Vault is locked")))
    p2 = ConnectPanel()
    for _ in range(20):
        _pump(25)
        if p2._load_error:
            break
    assert "Vault locked" in p2.status_lbl.text()
    assert p2.retry_btn.text() == "Unlock / Retry"
    assert not p2.retry_btn.isHidden()

    # 3. Profile list failure
    monkeypatch.setattr(cfg_module, "load", lambda: {"url": "", "api_key": ""})
    monkeypatch.setattr(profile_manager, "list_profiles", lambda: (_ for _ in ()).throw(OSError("profiles unreadable")))
    p3 = ConnectPanel()
    for _ in range(20):
        _pump(25)
        if p3._load_error:
            break
    assert "profiles unreadable" in p3.status_lbl.text()
    assert p3.profile_combo.isEnabled()


def test_verify_site_mode_refuses_unknown_or_mismatched(monkeypatch):
    _app()
    warnings = []
    monkeypatch.setattr(QMessageBox, "warning", lambda *_a: warnings.append(_a[1]))

    class _Conn:
        def __init__(self, mode):
            self._m = mode

        def probe_site_mode(self, timeout=6):
            return self._m, bool(self._m), "test"

    assert verify_site_mode(None, _Conn(""), "photoblog", "SOLO") is False
    assert verify_site_mode(None, _Conn("carousel"), "photoblog", "SOLO") is False
    assert verify_site_mode(None, _Conn("photoblog"), "photoblog", "SOLO") is True
    assert len(warnings) == 2


# ===== SNAPSMACK EOF =====
