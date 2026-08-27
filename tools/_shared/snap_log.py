"""
SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
snap_log — one shared logger + crash capture for every SnapSmack desktop tool.

Until now nothing wrote a log: handled errors flashed a dialog and vanished,
and an uncaught exception closed a windowed .exe with nothing left behind. This
module fixes both. Logs land in the family-wide C:\\snapsmack\\logs (via
snap_home.log_path), so support and debugging have something to read.

USAGE — call once at program start, before the UI opens:

    import snap_log
    log = snap_log.setup("snap_slapper")          # writes C:\\snapsmack\\logs\\...
    ...
    try:
        risky()
    except Exception:
        log.exception("import failed")            # full traceback in the log

Uncaught exceptions (main thread and worker threads) are logged automatically
after setup(). Framework-agnostic: no tkinter or Qt import here, so Tk tools,
the Qt SNAP SLAPPER, and headless scripts can all use it.
"""
from __future__ import annotations

import datetime
import logging
import os
import re
import sys
import threading

try:  # snap_home gives the family-wide log directory; fall back if absent
    import snap_home
except Exception:  # noqa: BLE001
    snap_home = None

_LOGGERS: dict[str, logging.Logger] = {}
_PRIMARY: logging.Logger | None = None
_FORMAT = "%(asctime)s %(levelname)-8s %(name)s: %(message)s"


def _fallback_log_dir() -> str:
    base = os.environ.get("LOCALAPPDATA") or os.path.expanduser("~")
    path = os.path.join(base, "SnapSmack", "logs")
    os.makedirs(path, exist_ok=True)
    return path


def _tool_key(tool: str) -> str:
    return re.sub(r"[^A-Za-z0-9_.-]", "-", (tool or "app").strip()).strip(".-").lower() or "app"


def _log_file(tool: str, logname: str) -> str:
    if snap_home is not None:
        try:
            return snap_home.log_path(tool, logname)
        except Exception:  # noqa: BLE001
            pass
    stamp = datetime.datetime.now().strftime("%Y-%m-%d-%H-%M")
    return os.path.join(_fallback_log_dir(),
                        f"{_tool_key(tool)}_{_tool_key(logname)}_{stamp}.log")


def _prune(tool: str, keep: int = 25) -> None:
    """Keep only the most recent `keep` log files for this tool."""
    try:
        directory = snap_home.logs_dir() if snap_home is not None else _fallback_log_dir()
        prefix = _tool_key(tool) + "_"
        files = [os.path.join(directory, name) for name in os.listdir(directory)
                 if name.lower().startswith(prefix) and name.lower().endswith(".log")]
        files.sort(key=os.path.getmtime, reverse=True)
        for stale in files[keep:]:
            try:
                os.remove(stale)
            except OSError:
                pass
    except Exception:  # noqa: BLE001 — pruning must never break startup
        pass


def _install_excepthooks(logger: logging.Logger) -> None:
    def hook(exc_type, exc, tb):
        if issubclass(exc_type, KeyboardInterrupt):
            sys.__excepthook__(exc_type, exc, tb)
            return
        logger.critical("Uncaught exception", exc_info=(exc_type, exc, tb))
        sys.__excepthook__(exc_type, exc, tb)

    sys.excepthook = hook

    # Python 3.8+: log exceptions that escape worker threads too.
    def thread_hook(args):
        if issubclass(args.exc_type, KeyboardInterrupt):
            return
        logger.critical("Uncaught exception in thread %s", args.thread and args.thread.name,
                        exc_info=(args.exc_type, args.exc_value, args.exc_traceback))

    try:
        threading.excepthook = thread_hook
    except Exception:  # noqa: BLE001
        pass


def setup(tool: str, logname: str = "run", *, level: int = logging.INFO,
          console: bool = True) -> logging.Logger:
    """Configure and return the logger for `tool`, and start capturing crashes.

    Safe to call more than once — the same logger is returned and reused.
    """
    if tool in _LOGGERS:
        return _LOGGERS[tool]

    logger = logging.getLogger(f"snapsmack.{_tool_key(tool)}")
    logger.setLevel(level)
    logger.propagate = False
    for handler in list(logger.handlers):
        logger.removeHandler(handler)

    path = _log_file(tool, logname)
    try:
        file_handler = logging.FileHandler(path, encoding="utf-8")
        file_handler.setFormatter(logging.Formatter(_FORMAT))
        logger.addHandler(file_handler)
    except Exception:  # noqa: BLE001 — never let logging setup crash the app
        path = "(file log unavailable)"

    if console:
        stream = logging.StreamHandler()
        stream.setFormatter(logging.Formatter(_FORMAT))
        logger.addHandler(stream)

    logger.info("===== %s starting (pid %s) =====", tool, os.getpid())
    logger.info("log file: %s", path)
    _install_excepthooks(logger)
    _prune(tool)
    _LOGGERS[tool] = logger
    global _PRIMARY
    if _PRIMARY is None:
        _PRIMARY = logger
    return logger


def get(tool: str) -> logging.Logger:
    """The logger for `tool` (already configured by setup(), else a bare one)."""
    return _LOGGERS.get(tool) or logging.getLogger(f"snapsmack.{_tool_key(tool)}")


def primary() -> logging.Logger | None:
    """The first logger configured this run (for shared code like snap_errors
    that doesn't know which tool it's running inside). None if setup() unused."""
    return _PRIMARY
# ===== SNAPSMACK EOF =====
