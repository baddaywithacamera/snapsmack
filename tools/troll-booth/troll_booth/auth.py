# SNAPSMACK_EOF_HEADER
"""Local password, TOTP, and short-lived session handling."""

from __future__ import annotations

import base64
import hashlib
import hmac
import json
import os
import secrets
import struct
import time
from dataclasses import dataclass
from pathlib import Path


class AuthError(ValueError):
    """Authentication data or credentials were refused."""


def _password_hash(password: str, salt: bytes, rounds: int = 600_000) -> bytes:
    if len(password) < 12:
        raise AuthError("password must contain at least 12 characters")
    return hashlib.pbkdf2_hmac("sha256", password.encode("utf-8"), salt, rounds)


def generate_totp_secret() -> str:
    return base64.b32encode(secrets.token_bytes(20)).decode("ascii").rstrip("=")


def totp(secret: str, at_time: int | None = None) -> str:
    padding = "=" * ((8 - len(secret) % 8) % 8)
    key = base64.b32decode(secret.upper() + padding, casefold=True)
    counter = int((time.time() if at_time is None else at_time) // 30)
    digest = hmac.new(key, struct.pack(">Q", counter), hashlib.sha1).digest()
    offset = digest[-1] & 0x0F
    value = (struct.unpack(">I", digest[offset:offset + 4])[0] & 0x7FFFFFFF) % 1_000_000
    return f"{value:06d}"


@dataclass
class Session:
    actor: str
    expires_at: float


@dataclass
class Pairing:
    actor: str
    expires_at: float


class AuthManager:
    def __init__(self, credential_path: Path, session_seconds: int = 600):
        self.credential_path = credential_path.resolve()
        self.session_seconds = session_seconds
        self._sessions: dict[str, Session] = {}
        self._pairings: dict[str, Pairing] = {}

    @property
    def configured(self) -> bool:
        return self.credential_path.is_file()

    @property
    def locked(self) -> bool:
        now = time.time()
        self._sessions = {
            token: session for token, session in self._sessions.items()
            if session.expires_at > now
        }
        return not bool(self._sessions)

    def configure(self, password: str, secret: str | None = None) -> str:
        if self.configured:
            raise AuthError("credentials are already configured")
        chosen_secret = secret or generate_totp_secret()
        salt = secrets.token_bytes(16)
        digest = _password_hash(password, salt)
        record = {
            "version": 1,
            "rounds": 600_000,
            "salt": base64.b64encode(salt).decode("ascii"),
            "password_hash": base64.b64encode(digest).decode("ascii"),
            "totp_secret": chosen_secret,
        }
        self.credential_path.parent.mkdir(parents=True, exist_ok=True)
        temporary = self.credential_path.with_suffix(".tmp")
        temporary.write_text(json.dumps(record, indent=2) + "\n", encoding="utf-8")
        os.replace(temporary, self.credential_path)
        return chosen_secret

    def _record(self) -> dict[str, object]:
        try:
            record = json.loads(self.credential_path.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError) as exc:
            raise AuthError("credential store is unavailable") from exc
        if not isinstance(record, dict) or record.get("version") != 1:
            raise AuthError("credential store has an unsupported format")
        return record

    def unlock(self, actor: str, password: str, code: str,
               now: int | None = None) -> tuple[str, float]:
        if actor not in {"sean", "codex", "claude", "assistant"}:
            raise AuthError("unrecognized actor")
        record = self._record()
        try:
            salt = base64.b64decode(str(record["salt"]), validate=True)
            expected = base64.b64decode(str(record["password_hash"]), validate=True)
            rounds = int(record["rounds"])
            secret = str(record["totp_secret"])
        except (KeyError, ValueError) as exc:
            raise AuthError("credential store is incomplete") from exc
        actual = _password_hash(password, salt, rounds)
        clock = int(time.time() if now is None else now)
        valid_code = any(hmac.compare_digest(code, totp(secret, clock + drift))
                         for drift in (-30, 0, 30) if clock + drift >= 0)
        if not hmac.compare_digest(actual, expected) or not valid_code:
            raise AuthError("password or TOTP was refused")
        token = secrets.token_urlsafe(32)
        expires = time.time() + self.session_seconds
        self._sessions[token] = Session(actor, expires)
        return token, expires

    def authenticate(self, token: str) -> Session:
        session = self._sessions.get(token)
        if session is None or session.expires_at <= time.time():
            self._sessions.pop(token, None)
            raise AuthError("session is missing or expired")
        return session

    def create_pairing(self, owner_token: str, actor: str,
                       lifetime: int = 120) -> tuple[str, float]:
        self.authenticate(owner_token)
        if actor not in {"sean", "codex", "claude", "assistant"}:
            raise AuthError("unrecognized actor")
        code = secrets.token_urlsafe(18)
        expires = time.time() + max(30, min(lifetime, 300))
        self._pairings[code] = Pairing(actor, expires)
        return code, expires

    def consume_pairing(self, code: str) -> tuple[str, Session]:
        pairing = self._pairings.pop(code, None)
        if pairing is None or pairing.expires_at <= time.time():
            raise AuthError("pairing code is missing, expired, or already used")
        token = secrets.token_urlsafe(32)
        session = Session(pairing.actor, time.time() + self.session_seconds)
        self._sessions[token] = session
        return token, session

    def lock(self) -> None:
        self._sessions.clear()
        self._pairings.clear()

# ===== SNAPSMACK EOF =====
