"""Qt entry point for SMACK YOUR BATCH UP."""

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
    raise SystemExit(main())

# ===== SNAPSMACK EOF =====
