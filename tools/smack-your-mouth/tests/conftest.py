"""Sandbox SNAPSMACK_HOME to a temp directory for every test in this suite."""

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.

import pytest


@pytest.fixture(autouse=True)
def _sandbox_snapsmack_home(tmp_path_factory, monkeypatch):
    sandbox = tmp_path_factory.mktemp("snapsmack_home")
    monkeypatch.setenv("SNAPSMACK_HOME", str(sandbox))

# ===== SNAPSMACK EOF =====
