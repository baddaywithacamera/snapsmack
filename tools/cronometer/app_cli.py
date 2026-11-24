"""CRONOMETER desktop CLI (Protocol 1). SNAPSMACK_EOF_HEADER."""
from __future__ import annotations

from dataclasses import asdict
from pathlib import Path
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "_shared"))
import snap_cli as C


def configure(sub):
    sub.add_parser("sites", help="List configured fleet sites visible to CRONOMETER")
    p = sub.add_parser("check", help="Probe cron health for one site or the whole fleet")
    p.add_argument("--site", default="", help="Optional site URL or profile name filter")
    p.add_argument("--timeout", type=int, default=12)


def execute(args):
    import config
    import heartbeat_client

    sites = config.load_sites() if hasattr(config, "load_sites") else []
    if not sites:
        import snap_profiles
        sites = [p for p in snap_profiles.list_profiles() if p.get("site_url")]

    if args.command == "sites":
        out = [{"name": s.get("name", ""), "site_url": s.get("site_url", "")} for s in sites]
        return {"sites": out, "count": len(out)}

    if args.command == "check":
        needle = (args.site or "").strip().lower()
        targets = [
            s for s in sites
            if not needle
            or needle in str(s.get("site_url", "")).lower()
            or needle in str(s.get("name", "")).lower()
        ]
        results = [asdict(heartbeat_client.probe(s, timeout=args.timeout)) for s in targets]
        return {"sites": results, "count": len(results)}

    raise ValueError(f"Unknown command: {args.command}")


COMMAND_SCOPES = {'sites': 'read', 'check': 'inspect'}

def main(argv=None):
    return C.run("cronometer", configure, execute, argv, command_scopes=COMMAND_SCOPES)


if __name__ == "__main__":
    raise SystemExit(main())

# ===== SNAPSMACK EOF =====
