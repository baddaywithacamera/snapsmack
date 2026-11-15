"""SS-1350: a switchable DEV LOG, shared by every SnapSmack desktop tool.

Sean's acceptance is "flip it on, reproduce the idle memory swing and the busy
cursor, and the log shows what fired". These tests prove the switch works, the
level actually moves without a restart, the log cannot grow without bound, and
-- the part worth being strict about -- that nothing secret can reach the file
through the tracing helpers.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import logging
import os
import sys

os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")
sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))
sys.path.insert(0, os.path.join(
    os.path.dirname(os.path.dirname(os.path.dirname(__file__))), "_shared"))

import pytest

import snap_log


@pytest.fixture()
def clean_log(tmp_path, monkeypatch):
    """A private switch file and log folder, and a fresh logger registry."""
    switch = str(tmp_path / "dev_log.json")
    logs = str(tmp_path / "logs")
    os.makedirs(logs, exist_ok=True)
    monkeypatch.setattr(snap_log, "_switch_path", lambda: switch)
    monkeypatch.setattr(snap_log.snap_home, "logs_dir", lambda: logs)
    monkeypatch.setattr(
        snap_log.snap_home, "log_path",
        lambda tool, logname="run", when=None: os.path.join(
            logs, f"{tool}_{logname}.log"))
    monkeypatch.setattr(snap_log, "_LOGGERS", {})
    monkeypatch.setattr(snap_log, "_PRIMARY", None)
    for name in list(logging.Logger.manager.loggerDict):
        if name.startswith("snapsmack.devlogtest"):
            logging.Logger.manager.loggerDict.pop(name, None)
    yield logs


def _read(logs):
    text = ""
    for name in sorted(os.listdir(logs)):
        with open(os.path.join(logs, name), "r", encoding="utf-8") as handle:
            text += handle.read()
    return text


def test_off_by_default_and_trace_writes_nothing(clean_log):
    assert snap_log.dev_log_enabled() is False
    logger = snap_log.setup("devlogtest")
    assert logger.level == logging.INFO
    snap_log.trace("devlogtest", "pool.task.start", file="x.jpg")
    assert "pool.task.start" not in _read(clean_log)


def test_on_records_the_trace_with_a_timestamp_and_the_pid(clean_log):
    assert snap_log.set_dev_log(True) is True
    assert snap_log.dev_log_enabled() is True
    snap_log.setup("devlogtest")
    snap_log.trace("devlogtest", "child.start", exe="xpano.exe", pid=4242)
    text = _read(clean_log)
    assert "child.start" in text and "exe=xpano.exe" in text
    assert f"pid{os.getpid()}" in text, "the logging process id is not in the line"
    assert "20" in text.split()[0], "no date stamp on the line"


def test_the_switch_moves_a_running_logger_without_a_restart(clean_log):
    """The point of the ticket: Sean flips it while the fault is happening."""
    logger = snap_log.setup("devlogtest")
    assert logger.level == logging.INFO
    snap_log.set_dev_log(True)
    assert logger.level == logging.DEBUG, "the already-running logger did not move"
    snap_log.trace("devlogtest", "window.open", window="library")
    assert "window.open" in _read(clean_log)
    snap_log.set_dev_log(False)
    assert logger.level == logging.INFO
    snap_log.trace("devlogtest", "window.close", window="library")
    assert "window.close" not in _read(clean_log), "tracing continued after off"


def test_a_left_on_log_cannot_fill_the_disk(clean_log):
    import logging.handlers
    snap_log.set_dev_log(True)
    logger = snap_log.setup("devlogtest")
    rotating = [h for h in logger.handlers
                if isinstance(h, logging.handlers.RotatingFileHandler)]
    assert rotating, "the DEV LOG file handler does not rotate"
    assert rotating[0].maxBytes == snap_log.DEV_LOG_MAX_BYTES
    assert rotating[0].backupCount == snap_log.DEV_LOG_BACKUPS
    total = snap_log.DEV_LOG_MAX_BYTES * (snap_log.DEV_LOG_BACKUPS + 1)
    assert total <= 32 * 1024 * 1024, f"worst case is {total} bytes"


def test_a_long_value_is_truncated_so_one_line_cannot_be_a_payload(clean_log):
    snap_log.set_dev_log(True)
    snap_log.setup("devlogtest")
    snap_log.trace("devlogtest", "child.start", exe="x" * 5000)
    line = [l for l in _read(clean_log).splitlines() if "child.start" in l][0]
    assert len(line) < 500, "a trace line is not bounded"
    assert line.rstrip().endswith("...")


def test_a_span_logs_both_ends_and_does_not_swallow_the_error(clean_log):
    snap_log.set_dev_log(True)
    snap_log.setup("devlogtest")
    with pytest.raises(ValueError):
        with snap_log.trace_span("devlogtest", "render", file="a.jpg"):
            raise ValueError("boom")
    text = _read(clean_log)
    assert "render.start" in text
    assert "render.end" in text and "outcome=raised:ValueError" in text
    assert "boom" not in text, "the exception message leaked into the trace"


def test_the_switch_is_shared_not_per_tool(clean_log):
    """One switch for SNAP SLAPPER, SNAP HQ, LEWK AGAIN and the rest."""
    snap_log.set_dev_log(True)
    first = snap_log.setup("devlogtest")
    second = snap_log.setup("devlogtest2")
    assert first.level == second.level == logging.DEBUG


def test_an_unwritable_switch_is_reported_not_raised(clean_log, monkeypatch):
    monkeypatch.setattr(snap_log, "_switch_path",
                        lambda: os.path.join(clean_log, "nope", "x", "d.json"))
    monkeypatch.setattr(snap_log.os, "makedirs",
                        lambda *a, **k: (_ for _ in ()).throw(OSError("read-only")))
    assert snap_log.set_dev_log(True) is False


def test_the_preferences_dialog_offers_the_toggle_and_the_folder_button():
    """It is not a feature if nobody can reach it."""
    import inspect
    from slapper_qt import prefs_dialog
    source = inspect.getsource(prefs_dialog.PreferencesDialog)
    assert "DEV LOG" in source
    assert "OPEN LOG FOLDER" in source
    assert "_toggle_dev_log" in source and "set_dev_log" in \
        inspect.getsource(prefs_dialog.PreferencesDialog._toggle_dev_log)
# ===== SNAPSMACK EOF =====
