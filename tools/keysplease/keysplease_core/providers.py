"""Offline fixture & vault-isolation provider for KEYS PLEASE (SS-1398 / SS-1400)."""

import secrets
from datetime import datetime, timedelta, timezone
from urllib.parse import urlparse

from .models import SiteKeyRecord, compute_attention_flags


def vault_entry_name(key_type: str, site_url: str) -> str:
    """Isolated per-tool, per-site vault key name (never shared across tools)."""
    host = (urlparse(site_url).hostname or site_url).lower().replace(".", "_")
    return f"keysplease_{key_type.lower()}_{host}_api_key"


class FakeKeysProvider:
    """Offline fixture provider simulating fleet keys, vault prefixes, and SS-1400 enforcement."""

    def __init__(self, vault_store=None):
        self.vault = vault_store if vault_store is not None else {}
        now = datetime.now(timezone.utc)
        soon = (now + timedelta(days=10)).strftime("%Y-%m-%d %H:%M:%S")
        future = (now + timedelta(days=180)).strftime("%Y-%m-%d %H:%M:%S")
        past = (now - timedelta(days=5)).strftime("%Y-%m-%d %H:%M:%S")

        self._records = [
            SiteKeyRecord(
                "https://foundtextures.example", "Found Textures", 101,
                "HUB auto-provisioned (sybu)", "sybu", "aa11bb22",
                "2026-06-01 10:00:00", soon, "2026-11-16 08:00:00", True,
            ),
            SiteKeyRecord(
                "https://foundtextures.example", "Found Textures", 102,
                "HUB auto-provisioned (suyb)", "suyb", "cc33dd44",
                "2026-06-01 10:00:00", future, None, True,
            ),
            SiteKeyRecord(
                "https://photowalk.example", "Photowalking", 201,
                "Old SmackPress key", "smackpress", "ee55ff66",
                "2025-11-01 10:00:00", past, "2026-10-01 09:00:00", False,
            ),
        ]
        self.vault[vault_entry_name("sybu", "https://foundtextures.example")] = "aa11bb22_secret_sybu"
        # Revoked key still lingering on desktop vault
        self.vault[vault_entry_name("smackpress", "https://photowalk.example")] = "ee55ff66_old_secret"
        self._next_id = 300

    def _desktop_prefix(self, key_type: str, site_url: str) -> str:
        val = str(self.vault.get(vault_entry_name(key_type, site_url), "") or "")
        return val[:8] if val else ""

    def list_keys(self) -> list[SiteKeyRecord]:
        out = []
        for rec in self._records:
            rec.flags = compute_attention_flags(rec, self._desktop_prefix(rec.key_type, rec.site_url))
            out.append(rec)
        return out

    def create_key(self, site_url: str, site_name: str, key_type: str, password: str, totp: str) -> SiteKeyRecord:
        if not password.strip() or not totp.strip().isdigit() or len(totp.strip()) != 6:
            raise PermissionError("Password and 6-digit TOTP required for session.")
        raw = secrets.token_hex(32)
        prefix = raw[:8]
        # Write straight into the owning tool's vault entry; NEVER return raw key!
        self.vault[vault_entry_name(key_type, site_url)] = raw
        self._next_id += 1
        rec = SiteKeyRecord(
            site_url=site_url,
            site_name=site_name,
            key_id=self._next_id,
            label=f"KEYS PLEASE ({key_type})",
            key_type=key_type,
            key_prefix=prefix,
            created_at=datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S"),
            expires_at=(datetime.now(timezone.utc) + timedelta(days=365)).strftime("%Y-%m-%d %H:%M:%S"),
            last_used_at=None,
            is_active=True,
        )
        self._records.insert(0, rec)
        return rec

    def revoke_key(self, key_id: int, password: str, totp: str) -> SiteKeyRecord:
        if not password.strip() or not totp.strip().isdigit() or len(totp.strip()) != 6:
            raise PermissionError("Password and 6-digit TOTP required for session.")
        for rec in self._records:
            if rec.key_id == key_id:
                rec.is_active = False
                return rec
        raise KeyError(f"Key {key_id} not found.")

    def delete_key(self, key_id: int, password: str, totp: str) -> SiteKeyRecord:
        if not password.strip() or not totp.strip().isdigit() or len(totp.strip()) != 6:
            raise PermissionError("Password and 6-digit TOTP required for session.")
        for idx, rec in enumerate(self._records):
            if rec.key_id == key_id:
                if rec.is_active:
                    raise PermissionError("Live key must be revoked before it can be deleted (SS-1400).")
                return self._records.pop(idx)
        raise KeyError(f"Key {key_id} not found.")


# ===== SNAPSMACK EOF =====
