"""FED UP desktop CLI (Protocol 1). SNAPSMACK_EOF_HEADER."""
from __future__ import annotations

from pathlib import Path
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "_shared"))
import snap_cli as C


def configure(sub):
    p = sub.add_parser("inspect-archive", help="Summarise a local FED UP Fediverse archive")
    p.add_argument("--input", required=True, help="Path to FED UP archive directory")
    p.add_argument("--include-replies", action="store_true")
    p2 = sub.add_parser("followers", help="List followers recorded in a FED UP archive")
    p2.add_argument("--input", required=True, help="Path to FED UP archive directory")


def execute(args):
    from fed_up import restore

    if args.command == "inspect-archive":
        posts = restore.load_posts(args.input)
        parsed = restore.parse(args.input, include_replies=bool(args.include_replies))
        parsed_posts = getattr(parsed, "posts", []) if not isinstance(parsed, list) else parsed
        return {
            "archive": str(Path(args.input).resolve()),
            "raw_post_count": len(posts),
            "restorable_count": len(parsed_posts),
        }

    if args.command == "followers":
        followers = restore.followers_to_tell(args.input)
        return {"archive": str(Path(args.input).resolve()), "followers": followers, "count": len(followers)}

    raise ValueError(f"Unknown command: {args.command}")


COMMAND_SCOPES = {'inspect-archive': 'inspect', 'followers': 'read'}

def main(argv=None):
    return C.run("fed-up", configure, execute, argv, command_scopes=COMMAND_SCOPES)


if __name__ == "__main__":
    raise SystemExit(main())

# ===== SNAPSMACK EOF =====
