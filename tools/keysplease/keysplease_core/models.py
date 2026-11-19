"""Data models and attention-flagging rules for KEYS PLEASE (SS-1398 / SS-1400)."""

from dataclasses import dataclass, field
from datetime import datetime, timezone
from typing import Optional


@dataclass
class SiteKeyRecord:
    site_url: str
    site_name: str
    key_id: int
    label: str
    key_type: str
    key_prefix: str
    created_at: str
    expires_at: Optional[str]
    last_used_at: Optional[str]
    is_active: bool
    flags: list[str] = field(default_factory=list)


def _parse_iso(ts: Optional[str]) -> Optional[datetime]:
    if not ts:
        return None
    raw = str(ts).strip().replace("Z", "+00:00")
    for fmt in ("%Y-%m-%d %H:%M:%S", "%Y-%m-%dT%H:%M:%S", "%Y-%m-%d"):
        try:
            return datetime.strptime(raw[:19], fmt).replace(tzinfo=timezone.utc)
        except ValueError:
            continue
    return None


def compute_attention_flags(
    rec: SiteKeyRecord,
    desktop_prefix: Optional[str],
    now: Optional[datetime] = None,
) -> list[str]:
    now = now or datetime.now(timezone.utc)
    flags: list[str] = []
    exp = _parse_iso(rec.expires_at)

    if exp is not None:
        delta_days = (exp - now).total_seconds() / 86400.0
        if delta_days <= 0:
            flags.append("EXPIRED")
        elif delta_days <= 30:
            flags.append("EXPIRING SOON")

    if not rec.is_active and desktop_prefix:
        flags.append("REVOKED BUT STILL IN VAULT")
    elif rec.is_active:
        if not rec.last_used_at:
            flags.append("UNUSED")
        if desktop_prefix is None or desktop_prefix == "":
            flags.append("DESKTOP COPY MISSING")
        elif desktop_prefix != rec.key_prefix:
            flags.append("DESKTOP MISMATCH")

    return flags


# ===== SNAPSMACK EOF =====
