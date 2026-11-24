"""SMACK YOUR MOUTH desktop CLI (Protocol 1). SNAPSMACK_EOF_HEADER."""
from __future__ import annotations

from pathlib import Path
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "_shared"))
import snap_cli as C


def configure(sub):
    sub.add_parser("sessions", help="List saved SMACK YOUR MOUTH offline moderation sessions")
    p = sub.add_parser("session-detail", help="Inspect one saved moderation session")
    p.add_argument("--session", required=True, help="Session ID")
    sub.add_parser("new-session", help="Create a new offline moderation session")


def execute(args):
    import smackmouth_core

    core = smackmouth_core.MouthCore()
    if args.command == "sessions":
        items = core.sessions_payload()
        return {"sessions": items, "count": len(items)}
    if args.command == "session-detail":
        return core.select_session(args.session)
    if args.command == "new-session":
        return core.new_session()
    raise ValueError(f"Unknown command: {args.command}")


COMMAND_SCOPES = {'sessions': 'read', 'session-detail': 'read', 'new-session': 'local_write'}

def main(argv=None):
    return C.run("smack-your-mouth", configure, execute, argv, command_scopes=COMMAND_SCOPES)


if __name__ == "__main__":
    raise SystemExit(main())

# ===== SNAPSMACK EOF =====
