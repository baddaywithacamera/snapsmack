"""UNZUCKER desktop CLI (Protocol 1). SNAPSMACK_EOF_HEADER."""
from __future__ import annotations

from pathlib import Path
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "_shared"))
import snap_cli as C


def configure(sub):
    p = sub.add_parser("inspect-export", help="Parse and summarise an Instagram export folder")
    p.add_argument("--input", required=True, help="Path to Instagram export folder")
    sub.add_parser("jobs", help="List saved UNZUCKER import job files")


def execute(args):
    import ig_parser
    import job_state

    if args.command == "inspect-export":
        res = ig_parser.parse(args.input)
        posts = getattr(res, "posts", []) or []
        return {
            "export": str(Path(args.input).resolve()),
            "post_count": len(posts),
            "media_count": sum(len(getattr(p, "media_paths", []) or []) for p in posts),
            "warnings": list(getattr(res, "warnings", []) or []),
        }

    if args.command == "jobs":
        jobs_dir = Path(job_state._jobs_dir())
        files = sorted(p.name for p in jobs_dir.glob("*.json")) if jobs_dir.is_dir() else []
        return {"jobs_dir": str(jobs_dir), "jobs": files, "count": len(files)}

    raise ValueError(f"Unknown command: {args.command}")


def main(argv=None):
    return C.run("unzucker", configure, execute, argv)


if __name__ == "__main__":
    raise SystemExit(main())

# ===== SNAPSMACK EOF =====
