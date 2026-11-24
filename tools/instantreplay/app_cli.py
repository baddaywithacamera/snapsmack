"""INSTANT REPLAY desktop CLI (Protocol 1). SNAPSMACK_EOF_HEADER."""
from __future__ import annotations

from dataclasses import asdict
from pathlib import Path
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "_shared"))
import snap_cli as C


def configure(sub):
    sub.add_parser("backups", help="List available backup packages")
    sub.add_parser("targets", help="List available test-blog targets")
    p = sub.add_parser("verify", help="Verify a backup snapshot against INSTANT REPLAY rules")
    p.add_argument("--backup-id", required=True)
    p.add_argument("--input", required=True, help="JSON snapshot file")


def execute(args):
    from replay_core import providers, verifier

    catalog = providers.FakeBackupCatalog()
    targets = providers.FakeTestBlogTarget()
    if args.command == "backups":
        items = [asdict(b) for b in catalog.list_backups()]
        return {"backups": items, "count": len(items)}
    if args.command == "targets":
        items = [asdict(t) for t in targets.list_targets()]
        return {"targets": items, "count": len(items)}
    if args.command == "verify":
        matches = [b for b in catalog.list_backups() if b.backup_id == args.backup_id]
        if not matches:
            raise ValueError(f"Unknown backup_id: {args.backup_id}")
        snapshot = C.object_json(args.input)
        res = verifier.verify_restored_blog(matches[0], snapshot)
        return asdict(res)
    raise ValueError(f"Unknown command: {args.command}")


COMMAND_SCOPES = {'backups': 'read', 'targets': 'read', 'verify': 'inspect'}

def main(argv=None):
    return C.run("instantreplay", configure, execute, argv, command_scopes=COMMAND_SCOPES)


if __name__ == "__main__":
    raise SystemExit(main())

# ===== SNAPSMACK EOF =====
