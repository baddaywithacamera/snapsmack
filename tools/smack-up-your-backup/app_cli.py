"""CLI facade preserving the existing backup-all engine. SNAPSMACK_EOF_HEADER."""
from pathlib import Path
import sys
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "_shared"))
import snap_cli as C


def configure(sub):
    p = sub.add_parser("backup-all", help="Plan or run the existing headless backup-all operation")
    p.add_argument("--apply", action="store_true")


def execute(args):
    if not args.apply:
        return {"status": "planned", "operation": "backup-all", "note": "Uses existing configured backup profiles"}
    import headless
    code = headless.run_backup_all(silent=True)
    if code:
        raise ValueError("One or more backups failed; inspect the existing SUYB backup log")
    return {"status": "completed"}


def main(argv=None):
    return C.run("suyb", configure, execute, argv)


if __name__ == "__main__":
    raise SystemExit(main())
# ===== SNAPSMACK EOF =====
