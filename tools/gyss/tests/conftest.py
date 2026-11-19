"""Sandbox SNAPSMACK_HOME to a temp directory for every GYSS test."""

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.

import pytest


@pytest.fixture(autouse=True)
def _sandbox_snapsmack_home(tmp_path, monkeypatch):
    sandbox = tmp_path / "snapsmack_home"
    sandbox.mkdir(parents=True, exist_ok=True)
    monkeypatch.setenv("SNAPSMACK_HOME", str(sandbox))

# ===== SNAPSMACK EOF =====
