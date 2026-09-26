"""
Smack Up Your Backup — Qt site-profile dialogs (suyb_qt_profiles.py).

Headless (QT_QPA_PLATFORM=offscreen), no pytest-qt, no network, no visible
windows. Profiles are written to a temp folder; the credential vault and The
Hub's shared stores are switched off so nothing on this machine is read.
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.

from __future__ import annotations

import os
import sys
import threading
import time
from pathlib import Path

os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")

APP_DIR = Path(__file__).resolve().parents[1]
if str(APP_DIR) not in sys.path:
    sys.path.insert(0, str(APP_DIR))

import pytest
from PySide6.QtWidgets import QApplication, QMessageBox

import config
import ftps_pins
import profile_manager
import secret_vault
import suyb_qt_profiles as qp

_APP = QApplication.instance() or QApplication([])


def _wait(predicate, timeout=5.0):
    end = time.monotonic() + timeout
    while time.monotonic() < end:
        _APP.processEvents()
        if predicate():
            return True
        time.sleep(0.01)
    _APP.processEvents()
    return predicate()


@pytest.fixture(autouse=True)
def isolated(tmp_path, monkeypatch):
    monkeypatch.setattr(profile_manager, "PROFILES_DIR", str(tmp_path / "profiles"))
    monkeypatch.setattr(profile_manager, "sync_shared_profiles", lambda: 0)
    monkeypatch.setattr(secret_vault, "is_enabled", lambda: False)
    monkeypatch.setattr(config, "shared_cred", lambda key, default="": default)
    monkeypatch.setattr(ftps_pins, "_default_pin_path", lambda: str(tmp_path / "pins.json"))
    messages = []
    for kind in ("critical", "warning", "information"):
        monkeypatch.setattr(qp, {"critical": "_error", "warning": "_warning",
                                 "information": "_info"}[kind],
                            lambda parent, title, text, k=kind: messages.append((k, title, text)))
    return messages


def _fill(dlg, **values):
    for key, val in values.items():
        dlg._edits[key].setText(str(val))


def _tk_expected(profile, form):
    """What Tk ProfileDialog._save builds: template + profile, form values over it,
    int keys converted with the stored value's type when that works."""
    base = dict(profile_manager.new_profile_template())
    base.update(profile or {})
    out = dict(base)
    for key, val in form.items():
        if key in ("ftp_port", "pacing_delay", "batch_size"):
            try:
                val = type(base.get(key, 0))(val)
            except (ValueError, TypeError):
                pass
        out[key] = val
    return out


# ── ProfileDialog ──────────────────────────────────────────────────────────

def test_new_profile_result_matches_tk(isolated):
    dlg = qp.ProfileDialog(None)
    _fill(dlg, name="Found Textures", site_url="https://foundtextures.ca",
          api_key="k1", ftp_host="ftp.example.com", ftp_user="u", ftp_pass="p",
          backup_dir=r"C:\bk", batch_size="5")
    dlg._save()
    assert dlg.result is not None
    tmpl = profile_manager.new_profile_template()
    assert set(dlg.result) == set(tmpl)
    form = {k: e.text() for k, e in dlg._edits.items()}
    form.update({"ftp_ssl": True, "transport": "http", "cloud_provider": "google_drive",
                 "pacing_delay": "0.0"})
    assert dlg.result == _tk_expected(None, form)
    assert dlg.result["ftp_port"] == 21 and dlg.result["batch_size"] == 5
    assert dlg.result["transport"] == "http"       # template value kept, as Tk did
    profile_manager.save_profile(dlg.result)
    assert profile_manager.list_profiles() == ["Found Textures"]


def test_validation_blocks_empty_name_and_url(isolated):
    dlg = qp.ProfileDialog(None)
    _fill(dlg, site_url="https://x.test")
    dlg._save()
    assert dlg.result is None
    assert isolated[-1] == ("critical", "Required", "Blog name is required.")
    _fill(dlg, name="X", site_url="  ")
    dlg._save()
    assert dlg.result is None
    assert isolated[-1] == ("critical", "Required", "Site URL is required.")


def test_edit_keeps_unknown_keys_and_types(isolated):
    existing = dict(profile_manager.new_profile_template())
    existing.update({"name": "Blog", "site_url": "https://b.test", "pacing_delay": 2.0,
                     "sftp_key_file": r"C:\k", "schedule_enabled": True,
                     "transport": "sftp", "ftp_port": 2222, "future_key": [1, 2]})
    dlg = qp.ProfileDialog(None, profile=existing, title="Edit site")
    assert dlg._combos["pacing_delay"].currentText() == "Sunday Driver"
    assert not dlg._tls.isEnabled()                 # SFTP greys TLS
    assert dlg._edits["ftp_port"].text() == "2222"  # custom port untouched
    dlg._save()
    r = dlg.result
    assert r["future_key"] == [1, 2] and r["sftp_key_file"] == r"C:\k"
    assert r["schedule_enabled"] is True and r["pacing_delay"] == 2.0
    assert r["ftp_port"] == 2222 and r["transport"] == "sftp"


def test_transport_swaps_default_port(isolated):
    dlg = qp.ProfileDialog(None)
    dlg._transport.setCurrentIndex(dlg._transport.findData("sftp"))
    assert dlg._edits["ftp_port"].text() == "22" and not dlg._tls.isEnabled()
    dlg._transport.setCurrentIndex(dlg._transport.findData("ftp"))
    assert dlg._edits["ftp_port"].text() == "21" and dlg._tls.isEnabled()


def test_test_ftp_runs_on_thread_and_disables_buttons(isolated, monkeypatch):
    import transport
    gate = threading.Event()
    seen = {}

    class FakeClient:
        def connect(self):
            seen["thread"] = threading.current_thread() is not threading.main_thread()
            gate.wait(5)

        def disconnect(self):
            pass

    monkeypatch.setattr(transport, "make_client", lambda p, **kw: seen.setdefault("p", p) and FakeClient())
    dlg = qp.ProfileDialog(None)
    _fill(dlg, ftp_host="ftp.example.com")
    dlg._conn_btn.click()
    assert not dlg._conn_btn.isEnabled() and not dlg._login_btn.isEnabled()
    gate.set()
    assert _wait(lambda: dlg._conn_btn.isEnabled())
    assert seen["thread"] is True
    assert dlg.status.text().startswith("✓ Connected to ftp.example.com")
    assert dlg.status.objectName() == "StatusGood"


def test_test_ftp_failure_reported(isolated, monkeypatch):
    import transport

    def boom(p, **kw):
        raise OSError("refused")
    monkeypatch.setattr(transport, "make_client", boom)
    dlg = qp.ProfileDialog(None)
    _fill(dlg, ftp_host="h")
    dlg._conn_btn.click()
    assert _wait(lambda: dlg._conn_btn.isEnabled())
    assert "refused" in dlg.status.text() and dlg.status.objectName() == "StatusBad"


def test_test_login_uses_api_key_path(isolated, monkeypatch):
    import backup_engine

    class Resp:
        status_code = 200
        def close(self):
            pass

    class Sess:
        def __init__(self, url, api_key="", login_slug=""):
            self.session = self
        def get(self, url, **kw):
            assert url.endswith("/suyb-export.php?type=schema")
            return Resp()

    monkeypatch.setattr(backup_engine, "SnapSmackSession", Sess)
    dlg = qp.ProfileDialog(None)
    _fill(dlg, site_url="https://b.test", api_key="key")
    dlg._login_btn.click()
    assert _wait(lambda: dlg._login_btn.isEnabled())
    assert dlg.status.text() == "✓ API key valid"


def test_certificate_change_offered_and_accepted(isolated, monkeypatch):
    import transport
    calls = {"n": 0}

    class Client:
        def connect(self):
            calls["n"] += 1
            if calls["n"] == 1:
                raise ftps_pins.CertificateChanged("h", "AA", "BB", "self-signed")
        def disconnect(self):
            pass

    monkeypatch.setattr(transport, "make_client", lambda p, **kw: Client())
    accepted = []
    monkeypatch.setattr(ftps_pins, "accept_change", lambda h, port, fp, store=None: accepted.append((h, port, fp)))

    def fake_exec(box):
        for b in box.buttons():
            if b.objectName() == "Danger":
                b.click()
        return 0
    monkeypatch.setattr(QMessageBox, "exec", fake_exec)
    dlg = qp.ProfileDialog(None)
    _fill(dlg, ftp_host="h")
    dlg._transport.setCurrentIndex(dlg._transport.findData("ftp"))
    dlg._conn_btn.click()
    assert _wait(lambda: calls["n"] == 2 and dlg._conn_btn.isEnabled())
    assert accepted == [("h", 21, "BB")]
    assert dlg.status.text().startswith("✓")


# ── SetupWizard ────────────────────────────────────────────────────────────

def test_wizard_produces_savable_profile(isolated):
    wiz = qp.SetupWizard(None)
    assert not wiz._back_btn.isEnabled()
    wiz._next()                                   # welcome -> blog details
    wiz._next()                                   # blank name blocked
    assert wiz._step == 1
    assert isolated[-1] == ("warning", "Blog name required", "Enter a name for this blog profile.")
    _fill(wiz, name="Wiz", site_url="https://w.test", ftp_host="ftp.w.test", ftp_port="2121")
    for _ in range(3):
        wiz._next()
    assert wiz._step == 4
    _fill(wiz, backup_dir="")
    wiz._next()
    assert wiz._step == 4 and isolated[-1][1] == "Folder required"
    _fill(wiz, backup_dir=r"C:\bk")
    wiz._next()
    assert wiz._step == 5 and wiz._next_btn.text() == "Finish ✓"
    assert "ftp.w.test:2121" in wiz._summary.text()
    wiz._next()
    r = wiz.result
    assert r and set(profile_manager.new_profile_template()) <= set(r)
    assert r["ftp_port"] == 2121 and r["ftp_ssl"] is True and r["name"] == "Wiz"
    profile_manager.save_profile(r)
    assert profile_manager.load_profile("Wiz")["site_url"] == "https://w.test"


def test_wizard_skip_gives_no_result(isolated):
    wiz = qp.SetupWizard(None)
    wiz._skip_btn.click()
    assert wiz.result is None


def test_wizard_test_ftp_dials_ftp_on_thread(isolated, monkeypatch):
    import transport
    seen = {}

    class C:
        def connect(self):
            seen["thread"] = threading.current_thread() is not threading.main_thread()
        def disconnect(self):
            pass

    def make(p, **kw):
        seen["transport"] = p["transport"]
        return C()
    monkeypatch.setattr(transport, "make_client", make)
    wiz = qp.SetupWizard(None)
    wiz._ftp_btn.click()
    assert _wait(lambda: wiz._ftp_btn.isEnabled())
    assert seen == {"transport": "ftp", "thread": True}
    assert wiz._ftp_status.text() == "✓ FTP connected successfully"


# ── HubDiscoveryDialog ─────────────────────────────────────────────────────

def test_hub_discovery_creates_profiles(isolated, monkeypatch):
    import hub_discovery

    class FakeDisc:
        def __init__(self, url, api_key=""):
            assert threading.current_thread() is not threading.main_thread()
        def discover_spokes(self):
            return ({"site_url": "https://hub.test", "site_name": "Hub", "cloud_config": {}},
                    [{"site_url": "https://s1.test", "site_name": "S1", "api_key_backup": "b1"}])
        def fetch_spoke_backup_config(self, url, key):
            return None
        def close(self):
            pass

    monkeypatch.setattr(hub_discovery, "HubDiscovery", FakeDisc)
    profile_manager.save_profile(dict(profile_manager.new_profile_template(), name="S1"))

    class App:
        current_profile = {"site_url": "https://hub.test", "api_key": "hk"}
        def global_cloud(self):
            return {"cloud_provider": "google_drive", "cloud_credentials_file": "", "cloud_folder_id": ""}

    dlg = qp.HubDiscoveryDialog(None, app=App())
    assert dlg.url_edit.text() == "https://hub.test" and dlg.key_edit.text() == "hk"
    dlg._go_btn.click()
    assert _wait(lambda: dlg.created_count == 1)
    assert "Skipped 1 existing" in dlg.status.text()
    assert sorted(profile_manager.list_profiles()) == ["Hub", "S1"]
    assert isolated[-1][1] == "Discovery complete"


def test_hub_discovery_requires_url_and_key(isolated):
    dlg = qp.HubDiscoveryDialog(None)
    dlg.url_edit.setText(""); dlg.key_edit.setText("")
    dlg._discover()
    assert isolated[-1] == ("critical", "Required", "Hub URL and API key are required.")


# ── delete_profile_confirmed ───────────────────────────────────────────────

def _save(name):
    profile_manager.save_profile(dict(profile_manager.new_profile_template(), name=name))


def test_delete_only_after_confirm(isolated, monkeypatch):
    _save("Keep")
    shown = []

    def refuse(box):
        shown.append(box.text())
        return 0
    monkeypatch.setattr(QMessageBox, "exec", refuse)
    assert qp.delete_profile_confirmed(None, "Keep") is False
    assert profile_manager.list_profiles() == ["Keep"]
    assert "Backups already saved to disk or the cloud are NOT touched." in shown[0]

    def confirm(box):
        for b in box.buttons():
            if b.objectName() == "Danger":
                b.click()
        return 0
    monkeypatch.setattr(QMessageBox, "exec", confirm)
    assert qp.delete_profile_confirmed(None, "Keep") is True
    assert profile_manager.list_profiles() == []

# ===== SNAPSMACK EOF =====
