from __future__ import annotations
# SNAPSMACK_EOF_HEADER: preserve the final EOF marker.

import csv
import hashlib
import io
import json
import os
import secrets
import sqlite3
import time
from datetime import datetime
from pathlib import Path
from typing import Any
from urllib.parse import unquote, urlparse


CSV_COLUMNS = (
    "id",
    "room_id",
    "sender",
    "body",
    "ticket_id",
    "task_id",
    "created_at",
    "previous_hash",
    "message_hash",
    "member_sequence",
    "attachments",
)


def format_messages_as_text(
    room_id: str,
    room_title: str,
    messages: list[dict[str, Any]],
) -> str:
    """Format a chronological room transcript with local timestamps, sender,
    message ID, sequence number, and hash-chain values (SS-1474)."""
    lines = [
        f"BRAIN TRUST ROOM EXPORT — {room_title} ({room_id})",
        f"Exported: {datetime.now().astimezone().strftime('%Y-%m-%d %H:%M:%S %Z')}",
        f"Messages: {len(messages)}",
        "=" * 78,
        "",
    ]
    for idx, msg in enumerate(messages, start=1):
        seq = msg.get("member_sequence")
        seq_val = seq if seq is not None else idx
        ts = int(msg.get("created_at") or 0)
        local_dt = datetime.fromtimestamp(ts).astimezone().strftime("%Y-%m-%d %H:%M:%S %Z")
        lines.append(
            f"[#{seq_val}] {local_dt} | sender={msg.get('sender', '')} | id={msg.get('id', '')}"
        )
        if msg.get("ticket_id") or msg.get("task_id"):
            lines.append(
                f"  ticket_id={msg.get('ticket_id') or ''}  task_id={msg.get('task_id') or ''}"
            )
        lines.append(f"  previous_hash: {msg.get('previous_hash') or 'None'}")
        lines.append(f"  message_hash:  {msg.get('message_hash') or ''}")
        lines.append("")
        lines.append(str(msg.get("body") or ""))
        lines.append("-" * 78)
        lines.append("")
    return "\n".join(lines)


def format_messages_as_csv(messages: list[dict[str, Any]]) -> str:
    """Export one row per database row with columns matching the messages table
    exactly: id, room_id, sender, body, ticket_id, task_id, created_at,
    previous_hash, message_hash, member_sequence, attachments (SS-1474)."""
    buf = io.StringIO(newline="")
    writer = csv.writer(buf, quoting=csv.QUOTE_MINIMAL, lineterminator="\r\n")
    writer.writerow(CSV_COLUMNS)
    for idx, msg in enumerate(messages, start=1):
        seq = msg.get("member_sequence")
        if seq is None:
            seq = idx
        attachments = msg.get("attachments")
        if isinstance(attachments, str):
            att_cell = attachments
        else:
            att_cell = json.dumps(attachments if attachments is not None else [], ensure_ascii=False)
        writer.writerow([
            msg.get("id") or "",
            msg.get("room_id") or "",
            msg.get("sender") or "",
            msg.get("body") or "",
            "" if msg.get("ticket_id") is None else msg.get("ticket_id"),
            "" if msg.get("task_id") is None else msg.get("task_id"),
            "" if msg.get("created_at") is None else msg.get("created_at"),
            "" if msg.get("previous_hash") is None else msg.get("previous_hash"),
            msg.get("message_hash") or "",
            seq,
            att_cell,
        ])
    return buf.getvalue()


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
                    created_at INTEGER NOT NULL,
                    archived_at INTEGER,
                    removed_at INTEGER
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
                CREATE TABLE IF NOT EXISTS scope_claims (
                    scope TEXT PRIMARY KEY,
                    actor TEXT NOT NULL,
                    task_id TEXT,
                    note TEXT,
                    claimed_at INTEGER NOT NULL,
                    expires_at INTEGER NOT NULL
                );
                CREATE INDEX IF NOT EXISTS scope_claims_expiry ON scope_claims(expires_at);
                """
            )
            existing_cols = {
                row["name"]
                for row in db.execute("PRAGMA table_info(rooms)").fetchall()
            }
            for col in ("archived_at", "removed_at"):
                if col not in existing_cols:
                    db.execute(f"ALTER TABLE rooms ADD COLUMN {col} INTEGER")

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

    def rename_room(self, room_id: str, new_title: str) -> bool:
        """SS-1474: Rename display title only; room_id and message hash chain are untouched."""
        rid = room_id.strip()
        title = new_title.strip()
        if not rid or not title:
            raise ValueError("room_id and new_title are required")
        with self._connect() as db:
            changed = db.execute(
                "UPDATE rooms SET title=? WHERE id=?", (title, rid)
            ).rowcount
        return changed == 1

    def archive_room(self, room_id: str, archived: bool = True) -> bool:
        """SS-1474: Soft-archive or restore a room. archived rooms are read-only in UI."""
        rid = room_id.strip()
        stamp = int(time.time()) if archived else None
        with self._connect() as db:
            changed = db.execute(
                "UPDATE rooms SET archived_at=? WHERE id=?", (stamp, rid)
            ).rowcount
        return changed == 1

    def soft_remove_room(self, room_id: str, removed: bool = True) -> bool:
        """SS-1474: Soft-remove (hide) or restore a room. Keeps all rows and hash chain intact."""
        rid = room_id.strip()
        stamp = int(time.time()) if removed else None
        with self._connect() as db:
            changed = db.execute(
                "UPDATE rooms SET removed_at=? WHERE id=?", (stamp, rid)
            ).rowcount
        return changed == 1

    def room_info(self, room_id: str) -> dict[str, Any] | None:
        with self._connect() as db:
            row = db.execute("SELECT * FROM rooms WHERE id=?", (room_id,)).fetchone()
        return dict(row) if row else None

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

    def rooms(
        self,
        limit: int = 100,
        *,
        include_archived: bool = False,
        include_removed: bool = False,
    ) -> list[dict[str, Any]]:
        limit = max(1, min(limit, 500))
        clauses = []
        if not include_removed:
            clauses.append("r.removed_at IS NULL")
        if not include_archived:
            clauses.append("r.archived_at IS NULL")
        where_sql = ("WHERE " + " AND ".join(clauses) + " ") if clauses else ""
        with self._connect() as db:
            rows = db.execute(
                "SELECT r.*, COUNT(m.id) AS message_count, MAX(m.created_at) AS last_message_at "
                f"FROM rooms r LEFT JOIN messages m ON m.room_id=r.id {where_sql}"
                "GROUP BY r.id ORDER BY last_message_at DESC, r.created_at DESC LIMIT ?",
                (limit,),
            ).fetchall()
        return [dict(row) for row in rows]

    def messages(self, room_id: str, limit: int = 200, before: int | None = None) -> list[dict[str, Any]]:
        limit = max(1, min(limit, 1000))
        sql = "SELECT rowid AS member_sequence, * FROM messages WHERE room_id=?"
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

    def all_messages(
        self,
        room_id: str,
        *,
        since: int | None = None,
        until: int | None = None,
    ) -> list[dict[str, Any]]:
        """Chronological full export of a room's messages (no 1000-row cap)."""
        sql = "SELECT rowid AS member_sequence, * FROM messages WHERE room_id=?"
        params: list[Any] = [room_id]
        if since is not None:
            sql += " AND created_at>=?"
            params.append(int(since))
        if until is not None:
            sql += " AND created_at<=?"
            params.append(int(until))
        sql += " ORDER BY created_at ASC, rowid ASC"
        with self._connect() as db:
            rows = db.execute(sql, params).fetchall()
        return [self._message_dict(row) for row in rows]

    def export_text(
        self,
        room_id: str,
        *,
        since: int | None = None,
        until: int | None = None,
    ) -> str:
        info = self.room_info(room_id)
        title = info["title"] if info else room_id
        msgs = self.all_messages(room_id, since=since, until=until)
        return format_messages_as_text(room_id, title, msgs)

    def export_csv(
        self,
        room_id: str,
        *,
        since: int | None = None,
        until: int | None = None,
    ) -> str:
        msgs = self.all_messages(room_id, since=since, until=until)
        return format_messages_as_csv(msgs)

    def search(self, query: str, room_id: str | None = None, limit: int = 50) -> list[dict[str, Any]]:
        if not query.strip():
            return []
        limit = max(1, min(limit, 200))
        sql = (
            "SELECT m.rowid AS member_sequence, m.*, bm25(messages_fts) AS rank FROM messages_fts "
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

    def claim_scope(
        self,
        scope: str,
        actor: str,
        *,
        task_id: str | None = None,
        note: str | None = None,
        ttl: int = 3600,
    ) -> dict[str, Any]:
        scope = scope.strip().replace("\\", "/")
        actor = actor.strip()
        if not scope or not actor:
            raise ValueError("scope and actor are required")
        now = int(time.time())
        expires_at = now + max(300, min(int(ttl), 43200))
        with self._connect() as db:
            db.execute("BEGIN IMMEDIATE")
            db.execute("DELETE FROM scope_claims WHERE expires_at<=?", (now,))
            existing = db.execute("SELECT * FROM scope_claims WHERE scope=?", (scope,)).fetchone()
            if existing and existing["actor"] != actor:
                db.commit()
                return {"claimed": False, "conflict": dict(existing)}
            db.execute(
                "INSERT INTO scope_claims(scope,actor,task_id,note,claimed_at,expires_at) VALUES(?,?,?,?,?,?) "
                "ON CONFLICT(scope) DO UPDATE SET task_id=excluded.task_id,note=excluded.note,"
                "claimed_at=excluded.claimed_at,expires_at=excluded.expires_at",
                (scope, actor, task_id, note, now, expires_at),
            )
            db.commit()
        return {
            "claimed": True,
            "claim": {
                "scope": scope,
                "actor": actor,
                "task_id": task_id,
                "note": note,
                "claimed_at": now,
                "expires_at": expires_at,
            },
        }

    def release_scope(self, scope: str, actor: str) -> dict[str, Any]:
        scope = scope.strip().replace("\\", "/")
        with self._connect() as db:
            existing = db.execute("SELECT * FROM scope_claims WHERE scope=?", (scope,)).fetchone()
            if not existing:
                return {"released": False, "reason": "not claimed"}
            if existing["actor"] != actor:
                return {"released": False, "reason": "claimed by another actor", "claim": dict(existing)}
            db.execute("DELETE FROM scope_claims WHERE scope=? AND actor=?", (scope, actor))
        return {"released": True, "scope": scope}

    def scopes(self) -> list[dict[str, Any]]:
        now = int(time.time())
        with self._connect() as db:
            db.execute("DELETE FROM scope_claims WHERE expires_at<=?", (now,))
            rows = db.execute("SELECT * FROM scope_claims ORDER BY scope").fetchall()
        return [dict(row) for row in rows]

    @staticmethod
    def _message_dict(row: sqlite3.Row) -> dict[str, Any]:
        data = dict(row)
        data.pop("rank", None)
        data["attachments"] = json.loads(data.pop("attachments_json"))
        return data


class MariaConversationStore:
    """Authoritative conversation store hosted by the central MariaDB service."""

    def __init__(self, url: str):
        import pymysql

        parsed = urlparse(url)
        if parsed.scheme not in {"mariadb", "mysql"} or not parsed.hostname or not parsed.path.strip("/"):
            raise ValueError("TROLL_BOOTH_MARIADB_URL must be mariadb://user:password@host/database")
        self._pymysql = pymysql
        self.config = {
            "host": parsed.hostname, "port": parsed.port or 3306,
            "user": unquote(parsed.username or ""), "password": unquote(parsed.password or ""),
            "database": parsed.path.strip("/"), "charset": "utf8mb4", "autocommit": False,
            "cursorclass": pymysql.cursors.DictCursor, "connect_timeout": 5,
            "read_timeout": 15, "write_timeout": 15,
        }
        self._initialize()

    def _connect(self):
        return self._pymysql.connect(**self.config)

    def _initialize(self) -> None:
        statements = [
            """CREATE TABLE IF NOT EXISTS troll_rooms (
                id VARCHAR(191) PRIMARY KEY, title VARCHAR(255) NOT NULL,
                ticket_id VARCHAR(191) NULL, task_id VARCHAR(191) NULL, created_at BIGINT NOT NULL,
                archived_at BIGINT NULL, removed_at BIGINT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci""",
            """CREATE TABLE IF NOT EXISTS troll_messages (
                id VARCHAR(191) PRIMARY KEY, room_id VARCHAR(191) NOT NULL,
                sender VARCHAR(191) NOT NULL, body LONGTEXT NOT NULL,
                ticket_id VARCHAR(191) NULL, task_id VARCHAR(191) NULL,
                attachments_json LONGTEXT NOT NULL, created_at BIGINT NOT NULL,
                previous_hash CHAR(64) NULL, message_hash CHAR(64) NOT NULL UNIQUE,
                INDEX troll_messages_room_time(room_id, created_at),
                FULLTEXT INDEX troll_messages_search(sender, body),
                CONSTRAINT troll_messages_room_fk FOREIGN KEY(room_id) REFERENCES troll_rooms(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci""",
            """CREATE TABLE IF NOT EXISTS troll_scope_claims (
                scope VARCHAR(512) PRIMARY KEY, actor VARCHAR(191) NOT NULL,
                task_id VARCHAR(191) NULL, note TEXT NULL, claimed_at BIGINT NOT NULL,
                expires_at BIGINT NOT NULL, INDEX troll_scope_expiry(expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci""",
        ]
        with self._connect() as db:
            with db.cursor() as cursor:
                for statement in statements:
                    cursor.execute(statement)
                cursor.execute("SHOW COLUMNS FROM troll_rooms")
                existing_cols = {row["Field"] for row in cursor.fetchall()}
                for col in ("archived_at", "removed_at"):
                    if col not in existing_cols:
                        cursor.execute(f"ALTER TABLE troll_rooms ADD COLUMN {col} BIGINT NULL")
            db.commit()

    def ensure_room(self, room_id: str, title: str, ticket_id: str | None = None, task_id: str | None = None) -> None:
        with self._connect() as db:
            with db.cursor() as cursor:
                cursor.execute(
                    "INSERT INTO troll_rooms(id,title,ticket_id,task_id,created_at) VALUES(%s,%s,%s,%s,%s) "
                    "ON DUPLICATE KEY UPDATE title=VALUES(title),ticket_id=COALESCE(ticket_id,VALUES(ticket_id)),task_id=COALESCE(task_id,VALUES(task_id))",
                    (room_id, title, ticket_id, task_id, int(time.time())),
                )
            db.commit()

    def rename_room(self, room_id: str, new_title: str) -> bool:
        rid = room_id.strip()
        title = new_title.strip()
        if not rid or not title:
            raise ValueError("room_id and new_title are required")
        with self._connect() as db:
            with db.cursor() as cursor:
                cursor.execute("UPDATE troll_rooms SET title=%s WHERE id=%s", (title, rid))
                changed = cursor.rowcount
            db.commit()
        return changed >= 1

    def archive_room(self, room_id: str, archived: bool = True) -> bool:
        rid = room_id.strip()
        stamp = int(time.time()) if archived else None
        with self._connect() as db:
            with db.cursor() as cursor:
                cursor.execute("UPDATE troll_rooms SET archived_at=%s WHERE id=%s", (stamp, rid))
                changed = cursor.rowcount
            db.commit()
        return changed >= 1

    def soft_remove_room(self, room_id: str, removed: bool = True) -> bool:
        rid = room_id.strip()
        stamp = int(time.time()) if removed else None
        with self._connect() as db:
            with db.cursor() as cursor:
                cursor.execute("UPDATE troll_rooms SET removed_at=%s WHERE id=%s", (stamp, rid))
                changed = cursor.rowcount
            db.commit()
        return changed >= 1

    def room_info(self, room_id: str) -> dict[str, Any] | None:
        with self._connect() as db:
            with db.cursor() as cursor:
                cursor.execute("SELECT * FROM troll_rooms WHERE id=%s", (room_id,))
                row = cursor.fetchone()
        return dict(row) if row else None

    def append(self, room_id: str, sender: str, body: str, *, title: str | None = None,
               ticket_id: str | None = None, task_id: str | None = None,
               attachments: list[dict[str, Any]] | None = None, created_at: int | None = None) -> str:
        if not room_id.strip() or not sender.strip() or not body.strip():
            raise ValueError("room, sender, and body are required")
        message_id, timestamp, attachment_data = secrets.token_urlsafe(18), created_at or int(time.time()), attachments or []
        with self._connect() as db:
            with db.cursor() as cursor:
                cursor.execute("INSERT IGNORE INTO troll_rooms(id,title,ticket_id,task_id,created_at) VALUES(%s,%s,%s,%s,%s)",
                               (room_id, title or room_id, ticket_id, task_id, timestamp))
                cursor.execute("SELECT message_hash FROM troll_messages WHERE room_id=%s ORDER BY created_at DESC,id DESC LIMIT 1 FOR UPDATE", (room_id,))
                prior = cursor.fetchone()
                previous_hash = prior["message_hash"] if prior else None
                canonical = json.dumps({"id": message_id, "room_id": room_id, "sender": sender, "body": body,
                    "ticket_id": ticket_id, "task_id": task_id, "attachments": attachment_data,
                    "created_at": timestamp, "previous_hash": previous_hash}, ensure_ascii=False, sort_keys=True, separators=(",", ":"))
                message_hash = hashlib.sha256(canonical.encode("utf-8")).hexdigest()
                cursor.execute("INSERT INTO troll_messages(id,room_id,sender,body,ticket_id,task_id,attachments_json,created_at,previous_hash,message_hash) VALUES(%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)",
                    (message_id, room_id, sender, body, ticket_id, task_id, json.dumps(attachment_data), timestamp, previous_hash, message_hash))
            db.commit()
        return message_id

    def rooms(
        self,
        limit: int = 100,
        *,
        include_archived: bool = False,
        include_removed: bool = False,
    ) -> list[dict[str, Any]]:
        clauses = []
        if not include_removed:
            clauses.append("r.removed_at IS NULL")
        if not include_archived:
            clauses.append("r.archived_at IS NULL")
        where_sql = ("WHERE " + " AND ".join(clauses) + " ") if clauses else ""
        with self._connect() as db:
            with db.cursor() as cursor:
                cursor.execute(
                    "SELECT r.*,COALESCE(m.message_count,0) message_count,m.last_message_at "
                    "FROM troll_rooms r LEFT JOIN ("
                    "SELECT room_id,COUNT(*) message_count,MAX(created_at) last_message_at "
                    f"FROM troll_messages GROUP BY room_id) m ON m.room_id=r.id {where_sql}"
                    "ORDER BY m.last_message_at DESC,r.created_at DESC LIMIT %s",
                    (max(1, min(limit, 500)),),
                )
                return list(cursor.fetchall())

    def messages(self, room_id: str, limit: int = 200, before: int | None = None) -> list[dict[str, Any]]:
        sql, params = "SELECT * FROM troll_messages WHERE room_id=%s", [room_id]
        if before is not None:
            sql += " AND created_at<%s"; params.append(before)
        sql += " ORDER BY created_at DESC,id DESC LIMIT %s"; params.append(max(1, min(limit, 1000)))
        with self._connect() as db:
            with db.cursor() as cursor:
                cursor.execute(sql, params); rows = list(cursor.fetchall())
        rows.reverse()
        return [self._message_dict(row) for row in rows]

    def all_messages(
        self,
        room_id: str,
        *,
        since: int | None = None,
        until: int | None = None,
    ) -> list[dict[str, Any]]:
        sql, params = "SELECT * FROM troll_messages WHERE room_id=%s", [room_id]
        if since is not None:
            sql += " AND created_at>=%s"; params.append(int(since))
        if until is not None:
            sql += " AND created_at<=%s"; params.append(int(until))
        sql += " ORDER BY created_at ASC, id ASC"
        with self._connect() as db:
            with db.cursor() as cursor:
                cursor.execute(sql, params); rows = list(cursor.fetchall())
        return [self._message_dict(row) for row in rows]

    def export_text(
        self,
        room_id: str,
        *,
        since: int | None = None,
        until: int | None = None,
    ) -> str:
        info = self.room_info(room_id)
        title = info["title"] if info else room_id
        msgs = self.all_messages(room_id, since=since, until=until)
        return format_messages_as_text(room_id, title, msgs)

    def export_csv(
        self,
        room_id: str,
        *,
        since: int | None = None,
        until: int | None = None,
    ) -> str:
        msgs = self.all_messages(room_id, since=since, until=until)
        return format_messages_as_csv(msgs)

    def search(self, query: str, room_id: str | None = None, limit: int = 50) -> list[dict[str, Any]]:
        if not query.strip(): return []
        sql, params = "SELECT * FROM troll_messages WHERE MATCH(sender,body) AGAINST(%s IN NATURAL LANGUAGE MODE)", [query]
        if room_id: sql += " AND room_id=%s"; params.append(room_id)
        sql += " ORDER BY created_at DESC LIMIT %s"; params.append(max(1, min(limit, 200)))
        with self._connect() as db:
            with db.cursor() as cursor:
                cursor.execute(sql, params); return [self._message_dict(row) for row in cursor.fetchall()]

    def claim_scope(self, scope: str, actor: str, *, task_id: str | None = None, note: str | None = None, ttl: int = 3600) -> dict[str, Any]:
        scope, actor, now = scope.strip().replace("\\", "/"), actor.strip(), int(time.time())
        expires = now + max(300, min(int(ttl), 43200))
        with self._connect() as db:
            with db.cursor() as cursor:
                cursor.execute("DELETE FROM troll_scope_claims WHERE expires_at<=%s", (now,))
                cursor.execute("SELECT * FROM troll_scope_claims WHERE scope=%s FOR UPDATE", (scope,)); existing = cursor.fetchone()
                if existing and existing["actor"] != actor:
                    db.commit(); return {"claimed": False, "conflict": existing}
                cursor.execute("INSERT INTO troll_scope_claims(scope,actor,task_id,note,claimed_at,expires_at) VALUES(%s,%s,%s,%s,%s,%s) ON DUPLICATE KEY UPDATE task_id=VALUES(task_id),note=VALUES(note),claimed_at=VALUES(claimed_at),expires_at=VALUES(expires_at)", (scope, actor, task_id, note, now, expires))
            db.commit()
        return {"claimed": True, "claim": {"scope": scope, "actor": actor, "task_id": task_id, "note": note, "claimed_at": now, "expires_at": expires}}

    def release_scope(self, scope: str, actor: str) -> dict[str, Any]:
        with self._connect() as db:
            with db.cursor() as cursor:
                cursor.execute("SELECT * FROM troll_scope_claims WHERE scope=%s", (scope,)); existing = cursor.fetchone()
                if not existing: return {"released": False, "reason": "not claimed"}
                if existing["actor"] != actor: return {"released": False, "reason": "claimed by another actor", "claim": existing}
                cursor.execute("DELETE FROM troll_scope_claims WHERE scope=%s AND actor=%s", (scope, actor))
            db.commit()
        return {"released": True, "scope": scope}

    def scopes(self) -> list[dict[str, Any]]:
        with self._connect() as db:
            with db.cursor() as cursor:
                cursor.execute("DELETE FROM troll_scope_claims WHERE expires_at<=%s", (int(time.time()),))
                cursor.execute("SELECT * FROM troll_scope_claims ORDER BY scope"); rows = list(cursor.fetchall())
            db.commit()
        return rows

    @staticmethod
    def _message_dict(row: dict[str, Any]) -> dict[str, Any]:
        data = dict(row); data["attachments"] = json.loads(data.pop("attachments_json")); return data


def open_conversation_store(*, allow_offline_cache: bool = True):
    """Open MariaDB when configured; SQLite is only an explicit offline cache."""
    from .secret_config import load_mariadb_url
    url = load_mariadb_url()
    if url:
        try:
            return MariaConversationStore(url), "MARIADB"
        except Exception:
            # The owner console must remain reachable when the central host is
            # rebooting, disconnected, or otherwise unavailable. Agent/server
            # processes pass allow_offline_cache=False and still fail closed;
            # only an explicitly offline-capable caller may use the local copy.
            if not allow_offline_cache:
                raise
            path = Path(os.environ.get("TROLL_BOOTH_OFFLINE_CACHE", Path.home() / ".troll-booth" / "history.sqlite3"))
            return ConversationStore(path), "OFFLINE CACHE · MARIADB UNREACHABLE"
    if not allow_offline_cache:
        raise RuntimeError("TROLL_BOOTH_MARIADB_URL is not configured")
    path = Path(os.environ.get("TROLL_BOOTH_OFFLINE_CACHE", Path.home() / ".troll-booth" / "history.sqlite3"))
    return ConversationStore(path), "OFFLINE CACHE"

# ===== SNAPSMACK EOF =====
