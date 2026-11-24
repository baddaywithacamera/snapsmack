"""FLKR FCKR desktop CLI (Protocol 1). SNAPSMACK_EOF_HEADER."""
from __future__ import annotations

from pathlib import Path
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "_shared"))
import snap_cli as C


def configure(sub):
    p = sub.add_parser("inspect-export", help="Parse and summarise a Flickr export folder")
    p.add_argument("--input", required=True, help="Path to Flickr export folder")
    p2 = sub.add_parser("checkpoint", help="Inspect FLKR FCKR checkpoint status for an export")
    p2.add_argument("--input", required=True, help="Path to Flickr export folder")


def execute(args):
    import checkpoint
    import flickr_parser

    if args.command == "inspect-export":
        res = flickr_parser.parse(args.input)
        photos = getattr(res, "photos", []) or []
        albums = getattr(res, "albums", []) or []
        return {
            "export": str(Path(args.input).resolve()),
            "photo_count": len(photos),
            "album_count": len(albums),
        }

    if args.command == "checkpoint":
        cp = checkpoint.ImportCheckpoint(args.input)
        posted = getattr(cp, "posted_ids", lambda: set())()
        return {"export": str(Path(args.input).resolve()), "posted_count": len(posted)}

    raise ValueError(f"Unknown command: {args.command}")


def main(argv=None):
    return C.run("flkr-fckr", configure, execute, argv)


if __name__ == "__main__":
    raise SystemExit(main())

# ===== SNAPSMACK EOF =====
