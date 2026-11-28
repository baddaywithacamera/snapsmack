"""PROX VOX P1b: audited, server-enforced read access inside containers.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

from contextlib import contextmanager
from dataclasses import dataclass
import hashlib
import posixpath
import re

from .broker import Refused
from .secrets import blocked_read_reason
from .credentials import load_container_secret


@dataclass(frozen=True)
class ContainerTarget:
    name: str
    host: str
    username: str
    credential_kind: str
    roots: tuple
    ssh_port: int = 22


@dataclass(frozen=True)
class DatabaseTarget:
    host: str
    username: str
    credential_kind: str = "mysql_ro"
    port: int = 3306


_SELECT = re.compile(r"^\s*SELECT\b", re.IGNORECASE)
_FORBIDDEN_SELECT = re.compile(
    r"\b(?:INTO\s+(?:OUTFILE|DUMPFILE)|FOR\s+UPDATE|LOCK\s+IN\s+SHARE\s+MODE|"
    r"LOAD_FILE\s*\(|SLEEP\s*\(|BENCHMARK\s*\()",
    re.IGNORECASE,
)


def _safe_path(target: ContainerTarget, path: str) -> str:
    raw = str(path or "")
    if not raw.startswith("/") or "\x00" in raw or "\\" in raw:
        raise Refused("Container reads require an absolute POSIX path.")
    clean = posixpath.normpath(raw)
    if any(clean == root or clean.startswith(root.rstrip("/") + "/")
           for root in target.roots):
        return clean
    raise Refused("That path is outside the configured read-only roots.")


def _resolved_safe_path(target: ContainerTarget, sftp, path: str) -> str:
    """Resolve remote symlinks, then repeat the root check on the real path."""
    resolved = posixpath.normpath(str(sftp.normalize(path)))
    resolved_roots = tuple(
        posixpath.normpath(str(sftp.normalize(root))) for root in target.roots
    )
    if any(resolved == root or resolved.startswith(root.rstrip("/") + "/")
           for root in resolved_roots):
        return resolved
    raise Refused("That path resolves outside the configured read-only roots.")


def _select_only(sql: str) -> str:
    query = str(sql or "").strip()
    one = query[:-1].rstrip() if query.endswith(";") else query
    if (not _SELECT.match(one) or ";" in one or "--" in one or "/*" in one
            or _FORBIDDEN_SELECT.search(one)):
        raise Refused("P1b database access accepts one side-effect-free SELECT only.")
    return one


class ContainerReadBroker:
    """Read files over forced read-only SFTP and query through a RO DB grant."""

    def __init__(self, targets, database: DatabaseTarget, audit,
                 credential_reader=None, ssh_factory=None, mysql_factory=None):
        self.targets = {target.name: target for target in targets}
        self.database = database
        self.audit = audit
        self._credential_reader = credential_reader
        self._ssh_factory = ssh_factory
        self._mysql_factory = mysql_factory

    def _target(self, name: str) -> ContainerTarget:
        try:
            return self.targets[str(name)]
        except KeyError as err:
            raise Refused("Unknown PROX VOX container %r." % name) from err

    def _open_ssh(self, target: ContainerTarget, password: str):
        if self._ssh_factory is not None:
            return self._ssh_factory(target, password)
        try:                                                    # pragma: no cover
            import paramiko
        except ImportError as err:
            raise RuntimeError("PROX VOX P1b requires paramiko.") from err
        client = paramiko.SSHClient()
        client.load_system_host_keys()
        client.set_missing_host_key_policy(paramiko.RejectPolicy())
        client.connect(
            target.host, port=target.ssh_port, username=target.username,
            password=password, allow_agent=False, look_for_keys=False,
            timeout=15,
        )
        return client

    @contextmanager
    def _ssh(self, target: ContainerTarget):
        password = load_container_secret(target.credential_kind, self._credential_reader)
        client = self._open_ssh(target, password)
        try:
            yield client
        finally:
            client.close()

    def _open_mysql(self, target, ssh_password, db_password, database):
        if self._mysql_factory is not None:
            return self._mysql_factory(
                target, ssh_password, self.database, db_password, database
            )
        try:                                                    # pragma: no cover
            import pymysql
        except ImportError as err:
            raise RuntimeError("PROX VOX P1b requires PyMySQL.") from err
        ssh = self._open_ssh(target, ssh_password)
        try:
            channel = ssh.get_transport().open_channel(
                "direct-tcpip", (self.database.host, self.database.port),
                ("127.0.0.1", 0),
            )
            mysql = pymysql.connect(
                user=self.database.username, password=db_password,
                database=database, autocommit=False,
                read_timeout=30, write_timeout=15, defer_connect=True,
            )
            mysql.connect(channel)
        except Exception:
            ssh.close()
            raise

        class _TunnelledConnection:
            def cursor(self):
                return mysql.cursor()

            def rollback(self):
                return mysql.rollback()

            def close(self):
                try:
                    mysql.close()
                finally:
                    ssh.close()

        return _TunnelledConnection()

    def listdir(self, container: str, path: str) -> list:
        try:
            target = self._target(container)
            clean = _safe_path(target, path)
        except Refused as err:
            self.audit.record("container_read_refused", {
                "container": str(container), "operation": "list",
                "path": str(path), "why": str(err),
            })
            raise
        with self._ssh(target) as client:
            sftp = client.open_sftp()
            try:
                try:
                    resolved = _resolved_safe_path(target, sftp, clean)
                except Refused as err:
                    self.audit.record("container_read_refused", {
                        "container": target.name, "operation": "list",
                        "path": clean, "why": str(err),
                    })
                    raise
                self.audit.record("container_read", {
                    "container": target.name, "operation": "list", "path": resolved,
                })
                return sorted(sftp.listdir(resolved))
            finally:
                sftp.close()

    def read_file(self, container: str, path: str) -> bytes:
        # SEAN, 2026-11-28 15:02. The four-entry read blocklist, enforced
        # BEFORE the target is resolved or any connection is made, and LOGGED
        # rather than silently dropped -- his words: "A blocked read gets
        # logged, not silently dropped."
        blocked = blocked_read_reason(path)
        if blocked:
            self.audit.record("container_read_blocked", {
                "container": str(container), "operation": "read",
                "path": str(path), "why": blocked,
            })
            raise Refused(blocked)
        try:
            target = self._target(container)
            clean = _safe_path(target, path)
        except Refused as err:
            self.audit.record("container_read_refused", {
                "container": str(container), "operation": "read",
                "path": str(path), "why": str(err),
            })
            raise
        with self._ssh(target) as client:
            sftp = client.open_sftp()
            try:
                try:
                    resolved = _resolved_safe_path(target, sftp, clean)
                except Refused as err:
                    self.audit.record("container_read_refused", {
                        "container": target.name, "operation": "read",
                        "path": clean, "why": str(err),
                    })
                    raise
                self.audit.record("container_read", {
                    "container": target.name, "operation": "read", "path": resolved,
                })
                with sftp.open(resolved, "rb") as stream:
                    data = stream.read()
            finally:
                sftp.close()
        return data

    def select(self, gateway: str, database: str, sql: str, params=(),
               maximum_rows: int = 10_000) -> dict:
        try:
            target = self._target(gateway)
            query = _select_only(sql)
        except Refused as err:
            self.audit.record("container_db_refused", {
                "gateway": str(gateway), "database": str(database),
                "query_sha256": hashlib.sha256(str(sql).encode("utf-8")).hexdigest(),
                "why": str(err),
            })
            raise
        digest = hashlib.sha256(query.encode("utf-8")).hexdigest()
        self.audit.record("container_db_read", {
            "gateway": target.name, "database": str(database),
            "query_sha256": digest, "maximum_rows": int(maximum_rows),
        })
        ssh_password = load_container_secret(target.credential_kind, self._credential_reader)
        db_password = load_container_secret(self.database.credential_kind,
                                            self._credential_reader)
        connection = self._open_mysql(
            target, ssh_password, db_password, str(database)
        )
        try:
            cursor = connection.cursor()
            try:
                cursor.execute("SET SESSION TRANSACTION READ ONLY")
                cursor.execute("START TRANSACTION READ ONLY")
                cursor.execute(query, tuple(params or ()))
                rows = cursor.fetchmany(max(1, int(maximum_rows)) + 1)
                columns = [item[0] for item in (cursor.description or ())]
            finally:
                cursor.close()
            connection.rollback()
        finally:
            connection.close()
        limit = max(1, int(maximum_rows))
        return {"columns": columns, "rows": rows[:limit], "truncated": len(rows) > limit}

# ===== SNAPSMACK EOF =====
