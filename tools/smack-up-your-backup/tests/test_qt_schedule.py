"""Qt Schedule page + InAppScheduler: edits persist, Run now, main-thread triggers."""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.

import json
import os
import sys
import threading
import time
from datetime import datetime

os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")

HERE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if HERE not in sys.path:
    sys.path.insert(0, HERE)

import pytest
from PySide6.QtCore import QTime
from PySide6.QtWidgets import QApplication

import profile_manager
import scheduler
import suyb_qt_schedule
from suyb_qt_schedule import InAppScheduler, SchedulePage

APP = QApplication.instance() or QApplication([])


def pump(until, timeout=5.0):
    end = time.time() + timeout
    while time.time() < end:
        APP.processEvents()
        if until():
            return True
        time.sleep(0.01)
    APP.processEvents()
    return until()


class FakeWindow:
    def __init__(self, busy=False, accept=True):
        self._busy = busy
        self._accept = accept
        self.calls = []
        self.notes = []

    def busy(self):
        return self._busy

    def run_backup_for(self, names, force_full=False, unattended=False):
        self.calls.append({"names": list(names), "force_full": force_full,
                           "unattended": unattended,
                           "main_thread": threading.current_thread() is threading.main_thread()})
        return self._accept

    def notify(self, title, message, critical=False):
        self.notes.append((title, message))


@pytest.fixture
def profiles(tmp_path, monkeypatch):
    folder = tmp_path / "profiles"
    folder.mkdir()
    monkeypatch.setattr(profile_manager, "PROFILES_DIR", str(folder))
    monkeypatch.setattr(profile_manager, "sync_shared_profiles", lambda: 0)
    monkeypatch.setattr(profile_manager.secret_vault, "is_enabled", lambda: False)
    monkeypatch.setattr(suyb_qt_schedule, "_windows_task_state", lambda: (False, ""))
    for name, url in (("Alpha Site", "https://alpha.test"), ("Beta Site", "https://beta.test")):
        profile = profile_manager.new_profile_template()
        profile.update({"name": name, "site_url": url})
        profile_manager.save_profile(profile)
    return folder


def on_disk(folder, name):
    for fname in os.listdir(folder):
        with open(os.path.join(folder, fname), encoding="utf-8") as handle:
            data = json.load(handle)
        if data.get("name") == name:
            return data
    raise AssertionError(name)


def test_refresh_builds_one_card_per_site_without_writing(profiles):
    before = {f: open(os.path.join(profiles, f), "rb").read() for f in os.listdir(profiles)}
    page = SchedulePage(FakeWindow())
    page.refresh()
    assert sorted(page._rows) == ["Alpha Site", "Beta Site"]
    after = {f: open(os.path.join(profiles, f), "rb").read() for f in os.listdir(profiles)}
    assert before == after
    row = page._rows["Alpha Site"]
    assert row["last"].text() == "Never"
    assert row["next"].text() == "Disabled"
    assert not row["day"].isEnabled()     # daily → day box greyed


def test_every_control_saves_to_the_profile(profiles):
    page = SchedulePage(FakeWindow())
    page.refresh()
    row = page._rows["Alpha Site"]
    row["enabled"].setChecked(True)
    assert on_disk(profiles, "Alpha Site")["schedule_enabled"] is True
    row["freq"].setCurrentIndex(row["freq"].findData("weekly"))
    assert on_disk(profiles, "Alpha Site")["schedule_type"] == "weekly"
    assert row["day"].isEnabled()
    row["day"].setCurrentIndex(row["day"].findData("friday"))
    assert on_disk(profiles, "Alpha Site")["schedule_day"] == "friday"
    row["time"].setTime(QTime(7, 30))
    saved = on_disk(profiles, "Alpha Site")
    assert saved["schedule_time"] == "07:30"
    assert row["next"].text() == "Weekly — Friday at 07:30"
    assert on_disk(profiles, "Beta Site")["schedule_enabled"] is False   # untouched
    # A fresh page reads the saved values back.
    again = SchedulePage(FakeWindow()); again.refresh()
    fresh = again._rows["Alpha Site"]
    assert fresh["enabled"].isChecked()
    assert fresh["freq"].currentData() == "weekly"
    assert fresh["day"].currentData() == "friday"
    assert fresh["time"].time() == QTime(7, 30)


def test_save_failure_is_shown_not_swallowed(profiles, monkeypatch):
    page = SchedulePage(FakeWindow()); page.refresh()
    def refuse(_profile):
        raise RuntimeError("Credential vault is locked")
    monkeypatch.setattr(profile_manager, "save_profile", refuse)
    page._rows["Beta Site"]["enabled"].setChecked(True)
    error = page._rows["Beta Site"]["error"]
    assert not error.isHidden()
    assert "vault is locked" in error.text()


def test_run_now_calls_run_backup_for_interactively(profiles):
    window = FakeWindow()
    page = SchedulePage(window); page.refresh()
    page._rows["Beta Site"]["run"].click()
    assert window.calls == [{"names": ["Beta Site"], "force_full": False,
                             "unattended": False, "main_thread": True}]


def test_trigger_from_scheduler_thread_reaches_window_on_main_thread(profiles, monkeypatch):
    monkeypatch.setattr(InAppScheduler, "GATHER_MS", 20)
    window = FakeWindow()
    timer = InAppScheduler()
    timer._window = window
    alpha = profile_manager.load_profile("Alpha Site")
    beta = profile_manager.load_profile("Beta Site")
    worker = threading.Thread(target=lambda: (timer._on_trigger(alpha), timer._on_trigger(beta)))
    worker.start(); worker.join()
    assert pump(lambda: window.calls)
    assert window.calls == [{"names": ["Alpha Site", "Beta Site"], "force_full": False,
                             "unattended": True, "main_thread": True}]
    for name in ("Alpha Site", "Beta Site"):
        stamp = on_disk(profiles, name)["last_scheduled_run"]
        assert datetime.fromisoformat(stamp).date() == datetime.now().date()


def test_busy_window_skips_without_stamping(profiles, monkeypatch):
    monkeypatch.setattr(InAppScheduler, "GATHER_MS", 20)
    window = FakeWindow(busy=True)
    timer = InAppScheduler(); timer._window = window
    skipped = []
    timer.skipped.connect(skipped.append)
    threading.Thread(target=timer._on_trigger,
                     args=(profile_manager.load_profile("Alpha Site"),)).start()
    assert pump(lambda: skipped)
    assert window.calls == []
    # No balloon (it would repeat every minute) and no stamp: the site stays due
    # and starts at a later check once the running backup is finished.
    assert window.notes == []
    assert on_disk(profiles, "Alpha Site")["last_scheduled_run"] == ""


def test_real_backup_scheduler_tick_fires_through_to_window(profiles, monkeypatch):
    """start(window) → BackupScheduler thread → _is_due → window, end to end."""
    monkeypatch.setattr(InAppScheduler, "GATHER_MS", 20)
    fixed = datetime(2026, 9, 25, 3, 15)

    class FixedClock(datetime):
        @classmethod
        def now(cls, tz=None):
            return fixed
    monkeypatch.setattr(scheduler, "datetime", FixedClock)

    profile = profile_manager.load_profile("Beta Site")
    # Ran yesterday, so the start-up baseline does not apply; the 03:00 slot
    # today has passed (clock 03:15), so it is due even though the tick did
    # not land on 03:00 exactly — the missed-minute catch-up.
    profile.update({"schedule_enabled": True, "schedule_type": "daily", "schedule_time": "03:00",
                    "last_scheduled_run": "2026-09-24T03:00:05"})
    profile_manager.save_profile(profile)

    window = FakeWindow()
    timer = InAppScheduler()
    timer.start(window)
    try:
        assert timer.running()
        threading.Thread(target=timer._scheduler._check_all).start()   # one tick, now
        assert pump(lambda: window.calls)
        assert window.calls[0]["names"] == ["Beta Site"]
        assert window.calls[0]["main_thread"] and window.calls[0]["unattended"]
        # Stamped today, so the same day's tick does not fire again.
        window.calls.clear()
        refreshed = profile_manager.load_profile("Beta Site")
        refreshed["last_scheduled_run"] = fixed.isoformat()
        profile_manager.save_profile(refreshed)
        threading.Thread(target=timer._scheduler._check_all).start()
        pump(lambda: False, timeout=0.3)
        assert window.calls == []
    finally:
        timer.stop()
    assert not timer.running()

def test_is_due_catches_up_and_never_repeats():
    """The old rule needed the clock to EQUAL the scheduled minute."""
    due = scheduler.BackupScheduler._is_due
    base = {"schedule_enabled": True, "schedule_type": "daily", "schedule_time": "02:00"}
    now = datetime(2026, 9, 25, 9, 41)
    assert due(dict(base, last_scheduled_run="2026-09-24T02:00:10"), now)       # missed at 02:00
    assert not due(dict(base, last_scheduled_run="2026-09-25T02:00:10"), now)   # already ran today
    assert not due(dict(base, last_scheduled_run="2026-09-24T02:00:10"),
                   datetime(2026, 9, 25, 1, 59))                                # not yet today
    weekly = dict(base, schedule_type="weekly", schedule_day="monday")          # 2026-09-21 = Monday
    assert due(dict(weekly, last_scheduled_run="2026-09-14T02:00:00"), now)
    assert not due(dict(weekly, last_scheduled_run="2026-09-21T02:00:30"), now)
    assert not due(dict(base, schedule_enabled=False), now)


def test_never_run_schedule_gets_a_startup_baseline(profiles):
    """Turning on this build must not fire every never-stamped schedule at once."""
    profile = profile_manager.load_profile("Beta Site")
    profile.update({"schedule_enabled": True, "schedule_time": "00:00", "last_scheduled_run": ""})
    profile_manager.save_profile(profile)
    timer = InAppScheduler()
    timer.start(FakeWindow())
    try:
        seen = {p["name"]: p for p in timer._profiles_with_stamps()}
        assert not scheduler.BackupScheduler._is_due(seen["Beta Site"], datetime.now())
    finally:
        timer.stop()

# ===== SNAPSMACK EOF =====
