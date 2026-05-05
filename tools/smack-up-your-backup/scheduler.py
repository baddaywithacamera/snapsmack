"""
Smack Up Your Backup — scheduler.py
Background scheduler for automatic per-profile backups.

Runs in a daemon thread, waking every 60 seconds to check whether any
profile's scheduled backup is due.  When due, calls on_trigger(profile)
which hands off to the main app's backup queue.

Schedule is stored per-profile:
    schedule_enabled  bool   — master on/off switch
    schedule_type     str    — "daily" | "weekly"
    schedule_day      str    — "monday"…"sunday"  (weekly only)
    schedule_time     str    — "HH:MM" 24-hour local time
    last_scheduled_run str   — ISO-8601 timestamp of last auto-run
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.




import threading
from datetime import datetime, date, timedelta
from typing import Callable, List, Optional


DAYS = ["monday", "tuesday", "wednesday", "thursday", "friday", "saturday", "sunday"]


class BackupScheduler:

    def __init__(self, on_trigger: Callable[[dict], None]):
        """
        on_trigger is called with the profile dict when a backup is due.
        It is invoked from the scheduler thread — implementations must
        be thread-safe (e.g. use app.queue_msg).
        """
        self._on_trigger   = on_trigger
        self._get_profiles: Optional[Callable[[], List[dict]]] = None
        self._stop_event   = threading.Event()
        self._thread: Optional[threading.Thread] = None
        self._running      = False

    # ------------------------------------------------------------------
    # Lifecycle
    # ------------------------------------------------------------------

    def start(self, get_profiles: Callable[[], List[dict]]) -> None:
        """Start the background scheduling thread."""
        self._get_profiles = get_profiles
        self._stop_event.clear()
        self._running = True
        self._thread = threading.Thread(
            target=self._loop, daemon=True, name="BackupScheduler"
        )
        self._thread.start()

    def stop(self) -> None:
        """Signal the scheduler to stop and wait briefly for it."""
        self._running = False
        self._stop_event.set()

    def reschedule(self) -> None:
        """Call after profile changes so the next tick picks up new settings."""
        pass  # stateless — checks live every tick, nothing to do

    # ------------------------------------------------------------------
    # Internals
    # ------------------------------------------------------------------

    def _loop(self) -> None:
        """Wake every 60 s and check for due backups."""
        while not self._stop_event.wait(60):
            if not self._running:
                break
            try:
                self._check_all()
            except Exception:
                pass  # never crash the scheduler thread

    def _check_all(self) -> None:
        if not self._get_profiles:
            return
        now = datetime.now()
        for profile in self._get_profiles():
            try:
                if self._is_due(profile, now):
                    self._on_trigger(profile)
            except Exception:
                pass

    @staticmethod
    def _is_due(profile: dict, now: datetime) -> bool:
        if not profile.get("schedule_enabled"):
            return False

        # Parse scheduled time
        schedule_time = profile.get("schedule_time", "02:00")
        try:
            h, m = map(int, schedule_time.split(":"))
        except Exception:
            return False

        # Must be the right minute
        if now.hour != h or now.minute != m:
            return False

        schedule_type = profile.get("schedule_type", "daily")

        # Weekly: must be the right day of the week
        if schedule_type == "weekly":
            day_name = profile.get("schedule_day", "monday").lower()
            try:
                target_weekday = DAYS.index(day_name)
            except ValueError:
                return False
            if now.weekday() != target_weekday:
                return False

        # Deduplication: don't run twice in the same window
        last_run = profile.get("last_scheduled_run", "")
        if last_run:
            try:
                last_dt = datetime.fromisoformat(last_run)
                if schedule_type == "daily":
                    if last_dt.date() == now.date():
                        return False
                elif schedule_type == "weekly":
                    if (now.date() - last_dt.date()).days < 6:
                        return False
            except Exception:
                pass

        return True

    @staticmethod
    def next_run_str(profile: dict) -> str:
        """Human-readable description of when the next run is due."""
        if not profile.get("schedule_enabled"):
            return "Disabled"
        t = profile.get("schedule_time", "02:00")
        stype = profile.get("schedule_type", "daily")
        if stype == "weekly":
            day = profile.get("schedule_day", "monday").capitalize()
            return f"Weekly — {day} at {t}"
        return f"Daily at {t}"
# ===== SNAPSMACK EOF =====
