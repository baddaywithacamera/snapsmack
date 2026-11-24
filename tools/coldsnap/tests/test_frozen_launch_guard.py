"""Frozen COLD SNAP builds must never trip snap_home's test guard at startup."""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.

import os
import sys
import unittest.mock  # noqa: F401 — simulates pyi_rth_setuptools transitive import

import snap_home


def test_frozen_coldsnap_never_trips_snap_home_test_guard(monkeypatch):
    """PyInstaller's pyi_rth_setuptools imports setuptools -> _msvccompiler ->
    unittest.mock before coldsnap_qt_launcher runs. A frozen build must never
    treat that as a test runner and must allow snap_home.home() to resolve."""
    monkeypatch.delenv("SNAPSMACK_HOME", raising=False)
    monkeypatch.delenv("SNAPSMACK_ALLOW_REAL_HOME_IN_TESTS", raising=False)
    monkeypatch.setattr(sys, "frozen", True, raising=False)
    assert "unittest" in sys.modules
    assert snap_home._is_test_process() is False
    assert os.path.normcase(snap_home.home()) == os.path.normcase(os.path.abspath(r"C:\snapsmack"))

# ===== SNAPSMACK EOF =====
