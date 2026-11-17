"""Own credential lifecycle for DRIVE BY (isolated from SUYB, drive.file + B2 app keys)."""

from dataclasses import dataclass
import time
from typing import Callable, Optional

import requests

from .providers import is_likely_b2_master_key


DRIVE_SCOPE = "https://www.googleapis.com/auth/drive.file"
GOOGLE_REVOKE_URL = "https://oauth2.googleapis.com/revoke"
DEFAULT_GRANT_LIFETIME_DAYS = 7

# Dedicated snap_creds keys owned solely by DRIVE BY (never shared with SUYB).
CRED_DRIVE_REFRESH_TOKEN = "driveby_google_drive_refresh_token"
CRED_DRIVE_ISSUED_AT = "driveby_google_drive_issued_at"
CRED_DRIVE_LIFETIME_DAYS = "driveby_google_drive_lifetime_days"
CRED_DRIVE_REVOKE_PENDING = "driveby_google_drive_revoke_pending"
CRED_B2_KEY_ID = "driveby_b2_app_key_id"
CRED_B2_APP_KEY = "driveby_b2_app_key"


@dataclass
class GrantStatus:
    linked: bool
    expired: bool
    revoke_pending: bool
    issued_at: float
    lifetime_days: int
    remaining_seconds: float

    @property
    def deletes_allowed(self) -> bool:
        return self.linked and not self.expired and not self.revoke_pending


def get_grant_status(store, now: Optional[float] = None) -> GrantStatus:
    now = float(now if now is not None else time.time())
    token = str(store.get(CRED_DRIVE_REFRESH_TOKEN, "") or "").strip()
    issued_raw = str(store.get(CRED_DRIVE_ISSUED_AT, "0") or "0").strip()
    life_raw = str(store.get(CRED_DRIVE_LIFETIME_DAYS, str(DEFAULT_GRANT_LIFETIME_DAYS)) or str(DEFAULT_GRANT_LIFETIME_DAYS)).strip()
    pending = str(store.get(CRED_DRIVE_REVOKE_PENDING, "") or "").strip().lower() in ("1", "true", "yes")
    try:
        issued_at = float(issued_raw)
    except ValueError:
        issued_at = 0.0
    try:
        lifetime_days = max(1, int(float(life_raw)))
    except ValueError:
        lifetime_days = DEFAULT_GRANT_LIFETIME_DAYS

    if not token or issued_at <= 0:
        return GrantStatus(False, False, pending, issued_at, lifetime_days, 0.0)

    expires_at = issued_at + lifetime_days * 86400.0
    remaining = max(0.0, expires_at - now)
    return GrantStatus(
        linked=True,
        expired=(now >= expires_at),
        revoke_pending=pending,
        issued_at=issued_at,
        lifetime_days=lifetime_days,
        remaining_seconds=remaining,
    )


def record_drive_grant(store, refresh_token: str, lifetime_days: int = DEFAULT_GRANT_LIFETIME_DAYS,
                       now: Optional[float] = None):
    issued = float(now if now is not None else time.time())
    store.set(CRED_DRIVE_REFRESH_TOKEN, str(refresh_token).strip())
    store.set(CRED_DRIVE_ISSUED_AT, str(issued))
    store.set(CRED_DRIVE_LIFETIME_DAYS, str(max(1, int(lifetime_days))))
    store.set(CRED_DRIVE_REVOKE_PENDING, "")


def revoke_drive_grant(store, post_fn: Optional[Callable] = None) -> tuple[bool, str]:
    """Revoke at Google FIRST, THEN purge local token.

    If provider revoke fails, keep the local token, set revoke_pending=True
    (which refuses deletes locally), and return (False, reason).
    """
    token = str(store.get(CRED_DRIVE_REFRESH_TOKEN, "") or "").strip()
    if not token:
        store.set(CRED_DRIVE_ISSUED_AT, "")
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
        return False, f"Provider revoke returned HTTP {getattr(resp, 'status_code', '?')}; local token retained and deletes locked."

    # Provider confirmed revocation — NOW purge local token and metadata
    store.set(CRED_DRIVE_REFRESH_TOKEN, "")
    store.set(CRED_DRIVE_ISSUED_AT, "")
    store.set(CRED_DRIVE_REVOKE_PENDING, "")
    return True, "Drive grant revoked at Google and purged locally."


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
