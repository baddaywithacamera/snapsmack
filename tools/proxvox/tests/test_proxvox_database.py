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
    RowUpdate, UnsafeDatabaseUpdate, apply_row_update, read_row_fingerprint,
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
            yield connection
    finally:
        server.terminate()
        server.wait(timeout=15)


def test_locked_row_backup_audit_rollback_and_restore(database, tmp_path):
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


# ===== SNAPSMACK EOF =====
