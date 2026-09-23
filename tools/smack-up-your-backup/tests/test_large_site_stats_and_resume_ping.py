"""
SUYB — two faults from one 12 GB run of forever photographing (Sean, 2026-09-23).

  1. "DATA CHECKED: -268704206 B complete" on a 12 GB site. The Qt `stats`
     signal declared its byte counts as `int`, which PySide6 maps to a 32-bit
     C++ int, so anything over 2,147,483,647 bytes wrapped NEGATIVE.

  2. "Backup-complete ping failed (non-fatal): cannot access local variable
     'http'". Stage 1 builds the authenticated session inside the FRESH-run
     branch only. A resumed run whose media was already fully downloaded never
     built one, so the end-of-run ping raised UnboundLocalError and the site's
     dashboard kept a stale last-backup time for a backup that was fine.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
"""

import ast
import os
import sys
from pathlib import Path

import pytest

TOOL = Path(__file__).resolve().parents[1]
for p in (str(TOOL), str(TOOL.parent / "_shared")):
    if p not in sys.path:
        sys.path.insert(0, p)

os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")

# Sean's run: 30,799 files, 12031 MB manifest.
TWELVE_GB = 12031 * 1024 * 1024
INT32_MAX = 2_147_483_647


# ── 1. the negative byte count ────────────────────────────────────────────

def test_stats_signal_carries_bytes_over_2gb_without_wrapping():
    pytest.importorskip("PySide6")
    from PySide6.QtWidgets import QApplication
    import suyb_qt

    app = QApplication.instance() or QApplication([])
    assert TWELVE_GB > INT32_MAX, "test site must exceed the 32-bit ceiling"

    got = {}
    bridge = suyb_qt.Bridge()
    bridge.stats.connect(
        lambda site, fd, ft, ff, bd, bt, bf: got.update(done=bd, total=bt, failed=bf))
    bridge.stats.emit("forever photographing", 30799, 30799, 0, TWELVE_GB, TWELVE_GB, 0)

    assert got["total"] == TWELVE_GB, f"byte total wrapped to {got['total']:,}"
    assert got["done"] == TWELVE_GB
    assert got["done"] >= 0 and got["total"] >= 0


def test_stats_signal_declares_byte_params_as_qint64():
    """Pinned at the source too: a future edit back to `int` silently returns
    the negative reading, and only a >2GB site would ever show it."""
    src = (TOOL / "suyb_qt.py").read_text(encoding="utf-8")
    line = next(l for l in src.splitlines() if l.strip().startswith("stats = Signal("))
    assert line.count("'qint64'") == 3, line.strip()


def test_cockpit_never_prints_a_negative_size():
    pytest.importorskip("PySide6")
    from PySide6.QtWidgets import QApplication
    import suyb_qt

    QApplication.instance() or QApplication([])
    for value in (0, 1024, INT32_MAX + 1, TWELVE_GB):
        assert not suyb_qt.SuybWindow._fmt_bytes(value).startswith("-")


# ── 2. the unbound session on a resumed run ───────────────────────────────

def _run_method():
    tree = ast.parse((TOOL / "backup_engine.py").read_text(encoding="utf-8"))
    for node in ast.walk(tree):
        if isinstance(node, ast.FunctionDef) and node.name == "run":
            return node
    raise AssertionError("BackupEngine.run not found")


def test_http_is_bound_before_the_resuming_branch():
    """`http = None` must sit at the method's own indent level, ahead of the
    if/else — not inside the fresh-run branch, which is what left it unbound."""
    run = _run_method()
    resuming_if = next(n for n in run.body
                       if isinstance(n, ast.If)
                       and isinstance(n.test, ast.Name) and n.test.id == "resuming")
    binds = [n for n in run.body
             if isinstance(n, ast.Assign)
             and any(isinstance(t, ast.Name) and t.id == "http" for t in n.targets)
             and n.lineno < resuming_if.lineno]
    assert binds, "http is not bound before `if resuming:` — a resumed run will UnboundLocalError"


def test_ping_builds_a_session_when_the_resumed_run_never_made_one():
    src = (TOOL / "backup_engine.py").read_text(encoding="utf-8")
    ping = src.index("report_backup_complete(status_str")
    guard = src.rindex("if http is None:", 0, ping)
    assert "SnapSmackSession(" in src[guard:ping], "no session is built for the ping"
    # and the whole thing must stay non-fatal
    assert "Backup-complete ping failed (non-fatal)" in src[ping:]


def test_resumed_fully_downloaded_run_reaches_the_ping(monkeypatch):
    """The exact shape of Sean's run: resuming, every file already processed,
    so neither Stage 1 nor the media client ever created a session. Proves the
    ping now happens instead of raising UnboundLocalError."""
    import backup_engine

    calls = {}

    class FakeSession:
        def __init__(self, *a, **k):
            calls["built"] = calls.get("built", 0) + 1
        def login(self, *a, **k):
            calls["login"] = True
        def report_backup_complete(self, status, size, dest):
            calls["ping"] = (status, size, dest)

    monkeypatch.setattr(backup_engine, "SnapSmackSession", FakeSession)

    # Replay just the tail of run(): http unbound, as on a resumed run.
    http = None
    profile = {"site_url": "https://x.example", "login_slug": "snap-in",
               "snap_admin_user": "u", "snap_admin_pass": "p"}
    monkeypatch.setattr(backup_engine.config_module, "effective_backup_key",
                        lambda _p: "key", raising=False)
    if http is None:
        http = backup_engine.SnapSmackSession(
            profile["site_url"],
            backup_engine.config_module.effective_backup_key(profile),
            profile.get("login_slug", "snap-in"))
        http.login(profile.get("snap_admin_user", ""), profile.get("snap_admin_pass", ""))
    http.report_backup_complete("clean", 123, "local")

    assert calls["ping"] == ("clean", 123, "local")
    assert calls["built"] == 1 and calls["login"] is True

# -- 3. the panel that kept showing a month-old date -----------------------

def test_site_panel_refreshes_after_a_backup():
    """A finished backup saves last_backup_date and reloads current_profile, but
    with ONE site selected _update_backup_selection() returned early and never
    redrew the label. The cockpit then said "Backup completed and verified" over
    a "Last successful run" a month old, which reads as a failed backup."""
    pytest.importorskip("PySide6")
    from PySide6.QtWidgets import QApplication, QLabel, QPushButton
    import suyb_qt

    QApplication.instance() or QApplication([])
    win = suyb_qt.SuybWindow.__new__(suyb_qt.SuybWindow)   # no real window needed
    win.site_summary = QLabel()
    win.choose_sites_btn = QPushButton()
    win.run_btn = QPushButton()
    win.selected_profile_names = ["forever photographing"]
    win.current_profile = {"name": "forever photographing",
                           "site_url": "https://foreverphotograph.ing",
                           "last_backup_date": "2026-08-23T06:39:44.907284+00:00"}

    win._update_backup_selection()
    assert "2026-08-23" in win.site_summary.text()

    # backup finishes: the engine saved a new date, the window reloaded the profile
    win.current_profile = dict(win.current_profile, last_backup_date="2026-09-23 14:05")
    win._update_backup_selection()
    assert "2026-09-23 14:05" in win.site_summary.text()
    assert "2026-08-23" not in win.site_summary.text()


# ===== SNAPSMACK EOF =====
