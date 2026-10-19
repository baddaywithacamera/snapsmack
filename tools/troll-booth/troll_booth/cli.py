from __future__ import annotations

import argparse
import json
import os
from pathlib import Path

from .history import ConversationStore, open_conversation_store
from .attachments import import_image, read_image
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
    add.add_argument("--attach", action="append", default=[], help="Attach a PNG, JPEG, GIF, or WebP screenshot")
    search = history_commands.add_parser("search", help="Search local message text")
    search.add_argument("query")
    search.add_argument("--room")
    search.add_argument("--limit", type=int, default=50)
    history_commands.add_parser("rooms", help="List rooms")
    messages = history_commands.add_parser("messages", help="Read one room")
    messages.add_argument("--room", required=True)
    messages.add_argument("--limit", type=int, default=200)
    attachment = history_commands.add_parser("attachment", help="Read a screenshot attached to a message")
    attachment.add_argument("--message", required=True)
    attachment.add_argument("--id", required=True)
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
            attachments = [import_image(path, Path.home() / ".troll-booth") for path in args.attach]
            print(
                messages.append(
                    args.room,
                    args.sender,
                    args.body,
                    title=args.title,
                    ticket_id=args.ticket,
                    task_id=args.task,
                    attachments=attachments,
                )
            )
        elif args.history_command == "search":
            print(json.dumps(messages.search(args.query, args.room, args.limit), indent=2))
        elif args.history_command == "rooms":
            print(json.dumps(messages.rooms(), indent=2))
        elif args.history_command == "messages":
            print(json.dumps(messages.messages(args.room, args.limit), indent=2))
        elif args.history_command == "attachment":
            message = messages.message(args.message)
            if not message:
                parser.error("message not found")
            item = next((item for item in message.get("attachments", []) if item.get("id") == args.id), None)
            if not item:
                parser.error("attachment not found on that message")
            print(json.dumps(read_image(item, Path.home() / ".troll-booth"), indent=2))
        elif args.history_command == "scopes":
            print(json.dumps(messages.scopes(), indent=2))


if __name__ == "__main__":
    main()

