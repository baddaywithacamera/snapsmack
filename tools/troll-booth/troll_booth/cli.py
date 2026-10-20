from __future__ import annotations

import argparse
import json
import os
import time
from pathlib import Path

from .history import ConversationStore, open_conversation_store
from .store import BoothStore


def main() -> None:
    parser = argparse.ArgumentParser(prog="troll-booth")
    parser.add_argument(
        "--database",
        default=os.environ.get("TROLL_BOOTH_DATABASE", str(Path.home() / ".troll-booth" / "remote.sqlite3")),
    )
    commands = parser.add_subparsers(dest="command", required=True)
    pair = commands.add_parser("pair-code", help="Issue a short-lived, single-use browser pairing code")
    pair.add_argument("--actor", default="claude")
    pair.add_argument("--ttl", type=int, default=300)
    pair.add_argument("--write", action="store_true", help="Also grant collaboration write access")
    commands.add_parser("status", help="Show remote broker and worker status")
    history = commands.add_parser("history", help="Manage the append-only local collaboration history")
    history.add_argument("--history-database", type=Path)
    history_commands = history.add_subparsers(dest="history_command", required=True)
    add = history_commands.add_parser("add", help="Append one authenticated message")
    add.add_argument("--room", required=True)
    add.add_argument("--sender", required=True)
    add.add_argument("--body", required=True)
    add.add_argument("--title")
    add.add_argument("--ticket")
    add.add_argument("--task")
    search = history_commands.add_parser("search", help="Search local message text")
    search.add_argument("query")
    search.add_argument("--room")
    search.add_argument("--limit", type=int, default=50)
    history_commands.add_parser("rooms", help="List rooms")
    messages = history_commands.add_parser("messages", help="Read one room")
    messages.add_argument("--room", required=True)
    messages.add_argument("--limit", type=int, default=200)
    watch = history_commands.add_parser("watch", help="Wait until another agent posts a new message")
    watch.add_argument("--actor", required=True)
    watch.add_argument("--room")
    watch.add_argument("--timeout", type=int, default=300)
    watch.add_argument("--poll", type=float, default=2.0)
    claim = history_commands.add_parser("claim", help="Claim a file or subsystem before editing")
    claim.add_argument("--scope", required=True)
    claim.add_argument("--actor", required=True)
    claim.add_argument("--task")
    claim.add_argument("--note")
    claim.add_argument("--ttl", type=int, default=3600)
    release = history_commands.add_parser("release", help="Release a file or subsystem claim")
    release.add_argument("--scope", required=True)
    release.add_argument("--actor", required=True)
    scopes = history_commands.add_parser("scopes", help="List active work-scope claims")
    args = parser.parse_args()
    store = BoothStore(args.database)
    if args.command == "pair-code":
        scopes = ["booth:read", "booth:write"] if args.write else ["booth:read"]
        print(store.issue_pair_code(args.actor, scopes, max(60, min(args.ttl, 900))))
    elif args.command == "status":
        print(json.dumps(store.status(), indent=2))
    elif args.command == "history":
        messages = ConversationStore(args.history_database) if args.history_database else open_conversation_store()[0]
        if args.history_command == "add":
            print(
                messages.append(
                    args.room,
                    args.sender,
                    args.body,
                    title=args.title,
                    ticket_id=args.ticket,
                    task_id=args.task,
                )
            )
        elif args.history_command == "search":
            print(json.dumps(messages.search(args.query, args.room, args.limit), indent=2))
        elif args.history_command == "rooms":
            print(json.dumps(messages.rooms(), indent=2))
        elif args.history_command == "messages":
            print(json.dumps(messages.messages(args.room, args.limit), indent=2))
        elif args.history_command == "watch":
            room_ids = [args.room] if args.room else [room["id"] for room in messages.rooms()]
            seen = {
                message["id"]
                for room_id in room_ids
                for message in messages.messages(room_id, 1000)
            }
            deadline = time.monotonic() + max(1, min(args.timeout, 3600))
            while time.monotonic() < deadline:
                current_rooms = [args.room] if args.room else [room["id"] for room in messages.rooms()]
                found = []
                for room_id in current_rooms:
                    for message in messages.messages(room_id, 1000):
                        if message["id"] not in seen and message["sender"] != args.actor:
                            found.append(message)
                        seen.add(message["id"])
                if found:
                    print(json.dumps(found, indent=2))
                    return
                time.sleep(max(0.25, min(args.poll, 30.0)))
            print("[]")
        elif args.history_command == "claim":
            print(json.dumps(messages.claim_scope(
                args.scope, args.actor, task_id=args.task, note=args.note, ttl=args.ttl
            ), indent=2))
        elif args.history_command == "release":
            print(json.dumps(messages.release_scope(args.scope, args.actor), indent=2))
        elif args.history_command == "scopes":
            print(json.dumps(messages.scopes(), indent=2))


if __name__ == "__main__":
    main()

