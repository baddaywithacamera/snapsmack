# SNAPSMACK_EOF_HEADER: this file must end with the canonical Python EOF marker.
"""Put SYBU's own directory on sys.path for its tests.

EIGHTEEN TESTS IN THIS FOLDER WERE NOT RUNNING. They import SYBU's sibling
modules by bare name -- `import gemini`, `import sybu_core`, `import
manifest_parser` -- with no sys.path setup, so they depended on being launched
with tools/sybu as the working directory AND on that directory landing on
sys.path. pytest inserts the test file's own folder, not its parent, so they
raised ModuleNotFoundError during collection and were reported as ERRORS
rather than failures. An error in a collection summary reads like
infrastructure noise, and 101 passes next to it reads like a green suite.

Found while adding coverage for 0.7.77: the same 18 errored on every commit I
tried, from the repository root and from tools/sybu, with and without
PYTHONPATH. They are not caused by that change.

The newer tests here each insert the path themselves (test_qt_prompt_and_queue
does). This makes that unnecessary rather than wrong -- both work, and nothing
has to be edited to benefit.
"""

import os
import sys

TOOL = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
if TOOL not in sys.path:
    sys.path.insert(0, TOOL)

SHARED = os.path.abspath(os.path.join(TOOL, "..", "_shared"))
if os.path.isdir(SHARED) and SHARED not in sys.path:
    sys.path.insert(0, SHARED)


import pytest


@pytest.fixture(autouse=True)
def _sandbox_snapsmack_home(tmp_path_factory, monkeypatch):
    sandbox = tmp_path_factory.mktemp("snapsmack_home")
    monkeypatch.setenv("SNAPSMACK_HOME", str(sandbox))

# ===== SNAPSMACK EOF =====
