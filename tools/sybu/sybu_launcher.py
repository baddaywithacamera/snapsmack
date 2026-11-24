"""Qt entry point for SMACK YOUR BATCH UP."""

import os
import sys


def _prepare_frozen_profile_home():
    """Do not let test-runner imports misclassify the packaged desktop app.

    PyInstaller bundles modules that can import ``unittest`` during ordinary
    application startup.  The shared profile guard quite correctly refuses
    C:\\snapsmack when it believes it is inside a test process, but a frozen
    SYBU executable is the installed application, not a test runner.
    """
    if getattr(sys, "frozen", False):
        os.environ.setdefault("SNAPSMACK_ALLOW_REAL_HOME_IN_TESTS", "1")


_prepare_frozen_profile_home()


# Headless automation must run before the desktop event loop or log capture.
import os as _cli_os, sys as _cli_sys
if "--cli" in _cli_sys.argv:
    _cli_sys.path.insert(0, _cli_os.path.abspath(_cli_os.path.join(_cli_os.path.dirname(__file__), "..", "_shared")))
    import app_cli as _desktop_cli
    raise SystemExit(_desktop_cli.main(_cli_sys.argv[_cli_sys.argv.index("--cli") + 1:]))

def main():
    try:
        import snap_single_instance
        if not snap_single_instance.acquire("sybu", "SMACK YOUR BATCH UP"):
            return 0
    except Exception:
        pass
    from sybu_qt import run
    return run()


if __name__ == "__main__":
    # PyInstaller's one-file parent waits for the embedded Python child.  Some
    # optional libraries register shutdown workers that can keep that child
    # alive after Qt has closed its final window.  The UI has already saved its
    # state in closeEvent, so terminate the child deterministically once the Qt
    # event loop returns; the parent then exits and releases the instance mutex.
    os._exit(int(main() or 0))

# ===== SNAPSMACK EOF =====
