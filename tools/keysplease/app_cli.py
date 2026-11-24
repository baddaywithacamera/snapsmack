"""KEYS PLEASE desktop CLI (Protocol 1). SNAPSMACK_EOF_HEADER."""
from __future__ import annotations

from dataclasses import asdict
from pathlib import Path
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "_shared"))
import snap_cli as C


def configure(sub):
    sub.add_parser("keys", help="List key metadata (no secret values)")
    p = sub.add_parser("audit-log", help="Read recent KEYS PLEASE audit log entries")
    p.add_argument("--limit", type=int, default=50)


def execute(args):
    import json
    from keysplease_core import audit_log, providers

    if args.command == "keys":
        prov = providers.FakeKeysProvider()
        items = [asdict(k) for k in prov.list_keys()]
        return {"keys": items, "count": len(items)}

    if args.command == "audit-log":
        log_path = Path(audit_log.default_log_path())
        entries = []
        if log_path.is_file():
            with log_path.open("r", encoding="utf-8") as fh:
                for line in fh:
                    line = line.strip()
                    if line:
                        try:
                            entries.append(json.loads(line))
                        except ValueError:
                            pass
        limit = max(1, min(500, int(args.limit)))
        return {"log_path": str(log_path), "entries": entries[-limit:], "count": len(entries[-limit:])}

    raise ValueError(f"Unknown command: {args.command}")


COMMAND_SCOPES = {'keys': 'read', 'audit-log': 'read'}

def main(argv=None):
    return C.run("keysplease", configure, execute, argv, command_scopes=COMMAND_SCOPES)


if __name__ == "__main__":
    raise SystemExit(main())

# ===== SNAPSMACK EOF =====
