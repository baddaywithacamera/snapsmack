"""Installed SYBU must be allowed to use the real shared profile home."""

import importlib
import os
import sys

import sybu_launcher


def test_frozen_launcher_allows_real_profile_home(monkeypatch):
    monkeypatch.delenv("SNAPSMACK_ALLOW_REAL_HOME_IN_TESTS", raising=False)
    monkeypatch.setattr(sys, "frozen", True, raising=False)

    importlib.reload(sybu_launcher)

    assert os.environ["SNAPSMACK_ALLOW_REAL_HOME_IN_TESTS"] == "1"


# ===== SNAPSMACK EOF =====
