"""SMACKPRESS tracking CLI; existing migration tools remain unchanged. SNAPSMACK_EOF_HEADER."""
from pathlib import Path
import sqlite3
import sys
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "_shared"))
import snap_cli as C


def configure(sub):
    for name in ("tracked", "set-note"):
        p = sub.add_parser(name)
        state = Path(sys.executable).resolve().parent if getattr(sys, "frozen", False) else Path(__file__).resolve().parent
        p.add_argument("--db", default=str(state / "smackpress.db"))
        if name == "tracked":
            p.add_argument("--after", type=int, default=0); p.add_argument("--limit", type=int, default=100)
        else:
            p.add_argument("--id", type=int, required=True); p.add_argument("--note", required=True)
            p.add_argument("--apply", action="store_true")


def execute(args):
    path = Path(args.db).resolve(strict=True)
    if args.command == "tracked":
        if not 1 <= args.limit <= 500:
            raise ValueError("--limit must be between 1 and 500")
        with sqlite3.connect(path.as_uri() + "?mode=ro", uri=True) as conn:
            conn.row_factory = sqlite3.Row
            rows = [dict(row) for row in conn.execute("SELECT * FROM posts WHERE wp_id > ? ORDER BY wp_id LIMIT ?", (args.after, args.limit + 1))]
        return {"items": rows[:args.limit], "next_after": rows[args.limit - 1]["wp_id"] if len(rows) > args.limit else None}
    if args.id <= 0 or len(args.note) > 20000:
        raise ValueError("Invalid note or post ID")
    if not args.apply:
        return {"status": "planned", "id": args.id, "note": args.note}
    from smackpress import config, db
    config.DB_PATH = path
    db.set_note(args.id, args.note)
    return {"status": "saved", "id": args.id}


def main(argv=None):
    return C.run("smackpress", configure, execute, argv)


if __name__ == "__main__":
    raise SystemExit(main())
# ===== SNAPSMACK EOF =====
