from __future__ import annotations

import hashlib
import json
import secrets
import sqlite3
import time
from pathlib import Path
from typing import Any


class ConversationStore:
    """Append-only, locally readable conversation history with full-text search."""

    def __init__(self, path: str | Path):
        self.path = Path(path)
        self.path.parent.mkdir(parents=True, exist_ok=True)
        self._initialize()

    def _connect(self) -> sqlite3.Connection:
        db = sqlite3.connect(self.path, timeout=10)
        db.row_factory = sqlite3.Row
        db.execute("PRAGMA journal_mode=WAL")
        db.execute("PRAGMA foreign_keys=ON")
        return db

    def _initialize(self) -> None:
        with self._connect() as db:
            db.executescript(
                """
                CREATE TABLE IF NOT EXISTS rooms (
                    id TEXT PRIMARY KEY,
                    title TEXT NOT NULL,
                    ticket_id TEXT,
                    task_id TEXT,
                    created_at INTEGER NOT NULL
                );
                CREATE TABLE IF NOT EXISTS messages (
                    id TEXT PRIMARY KEY,
                    room_id TEXT NOT NULL REFERENCES rooms(id),
                    sender TEXT NOT NULL,
                    body TEXT NOT NULL,
                    ticket_id TEXT,
                    task_id TEXT,
                    attachments_json TEXT NOT NULL,
                    created_at INTEGER NOT NULL,
                    previous_hash TEXT,
                    message_hash TEXT NOT NULL UNIQUE
                );
                CREATE INDEX IF NOT EXISTS messages_room_time ON messages(room_id, created_at, id);
                CREATE VIRTUAL TABLE IF NOT EXISTS messages_fts USING fts5(
                    message_id UNINDEXED,
                    room_id UNINDEXED,
                    sender,
                    body,
                    ticket_id,
                    task_id,
                    tokenize='unicode61'
                );
                """
            )

    def ensure_room(
        self, room_id: str, title: str, ticket_id: str | None = None, task_id: str | None = None
    ) -> None:
        with self._connect() as db:
            db.execute(
                "INSERT INTO rooms(id,title,ticket_id,task_id,created_at) VALUES(?,?,?,?,?) "
                "ON CONFLICT(id) DO UPDATE SET title=excluded.title, "
                "ticket_id=COALESCE(rooms.ticket_id,excluded.ticket_id), "
                "task_id=COALESCE(rooms.task_id,excluded.task_id)",
                (room_id, title, ticket_id, task_id, int(time.time())),
            )

    def append(
        self,
        room_id: str,
        sender: str,
        body: str,
        *,
        title: str | None = None,
        ticket_id: str | None = None,
        task_id: str | None = None,
        attachments: list[dict[str, Any]] | None = None,
        created_at: int | None = None,
    ) -> str:
        if not room_id.strip() or not sender.strip() or not body.strip():
            raise ValueError("room, sender, and body are required")
        message_id = secrets.token_urlsafe(18)
        timestamp = created_at or int(time.time())
        attachment_data = attachments or []
        with self._connect() as db:
            db.execute("BEGIN IMMEDIATE")
            db.execute(
                "INSERT INTO rooms(id,title,ticket_id,task_id,created_at) VALUES(?,?,?,?,?) "
                "ON CONFLICT(id) DO NOTHING",
                (room_id, title or room_id, ticket_id, task_id, timestamp),
            )
            prior = db.execute(
                "SELECT message_hash FROM messages WHERE room_id=? ORDER BY rowid DESC LIMIT 1",
                (room_id,),
            ).fetchone()
            previous_hash = prior["message_hash"] if prior else None
            canonical = json.dumps(
                {
                    "id": message_id,
                    "room_id": room_id,
                    "sender": sender,
                    "body": body,
                    "ticket_id": ticket_id,
                    "task_id": task_id,
                    "attachments": attachment_data,
                    "created_at": timestamp,
                    "previous_hash": previous_hash,
                },
                ensure_ascii=False,
                sort_keys=True,
                separators=(",", ":"),
            )
            message_hash = hashlib.sha256(canonical.encode("utf-8")).hexdigest()
            db.execute(
                "INSERT INTO messages(id,room_id,sender,body,ticket_id,task_id,attachments_json,created_at,previous_hash,message_hash) "
                "VALUES(?,?,?,?,?,?,?,?,?,?)",
                (
                    message_id,
                    room_id,
                    sender,
                    body,
                    ticket_id,
                    task_id,
                    json.dumps(attachment_data),
                    timestamp,
                    previous_hash,
                    message_hash,
                ),
            )
            db.execute(
                "INSERT INTO messages_fts(message_id,room_id,sender,body,ticket_id,task_id) VALUES(?,?,?,?,?,?)",
                (message_id, room_id, sender, body, ticket_id or "", task_id or ""),
            )
            db.commit()
        return message_id

    def rooms(self, limit: int = 100) -> list[dict[str, Any]]:
        limit = max(1, min(limit, 500))
        with self._connect() as db:
            rows = db.execute(
                "SELECT r.*, COUNT(m.id) AS message_count, MAX(m.created_at) AS last_message_at "
                "FROM rooms r LEFT JOIN messages m ON m.room_id=r.id "
                "GROUP BY r.id ORDER BY last_message_at DESC, r.created_at DESC LIMIT ?",
                (limit,),
            ).fetchall()
        return [dict(row) for row in rows]

    def messages(self, room_id: str, limit: int = 200, before: int | None = None) -> list[dict[str, Any]]:
        limit = max(1, min(limit, 1000))
        sql = "SELECT * FROM messages WHERE room_id=?"
        params: list[Any] = [room_id]
        if before is not None:
            sql += " AND created_at<?"
            params.append(before)
        sql += " ORDER BY created_at DESC,rowid DESC LIMIT ?"
        params.append(limit)
        with self._connect() as db:
            rows = db.execute(sql, params).fetchall()
        results = [self._message_dict(row) for row in rows]
        results.reverse()
        return results

    def search(self, query: str, room_id: str | None = None, limit: int = 50) -> list[dict[str, Any]]:
        if not query.strip():
            return []
        limit = max(1, min(limit, 200))
        sql = (
            "SELECT m.*, bm25(messages_fts) AS rank FROM messages_fts "
            "JOIN messages m ON m.id=messages_fts.message_id WHERE messages_fts MATCH ?"
        )
        params: list[Any] = [query]
        if room_id:
            sql += " AND m.room_id=?"
            params.append(room_id)
        sql += " ORDER BY rank, m.created_at DESC LIMIT ?"
        params.append(limit)
        with self._connect() as db:
            rows = db.execute(sql, params).fetchall()
        return [self._message_dict(row) for row in rows]

    @staticmethod
    def _message_dict(row: sqlite3.Row) -> dict[str, Any]:
        data = dict(row)
        data.pop("rank", None)
        data["attachments"] = json.loads(data.pop("attachments_json"))
        return data
