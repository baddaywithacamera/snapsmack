"""Durable approved grants and client registrations for the MCP adapter.

SNAPSMACK_EOF_HEADER: preserve the final EOF marker.
"""

from contextlib import contextmanager
from hashlib import sha256
import json
import os
from pathlib import Path
from tempfile import NamedTemporaryFile

from cryptography.fernet import Fernet
from mcp.shared.auth import OAuthClientInformationFull

from .history import MariaConversationStore


def load_client_key(path: Path) -> bytes:
    """Keep the client encryption key outside the conversation database."""
    path.parent.mkdir(parents=True, exist_ok=True)
    try:
        with path.open("rb") as handle:
            return handle.read().strip()
    except FileNotFoundError:
        pass
    key = Fernet.generate_key()
    with NamedTemporaryFile(dir=path.parent, prefix=".oauth-key-", delete=False) as handle:
        temporary = Path(handle.name)
        handle.write(key)
        handle.flush()
        os.fsync(handle.fileno())
    try:
        os.link(temporary, path)
        return key
    except FileExistsError:
        return path.read_bytes().strip()
    finally:
        temporary.unlink()



class OAuthGrantStore:
    """Tokens are stored as verifiers and tied to one revocable grant."""

    def __init__(self, connect, client_key: bytes, issuer: str, *, mariadb: bool):
        self._connect = connect
        self._mariadb = mariadb
        self._issuer = issuer.rstrip("/").encode("utf-8")
        self._client_cipher = Fernet(client_key)
        engine = " ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" if mariadb else ""
        with self._transaction() as execute:
            execute("""CREATE TABLE IF NOT EXISTS troll_oauth_clients (
                client_hash CHAR(64) PRIMARY KEY, encrypted_client BLOB NOT NULL
            )""" + engine)
            execute("""CREATE TABLE IF NOT EXISTS troll_oauth_grants (
                refresh_hash CHAR(64) PRIMARY KEY, client_id TEXT NOT NULL,
                subject TEXT NOT NULL, resource TEXT, scopes TEXT NOT NULL
            )""" + engine)
            execute("""CREATE TABLE IF NOT EXISTS troll_oauth_access (
                access_hash CHAR(64) PRIMARY KEY, refresh_hash CHAR(64) NOT NULL,
                scopes TEXT NOT NULL,
                FOREIGN KEY (refresh_hash) REFERENCES troll_oauth_grants(refresh_hash) ON DELETE CASCADE
            )""" + engine)

    @classmethod
    def for_mariadb(cls, url: str, client_key: bytes, issuer: str):
        history = MariaConversationStore(url)
        return cls(history._connect, client_key, issuer, mariadb=True)

    def _verifier(self, value):
        return sha256(self._issuer + b"\0" + value.encode("utf-8")).hexdigest()

    @contextmanager
    def _transaction(self, *, writing=False):
        db = self._connect()
        cursor = db.cursor()
        try:
            if writing and not self._mariadb:
                cursor.execute("BEGIN IMMEDIATE")

            def execute(sql, params=()):
                cursor.execute(sql if self._mariadb else sql.replace("%s", "?"), params)
                return cursor

            yield execute
            db.commit()
        except BaseException:
            db.rollback()
            raise
        finally:
            cursor.close()
            db.close()

    def save_client(self, client):
        payload = self._client_cipher.encrypt(client.model_dump_json().encode("utf-8"))
        with self._transaction(writing=True) as execute:
            execute(
                "REPLACE INTO troll_oauth_clients (client_hash, encrypted_client) VALUES (%s,%s)",
                (self._verifier(client.client_id), payload),
            )

    def get_client(self, client_id):
        with self._transaction() as execute:
            row = execute(
                "SELECT encrypted_client FROM troll_oauth_clients WHERE client_hash=%s",
                (self._verifier(client_id),),
            ).fetchone()
        if row is None:
            return None
        result = OAuthClientInformationFull.model_validate_json(
            self._client_cipher.decrypt(row["encrypted_client"])
        )
        return result if result.client_id == client_id else None

    def issue(self, access, refresh):
        refresh_hash = self._verifier(refresh.token)
        with self._transaction(writing=True) as execute:
            execute(
                """INSERT INTO troll_oauth_grants (refresh_hash,client_id,subject,resource,scopes)
                   VALUES (%s,%s,%s,%s,%s)""",
                (refresh_hash, access.client_id, access.subject, access.resource, json.dumps(refresh.scopes)),
            )
            self._add_access(execute, access.token, refresh_hash, access.scopes)

    def _add_access(self, execute, token, refresh_hash, scopes):
        execute(
            "INSERT INTO troll_oauth_access (access_hash,refresh_hash,scopes) VALUES (%s,%s,%s)",
            (self._verifier(token), refresh_hash, json.dumps(scopes)),
        )

    def get_access(self, token):
        with self._transaction() as execute:
            row = execute(
                """SELECT g.client_id,g.subject,g.resource,a.scopes FROM troll_oauth_access a
                   JOIN troll_oauth_grants g ON a.refresh_hash=g.refresh_hash WHERE a.access_hash=%s""",
                (self._verifier(token),),
            ).fetchone()
        return self._decoded(row)

    def _refresh_row(self, execute, token, *, lock=False):
        suffix = " FOR UPDATE" if lock and self._mariadb else ""
        return execute(
            "SELECT client_id,subject,resource,scopes FROM troll_oauth_grants WHERE refresh_hash=%s" + suffix,
            (self._verifier(token),),
        ).fetchone()

    @staticmethod
    def _decoded(row):
        return {**dict(row), "scopes": json.loads(row["scopes"])} if row is not None else None

    def get_refresh(self, token, client_id):
        with self._transaction() as execute:
            row = self._refresh_row(execute, token)
        return self._decoded(row) if row is not None and row["client_id"] == client_id else None

    def refresh_access(self, refresh, client_id, token, requested):
        # Hold the grant lock until its new access token is committed. Revoking
        # the grant atomically removes every access verifier, including this one.
        with self._transaction(writing=True) as execute:
            row = self._refresh_row(execute, refresh, lock=True)
            if row is None or row["client_id"] != client_id:
                return None
            approved = json.loads(row["scopes"])
            scopes = [scope for scope in requested if scope in approved] if requested else approved
            self._add_access(execute, token, self._verifier(refresh), scopes)
            return scopes

    def revoke(self, token):
        verifier = self._verifier(token)
        with self._transaction(writing=True) as execute:
            execute(
                """DELETE FROM troll_oauth_grants WHERE refresh_hash=%s OR refresh_hash IN
                   (SELECT refresh_hash FROM troll_oauth_access WHERE access_hash=%s)""",
                (verifier, verifier),
            )


# ===== SNAPSMACK EOF =====
