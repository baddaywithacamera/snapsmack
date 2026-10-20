from __future__ import annotations

import base64
import json
import os
from pathlib import Path
from urllib.parse import quote


ROOT = Path(os.environ.get("TROLL_BOOTH_HOME", Path.home() / ".troll-booth"))
DATABASE_CONFIG = ROOT / "database.json"


def load_mariadb_url() -> str:
    direct = os.environ.get("TROLL_BOOTH_MARIADB_URL", "").strip()
    if direct:
        return direct
    try:
        data = json.loads(DATABASE_CONFIG.read_text(encoding="utf-8"))
        import win32crypt
        password = win32crypt.CryptUnprotectData(base64.b64decode(data["password_dpapi"]), None, None, None, 0)[1].decode("utf-8")
        return "mariadb://{}:{}@{}:{}/{}".format(
            quote(data["user"], safe=""), quote(password, safe=""), data["host"], int(data.get("port", 3306)), data["database"]
        )
    except (FileNotFoundError, KeyError, ValueError, json.JSONDecodeError, ImportError):
        return ""


def save_mariadb_settings(host: str, port: int, database: str, user: str, password: str) -> None:
    import win32crypt

    ROOT.mkdir(parents=True, exist_ok=True)
    encrypted = win32crypt.CryptProtectData(password.encode("utf-8"), "TROLL BOOTH MariaDB", None, None, None, 0)
    data = {"host": host, "port": port, "database": database, "user": user,
            "password_dpapi": base64.b64encode(encrypted).decode("ascii")}
    temporary = DATABASE_CONFIG.with_suffix(".tmp")
    temporary.write_text(json.dumps(data, indent=2) + "\n", encoding="utf-8")
    os.replace(temporary, DATABASE_CONFIG)


def public_mariadb_settings() -> dict:
    try:
        data = json.loads(DATABASE_CONFIG.read_text(encoding="utf-8"))
        return {key: data.get(key) for key in ("host", "port", "database", "user")}
    except (FileNotFoundError, json.JSONDecodeError):
        return {}
