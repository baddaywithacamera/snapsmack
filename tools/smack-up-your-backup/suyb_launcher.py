"""Smack Up Your Backup — packaged entry point.

The Qt desktop shell is the normal application. Headless scheduled backups keep
their existing CLI contract and engine untouched.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
"""

import argparse
import sys


def main():
    parser = argparse.ArgumentParser(prog="suyb", description="Smack Up Your Backup")
    parser.add_argument("--backup-all", action="store_true")
    parser.add_argument("--silent", action="store_true")
    args, _ = parser.parse_known_args()
    if args.backup_all:
        import headless
        return headless.run_backup_all(silent=args.silent)
    try:
        import snap_single_instance
        if not snap_single_instance.acquire("suyb", "SMACK UP YOUR BACKUP"):
            return 0
    except Exception:
        pass
    from suyb_qt import run
    return run()


if __name__ == "__main__":
    sys.exit(main())

# ===== SNAPSMACK EOF =====
