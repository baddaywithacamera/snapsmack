"""Qt entry point for SMACK YOUR BATCH UP."""

import os
import sys


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
