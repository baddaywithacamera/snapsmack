"""Run the database transaction and restore against a disposable MariaDB server.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the canonical SNAPSMACK EOF marker.
"""

from contextlib import closing
import hashlib
import json
import os
from pathlib import Path
import secrets
import socket
import subprocess
import sys
import time

import pytest

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "server"))
from proxvox_database import (

    RowUpdate, UnsafeDatabaseUpdate, apply_row_update, connect_site_database, read_row_fingerprint,
)


@pytest.fixture
def database(tmp_path):
    bindir = os.environ.get("PROXVOX_TEST_MARIADB_BIN")
    if not bindir or os.name != "nt":
        pytest.skip("No disposable MariaDB Windows distribution configured")
    pymysql = pytest.importorskip("pymysql")
    binaries = Path(bindir)
    with socket.socket() as available:
        available.bind(("127.0.0.1", 0))
        port = available.getsockname()[1]
    password = secrets.token_urlsafe(30)
    data = tmp_path / "data"
    flags = subprocess.CREATE_NO_WINDOW
    initialized = subprocess.run(
        [str(binaries / "mariadb-install-db.exe"), f"--datadir={data}",
         f"--password={password}", f"--port={port}", "--silent"],
        capture_output=True, creationflags=flags, timeout=45,
    )
    assert initialized.returncode == 0, "fixture initialization failed"
    server = subprocess.Popen(
        [str(binaries / "mariadbd.exe"), f'--defaults-file={data / "my.ini"}',
         "--bind-address=127.0.0.1", f"--port={port}"],
        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, creationflags=flags,
    )
    try:
        deadline = time.monotonic() + 20
        while True:
            try:
                connection = pymysql.connect(
                    host="127.0.0.1", port=port, user="root", password=password,
                    connect_timeout=1, charset="utf8mb4", binary_prefix=True,
                )
                break
            except pymysql.MySQLError:
                if server.poll() is not None or time.monotonic() > deadline:
                    pytest.fail("fixture MariaDB exited or timed out")
                time.sleep(0.25)

        with closing(connection):
            with connection.cursor() as cursor:
                cursor.execute("CREATE DATABASE fixture CHARACTER SET utf8mb4")
                cursor.execute(
                    "CREATE TABLE fixture.pages (id INT PRIMARY KEY, content TEXT, raw_data BLOB) ENGINE=InnoDB")
                cursor.executemany(
                    "INSERT INTO fixture.pages VALUES (%s,%s,%s)",
                    [(4, "before 'quoted'", b"\x00\xff"), (5, "other row", b"xyz")],
                )
            connection.commit()
            yield connection, port, password
    finally:
        server.terminate()
        server.wait(timeout=15)


def test_locked_row_backup_audit_rollback_and_restore(database, tmp_path):
    database, _, _ = database
    backup = tmp_path / "backup"
    backup.mkdir(mode=0o700)
    change = RowUpdate("fixture", "pages", "id", 4, "content", "updated 'é' 🌑")
    before = read_row_fingerprint(database, change)
    callbacks = []

    def unavailable(_detail):
        raise RuntimeError("fixture audit unavailable")

    with pytest.raises(RuntimeError, match="fixture audit unavailable"):
        apply_row_update(database, change, before, backup, attest=lambda: None, record_intent=unavailable)
    assert read_row_fingerprint(database, change) == before

    def record(detail):
        path = Path(detail["backup_path"])
        assert path.is_file() and hashlib.sha256(path.read_bytes()).hexdigest() == detail["backup_sha256"]
        assert change.value not in repr(detail)
        callbacks.append("audit")

    def revoke_before_commit():
        callbacks.append("attested")
        if callbacks.count("attested") == 2:
            raise RuntimeError("authorization revoked")

    with pytest.raises(RuntimeError, match="authorization revoked"):
        apply_row_update(database, change, before, backup,
                         attest=revoke_before_commit, record_intent=record)
    assert read_row_fingerprint(database, change) == before
    callbacks.clear()

    result = apply_row_update(database, change, before, backup,
                              attest=lambda: callbacks.append("attested"), record_intent=record)
    assert callbacks == ["audit", "attested", "attested"]
    assert read_row_fingerprint(database, change) == result["after_sha256"] != before

    with pytest.raises(UnsafeDatabaseUpdate, match="changed since preview"):
        apply_row_update(database, change, before, backup, attest=lambda: None, record_intent=record)

    stored = json.loads(Path(result["backup_path"]).read_bytes())
    with database.cursor() as cursor:
        cursor.execute(stored["restore_update"])
        cursor.execute("SELECT * FROM fixture.pages ORDER BY id")
        assert cursor.fetchall() == ((4, "before 'quoted'", b"\x00\xff"), (5, "other row", b"xyz"))
    database.commit()
    assert read_row_fingerprint(database, change) == before

    with database.cursor() as cursor:
        cursor.execute("CREATE TRIGGER fixture.before_page_update BEFORE UPDATE ON fixture.pages "
                       "FOR EACH ROW SET NEW.content = 'triggered'")
    with pytest.raises(UnsafeDatabaseUpdate, match="triggers"):
        read_row_fingerprint(database, change)


def test_site_connector_parses_config_without_evaluating_php_or_leaking_errors():
    php = br"""<?php
require_once __DIR__ . '/constants.php';

// --- DATABASE CREDENTIALS ---
$host = 'maria.internal';
$db = 'fixture';
$user = 'site_login';
$pass = 'synthetic\'quoted\\password';
$charset = 'utf8mb4';
throw new RuntimeException('must never execute PHP');
"""
    opened = []

    def unavailable(**options):
        opened.append(options)
        raise RuntimeError(options["password"])

    with pytest.raises(UnsafeDatabaseUpdate, match="Could not connect") as exc:
        with connect_site_database(php, "fixture", container=True, connect=unavailable):
            pytest.fail("Could not have opened the connection")
    assert opened[0]["password"] == "synthetic'quoted\\password"
    assert opened[0]["host"] == "maria.internal"
    assert "synthetic" not in str(exc.value)
    with pytest.raises(UnsafeDatabaseUpdate, match="does not match"):
        with connect_site_database(php, "other", container=True, connect=unavailable):
            pytest.fail("Unexpected site/database pairing")
    for local_host in (b"localhost", b"localhost.localdomain", b"LOCALHOST.LOCALDOMAIN.", b"127.0.0.2"):
        with pytest.raises(UnsafeDatabaseUpdate, match="local connector"):
            with connect_site_database(php.replace(b"maria.internal", local_host), "fixture",
                                       container=True, connect=unavailable):
                pytest.fail("Container localhost cannot be routed from the host")
    assert len(opened) == 1


def test_site_connector_refuses_login_without_trigger_visibility():
    php = b"""<?php
require_once __DIR__ . '/constants.php';
$host = 'maria.internal';
$db = 'fixture';
$user = 'site_login';
$pass = 'fixture';
"""
    class Connection:
        closed = False

        def cursor(self):
            return self

        def __enter__(self):
            return self

        def __exit__(self, *args):
            pass

        def execute(self, query):
            assert query == "SHOW GRANTS FOR CURRENT_USER"

        def fetchall(self):
            return (("GRANT SELECT, UPDATE ON `fixture`.* TO ignored",),)

        def close(self):
            self.closed = True

    connection = Connection()
    with pytest.raises(UnsafeDatabaseUpdate, match="cannot verify table triggers"):
        with connect_site_database(php, "fixture", container=True, connect=lambda **_: connection):
            pytest.fail("A login that cannot see triggers is insufficient")
    assert connection.closed


def test_forced_command_connects_with_site_login_and_rejects_nonce_drift(database, tmp_path, monkeypatch):
    from contextlib import nullcontext
    import pymysql
    import proxvox_database
    from test_proxvox_writer import _load

    _, port, password = database
    writer = _load()
    user = "root@pam"
    ticket = "PVE:root@pam:fixture"
    active = True
    backup = tmp_path / "row-backups"
    log = tmp_path / "writer.jsonl"
    config = f"""<?php
require_once __DIR__ . '/constants.php';
$host = '127.0.0.1';
$db = 'fixture';
$user = 'root';
$pass = '{password}';
""".encode()

    def site_connect(content, db, *, container):
        assert not container

        def connect_fixture(**options):
            return pymysql.connect(**options, port=port)

        return connect_site_database(content, db, container=container, connect=connect_fixture)

    def session(account, supplied):
        if not active or (account, supplied) != (user, ticket):
            raise writer.Refused("Inactive fixture session")
        return time.time() + 120

    monkeypatch.setattr(proxvox_database, "connect_site_database", site_connect)
    monkeypatch.setattr(writer, "_read_site_db_config", lambda target, path: config)
    monkeypatch.setattr(writer, "_maintenance_lock", lambda target: nullcontext())
    monkeypatch.setattr(writer, "_check_write_session", session)
    monkeypatch.setattr(writer, "BACKUP_DIR", str(backup))
    monkeypatch.setattr(writer, "AUDIT_LOG", str(log))
    monkeypatch.setattr(writer, "WRITE_NONCES", str(tmp_path / "nonces"))
    plan = {"ticket": ticket, "target": "host", "config_path": "/var/www/fixture/core/db.php",
            "database": "fixture", "table": "pages", "key_column": "id", "key": 4,
            "column": "content", "value": "updated by the site connector"}

    def call(action, **extras):
        monkeypatch.setattr(writer, "_read_session_request", lambda: dict(plan, **extras))
        return writer.dispatch(f"{action} {user}")

    before = call("preview-db-update")["expected"]
    nonce = call("issue-db-update-nonce", expected=before)["write_nonce"]
    with pytest.raises(writer.Refused, match="different operation"):
        call("run-db-update", expected=before, nonce=nonce, value="tampered")
    active = False
    with pytest.raises(writer.Refused, match="Inactive fixture session"):
        call("run-db-update", expected=before, nonce=nonce)
    active = True
    result = call("run-db-update", expected=before, nonce=nonce)
    assert result["after_sha256"] != result["before_sha256"]
    assert Path(result["backup_path"]).exists()
    with pytest.raises(writer.Refused, match="already used"):
        call("run-db-update", expected=before, nonce=nonce)
    audit = log.read_text()
    assert "database-update-intent" in audit and "database-update-result" in audit
    assert all(secret not in audit for secret in (password, ticket, nonce, plan["value"]))


# ===== SNAPSMACK EOF =====
