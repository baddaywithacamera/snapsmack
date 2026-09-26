"""SMACK UP YOUR BACKUP — Schedule page (Qt) and the in-app backup timer.

Qt rebuild of the Tk SchedulerTab (main.py). Every site gets one card:
on/off, daily or weekly, the day (weekly only), the time, the last automatic
run, the next run, and a Run now button. Every change is written straight to
that site's profile file (same fields the Tk tab and scheduler.py use:
schedule_enabled, schedule_type, schedule_day, schedule_time).

InAppScheduler (bottom of this file) wraps scheduler.BackupScheduler so the
main window can start and stop the per-site timer. Read its docstring for
exactly when it fires and how it differs from the Windows scheduled task.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
"""

from datetime import datetime

from PySide6.QtCore import QObject, QTime, QTimer, Signal
from PySide6.QtWidgets import (
    QCheckBox, QComboBox, QGridLayout, QHBoxLayout, QLabel, QPushButton,
    QTimeEdit, QVBoxLayout, QWidget,
)

import profile_manager
from scheduler import DAYS, BackupScheduler
from suyb_qt_common import (
    FitScrollArea, Relay, card, label, make_page, run_in_thread, set_status,
)


SCHEDULE_FIELDS = ("schedule_enabled", "schedule_type", "schedule_day", "schedule_time")
FREQUENCIES = (("Every day", "daily"), ("Once a week", "weekly"))
TARGET_HEIGHT = 40   # big click targets (owner has Parkinson's)

HOW_IT_WORKS = (
    "Two different kinds of automatic backup exist, and they are not the same thing.\n\n"
    "1. The per-site times on this page only run while SMACK UP YOUR BACKUP is open "
    "(a window or the tray icon). If SUYB was closed or the computer was asleep at "
    "the scheduled time, the missed backup starts the next time SUYB is open. If a "
    "backup is already running, the scheduled one waits for it to finish.\n\n"
    "2. The Windows scheduled task (\"back up every site daily\") is separate. Windows "
    "starts SUYB by itself once a day, backs up EVERY site (it ignores the on/off "
    "switches on this page), then closes. It works with this window closed, but you "
    "must be logged in to Windows at that time."
)


def _load_all_profiles():
    """Every profile on disk (the scheduler thread calls this every minute)."""
    profiles = []
    for name in profile_manager.list_profiles():
        profile = profile_manager.load_profile(name)
        if profile:
            profiles.append(profile)
    return profiles


def _parse_time(text):
    """'02:00' / '2:00' → QTime; anything else → 02:00 (the engine default)."""
    for fmt in ("HH:mm", "H:mm"):
        parsed = QTime.fromString((text or "").strip(), fmt)
        if parsed.isValid():
            return parsed
    return QTime(2, 0)


def _last_run_text(profile):
    last = profile.get("last_scheduled_run", "") or ""
    if not last:
        return "Never"
    return last[:16].replace("T", " ")


def _windows_task_state():
    """Worker thread: is the OS 'back up every site daily' task registered, and when?"""
    import config as config_module
    import os_schedule
    state = os_schedule.schedule_state()
    try:
        cfg = config_module.load()
        when = cfg.get("app", "global_schedule_time", fallback="")
    except Exception:
        when = ""
    return bool(state.get("enabled")), when


class SchedulePage(QWidget):
    """Per-site automatic backup times. Changes save the moment you make them."""

    def __init__(self, window):
        super().__init__()
        self._window = window
        self._relay = Relay(self)
        self._rows = {}
        layout = make_page(
            self, "Schedule",
            "Pick when each site backs itself up. Every change saves the moment you make it.")

        how, how_layout = card("How automatic backups work")
        how_layout.addWidget(label(HOW_IT_WORKS, "Muted"))
        self._task_status = label("Checking the Windows scheduled task…", "StatusWarn")
        how_layout.addWidget(self._task_status)
        layout.addWidget(how)

        bar = QHBoxLayout()
        self._summary = label("", "Muted")
        bar.addWidget(self._summary, 1)
        self._refresh_btn = QPushButton("Refresh")
        self._refresh_btn.setMinimumHeight(TARGET_HEIGHT)
        self._refresh_btn.setToolTip("Re-read every site's schedule from disk.")
        self._refresh_btn.clicked.connect(self.refresh)
        bar.addWidget(self._refresh_btn)
        layout.addLayout(bar)

        self._rows_host = QWidget()
        self._rows_layout = QVBoxLayout(self._rows_host)
        self._rows_layout.setContentsMargins(0, 0, 0, 0)
        self._rows_layout.setSpacing(12)
        layout.addWidget(self._rows_host)
        layout.addStretch(1)

    # ── Data ─────────────────────────────────────────────────────────────
    def refresh(self):
        """Rebuild the site cards from the profile files on disk."""
        while self._rows_layout.count():
            item = self._rows_layout.takeAt(0)
            widget = item.widget()
            if widget:
                widget.setParent(None)
                widget.deleteLater()
        self._rows.clear()

        try:
            profiles = _load_all_profiles()
        except Exception as exc:
            profiles = []
            self._rows_layout.addWidget(label(f"Could not read your sites: {exc}", "StatusBad"))

        if not profiles:
            self._summary.setText("")
            self._rows_layout.addWidget(label(
                "No sites set up yet. Add a site on the Connection page first.", "Muted"))
        else:
            on = sum(1 for p in profiles if p.get("schedule_enabled"))
            self._summary.setText(
                f"{len(profiles)} site(s). {on} set to back up automatically while SUYB is open.")
            for profile in profiles:
                self._add_row(profile)

        self._check_windows_task()
        self._refit()

    def _refit(self):
        scroll = self.findChild(FitScrollArea)
        if scroll:
            scroll._fit_widget()

    def _check_windows_task(self):
        def done(result):
            enabled, when = result
            if enabled:
                at = f" at {when}" if when else ""
                set_status(self._task_status,
                           f"Windows scheduled task: ON — backs up every site daily{at}, "
                           "even with this window closed.", "good")
            else:
                set_status(self._task_status,
                           "Windows scheduled task: OFF — only the per-site times below "
                           "run, and only while SUYB is open.", "warn")

        def failed(exc):
            set_status(self._task_status,
                       f"Could not check the Windows scheduled task: {exc}", "bad")

        run_in_thread(self._relay, _windows_task_state, done, failed)

    # ── One card per site ────────────────────────────────────────────────
    def _add_row(self, profile):
        name = profile.get("name", "")
        frame, box = card(name, profile.get("site_url", ""))

        enabled = QCheckBox("Back up this site automatically")
        enabled.setChecked(bool(profile.get("schedule_enabled", False)))
        enabled.setMinimumHeight(TARGET_HEIGHT)
        box.addWidget(enabled)

        grid = QGridLayout()
        grid.setHorizontalSpacing(14)
        grid.setVerticalSpacing(4)
        for column, text in enumerate(("How often", "Day", "Time (24-hour)",
                                       "Last automatic run", "Next run")):
            grid.addWidget(label(text, "Muted"), 0, column)

        freq = QComboBox()
        freq.setMinimumHeight(TARGET_HEIGHT)
        for text, value in FREQUENCIES:
            freq.addItem(text, value)
        stype = profile.get("schedule_type", "daily")
        freq.setCurrentIndex(max(0, freq.findData(stype if stype in ("daily", "weekly") else "daily")))
        grid.addWidget(freq, 1, 0)

        day = QComboBox()
        day.setMinimumHeight(TARGET_HEIGHT)
        for value in DAYS:
            day.addItem(value.capitalize(), value)
        day_value = str(profile.get("schedule_day", "monday")).lower()
        day.setCurrentIndex(max(0, day.findData(day_value)))
        day.setEnabled(freq.currentData() == "weekly")
        day.setToolTip("Only used when the site backs up once a week.")
        grid.addWidget(day, 1, 1)

        time_edit = QTimeEdit()
        time_edit.setDisplayFormat("HH:mm")
        time_edit.setMinimumHeight(TARGET_HEIGHT)
        time_edit.setTime(_parse_time(profile.get("schedule_time", "02:00")))
        grid.addWidget(time_edit, 1, 2)

        last = label(_last_run_text(profile))
        grid.addWidget(last, 1, 3)
        next_lbl = QLabel()
        grid.addWidget(next_lbl, 1, 4)

        run_now = QPushButton("Run now")
        run_now.setMinimumHeight(TARGET_HEIGHT)
        run_now.setToolTip("Start a normal (differential) backup of this site right now.")
        grid.addWidget(run_now, 1, 5)
        grid.setColumnStretch(4, 1)
        box.addLayout(grid)

        error = label("", "StatusBad")
        error.hide()
        box.addWidget(error)

        row = {"enabled": enabled, "freq": freq, "day": day, "time": time_edit,
               "last": last, "next": next_lbl, "run": run_now, "error": error}
        self._rows[name] = row
        self._show_next(name, profile)

        # Connect only after the widgets hold the saved values, so building
        # the card never writes anything back to disk.
        enabled.toggled.connect(lambda v, n=name: self._save_field(n, "schedule_enabled", bool(v)))
        freq.currentIndexChanged.connect(lambda _i, n=name: self._on_frequency(n))
        day.currentIndexChanged.connect(
            lambda _i, n=name: self._save_field(n, "schedule_day", self._rows[n]["day"].currentData()))
        time_edit.timeChanged.connect(
            lambda t, n=name: self._save_field(n, "schedule_time", t.toString("HH:mm")))
        run_now.clicked.connect(lambda _=False, n=name: self._run_now(n))

        self._rows_layout.addWidget(frame)

    def _show_next(self, name, profile):
        row = self._rows.get(name)
        if not row:
            return
        text = BackupScheduler.next_run_str(profile)
        if profile.get("schedule_enabled"):
            set_status(row["next"], text, "good")
        else:
            row["next"].setText(text)
            row["next"].setObjectName("Muted")
            row["next"].style().unpolish(row["next"]); row["next"].style().polish(row["next"])

    # ── Actions ──────────────────────────────────────────────────────────
    def _on_frequency(self, name):
        row = self._rows[name]
        value = row["freq"].currentData()
        row["day"].setEnabled(value == "weekly")
        self._save_field(name, "schedule_type", value)

    def _save_field(self, name, key, value):
        """Write one schedule field to the site's profile file (Tk _save_field)."""
        if key not in SCHEDULE_FIELDS:
            raise ValueError(f"Not a schedule field: {key}")
        row = self._rows.get(name, {})
        error = row.get("error")
        try:
            profile = profile_manager.load_profile(name)
            if not profile:
                raise RuntimeError("This site's settings file is missing. Press Refresh.")
            profile[key] = value
            profile_manager.save_profile(profile)
        except Exception as exc:
            if error:
                error.setText(f"Not saved: {exc}")
                error.show()
            return False
        if error:
            error.hide()
        self._show_next(name, profile)
        return True

    def _run_now(self, name):
        """Start a normal, interactive backup of this one site."""
        self._window.run_backup_for([name], force_full=False, unattended=False)

    def help_topics(self):
        return help_topics()


def help_topics():
    return [
        ("Schedule page", (
            "Each site has its own card. Tick \"Back up this site automatically\", pick "
            "Every day or Once a week, pick the day (weekly only) and the time in 24-hour "
            "form (02:00 is 2 in the morning). Every change saves at once — there is no "
            "Save button.\n\n"
            "Last automatic run shows when the timer last started a backup of that site. "
            "Next run repeats the setting in plain words.\n\n"
            "Run now starts a normal (differential) backup of that site straight away, "
            "the same as pressing Start on the Overview page. It does not change the "
            "schedule.\n\n"
            "Refresh re-reads every site from disk, for example after adding a site.")),
        ("When do scheduled backups run?", HOW_IT_WORKS + (
            "\n\nThe per-site timer checks once a minute. When the clock matches a site's "
            "time (and day, for weekly), it starts a differential backup of that site with "
            "no questions asked. If a backup is already running at that minute, the "
            "scheduled one is skipped and you get a tray message. Sites due at the same "
            "minute are backed up one after another in the same run.\n\n"
            "If credential encryption is on, the Windows task needs \"Allow unattended "
            "backups on this computer\" or it skips.")),
    ]


# ─────────────────────────────────────────────────────────────────────────────
# In-app timer
# ─────────────────────────────────────────────────────────────────────────────

class InAppScheduler(QObject):
    """Starts per-site scheduled backups while SUYB is running.

    WHEN IT FIRES (read from scheduler.py, which is unchanged):
      * BackupScheduler runs a daemon thread that sleeps 60 seconds, then reads
        every profile from disk and checks each one. The first check happens 60
        seconds after start(), not at start().
      * A site is due when schedule_enabled is on, its latest scheduled time
        (daily, or weekly on schedule_day) has passed, and no scheduled run has
        started since then (last_scheduled_run). A run missed because SUYB was
        closed, the PC slept, or a tick drifted starts at the next check.
        (Before 0.7.47 the clock had to EQUAL the scheduled minute, and a missed
        minute lost that backup silently.)
      * scheduler.py never writes last_scheduled_run (neither did the Tk
        window), so THIS class stamps it when it starts a backup, and also keeps
        the stamp in memory so a refused disk write can never make a site run
        again every minute.
      * A site that has never had a scheduled run gets a baseline of "now" when
        SUYB starts, so turning on this build does not fire every old schedule
        at once. Its first run is its next scheduled time.
      * If a backup is already running when a site comes due, nothing is
        stamped; the site is still due at the next check and starts once the
        running backup finishes.
      * It only exists while the SUYB process is running (window or tray).
        Closing SUYB stops it.

    THE WINDOWS TASK (os_schedule.py) is a different mechanism: one Task
    Scheduler entry, "SmackUpYourBackup-AutoBackup", that runs
    `suyb.exe --backup-all --silent` daily at one time. That path is
    headless.run_backup_all(): a differential backup of EVERY profile, ignoring
    schedule_enabled/type/day/time, whether or not the window is open. schtasks
    is created without /RU, so it runs only while the user is logged on. The
    two mechanisms do not know about each other; with both on, a site can be
    backed up twice.

    THREADING: on_trigger is called on the scheduler thread; it only emits a
    Signal. The queued slot runs on the main thread, gathers every site that
    came due in the same tick (Tk dropped all but the first because the engine
    was busy), and calls window.run_backup_for(names, unattended=True).
    """

    triggered = Signal(object)   # profile dict, emitted from the scheduler thread
    ran = Signal(list)           # site names actually started (main thread)
    skipped = Signal(list)       # site names skipped because a backup was running

    GATHER_MS = 1500             # due sites in one tick arrive within this window

    def __init__(self, parent=None, get_profiles=None):
        super().__init__(parent)
        self._window = None
        self._scheduler = None
        self._pending = []
        self._get_profiles = get_profiles or _load_all_profiles
        self._stamps = {}            # name -> ISO time of the last run started here
        self._flush_timer = QTimer(self)
        self._flush_timer.setSingleShot(True)
        self._flush_timer.setInterval(self.GATHER_MS)
        self._flush_timer.timeout.connect(self.flush)
        self.triggered.connect(self._queue)

    def start(self, window):
        """Start the per-minute check. Safe to call twice."""
        self._window = window
        if self._scheduler is not None:
            return
        baseline = datetime.now().isoformat(timespec="seconds")
        try:
            for profile in self._get_profiles() or []:
                if profile.get("schedule_enabled") and not profile.get("last_scheduled_run"):
                    self._stamps.setdefault(profile.get("name", ""), baseline)
        except Exception:
            pass
        self._scheduler = BackupScheduler(on_trigger=self._on_trigger)
        self._scheduler.start(self._profiles_with_stamps)

    def stop(self):
        """Stop the per-minute check (the thread exits at its next wake-up)."""
        if self._scheduler is not None:
            self._scheduler.stop()
            self._scheduler = None
        self._flush_timer.stop()
        self._pending = []

    def running(self):
        return self._scheduler is not None

    # scheduler thread
    def _profiles_with_stamps(self):
        """Profiles from disk, with this session's run stamps laid over them."""
        profiles = []
        for profile in self._get_profiles() or []:
            profile = dict(profile)
            mine = self._stamps.get(profile.get("name", ""))
            if mine and mine > str(profile.get("last_scheduled_run") or ""):
                profile["last_scheduled_run"] = mine
            profiles.append(profile)
        return profiles

    def _on_trigger(self, profile):
        self.triggered.emit(profile)

    # main thread
    def _queue(self, profile):
        name = (profile or {}).get("name", "")
        if name and name not in self._pending:
            self._pending.append(name)
        if not self._flush_timer.isActive():
            self._flush_timer.start()

    def flush(self):
        """Start every site gathered so far (main thread)."""
        names, self._pending = self._pending, []
        window = self._window
        if not names or window is None:
            return []
        busy = False
        try:
            busy = bool(window.busy())
        except Exception:
            pass
        started = False if busy else window.run_backup_for(names, force_full=False, unattended=True)
        if started is False:   # contract: False = refused; anything else = started
            # Not stamped, so the sites stay due and start at a later check once
            # the running backup is finished. No balloon: it would repeat every minute.
            self.skipped.emit(names)
            return []
        stamp = datetime.now().isoformat(timespec="seconds")
        for name in names:
            self._stamps[name] = stamp
            try:
                profile = profile_manager.load_profile(name)
                if profile:
                    profile["last_scheduled_run"] = stamp
                    profile_manager.save_profile(profile)
            except Exception:
                pass   # a locked vault refuses the write; the backup still runs
        self.ran.emit(names)
        return names

# ===== SNAPSMACK EOF =====
