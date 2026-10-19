from __future__ import annotations

import hashlib
import json
import secrets
import sqlite3
import time
from pathlib import Path
from typing import Any


class BoothStore:
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
                CREATE TABLE IF NOT EXISTS pair_codes (
                    code_hash TEXT PRIMARY KEY,
                    actor TEXT NOT NULL,
                    scopes_json TEXT NOT NULL,
                    expires_at INTEGER NOT NULL,
                    used_at INTEGER
                );
                CREATE TABLE IF NOT EXISTS jobs (
                    id TEXT PRIMARY KEY,
                    actor TEXT NOT NULL,
                    capability TEXT NOT NULL,
                    arguments_json TEXT NOT NULL,
                    reason TEXT NOT NULL,
                    ticket_id TEXT,
                    status TEXT NOT NULL,
                    created_at INTEGER NOT NULL,
                    claimed_at INTEGER,
                    finished_at INTEGER,
                    result_json TEXT,
                    error TEXT
                );
                CREATE TABLE IF NOT EXISTS audit (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    happened_at INTEGER NOT NULL,
                    actor TEXT NOT NULL,
                    event TEXT NOT NULL,
                    detail_json TEXT NOT NULL
                );
                CREATE TABLE IF NOT EXISTS workers (
                    worker_id TEXT PRIMARY KEY,
                    last_seen INTEGER NOT NULL,
                    version TEXT NOT NULL,
                    apps_json TEXT NOT NULL
                );
                """
            )

    @staticmethod
    def _hash_code(code: str) -> str:
        return hashlib.sha256(code.encode("utf-8")).hexdigest()

    def issue_pair_code(self, actor: str, scopes: list[str], ttl: int = 300) -> str:
        code = "-".join((secrets.token_hex(2), secrets.token_hex(2), secrets.token_hex(2))).upper()
        now = int(time.time())
        with self._connect() as db:
            db.execute("DELETE FROM pair_codes WHERE expires_at < ? OR used_at IS NOT NULL", (now,))
            db.execute(
                "INSERT INTO pair_codes(code_hash,actor,scopes_json,expires_at) VALUES(?,?,?,?)",
                (self._hash_code(code), actor, json.dumps(scopes), now + ttl),
            )
        self.audit(actor, "pair-code.issued", {"expires_at": now + ttl, "scopes": scopes})
        return code

    def consume_pair_code(self, code: str) -> dict[str, Any] | None:
        now = int(time.time())
        digest = self._hash_code(code.strip().upper())
        with self._connect() as db:
            row = db.execute(
                "SELECT * FROM pair_codes WHERE code_hash=? AND used_at IS NULL AND expires_at>=?",
                (digest, now),
            ).fetchone()
            if not row:
                return None
            changed = db.execute(
                "UPDATE pair_codes SET used_at=? WHERE code_hash=? AND used_at IS NULL",
                (now, digest),
            ).rowcount
            if changed != 1:
                return None
        data = {"actor": row["actor"], "scopes": json.loads(row["scopes_json"])}
        self.audit(data["actor"], "pair-code.consumed", {"scopes": data["scopes"]})
        return data

    def enqueue(self, actor: str, capability: str, arguments: dict[str, Any], reason: str, ticket_id: str | None) -> str:
        job_id = secrets.token_urlsafe(18)
        now = int(time.time())
        with self._connect() as db:
            db.execute(
                "INSERT INTO jobs(id,actor,capability,arguments_json,reason,ticket_id,status,created_at) "
                "VALUES(?,?,?,?,?,?,?,?)",
                (job_id, actor, capability, json.dumps(arguments), reason, ticket_id, "queued", now),
            )
        self.audit(actor, "job.queued", {"job_id": job_id, "capability": capability, "ticket_id": ticket_id})
        return job_id

    def claim(self) -> dict[str, Any] | None:
        now = int(time.time())
        with self._connect() as db:
            db.execute("BEGIN IMMEDIATE")
            row = db.execute("SELECT * FROM jobs WHERE status='queued' ORDER BY created_at,id LIMIT 1").fetchone()
            if not row:
                db.commit()
                return None
            changed = db.execute(
                "UPDATE jobs SET status='claimed',claimed_at=? WHERE id=? AND status='queued'", (now, row["id"])
            ).rowcount
            db.commit()
            if changed != 1:
                return None
        return {
            "id": row["id"],
            "actor": row["actor"],
            "capability": row["capability"],
            "arguments": json.loads(row["arguments_json"]),
            "reason": row["reason"],
            "ticket_id": row["ticket_id"],
        }

    def finish(self, job_id: str, result: dict[str, Any] | None, error: str | None = None) -> bool:
        now = int(time.time())
        status = "failed" if error else "complete"
        with self._connect() as db:
            changed = db.execute(
                "UPDATE jobs SET status=?,finished_at=?,result_json=?,error=? WHERE id=? AND status='claimed'",
                (status, now, json.dumps(result) if result is not None else None, error, job_id),
            ).rowcount
        if changed:
            self.audit("worker", f"job.{status}", {"job_id": job_id})
        return changed == 1

    def job(self, job_id: str) -> dict[str, Any] | None:
        with self._connect() as db:
            row = db.execute("SELECT * FROM jobs WHERE id=?", (job_id,)).fetchone()
        if not row:
            return None
        return {
            "id": row["id"],
            "status": row["status"],
            "result": json.loads(row["result_json"]) if row["result_json"] else None,
            "error": row["error"],
            "created_at": row["created_at"],
            "finished_at": row["finished_at"],
        }

    def take_job(self, job_id: str) -> dict[str, Any] | None:
        """Return a terminal result once, then remove its readable payload from the relay."""
        with self._connect() as db:
            db.execute("BEGIN IMMEDIATE")
            row = db.execute(
                "SELECT * FROM jobs WHERE id=? AND status IN ('complete','failed')", (job_id,)
            ).fetchone()
            if not row:
                db.commit()
                return None
            result = {
                "id": row["id"],
                "status": row["status"],
                "result": json.loads(row["result_json"]) if row["result_json"] else None,
                "error": row["error"],
                "created_at": row["created_at"],
                "finished_at": row["finished_at"],
            }
            db.execute("DELETE FROM jobs WHERE id=?", (job_id,))
            db.commit()
        self.audit("relay", "job.result-consumed", {"job_id": job_id, "status": result["status"]})
        return result

    def heartbeat(self, worker_id: str, version: str, apps: list[dict[str, Any]]) -> None:
        now = int(time.time())
        with self._connect() as db:
            db.execute(
                "INSERT INTO workers(worker_id,last_seen,version,apps_json) VALUES(?,?,?,?) "
                "ON CONFLICT(worker_id) DO UPDATE SET last_seen=excluded.last_seen,version=excluded.version,apps_json=excluded.apps_json",
                (worker_id, now, version, json.dumps(apps)),
            )

    def status(self) -> dict[str, Any]:
        now = int(time.time())
        with self._connect() as db:
            workers = [dict(row) for row in db.execute("SELECT * FROM workers ORDER BY worker_id")]
            queued = db.execute("SELECT COUNT(*) FROM jobs WHERE status='queued'").fetchone()[0]
        for worker in workers:
            worker["online"] = now - worker["last_seen"] <= 30
            worker["apps"] = json.loads(worker.pop("apps_json"))
        return {"workers": workers, "queued_jobs": queued, "server_time": now}

    def audit(self, actor: str, event: str, detail: dict[str, Any]) -> None:
        with self._connect() as db:
            db.execute(
                "INSERT INTO audit(happened_at,actor,event,detail_json) VALUES(?,?,?,?)",
                (int(time.time()), actor, event, json.dumps(detail)),
            )

