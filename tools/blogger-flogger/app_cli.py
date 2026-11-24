"""BLOGGER FLOGGER desktop CLI (Protocol 1). SNAPSMACK_EOF_HEADER."""
from __future__ import annotations

from pathlib import Path
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "_shared"))
import snap_cli as C


def configure(sub):
    p = sub.add_parser("inspect-archive", help="Discover and summarise a Blogger export (.xml or .zip)")
    p.add_argument("--input", required=True, help="Path to Blogger XML or Takeout ZIP")
    sub.add_parser("jobs", help="List saved BLOGGER FLOGGER import jobs")


def execute(args):
    from blogger_flogger import archive_reader, atom_parser, job_store

    if args.command == "inspect-archive":
        candidates = archive_reader.discover_archives(args.input)
        blogs = []
        for cand in candidates:
            raw = archive_reader.read_candidate(cand)
            blog = atom_parser.parse_feed(raw)
            entries = getattr(blog, "entries", []) or []
            blogs.append({
                "source": getattr(cand, "display_name", str(args.input)),
                "title": getattr(blog, "title", ""),
                "entry_count": len(entries),
            })
        return {"archives": blogs, "count": len(blogs)}

    if args.command == "jobs":
        store = job_store.JobStore()
        jobs = store.list_jobs() if hasattr(store, "list_jobs") else []
        return {"jobs": jobs, "count": len(jobs)}

    raise ValueError(f"Unknown command: {args.command}")


COMMAND_SCOPES = {'inspect-archive': 'inspect', 'jobs': 'read'}

def main(argv=None):
    return C.run("blogger-flogger", configure, execute, argv, command_scopes=COMMAND_SCOPES)


if __name__ == "__main__":
    raise SystemExit(main())

# ===== SNAPSMACK EOF =====
