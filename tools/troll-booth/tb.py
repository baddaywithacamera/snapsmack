# SNAPSMACK_EOF_HEADER
"""TROLL BOOTH command-line feasibility harness."""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

from troll_booth.broker import Broker


def emit(value, as_json: bool) -> None:
    if as_json:
        print(json.dumps(value, ensure_ascii=False, indent=2))
    elif isinstance(value, list):
        for row in value:
            state = "ready" if row.get("healthy") else "unavailable"
            print(f"{row.get('name')} [{state}] " + ", ".join(row.get("capabilities", [])))
            if row.get("error"):
                print("  " + row["error"])
    else:
        print(json.dumps(value, ensure_ascii=False, indent=2))


def parser() -> argparse.ArgumentParser:
    root = argparse.ArgumentParser(prog="tb", description="TROLL BOOTH capability broker")
    root.add_argument("--json", action="store_true")
    sub = root.add_subparsers(dest="command", required=True)
    apps = sub.add_parser("apps")
    caps = sub.add_parser("capabilities")
    caps.add_argument("app")
    invoke = sub.add_parser("invoke")
    invoke.add_argument("app")
    invoke.add_argument("capability")
    invoke.add_argument("--args", default="{}", help="JSON object of typed arguments")
    invoke.add_argument("--actor", required=True,
                        choices=["claude", "codex", "sean", "assistant"])
    invoke.add_argument("--reason", default="")
    invoke.add_argument("--ticket", default="")
    for child in (apps, caps, invoke):
        child.add_argument("--json", action="store_true", default=argparse.SUPPRESS)
    return root


def main(argv=None) -> int:
    args = parser().parse_args(argv)
    broker = Broker(Path(__file__).resolve().parent)
    if args.command == "apps":
        emit(broker.list_apps(), args.json)
        return 0
    if args.command == "capabilities":
        rows = {row["id"]: row for row in broker.list_apps()}
        emit(rows.get(args.app, {"id": args.app, "healthy": False,
                                 "error": "unknown app"}), args.json)
        return 0 if rows.get(args.app, {}).get("healthy") else 1
    try:
        values = json.loads(args.args)
    except json.JSONDecodeError as exc:
        print(f"invalid --args JSON: {exc}", file=sys.stderr)
        return 2
    if not isinstance(values, dict):
        print("--args must be a JSON object", file=sys.stderr)
        return 2
    result = broker.invoke(args.app, args.capability, values, args.actor,
                           args.reason, args.ticket)
    emit(result, args.json)
    return 0 if result.get("ok") else 1


if __name__ == "__main__":
    raise SystemExit(main())

# ===== SNAPSMACK EOF =====
