"""Transactional MariaDB row-update core for an attested PROX VOX action.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the canonical SNAPSMACK EOF marker.
"""

from contextlib import contextmanager
from dataclasses import dataclass
import hashlib
import json
import os
from pathlib import Path
import re
import secrets
import stat
import sys


class UnsafeDatabaseUpdate(ValueError):
    """The target cannot be limited to an inspectable, restorable row."""


_PHP_LITERAL = r"'((?:[^'\\\r\n]|\\['\\])*)'"
_CREDENTIAL_STANZA = re.compile(
    r"(?m)^[ \t]*require_once\s+__DIR__\s*\.\s*'/constants\.php';[ \t]*\r?$"
    + r"(?:\n[ \t]*(?://[^\r\n]*)?\r?$)*"
    + "".join(
        rf"\n[ \t]*\${name}[ \t]*=[ \t]*{_PHP_LITERAL};[ \t]*\r?$"
        for name in ("host", "db", "user", "pass")
    )
)
_SETTING_ASSIGNMENT = re.compile(r"(?m)^[ \t]*\$(host|db|user|pass)[ \t]*=")


def _site_login(content, database):
    """Read installer-generated literals only. Never evaluate the site's PHP."""
    try:
        php = content.decode("utf-8")
    except UnicodeError:
        raise UnsafeDatabaseUpdate("The site database config is not readable UTF-8.") from None
    stanza = _CREDENTIAL_STANZA.search(php)
    if stanza is None or _SETTING_ASSIGNMENT.findall(php) != ["host", "db", "user", "pass"]:
        raise UnsafeDatabaseUpdate("The site database config does not use supported literal settings.")
    host, configured, user, password = (
        re.sub(r"\\(['\\])", r"\1", literal) for literal in stanza.groups()
    )
    if configured != database:
        raise UnsafeDatabaseUpdate("The selected site's database does not match the requested database.")
    if not user or not re.fullmatch(r"[a-zA-Z0-9.-]+", host):
        raise UnsafeDatabaseUpdate("The site database endpoint is not supported.")
    return host, user, password


def _database_connector():
    runtime = Path(__file__).resolve().parent
    if runtime == Path("/usr/local/sbin"):
        wheel = runtime / "proxvox-pymysql.whl"
        checksum = "e169bed906168fffd0088925f45b3df024c2775c6fd128da0df773d47c458840"
        try:
            metadata = wheel.lstat()
            if (not stat.S_ISREG(metadata.st_mode) or metadata.st_uid != 0
                    or metadata.st_mode & 0o022 or metadata.st_size > 131072
                    or hashlib.sha256(wheel.read_bytes()).hexdigest() != checksum):
                raise OSError("invalid connector package")
        except OSError:
            raise UnsafeDatabaseUpdate("The pinned host MariaDB connector is missing or drifted.") from None
        sys.path.insert(0, str(wheel))
    try:
        import pymysql
    except ImportError:
        raise UnsafeDatabaseUpdate("The host MariaDB connector is not installed.") from None
    return pymysql.connect


@contextmanager
def connect_site_database(content, database, *, container, connect=None):
    """Use the site's existing login in memory and suppress driver diagnostics."""
    host, user, password = _site_login(content, database)
    del content
    endpoint = host.lower().rstrip(".")
    if container and (endpoint in {"localhost", "localhost.localdomain"} or endpoint.startswith("127.")):
        raise UnsafeDatabaseUpdate("This site's container-local database needs a local connector.")
    if connect is None:
        connect = _database_connector()

    try:
        connection = connect(host=host, user=user, password=password, database=database,
                             charset="utf8mb4", connect_timeout=10, read_timeout=20,
                             write_timeout=20, binary_prefix=True, autocommit=False)
    except Exception:
        raise UnsafeDatabaseUpdate("Could not connect using the site's database configuration.") from None
    finally:
        del password

    try:
        with connection.cursor() as cursor:
            cursor.execute("SHOW GRANTS FOR CURRENT_USER")
            grants = cursor.fetchall()
        scopes = {f"`{database}`.*", "*.*"}
        for row in grants:
            statement = row[0].partition(" TO ")[0]
            privileges, separator, scope = statement.removeprefix("GRANT ").partition(" ON ")
            if separator and scope in scopes and (
                privileges == "ALL PRIVILEGES" or "TRIGGER" in privileges.split(", ")
            ):
                break
        else:
            raise UnsafeDatabaseUpdate("The site's database login cannot verify table triggers.")
        yield connection
    finally:
        connection.close()


def _identifier(name):
    if not isinstance(name, str) or not re.fullmatch(r"[A-Za-z_][A-Za-z_0-9]{0,63}", name):
        raise UnsafeDatabaseUpdate("Invalid database identifier.")
    return f"`{name}`"


@dataclass(frozen=True)
class RowUpdate:
    database: str
    table: str
    key_column: str
    key: int
    column: str
    value: str

    def __post_init__(self):
        for name in (self.database, self.table, self.key_column, self.column):
            _identifier(name)
        if self.database.lower() in {"mysql", "sys", "information_schema", "performance_schema"}:
            raise UnsafeDatabaseUpdate("System catalogs cannot use the row writer.")
        if self.column.lower() == self.key_column.lower():
            raise UnsafeDatabaseUpdate("A row update cannot change its primary key.")
        if re.search(r"password|passwd|secret|token|credential|private_key|api_key", self.column, re.I):
            raise UnsafeDatabaseUpdate("Credential columns cannot use the row writer.")
        if type(self.key) is not int or not isinstance(self.value, str):
            raise UnsafeDatabaseUpdate("A row update needs an integer key and a text value.")

    @property
    def subject(self):
        return f"{_identifier(self.database)}.{_identifier(self.table)}"

    @property
    def statement(self):
        return (f"UPDATE {self.subject} SET {_identifier(self.column)}=%s "
                f"WHERE {_identifier(self.key_column)}=%s")

    @property
    def parameters(self):
        return self.value, self.key

    def audit_record(self):
        encoded = json.dumps(self.parameters, ensure_ascii=True, separators=(",", ":"))
        return {"sql_template": self.statement,
                "parameters_sha256": hashlib.sha256(encoded.encode("ascii")).hexdigest()}


def _validate_table(cursor, change):
    target = change.database, change.table
    cursor.execute(
        "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s",
        target,
    )
    if cursor.fetchone() != ("InnoDB",):
        raise UnsafeDatabaseUpdate("Expected an InnoDB base table.")
    cursor.execute(
        "SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE "
        "WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s AND CONSTRAINT_NAME='PRIMARY' "
        "ORDER BY ORDINAL_POSITION", target,
    )
    if cursor.fetchall() != ((change.key_column,),):
        raise UnsafeDatabaseUpdate("Expected the specified column to be the sole primary key.")
    cursor.execute(
        "SELECT COLUMN_NAME, EXTRA FROM information_schema.COLUMNS "
        "WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s", target,
    )
    columns = dict(cursor.fetchall())
    if change.column not in columns or any(
        "on update" in extra.lower() or "generated" in extra.lower()
        for extra in columns.values()
    ):
        raise UnsafeDatabaseUpdate("Automatic or ambiguous column changes require a wider backup.")
    cursor.execute(
        "SELECT COUNT(*) FROM information_schema.TRIGGERS "
        "WHERE EVENT_OBJECT_SCHEMA=%s AND EVENT_OBJECT_TABLE=%s", target,
    )
    if cursor.fetchone()[0]:
        raise UnsafeDatabaseUpdate("Tables with triggers require a wider backup.")
    cursor.execute(
        "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE "
        "WHERE REFERENCED_TABLE_SCHEMA=%s AND REFERENCED_TABLE_NAME=%s AND REFERENCED_COLUMN_NAME=%s",
        (*target, change.column),
    )
    if cursor.fetchone()[0]:
        raise UnsafeDatabaseUpdate("Referenced foreign-key columns require a wider backup.")
    # A site login may not see foreign keys owned by another schema. An unindexed
    # column cannot be the referenced side of a MariaDB foreign key.
    cursor.execute(
        "SELECT COUNT(*) FROM information_schema.STATISTICS "
        "WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s AND COLUMN_NAME=%s",
        (*target, change.column),
    )
    if cursor.fetchone()[0]:
        raise UnsafeDatabaseUpdate("Indexed columns require a wider backup.")


def _locked_row(cursor, change):
    cursor.execute(
        f"SELECT * FROM {change.subject} WHERE {_identifier(change.key_column)}=%s FOR UPDATE",
        (change.key,),
    )
    rows = cursor.fetchall()
    if len(rows) != 1:
        raise UnsafeDatabaseUpdate("Expected exactly one row at that primary key.")
    columns = tuple(field[0] for field in cursor.description)
    return columns, rows[0]


def _snapshot(cursor, change, columns, values):
    names = ", ".join(_identifier(name) for name in columns)
    marks = ", ".join(["%s"] * len(columns))
    statement = cursor.mogrify(f"INSERT INTO {change.subject} ({names}) VALUES ({marks});", values)
    digest = hashlib.sha256(statement.encode("utf-8", "surrogatepass")).hexdigest()
    return statement, digest


def _backup(directory, snapshot, restore):
    directory = Path(directory)
    metadata = directory.lstat()
    if not stat.S_ISDIR(metadata.st_mode) or (
        os.name == "posix" and (metadata.st_uid != os.geteuid() or metadata.st_mode & 0o077)
    ):
        raise UnsafeDatabaseUpdate("The backup directory must be private.")
    name = f"row-{secrets.token_hex(16)}.json"
    fd = os.open(directory / name, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    payload = json.dumps({"snapshot_insert": snapshot, "restore_update": restore},
                         ensure_ascii=True).encode("ascii")
    with os.fdopen(fd, "wb") as out:
        out.write(payload)
        out.flush()
        os.fsync(out.fileno())
    if os.name == "posix":
        directory_fd = os.open(directory, os.O_RDONLY | os.O_DIRECTORY)
        try:
            os.fsync(directory_fd)
        finally:
            os.close(directory_fd)
    return str(directory / name), hashlib.sha256(payload).hexdigest()


def read_row_fingerprint(connection, change):
    """Preview a row without an open transaction or persistent mutation."""
    connection.begin()
    try:
        with connection.cursor() as cursor:
            _validate_table(cursor, change)
            columns, row = _locked_row(cursor, change)
            _validate_table(cursor, change)
            return _snapshot(cursor, change, columns, row)[1]
    finally:
        connection.rollback()


def apply_row_update(connection, change, expected, backup_directory, *, attest, record_intent):
    """Write one locked row after a durable backup and the caller's audit gate.

    The connector must have complete metadata visibility for the target schema.
    In particular, it must be able to see triggers on the table.
    """
    connection.begin()
    try:
        with connection.cursor() as cursor:
            _validate_table(cursor, change)
            columns, row = _locked_row(cursor, change)
            _validate_table(cursor, change)
            snapshot, before = _snapshot(cursor, change, columns, row)
            if expected != before:
                raise UnsafeDatabaseUpdate("Database row changed since preview.")
            position = columns.index(change.column)
            restore = cursor.mogrify(change.statement + ";", (row[position], change.key))
            path, digest = _backup(backup_directory, snapshot, restore)
            detail = dict(change.audit_record(), before_sha256=before,
                          backup_path=path, backup_sha256=digest)
            record_intent(detail)
            attest()
            cursor.execute(change.statement, change.parameters)
            after_columns, after_row = _locked_row(cursor, change)
            expected_values = list(row)
            expected_values[position] = change.value
            if after_columns != columns or after_row != tuple(expected_values):
                raise UnsafeDatabaseUpdate("The resulting row did not match the authorized update.")
            _, after = _snapshot(cursor, change, after_columns, after_row)
        # A revoke during execution can still prevent a transactional write from committing.
        attest()
        connection.commit()
        return dict(detail, after_sha256=after)
    except BaseException:
        connection.rollback()
        raise


# ===== SNAPSMACK EOF =====
