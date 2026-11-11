"""Every snap_home.<name>() a tool calls must actually exist.

FOUND IN SHIPPING CODE, 2026-11-11. tools/gyss/gyss_qt.py called
snap_home.app_dir("coldsnap") in two places to build the COLD SNAP exe path.
snap_home has app_out_dir, install_dir and config_dir -- it has never had
app_dir. Both call sites raised AttributeError, so GYSS's "send to COLD SNAP"
and "send selected to COLD TAKE" buttons crashed the moment they were pressed.

The first site even had a careful "COLD SNAP is not installed" dialog naming the
path it looked for. The owner would never have seen it: the crash happened one
line earlier, while building that path.

WHY A TEST AND NOT JUST A FIX. Python resolves an attribute at call time, so a
misspelled helper is invisible until a human presses that exact button. These
are desktop tools the owner runs by hand, one feature at a time, so a typo can
sit in a shipped build for weeks and surface as "it just closes". A name check
is cheap and it covers the whole family rather than the one that was noticed.

This asserts the CALLS, not a list of blessed names: add a helper to snap_home
and nothing here needs touching; call one that does not exist and this fails.

Run:  python -m unittest tools.tests.test_snap_home_calls_exist
      python tools/tests/test_snap_home_calls_exist.py
"""

import ast
import os
import sys
import unittest

_HERE = os.path.dirname(os.path.abspath(__file__))
_TOOLS = os.path.dirname(_HERE)
_SHARED = os.path.join(_TOOLS, "_shared")

if _SHARED not in sys.path:
    sys.path.insert(0, _SHARED)

import snap_home  # noqa: E402  (path set above, by design)

# Parsed with ast, NOT matched with a regex. A regex over the text found
# "snap_home.py(" inside snap_home.py's own docstring and reported it as a
# missing helper -- a test that cries wolf about prose gets switched off. The
# AST sees only real attribute access on the real name.

# Directories that are not shipped tool code: an abandoned port, build output,
# and virtualenvs. Listing them explicitly rather than skipping anything that
# fails, so a real tool can never be excluded by accident.
_SKIP_PARTS = (
    os.sep + "src-tauri" + os.sep,
    os.sep + "node_modules" + os.sep,
    os.sep + "build" + os.sep,
    os.sep + "dist" + os.sep,
    os.sep + ".venv" + os.sep,
    os.sep + "__pycache__" + os.sep,
)


def _python_files():
    for root, dirs, files in os.walk(_TOOLS):
        dirs[:] = [d for d in dirs if d not in ("__pycache__", "node_modules", ".venv")]
        for name in files:
            if not name.endswith(".py"):
                continue
            path = os.path.join(root, name)
            if any(part in path for part in _SKIP_PARTS):
                continue
            yield path


class SnapHomeCallsExist(unittest.TestCase):
    def test_every_called_helper_exists(self):
        missing = []
        seen = set()
        for path in _python_files():
            try:
                with open(path, encoding="utf-8", errors="replace") as fh:
                    src = fh.read()
            except OSError:
                continue
            try:
                tree = ast.parse(src)
            except SyntaxError:
                # A file this interpreter cannot parse is not something this test
                # can judge. Named, not silently skipped.
                missing.append(
                    "{} could not be parsed, so its snap_home calls were NOT checked".format(
                        os.path.relpath(path, _TOOLS)
                    )
                )
                continue

            for node in ast.walk(tree):
                if not isinstance(node, ast.Call):
                    continue
                func = node.func
                if not isinstance(func, ast.Attribute):
                    continue
                if not isinstance(func.value, ast.Name) or func.value.id != "snap_home":
                    continue
                name = func.attr
                seen.add(name)
                if not hasattr(snap_home, name):
                    missing.append(
                        "{}:{} calls snap_home.{}() which does not exist".format(
                            os.path.relpath(path, _TOOLS), node.lineno, name
                        )
                    )

        self.assertTrue(seen, "no snap_home calls found at all -- the scan is broken, "
                              "not the code")
        self.assertEqual(
            [], missing,
            "A tool calls a snap_home helper that is not defined. Python only "
            "notices when the owner presses that button, so this ships silently:"
            "\n  " + "\n  ".join(missing)
        )

    def test_the_known_regression_stays_fixed(self):
        """app_dir is the exact name that was wrong; keep it named here."""
        self.assertFalse(
            hasattr(snap_home, "app_dir"),
            "snap_home.app_dir now exists. If it was added deliberately, delete "
            "this assertion; if not, something re-introduced the name this test "
            "was written for."
        )
        gyss = os.path.join(_TOOLS, "gyss", "gyss_qt.py")
        with open(gyss, encoding="utf-8", errors="replace") as fh:
            src = fh.read()
        self.assertNotIn(
            "snap_home.app_dir(", src,
            "GYSS is calling snap_home.app_dir() again; the COLD SNAP and COLD "
            "TAKE buttons crash with AttributeError when it does."
        )
        self.assertIn(
            'snap_home.install_dir("coldsnap")', src,
            "GYSS should resolve the COLD SNAP exe through install_dir(), which "
            "is documented as where a tool installs its exe."
        )


if __name__ == "__main__":
    unittest.main(verbosity=2)

# ===== SNAPSMACK EOF =====
