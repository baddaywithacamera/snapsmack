"""
Smack Up Your Backup — ftps_pins.py

FTPS certificate memory (trust-on-first-use), the FTPS twin of the SFTP
host-key pin in sftp_client.py. Scheduled since SECAUDIT 004/037; built
2026-09-20.

THE PROBLEM WITH NAIVE PINNING on this fleet: cheap shared hosts renew their
certificate — and usually the private key — every 60–90 days (Let's Encrypt,
cPanel AutoSSL). Pin the fingerprint and every site trips a "CERTIFICATE
CHANGED" alarm every couple of months. Twenty-five sites = an alarm every few
days, all false, and people learn to click through the real one too.

THE RULE HERE:
  first connection         remember the certificate (SHA-256 of the DER)
  same certificate         fine
  different certificate    is the NEW one publicly valid for this host? — one
                           extra handshake with full verification ON
                             yes → it's a renewal; re-pin silently, one log line
                             no  → a self-signed cert replaced by a different
                                   self-signed cert, or something in the middle:
                                   STOP and ask, both fingerprints shown

Validation is never REQUIRED (that would break self-signed shared hosts, which
is why `ftp_verify_cert` defaults off). It is only used to tell a renewal from
a swap. Self-signed hosts almost never rotate, so the alarms you do get are
rare and worth reading.

Pin file: suyb_ftps_pins.json beside the exe (portable, like suyb_known_hosts).
Delete a host's entry to re-pin after a legitimate self-signed rotation.
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.

from __future__ import annotations

import datetime
import hashlib
import json
import os
import sys
from typing import Optional, Tuple


class CertificateChanged(Exception):
    """The server's certificate changed and the new one is NOT publicly valid
    for the host. The caller asks the operator; nothing is sent until they say
    yes (and accepting re-pins)."""

    def __init__(self, host: str, old_fp: str, new_fp: str, why: str):
        self.host, self.old_fp, self.new_fp, self.why = host, old_fp, new_fp, why
        super().__init__(
            f"The FTPS certificate for {host} has CHANGED and the new one is not a "
            f"public-CA certificate for that name ({why}).\n"
            f"  remembered: {old_fp}\n  offered now: {new_fp}\n"
            "If you changed the server's certificate yourself, accept it and SUYB "
            "will remember the new one. If you did not, stop here.")


def _default_pin_path() -> str:
    if getattr(sys, "frozen", False):
        base = os.path.dirname(sys.executable)
    else:
        base = os.path.dirname(os.path.abspath(__file__))
    return os.path.join(base, "suyb_ftps_pins.json")


def fingerprint(der: bytes) -> str:
    h = hashlib.sha256(der).hexdigest().upper()
    return ":".join(h[i:i + 2] for i in range(0, len(h), 2))


class PinStore:
    def __init__(self, path: Optional[str] = None):
        self.path = path or _default_pin_path()

    def _load(self) -> dict:
        try:
            with open(self.path, encoding="utf-8") as f:
                d = json.load(f)
            return d if isinstance(d, dict) else {}
        except Exception:
            return {}

    def get(self, host: str, port: int) -> Optional[str]:
        return (self._load().get(f"{host}:{port}") or {}).get("sha256")

    def set(self, host: str, port: int, fp: str, note: str = "") -> None:
        d = self._load()
        d[f"{host}:{port}"] = {
            "sha256": fp,
            "pinned_at": datetime.datetime.now(datetime.timezone.utc).isoformat(timespec="seconds"),
            "note": note,
        }
        tmp = self.path + ".tmp"
        with open(tmp, "w", encoding="utf-8") as f:
            json.dump(d, f, indent=2, sort_keys=True)
        os.replace(tmp, self.path)


def publicly_valid(host: str, port: int, timeout: int = 15) -> Tuple[bool, str]:
    """Is the certificate this server presents valid for `host` under the public
    CA bundle? Answered by one extra explicit-FTPS handshake with full
    verification ON (chain + hostname). Used ONLY to tell a renewal from a
    swap — normal connections never require validation. Returns (ok, reason)."""
    import ftplib
    import ssl
    try:
        ctx = ssl.create_default_context()          # system CAs, hostname checked
        probe = ftplib.FTP_TLS(context=ctx, timeout=timeout)
        probe.connect(host, port)
        probe.auth()                                # the TLS handshake; raises on a bad chain/name
        try:
            probe.quit()
        except Exception:
            pass
        return True, "public CA, name matches"
    except ssl.SSLCertVerificationError as e:
        return False, (getattr(e, "verify_message", None) or str(e)).splitlines()[0][:160]
    except Exception as e:  # noqa: BLE001
        return False, str(e).splitlines()[0][:160]


def check_connection(ftp_tls, host: str, port: int, store: Optional[PinStore] = None,
                     log=None) -> str:
    """Call right after `ftp.auth()` (the TLS handshake). Returns the fingerprint
    in use. Raises CertificateChanged when the operator must decide."""
    store = store or PinStore()
    sock = getattr(ftp_tls, "sock", None)
    der = sock.getpeercert(binary_form=True) if sock is not None else None
    if not der:
        return ""
    fp = fingerprint(der)
    known = store.get(host, port)
    if known is None:
        store.set(host, port, fp, "first connection")
        if log: log(f"FTPS: remembered {host}'s certificate ({fp[:23]}…)")
        return fp
    if known == fp:
        return fp
    ok, why = publicly_valid(host, port)
    if ok:
        store.set(host, port, fp, "renewed; public CA verified")
        if log: log(f"FTPS: {host} presented a renewed certificate (public CA, name matches) — re-pinned.")
        return fp
    raise CertificateChanged(host, known, fp, why)


def accept_change(host: str, port: int, fp: str, store: Optional[PinStore] = None) -> None:
    """The operator said yes to a changed certificate: remember the new one."""
    (store or PinStore()).set(host, port, fp, "accepted by operator after change")

# ===== SNAPSMACK EOF =====
