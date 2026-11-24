"""Credential lifecycle, SYBU/SUYB Drive grant reuse, and fresh TOTP step-up for DRIVE BY."""

from dataclasses import dataclass
import json
import os
from typing import Callable, Optional

import requests


DRIVE_SCOPE = "https://www.googleapis.com/auth/drive"
GOOGLE_REVOKE_URL = "https://oauth2.googleapis.com/revoke"

# Shared snap_creds keys (reused from SYBU / SNAP HQ — never isolated or pasted).
SYBU_DRIVE_TOKEN_KEY = "sybu_google_drive_authorized_user"
SHARED_GOOGLE_CREDS_KEY = "google_credentials"
SHARED_DRIVE_FOLDER_KEY = "drive_folder_id"

# DRIVE BY state/keys in snap_creds.
CRED_DRIVE_REVOKE_PENDING = "driveby_google_drive_revoke_pending"
CRED_B2_KEY_ID = "driveby_b2_app_key_id"
CRED_B2_APP_KEY = "driveby_b2_app_key"


class CredentialStoreUnavailableError(RuntimeError):
    """Raised when snap_creds cannot be initialized or the protected vault is locked."""


@dataclass
class DriveAuthState:
    state: str                 # "connected" | "never" | "expired" | "unreadable" | "revoke_pending" | "vault_error"
    message: str
    authorized_user_json: str = ""
    credentials_path: str = ""
    folder_id: str = ""
    revoke_pending: bool = False

    @property
    def linked(self) -> bool:
        return self.state == "connected"

    @property
    def deletes_allowed(self) -> bool:
        return self.state == "connected" and not self.revoke_pending


def _read_suyb_plain_token(credentials_path: str) -> str:
    """Best-effort read of SUYB's unsealed _token.json if SYBU's vault key is absent."""
    if not credentials_path or not os.path.isfile(credentials_path):
        return ""
    token_file = credentials_path.replace(".json", "_token.json")
    if not os.path.isfile(token_file):
        return ""
    try:
        with open(token_file, "r", encoding="utf-8") as fh:
            raw = fh.read()
        data = json.loads(raw)
        if isinstance(data, dict) and "suyb_sealed" not in data and (
            data.get("refresh_token") or data.get("token")
        ):
            return raw
    except Exception:  # noqa: BLE001
        return ""
    return ""


def get_drive_auth_state(store, vault_error: str = "") -> DriveAuthState:
    """Inspect SYBU/SUYB Google Drive authorization from the protected shared vault."""
    if vault_error or store is None:
        msg = vault_error or "Protected credential vault (snap_creds) is unavailable."
        return DriveAuthState(state="vault_error", message=msg)

    try:
        pending = str(store.get(CRED_DRIVE_REVOKE_PENDING, "") or "").strip().lower() in (
            "1", "true", "yes"
        )
        credentials_path = str(store.get(SHARED_GOOGLE_CREDS_KEY, "") or "").strip()
        folder_id = str(store.get(SHARED_DRIVE_FOLDER_KEY, "") or "").strip()
        saved = str(store.get(SYBU_DRIVE_TOKEN_KEY, "") or "").strip()
        if not saved and credentials_path:
            saved = _read_suyb_plain_token(credentials_path)
    except Exception as exc:  # noqa: BLE001
        return DriveAuthState(
            state="vault_error",
            message=f"Failed reading protected credential vault: {exc}",
        )

    if pending:
        return DriveAuthState(
            state="revoke_pending",
            message="Drive grant: REVOKE PENDING (deletes locked until revocation completes).",
            authorized_user_json=saved,
            credentials_path=credentials_path,
            folder_id=folder_id,
            revoke_pending=True,
        )

    if not saved:
        if credentials_path and not os.path.isfile(credentials_path):
            return DriveAuthState(
                state="never",
                message="Google credentials JSON not found. Set it in SNAP HQ Settings, then Connect Drive.",
                credentials_path=credentials_path,
                folder_id=folder_id,
            )
        return DriveAuthState(
            state="never",
            message="Drive not connected. Connect Drive in SYBU or Link / Keys.",
            credentials_path=credentials_path,
            folder_id=folder_id,
        )

    try:
        info = json.loads(saved)
        if not isinstance(info, dict):
            raise ValueError("Authorized user info must be a JSON object.")
    except Exception:  # noqa: BLE001
        return DriveAuthState(
            state="unreadable",
            message="Saved Drive authorization is unreadable. Reconnect Google Drive.",
            credentials_path=credentials_path,
            folder_id=folder_id,
        )

    if info.get("refresh_token") or info.get("token"):
        return DriveAuthState(
            state="connected",
            message="Drive connected (reusing shared SYBU/SUYB vault grant).",
            authorized_user_json=saved,
            credentials_path=credentials_path,
            folder_id=folder_id,
        )

    return DriveAuthState(
        state="expired",
        message="Saved Drive authorization has no token/refresh_token. Reconnect Google Drive.",
        credentials_path=credentials_path,
        folder_id=folder_id,
    )


def save_sybu_drive_authorization(store, authorized_user_json: str) -> None:
    """Persist OAuth authorized-user JSON into the shared SYBU vault key and verify readback."""
    if store is None:
        raise CredentialStoreUnavailableError("Protected credential vault is unavailable.")
    text = str(authorized_user_json or "").strip()
    json.loads(text)  # validate JSON before writing
    store.set(SYBU_DRIVE_TOKEN_KEY, text)
    store.set(CRED_DRIVE_REVOKE_PENDING, "")
    if str(store.get(SYBU_DRIVE_TOKEN_KEY, "") or "").strip() != text:
        raise CredentialStoreUnavailableError(
            "Saved Drive authorization could not be verified in the protected vault."
        )


def _extract_revoke_token(saved_json: str) -> str:
    if not saved_json:
        return ""
    try:
        info = json.loads(saved_json)
        if isinstance(info, dict):
            return str(info.get("refresh_token") or info.get("token") or "").strip()
    except Exception:  # noqa: BLE001
        pass
    return ""


def revoke_drive_grant(store, post_fn: Optional[Callable] = None) -> tuple[bool, str]:
    """Revoke at Google FIRST, THEN purge local shared token.

    If provider revoke fails, retain the local token, set revoke_pending=True
    (which refuses deletes locally), and return (False, reason).
    """
    if store is None:
        return False, "Protected credential vault is unavailable; cannot revoke."

    saved = str(store.get(SYBU_DRIVE_TOKEN_KEY, "") or "").strip()
    token = _extract_revoke_token(saved)
    if not token:
        store.set(SYBU_DRIVE_TOKEN_KEY, "")
        store.set(CRED_DRIVE_REVOKE_PENDING, "")
        return True, "No local Drive grant to revoke."

    poster = post_fn or requests.post
    try:
        resp = poster(GOOGLE_REVOKE_URL, data={"token": token}, timeout=15)
        ok = getattr(resp, "status_code", 0) == 200
    except Exception as exc:  # noqa: BLE001
        store.set(CRED_DRIVE_REVOKE_PENDING, "1")
        return False, f"Provider revoke failed ({exc}); local token retained and deletes locked."

    if not ok:
        store.set(CRED_DRIVE_REVOKE_PENDING, "1")
        return (
            False,
            f"Provider revoke returned HTTP {getattr(resp, 'status_code', '?')}; "
            "local token retained and deletes locked.",
        )

    store.set(SYBU_DRIVE_TOKEN_KEY, "")
    store.set(CRED_DRIVE_REVOKE_PENDING, "")
    return True, "Drive grant revoked at Google and purged from shared vault."


class TotpStepUpGuard:
    """Enforces a fresh 6-digit TOTP code on EVERY delete (never cached across deletes)."""

    def __init__(self):
        self._used_codes: set[str] = set()

    def verify(
        self,
        totp_code: str,
        *,
        stepup_verifier: Optional[Callable[[str], object]] = None,
    ) -> tuple[bool, str]:
        code = str(totp_code or "").strip()
        if not code.isdigit() or len(code) != 6:
            return False, "A fresh 6-digit TOTP code is required for every delete."
        if code in self._used_codes:
            return False, "That TOTP code was already used. Enter a fresh 6-digit TOTP code."

        if stepup_verifier is not None:
            try:
                res = stepup_verifier(code)
            except Exception as exc:  # noqa: BLE001
                return False, f"TOTP verification failed: {exc}"
            if isinstance(res, tuple):
                ok, msg = bool(res[0]), str(res[1])
            else:
                ok, msg = bool(res), ("TOTP verified." if res else "TOTP verification rejected.")
            if not ok:
                return False, msg

        self._used_codes.add(code)
        return True, "Fresh TOTP verified."


def is_likely_b2_master_key(key_id: str) -> bool:
    """B2 master account keys start with '000'..'005' without an app-key prefix 'K'."""
    kid = str(key_id or "").strip()
    return bool(kid) and not kid.startswith("K") and len(kid) <= 16


def validate_b2_credentials(key_id: str, app_key: str) -> tuple[bool, str]:
    kid = str(key_id or "").strip()
    secret = str(app_key or "").strip()
    if not kid or not secret:
        return False, "Both B2 App Key ID and App Key are required."
    if is_likely_b2_master_key(kid):
        return (
            False,
            "This looks like a Backblaze B2 master account key ID. DRIVE BY requires a "
            "bucket-restricted application key (starting with 'K') so account-wide "
            "buckets and keys cannot be modified.",
        )
    return True, "Bucket-restricted B2 application key accepted."


# ===== SNAPSMACK EOF =====
