"""Qt Settings page (suyb_qt_settings) — parity with the Tk SettingsTab.

Offscreen, temp config/profile/vault paths, no network, no OAuth, no OS keychain,
no real Windows task or registry changes, no visible windows.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
"""

import json
import os
import sys

os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")
sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

import pytest
from PySide6.QtCore import QObject, QTime, Signal
from PySide6.QtWidgets import QApplication, QInputDialog, QMessageBox

import config as config_module
import credential_store
import profile_manager
import secret_vault
import sync_manager
import suyb_qt_settings as settings_mod


_APP = QApplication.instance() or QApplication([])


class FakeWindow(QObject):
    profileChanged = Signal(object)

    def __init__(self):
        super().__init__()
        self.current_profile = None
        self.reloaded = []
        self.tray_calls = []
        self._busy = False

    def reload_profiles(self, name=""):
        self.reloaded.append(name)
        self.current_profile = profile_manager.load_profile(name) if name else None

    def global_cloud(self):
        return settings_mod.global_cloud_from_config()

    def busy(self):
        return self._busy

    def notify(self, *a, **k):
        pass

    def set_tray_enabled(self, enabled):
        self.tray_calls.append(enabled)


class Dialogs:
    """Scripted answers for QInputDialog / QMessageBox; records every question."""

    def __init__(self, monkeypatch):
        self.texts = []      # queued answers for getText (None = cancel)
        self.yes = []        # queued answers for question()
        self.log = []
        dlg = self

        def get_text(parent, title, prompt, mode=None, text=""):
            dlg.log.append(("text", title, prompt, mode))
            answer = dlg.texts.pop(0)
            return ("", False) if answer is None else (answer, True)

        def question(parent, title, text, *a, **k):
            dlg.log.append(("question", title))
            return QMessageBox.Yes if dlg.yes.pop(0) else QMessageBox.No

        def shower(kind):
            def show(parent, title, text, *a, **k):
                dlg.log.append((kind, title, text))
                return QMessageBox.Ok
            return show

        monkeypatch.setattr(QInputDialog, "getText", staticmethod(get_text))
        monkeypatch.setattr(QMessageBox, "question", staticmethod(question))
        for kind in ("information", "warning", "critical"):
            monkeypatch.setattr(QMessageBox, kind, staticmethod(shower(kind)))

    def kinds(self, kind):
        return [entry for entry in self.log if entry[0] == kind]


@pytest.fixture
def env(tmp_path, monkeypatch):
    profiles = tmp_path / "profiles"; profiles.mkdir()
    jobs = tmp_path / "sync_jobs"; jobs.mkdir()
    monkeypatch.setattr(config_module, "CONFIG_FILE", str(tmp_path / "config.ini"))
    monkeypatch.setattr(profile_manager, "PROFILES_DIR", str(profiles))
    monkeypatch.setattr(profile_manager, "_MIGRATION_JOURNAL", str(tmp_path / "vault-migration.json"))
    monkeypatch.setattr(profile_manager, "sync_shared_profiles", lambda: 0)
    monkeypatch.setattr(sync_manager, "SYNC_JOBS_DIR", str(jobs))
    monkeypatch.setattr(credential_store, "STORE_FILE", str(tmp_path / "credentials.json"))
    # Real vault, temp meta file, and never the operator's OS keychain.
    monkeypatch.setattr(secret_vault, "_meta_path", lambda: str(tmp_path / "vault.meta"))
    monkeypatch.setattr(secret_vault, "keychain_available", lambda: False)
    monkeypatch.setattr(secret_vault, "_key", None)
    # Never a background thread in these tests (AI model load / token checks).
    monkeypatch.setattr(settings_mod, "run_in_thread", lambda relay, work, on_done=None, on_error=None: None)
    return tmp_path


def _seed_profile(**extra):
    p = profile_manager.new_profile_template()
    p.update({
        "name": "a colourless life", "site_url": "https://acolourlesslife.ca",
        "api_key": "suyb_key_123", "backup_dir": r"C:\snapsmack\staging\acl",
        "transport": "ftp", "ftp_host": "ftp.example.test", "ftp_port": 2121,
        "ftp_user": "sean", "ftp_pass": "ftp-secret", "ftp_remote_dir": "/public_html",
        "ftp_ssl": True, "ftp_verify_cert": True, "snap_admin_user": "admin",
        "snap_admin_pass": "admin-secret", "login_slug": "let-me-in",
        "backup_method": "ftp", "schedule_enabled": True, "schedule_time": "04:30",
    })
    p.update(extra)
    profile_manager.save_profile(p)
    return profile_manager.load_profile(p["name"])


def _page(profile=None):
    win = FakeWindow()
    win.current_profile = profile
    page = settings_mod.SettingsPage(win)
    page.refresh()
    return win, page


def _raw(env, name="a colourless life"):
    return json.loads((env / "profiles" / f"{name}.json").read_text(encoding="utf-8"))


# ── Loading ─────────────────────────────────────────────────────────────────
def test_load_shows_saved_values(env):
    cfg = config_module.load()
    cfg.set("pacing", "transfer_delay", "5"); cfg.set("pacing", "batch_size", "40")
    cfg.set("cloud", "provider", "google_drive"); cfg.set("cloud", "folder_id", "FOLDER1")
    cfg.add_section("app") if not cfg.has_section("app") else None
    cfg.set("app", "tray_enabled", "true"); cfg.set("app", "global_schedule_enabled", "true")
    cfg.set("app", "global_schedule_time", "03:15")
    config_module.save(cfg)
    win, page = _page(_seed_profile())

    assert page._edits["ftp_host"].text() == "ftp.example.test"
    assert page._edits["ftp_port"].text() == "2121"
    assert page._edits["ftp_pass"].text() == "ftp-secret"
    assert page._edits["snap_admin_pass"].text() == "admin-secret"
    assert page._edits["login_slug"].text() == "let-me-in"
    assert page.transport_combo.currentData() == "ftp"
    assert page.ftp_ssl_chk.isChecked() and page.ftp_verify_chk.isChecked()
    assert page.method_buttons["ftp"].isChecked()
    assert page.delay_edit.text() == "5" and page.batch_edit.text() == "40"
    assert page.gc_folder_edit.text() == "FOLDER1"
    assert page.tray_chk.isChecked() and page.global_sched_chk.isChecked()
    assert page.global_sched_time.time() == QTime(3, 15)
    # Passwords are never shown in clear text.
    from PySide6.QtWidgets import QLineEdit
    for key in ("ftp_pass", "snap_admin_pass"):
        assert page._edits[key].echoMode() == QLineEdit.Password
    # The Connection page's fields are not on this page at all.
    for key in settings_mod.CONNECTION_KEYS:
        assert key not in page._edits


def test_legacy_profile_infers_method_and_keeps_unknown_transport(env):
    win, page = _page(_seed_profile(backup_method="", cloud_provider="google_drive", transport="http"))
    assert page.method_buttons["cloud"].isChecked()
    assert page.transport_combo.currentData() == "http"


def test_no_site_disables_site_cards(env):
    win, page = _page(None)
    assert not page.site_card.isEnabled() and not page.method_card.isEnabled()


# ── Saving ──────────────────────────────────────────────────────────────────
def test_save_profile_writes_only_owned_keys(env, monkeypatch):
    dialogs = Dialogs(monkeypatch)
    win, page = _page(_seed_profile())
    before = _raw(env)
    # Someone else (the Connection page) saves a new key and folder meanwhile.
    other = profile_manager.load_profile("a colourless life")
    other.update({"api_key": "suyb_key_NEW", "backup_dir": r"D:\elsewhere"})
    profile_manager.save_profile(other)

    page._edits["ftp_host"].setText("ftp2.example.test")
    page._edits["ftp_port"].setText("990")
    page._edits["ftp_pass"].setText("new-ftp-secret")
    page.ftp_ssl_chk.setChecked(False)
    page.method_buttons["local"].setChecked(True)
    page._save_profile()

    raw = _raw(env)
    saved = profile_manager.load_profile("a colourless life")
    assert saved["ftp_host"] == "ftp2.example.test"
    assert raw["ftp_port"] == 990 and isinstance(raw["ftp_port"], int)
    assert saved["ftp_pass"] == "new-ftp-secret"
    assert raw["ftp_ssl"] is False and raw["ftp_verify_cert"] is True
    assert raw["backup_method"] == "local" and raw["cloud_provider"] == "none"
    # Untouched: the Connection page's four fields, schedule, name.
    assert saved["api_key"] == "suyb_key_NEW"
    assert raw["backup_dir"] == r"D:\elsewhere"
    assert raw["site_url"] == before["site_url"] and raw["name"] == before["name"]
    assert raw["schedule_enabled"] is True and raw["schedule_time"] == "04:30"
    assert "ftp_pass" not in raw and "snap_admin_pass" not in raw
    assert win.reloaded == ["a colourless life"]
    assert dialogs.kinds("information")


def test_save_defaults_writes_tk_config_keys(env, monkeypatch):
    Dialogs(monkeypatch)
    win, page = _page(None)
    page.delay_edit.setText("7"); page.batch_edit.setText("25")
    page.gc_creds_edit.setText(r"  C:\keys\drive.json  ")
    page.gc_folder_edit.setText(" F123 ")
    page._save()
    cfg = config_module.load()
    assert cfg.get("pacing", "transfer_delay") == "7"
    assert cfg.get("pacing", "batch_size") == "25"
    assert cfg.get("cloud", "provider") == "google_drive"
    assert cfg.get("cloud", "credentials_file") == r"C:\keys\drive.json"
    assert cfg.get("cloud", "folder_id") == "F123"
    assert settings_mod.global_cloud_from_config() == {
        "cloud_provider": "google_drive",
        "cloud_credentials_file": r"C:\keys\drive.json",
        "cloud_folder_id": "F123",
    }


def test_transport_switch_swaps_default_port_only(env):
    win, page = _page(_seed_profile(ftp_port=21))
    page.transport_combo.setCurrentIndex(page.transport_combo.findData("sftp"))
    assert page._edits["ftp_port"].text() == "22"
    assert not page.ftp_ssl_chk.isEnabled()
    page._edits["ftp_port"].setText("2222")
    page.transport_combo.setCurrentIndex(page.transport_combo.findData("ftp"))
    assert page._edits["ftp_port"].text() == "2222"


# ── Encryption (real vault in a temp folder) ────────────────────────────────
def _record(monkeypatch, calls):
    for mod, name in ((profile_manager, "enable_encryption"),
                      (profile_manager, "change_encryption_passphrase"),
                      (profile_manager, "disable_encryption"),
                      (secret_vault, "unlock")):
        real = getattr(mod, name)

        def wrapper(*a, _real=real, _name=name, **k):
            calls.append((_name, a, k))
            return _real(*a, **k)
        monkeypatch.setattr(mod, name, wrapper)


def test_enable_change_disable_match_tk(env, monkeypatch):
    dialogs = Dialogs(monkeypatch)
    calls = []
    _record(monkeypatch, calls)
    win, page = _page(_seed_profile())

    # Enable: passphrase twice → enable_encryption(pw, store_machine_key=False)
    dialogs.texts = ["correct horse", "correct horse"]
    page._on_enc_enable()
    assert calls == [("enable_encryption", ("correct horse",), {"store_machine_key": False})]
    assert secret_vault.is_enabled() and secret_vault.is_unlocked()
    raw = _raw(env)
    assert raw["ftp_pass_enc"].startswith("enc1:") and raw["api_key"].startswith("enc1:")
    assert all(entry[3] == settings_mod.QLineEdit.Password for entry in dialogs.kinds("text"))
    assert page.enc_mk_chk is not None and not page.enc_mk_chk.isEnabled()   # no keychain

    # Change: old, new, new → change_encryption_passphrase(old, new)
    calls.clear(); dialogs.log.clear()
    dialogs.texts = ["correct horse", "battery staple", "battery staple"]
    page._on_enc_change()
    assert calls[0] == ("change_encryption_passphrase", ("correct horse", "battery staple"), {})
    assert dialogs.kinds("information")
    secret_vault.lock()
    assert not secret_vault.unlock("correct horse")
    assert secret_vault.unlock("battery staple")

    # Disable while locked: confirm → passphrase → unlock → disable_encryption()
    secret_vault.lock()
    calls.clear()
    dialogs.yes = [True]
    dialogs.texts = ["battery staple"]
    page._on_enc_disable()
    assert [c[0] for c in calls] == ["unlock", "disable_encryption"]
    assert calls[0][1] == ("battery staple",)
    assert not secret_vault.is_enabled()
    raw = _raw(env)
    assert not raw["api_key"].startswith("enc1:")
    assert profile_manager.load_profile("a colourless life")["ftp_pass"] == "ftp-secret"


def test_mismatched_or_cancelled_passphrase_changes_nothing(env, monkeypatch):
    dialogs = Dialogs(monkeypatch)
    calls = []
    _record(monkeypatch, calls)
    win, page = _page(_seed_profile())
    dialogs.texts = ["one", "two"]
    page._on_enc_enable()
    dialogs.texts = [None]
    page._on_enc_enable()
    assert calls == [] and not secret_vault.is_enabled()
    assert dialogs.kinds("critical")[0][2] == "Passphrases didn't match."


def test_wrong_passphrase_on_disable_and_change(env, monkeypatch):
    dialogs = Dialogs(monkeypatch)
    win, page = _page(_seed_profile())
    profile_manager.enable_encryption("right")
    secret_vault.lock()
    calls = []
    _record(monkeypatch, calls)
    dialogs.yes = [True]; dialogs.texts = ["wrong"]
    page._on_enc_disable()
    assert [c[0] for c in calls] == ["unlock"] and secret_vault.is_enabled()
    dialogs.texts = ["wrong", "new", "new"]
    page._on_enc_change()
    assert secret_vault.is_enabled()
    assert dialogs.kinds("critical")[-1][2] == "Current passphrase was wrong."
    assert _raw(env)["api_key"].startswith("enc1:")


def test_disable_declined_changes_nothing(env, monkeypatch):
    dialogs = Dialogs(monkeypatch)
    win, page = _page(_seed_profile())
    profile_manager.enable_encryption("right")
    calls = []
    _record(monkeypatch, calls)
    dialogs.yes = [False]
    page._on_enc_disable()
    assert calls == [] and secret_vault.is_enabled()


def test_encryption_refused_while_busy_or_migration_pending(env, monkeypatch):
    dialogs = Dialogs(monkeypatch)
    calls = []
    _record(monkeypatch, calls)
    win, page = _page(_seed_profile())
    win._busy = True
    page._on_enc_enable()
    win._busy = False
    (env / "vault-migration.json").write_text("{}", encoding="utf-8")
    page._on_enc_enable()
    assert calls == [] and dialogs.texts == [] and not secret_vault.is_enabled()


def test_enable_failure_is_reported_and_rolled_back(env, monkeypatch):
    dialogs = Dialogs(monkeypatch)
    win, page = _page(_seed_profile())
    before = _raw(env)

    def boom(*a, **k):
        raise RuntimeError("disk full")
    monkeypatch.setattr(profile_manager, "_commit_migration", boom)
    dialogs.texts = ["pw", "pw"]
    page._on_enc_enable()
    assert "disk full" in dialogs.kinds("critical")[-1][2]
    assert not secret_vault.is_enabled()
    assert _raw(env) == before


# ── Automatic backups / app options ─────────────────────────────────────────
def test_global_schedule_toggle_calls_os_schedule(env, monkeypatch):
    Dialogs(monkeypatch)
    import os_schedule
    calls = []
    monkeypatch.setattr(os_schedule, "set_global_schedule",
                        lambda enabled, t: (calls.append((enabled, t)) or (True, "Scheduled.")))
    monkeypatch.setattr(os_schedule, "schedule_state", lambda: {"enabled": False})
    win, page = _page(None)
    page.global_sched_time.setTime(QTime(4, 45))
    page.global_sched_chk.setChecked(True)
    page._on_global_schedule_toggle()
    assert calls == [(True, "04:45")]
    cfg = config_module.load()
    assert cfg.get("app", "global_schedule_enabled") == "true"
    assert cfg.get("app", "global_schedule_time") == "04:45"
    page.global_sched_chk.setChecked(False)
    page._on_global_schedule_toggle()
    assert calls[-1] == (False, "04:45")
    assert config_module.load().get("app", "global_schedule_enabled") == "false"


def test_global_schedule_failure_snaps_back_to_os_state(env, monkeypatch):
    Dialogs(monkeypatch)
    import os_schedule
    monkeypatch.setattr(os_schedule, "set_global_schedule", lambda e, t: (False, "schtasks failed"))
    monkeypatch.setattr(os_schedule, "schedule_state", lambda: {"enabled": False})
    win, page = _page(None)
    page.global_sched_chk.setChecked(True)
    page._on_global_schedule_toggle()
    assert not page.global_sched_chk.isChecked()
    assert config_module.load().get("app", "global_schedule_enabled") == "false"


def test_schedule_with_encryption_and_no_keychain_warns(env, monkeypatch):
    dialogs = Dialogs(monkeypatch)
    import os_schedule
    monkeypatch.setattr(os_schedule, "set_global_schedule", lambda e, t: (True, "ok"))
    win, page = _page(_seed_profile())
    profile_manager.enable_encryption("pw")
    page.global_sched_chk.setChecked(True)
    page._on_global_schedule_toggle()
    assert dialogs.kinds("warning")


def test_tray_and_startup_toggles(env, monkeypatch):
    win, page = _page(None)
    startup = []
    monkeypatch.setattr(page, "_set_windows_startup", lambda enabled: startup.append(("win", enabled)))
    monkeypatch.setattr(page, "_set_linux_autostart", lambda enabled: startup.append(("nix", enabled)))
    page.tray_chk.setChecked(True); page._on_tray_toggle()
    page.startup_chk.setChecked(True); page._on_startup_toggle()
    cfg = config_module.load()
    assert cfg.get("app", "tray_enabled") == "true"
    assert cfg.get("app", "startup_enabled") == "true"
    assert win.tray_calls == [True]
    assert startup and startup[0][1] is True


def test_profile_changed_signal_reloads_from_disk(env):
    win, page = _page(None)
    win.current_profile = _seed_profile()
    win.profileChanged.emit(win.current_profile)
    assert page._edits["ftp_user"].text() == "sean"
    assert page.site_card.isEnabled()


def test_help_topics_ported(env):
    win, page = _page(None)
    titles = [t for t, _ in page.help_topics()]
    assert "Credential encryption" in titles and "Cloud setup" in titles

# ===== SNAPSMACK EOF =====
