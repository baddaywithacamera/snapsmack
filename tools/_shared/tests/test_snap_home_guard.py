"""Tests for SS-1443: snap_home refuses the real C:\\snapsmack profile during tests."""

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.

import os
import sys

import pytest

SHARED_DIR = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
if SHARED_DIR not in sys.path:
    sys.path.insert(0, SHARED_DIR)

import snap_home  # noqa: E402


def test_snap_home_refuses_unset_env_during_tests(monkeypatch):
    monkeypatch.delenv("SNAPSMACK_HOME", raising=False)
    monkeypatch.delenv("SNAPSMACK_ALLOW_REAL_HOME_IN_TESTS", raising=False)
    with pytest.raises(RuntimeError, match="Refusing to access real profile path"):
        snap_home.home()


def test_snap_home_refuses_explicit_real_profile_during_tests(monkeypatch):
    monkeypatch.setenv("SNAPSMACK_HOME", r"C:\snapsmack")
    monkeypatch.delenv("SNAPSMACK_ALLOW_REAL_HOME_IN_TESTS", raising=False)
    with pytest.raises(RuntimeError, match="Refusing to access real profile path"):
        snap_home.home()


def test_snap_home_allows_temp_directory(tmp_path, monkeypatch):
    sandbox = tmp_path / "snapsmack_sandbox"
    monkeypatch.setenv("SNAPSMACK_HOME", str(sandbox))
    assert snap_home.home() == os.path.abspath(str(sandbox))


def test_snap_home_allows_frozen_exe_even_when_unittest_imported(monkeypatch):
    monkeypatch.delenv("SNAPSMACK_HOME", raising=False)
    monkeypatch.delenv("SNAPSMACK_ALLOW_REAL_HOME_IN_TESTS", raising=False)
    monkeypatch.delenv("PYTEST_CURRENT_TEST", raising=False)
    monkeypatch.setattr(sys, "frozen", True, raising=False)
    assert snap_home.home() == os.path.abspath(r"C:\snapsmack")

# ===== SNAPSMACK EOF =====
