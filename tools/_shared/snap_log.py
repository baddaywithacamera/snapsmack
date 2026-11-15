"""Small shared file logger for standalone SnapSmack desktop tools.

SS-1350 added a DEV LOG: one switch, stored once, honoured by every tool that
uses this module. On means DEBUG and a rotating file that cannot fill the
disk; off means the INFO behaviour this module always had. No restart either
way -- set_dev_log() moves the level on every logger already configured, so a
toggle takes effect on the next thing that happens.

NOTHING SECRET IS EVER LOGGED HERE. The helpers below record what ran, when,
for how long and with what exit code. They deliberately do not take a payload
argument, so there is no path by which a key, a token or a request body
reaches the file through them.
"""

import json
import logging
import logging.handlers
import os
import sys
import time

import snap_home


_LOGGERS = {}
_PRIMARY = None   # first logger configured this run (recovered post-652D-merge)

# A left-on DEV LOG must not fill Sean's disk. Five files of four megabytes is
# twenty megabytes of worst case and still days of ordinary use.
DEV_LOG_MAX_BYTES = 4 * 1024 * 1024
DEV_LOG_BACKUPS = 4


def _switch_path():
    """Where the DEV LOG switch lives: beside the shared layout, not inside any
    one tool's preferences, because the switch is for the whole family."""
    try:
        return os.path.join(snap_home.config_dir(), "dev_log.json")
    except Exception:  # noqa: BLE001
        root = os.environ.get("SNAPSMACK_HOME") or r"C:\snapsmack"
        return os.path.join(root, "config_files", "dev_log.json")


def dev_log_enabled():
    """Is the DEV LOG on? Unreadable or missing means off, never an error."""
    try:
        with open(_switch_path(), "r", encoding="utf-8") as handle:
            return bool(json.load(handle).get("enabled", False))
    except Exception:  # noqa: BLE001
        return False


def set_dev_log(enabled):
    """Turn the DEV LOG on or off for every SnapSmack desktop tool, now.

    Writes the shared switch and moves the level on every logger already
    configured in THIS process, so the running application starts or stops
    tracing without a restart. Other running tools pick it up when they next
    call setup(); that is the documented limit, not an accident.
    """
    enabled = bool(enabled)
    path = _switch_path()
    try:
        os.makedirs(os.path.dirname(path), exist_ok=True)
        with open(path, "w", encoding="utf-8") as handle:
            json.dump({"enabled": enabled}, handle)
    except OSError:
        return False
    level = logging.DEBUG if enabled else logging.INFO
    for logger in _LOGGERS.values():
        logger.setLevel(level)
        for handler in logger.handlers:
            handler.setLevel(level)
    return True


def log_folder():
    """The folder the OPEN LOG FOLDER button opens."""
    return snap_home.logs_dir()


def setup(tool, logname="run"):
    """Create one UTF-8 per-run log under the shared SnapSmack log folder."""
    key = f"snapsmack.{tool}"
    if key in _LOGGERS:
        return _LOGGERS[key]
    dev = dev_log_enabled()
    level = logging.DEBUG if dev else logging.INFO
    logger = logging.getLogger(key)
    logger.setLevel(level)
    logger.propagate = False
    path = snap_home.log_path(tool, logname)
    os.makedirs(os.path.dirname(path), exist_ok=True)
    # The PID is in every line when the DEV LOG is on. Two SNAP SLAPPER
    # windows are two processes, and "which one did that" is unanswerable
    # afterwards without it.
    formatter = logging.Formatter(
        "%(asctime)s.%(msecs)03d %(levelname)s pid%(process)d %(name)s %(message)s"
        if dev else "%(asctime)s %(levelname)s %(name)s %(message)s",
        datefmt="%Y-%m-%d %H:%M:%S")
    # Rotation only matters for the DEV LOG: an INFO run log is tiny and
    # already per-run, and rotating it would be a behaviour change nobody
    # asked for.
    if dev:
        file_handler = logging.handlers.RotatingFileHandler(
            path, maxBytes=DEV_LOG_MAX_BYTES, backupCount=DEV_LOG_BACKUPS,
            encoding="utf-8")
    else:
        file_handler = logging.FileHandler(path, encoding="utf-8")
    file_handler.setLevel(level)
    file_handler.setFormatter(formatter)
    logger.addHandler(file_handler)
    if getattr(sys, "stderr", None):
        stream = logging.StreamHandler(sys.stderr)
        stream.setFormatter(formatter)
        logger.addHandler(stream)
    logger.log_path = path
    _LOGGERS[key] = logger
    global _PRIMARY
    if _PRIMARY is None:
        _PRIMARY = logger
    return logger


def get(tool):
    return _LOGGERS.get(f"snapsmack.{tool}") or setup(tool)


def trace(tool, event, **fields):
    """One DEV LOG line: an event name and plain scalar fields.

    Every instrumentation point goes through here so the format is uniform and
    greppable, and so there is exactly one place that decides what a trace line
    may contain. Values are coerced with str() and truncated; no argument is
    ever a payload, a key or a credential, and callers pass identifiers and
    durations only.
    """
    logger = get(tool)
    if not logger.isEnabledFor(logging.DEBUG):
        return                      # cost of the DEV LOG when it is off
    parts = []
    for name, value in fields.items():
        text = str(value)
        if len(text) > 300:
            text = text[:297] + "..."
        parts.append(f"{name}={text}")
    logger.debug("%s %s", event, " ".join(parts))


class trace_span:
    """Time something and log both ends, including when it raises.

    with snap_log.trace_span("snap-slapper", "render", file=path):
        ...
    """

    def __init__(self, tool, event, **fields):
        self.tool, self.event, self.fields = tool, event, fields
        self.started = 0.0

    def __enter__(self):
        self.started = time.monotonic()
        trace(self.tool, self.event + ".start", **self.fields)
        return self

    def __exit__(self, exc_type, exc, tb):
        ms = round((time.monotonic() - self.started) * 1000, 1)
        trace(self.tool, self.event + ".end", ms=ms,
              outcome="raised:" + exc_type.__name__ if exc_type else "ok",
              **self.fields)
        return False                # never swallow


def primary():
    """The first logger configured this run (for shared code like snap_errors
    that doesn't know which tool it's running inside). None if setup() unused."""
    return _PRIMARY


# ===== SNAPSMACK EOF =====
