"""GHOSTED desktop CLI (Protocol 1). SNAPSMACK_EOF_HEADER."""
from __future__ import annotations

from pathlib import Path
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "_shared"))
import snap_cli as C


def configure(sub):
    p = sub.add_parser("inspect-archive", help="Discover and summarise a Ghost export (.json or .zip)")
    p.add_argument("--input", required=True, help="Path to Ghost JSON or ZIP export")
    sub.add_parser("jobs", help="List saved GHOSTED import jobs")


def execute(args):
    from ghosted import archive_reader, ghost_parser, job_store

    if args.command == "inspect-archive":
        candidates = archive_reader.discover_archives(args.input)
        exports = []
        for cand in candidates:
            raw, source_name, _media_root = archive_reader.read_candidate(cand)
            blog = ghost_parser.parse_ghost_json(raw, source_name=source_name)
            entries = getattr(blog, "entries", []) or []
            exports.append({
                "source": source_name,
                "title": getattr(blog, "title", ""),
                "entry_count": len(entries),
            })
        return {"archives": exports, "count": len(exports)}

    if args.command == "jobs":
        store = job_store.JobStore()
        jobs = store.list_jobs() if hasattr(store, "list_jobs") else []
        return {"jobs": jobs, "count": len(jobs)}

    raise ValueError(f"Unknown command: {args.command}")


def main(argv=None):
    return C.run("ghosted", configure, execute, argv)


if __name__ == "__main__":
    raise SystemExit(main())

# ===== SNAPSMACK EOF =====
