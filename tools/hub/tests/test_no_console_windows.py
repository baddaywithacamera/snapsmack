"""Sean's desktop must stay his. No worker child pops a console window.

He reported console windows appearing while the agents and the application
work, stealing focus and making the machine unusable. Everything launched
through subprocess_limits.run() is a worker with nothing to show -- a RAW
decoder, a diffusion runner, a face detector. Two callers passed
CREATE_NO_WINDOW by hand and the rest did not, which is the wrong way round.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import os
import subprocess
import sys

sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))

import subprocess_limits


class _FakeProcess:
    pid = 1234
    returncode = 0

    def communicate(self, timeout=None):
        return (b"", b"")


def test_a_worker_gets_no_console_window(monkeypatch):
    seen = {}

    def fake_popen(args, **kwargs):
        seen.update(kwargs)
        return _FakeProcess()

    monkeypatch.setattr(subprocess_limits.subprocess, "Popen", fake_popen)
    monkeypatch.setattr(subprocess_limits.os, "name", "nt")
    monkeypatch.setattr(subprocess_limits, "_assign_windows_job",
                        lambda p, m: (None, None))

    subprocess_limits.run(["worker.exe"], timeout=5, memory_bytes=1024)
    assert seen.get("creationflags") == subprocess.CREATE_NO_WINDOW, \
        "a worker child was launched with a visible console window"


def test_a_caller_that_wants_a_console_still_gets_one(monkeypatch):
    """The installers deliberately show progress. Do not take that away."""
    seen = {}

    def fake_popen(args, **kwargs):
        seen.update(kwargs)
        return _FakeProcess()

    monkeypatch.setattr(subprocess_limits.subprocess, "Popen", fake_popen)
    monkeypatch.setattr(subprocess_limits.os, "name", "nt")
    monkeypatch.setattr(subprocess_limits, "_assign_windows_job",
                        lambda p, m: (None, None))

    subprocess_limits.run(["installer.exe"], timeout=5, memory_bytes=1024,
                          creationflags=subprocess.CREATE_NEW_CONSOLE)
    assert seen.get("creationflags") == subprocess.CREATE_NEW_CONSOLE
# ===== SNAPSMACK EOF =====
