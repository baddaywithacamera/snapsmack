"""PROX VOX — the live-authorised write client and manual revoke. (SS-1507 P3 / P5)

Sean's rulings (2026-11-22 15:50, 15:51, 16:44):
  - Separate client object from ReadOnlyBroker (P1 stays read-only).
  - Authority is Option A: live Proxmox /access/ticket login with password +
    TOTP (in a TOTP-required realm). No stored write token, no stored
    password, no stored TOTP, and no unattended writes.
  - One authorisation = full write (POST, PUT, DELETE) across Proxmox API
    paths until the Proxmox ticket expires, with no per-action TOTP prompts.
  - Every call and every refusal is logged to AuditBuffer BEFORE execution.
  - Manual revoke (P5) immediately clears the in-memory ticket and CSRF token.
  - Logs remain local in AuditBuffer until Codex closes OWNER RIGHTS for P2.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import hashlib
import json
import re
import threading
import time
import urllib.error
import urllib.parse
import urllib.request

from . import host_guard
from .broker import Refused, _path_refusal
from .tls_pin import CertificatePinError, pinned_https_opener

ALLOWED_METHODS = ("POST", "PUT", "DELETE", "GET")
PROXMOX_TICKET_TTL_SECONDS = 7200


class WriteClient:
    """Short-lived, live-authorised Proxmox write client (Option A + P5 revoke)."""

    def __init__(self, base_url: str, audit, pinned_guid: str,
                 actual_guid: str = None, opener=None, verify_host: bool = True,
                 clock=None, ticket_ttl: int = PROXMOX_TICKET_TTL_SECONDS,
                 certificate_sha256: str = "", expiry_timer_factory=None):
        if verify_host:
            host_guard.require_host(pinned_guid, actual_guid)
        self.base_url = base_url.rstrip("/")
        self.audit = audit
        if self.base_url.lower().startswith("https://"):
            if opener is not None:
                self.audit.record("tls_refused", {
                    "why": "a custom HTTPS opener would bypass certificate pinning",
                })
                raise Refused("PROX VOX will not bypass HTTPS certificate pinning.")
            try:
                self._opener = pinned_https_opener(self.base_url, certificate_sha256)
            except CertificatePinError as err:
                self.audit.record("tls_refused", {"why": str(err)})
                raise Refused(str(err)) from err
        elif opener is not None:
            self.audit.record("transport", {
                "transport": "injected opener, not the shipped TLS path",
            })
            self._opener = opener
        else:
            self.audit.record("tls_refused", {"why": "Proxmox URL is not HTTPS"})
            raise Refused("PROX VOX requires an HTTPS Proxmox URL.")
        self._clock = clock or time.time
        self._ticket_ttl = max(1, int(ticket_ttl))
        self._expiry_timer_factory = expiry_timer_factory or threading.Timer
        self._certificate_sha256 = certificate_sha256
        self._session_lock = threading.RLock()

        self._username: str = ""
        self._ticket: str = ""
        self._csrf_token: str = ""
        self._expires_at: float = 0.0
        self._container_consoles = []
        self._expiry_timer = None
        self._expiry_generation = 0

    # ── session state ────────────────────────────────────────────────────
    def _clear_session(self):
        with self._session_lock:
            self._expiry_generation += 1
            timer = self._expiry_timer
            self._expiry_timer = None
            if timer is not None:
                timer.cancel()
            for console in self._container_consoles:
                try:
                    console.close()
                except Exception:
                    pass
            self._container_consoles = []
            self._username = ""
            self._ticket = ""
            self._csrf_token = ""
            self._expires_at = 0.0

    def _schedule_expiry(self):
        with self._session_lock:
            self._expiry_generation += 1
            generation = self._expiry_generation

            def expire():
                with self._session_lock:
                    if generation != self._expiry_generation or not self._ticket:
                        return
                    user = self._username
                    self.audit.record("write_expired", {"username": user})
                    self._clear_session()

            timer = self._expiry_timer_factory(self._ticket_ttl, expire)
            if hasattr(timer, "daemon"):
                timer.daemon = True
            self._expiry_timer = timer
            timer.start()

    def is_authorized(self) -> bool:
        with self._session_lock:
            if not self._ticket or not self._csrf_token:
                return False
            if float(self._clock()) >= self._expires_at:
                self._clear_session()
                return False
            return True

    # ── P3 Option A: /access/ticket + TFA ────────────────────────────────
    def authorize(self, username: str, password: str, otp: str,
                  timeout: int = 15) -> dict:
        """Authenticate live using Proxmox's password then TOTP challenge flow."""
        user = (username or "").strip()
        pw = password or ""
        code = (otp or "").strip()
        if not user or not pw or not code:
            self.audit.record("write_auth_refused", {
                "username": user,
                "why": "username, password, and OTP are all required",
            })
            raise Refused(
                "PROX VOX write authorisation requires live username, password, "
                "and TOTP code. Nothing is read from files or environment."
            )

        url = self.base_url + "/api2/json/access/ticket"

        def request_stage(stage: int, fields: dict):
            form = urllib.parse.urlencode(fields).encode("utf-8")
            request = urllib.request.Request(url, data=form, method="POST")
            request.add_header("Content-Type", "application/x-www-form-urlencoded")

            # Recorded BEFORE each request; secrets and challenge are omitted.
            self.audit.record("write_auth_attempt", {
                "username": user,
                "stage": stage,
            })
            try:
                with self._opener(request, timeout=timeout) as response:
                    body = response.read().decode("utf-8", "replace")
                    status = getattr(response, "status", 200)
            except urllib.error.HTTPError as err:
                self._clear_session()
                self.audit.record("write_auth_result", {
                    "username": user, "stage": stage, "ok": False,
                    "status": err.code,
                })
                raise
            except CertificatePinError as err:
                self._clear_session()
                self.audit.record("tls_refused", {
                    "username": user, "stage": stage, "why": str(err),
                })
                raise Refused(str(err)) from err
            except Exception as err:
                self._clear_session()
                self.audit.record("write_auth_result", {
                    "username": user, "stage": stage, "ok": False,
                    "error": type(err).__name__,
                })
                raise

            try:
                payload = json.loads(body)
            except ValueError:
                self._clear_session()
                self.audit.record("write_auth_result", {
                    "username": user, "stage": stage, "ok": False,
                    "status": status,
                    "why": "invalid JSON from /access/ticket",
                })
                raise Refused("Proxmox /access/ticket returned non-JSON response.")
            return payload, status

        stage_one, stage_one_status = request_stage(1, {
            "username": user,
            "password": pw,
        })
        stage_one_data = (stage_one.get("data")
                          if isinstance(stage_one, dict) else None)
        challenge = ((stage_one_data or {}).get("ticket")
                     if isinstance(stage_one_data, dict) else None)
        if (not isinstance(challenge, str)
                or not challenge.startswith("PVE:!tfa!")):
            self._clear_session()
            self.audit.record("write_auth_result", {
                "username": user, "stage": 1, "ok": False,
                "status": stage_one_status,
                "why": "missing TFA challenge",
            })
            raise Refused(
                "Proxmox /access/ticket did not return the required TFA challenge."
            )

        stage_two, status = request_stage(2, {
            "username": user,
            "tfa-challenge": challenge,
            "password": "totp:" + code,
        })
        data = stage_two.get("data") if isinstance(stage_two, dict) else None
        ticket = (data or {}).get("ticket") if isinstance(data, dict) else None
        csrf = ((data or {}).get("CSRFPreventionToken")
                if isinstance(data, dict) else None)

        if (not isinstance(ticket, str) or not ticket.strip()
                or ticket.startswith("PVE:!tfa!")
                or not isinstance(csrf, str) or not csrf.strip()):
            self._clear_session()
            self.audit.record("write_auth_result", {
                "username": user, "stage": 2, "ok": False,
                "status": status,
                "why": "missing ticket or CSRFPreventionToken (TFA incomplete)",
            })
            raise Refused(
                "Proxmox /access/ticket did not return a full write ticket "
                "and CSRFPreventionToken."
            )

        now = float(self._clock())
        self._clear_session()
        with self._session_lock:
            self._username = user
            self._ticket = ticket.strip()
            self._csrf_token = csrf.strip()
            self._expires_at = now + self._ticket_ttl
            self._schedule_expiry()

        self.audit.record("write_auth_result", {
            "username": user,
            "ok": True,
            "status": status,
            "expires_at": self._expires_at,
        })
        return {"username": user, "expires_at": self._expires_at}

    # ── P5: manual revoke ────────────────────────────────────────────────
    def revoke(self, reason: str = "manual") -> None:
        """Immediately invalidate the in-memory write session."""
        was_active = bool(self._ticket or self._csrf_token)
        user = self._username
        self._clear_session()
        self.audit.record("write_revoked", {
            "username": user,
            "was_active": was_active,
            "reason": str(reason or "manual"),
        })

    # ── SS-1507: a grant for the scoped FILE writer ──────────────────────
    #
    # The Proxmox API has no way to put a file on the host, which is why the
    # scoped writer exists as a forced SSH command instead. That writer will
    # not act on the SSH account alone -- Codex's P0 -- so it demands a grant
    # proving a live owner login, and this is where one is minted.
    #
    # THE TICKET NEVER LEAVES THIS OBJECT BY ANY OTHER ROUTE. There is no
    # accessor for it and no caller needs one: the grant is built in here,
    # bound to one operation, and handed out already sealed. A client that
    # exposed the ticket so a helper could assemble its own grant would be a
    # client that hands a live Proxmox credential to any code that asks.
    MAX_FILE_GRANT_SECONDS = 900

    def mint_file_grant(self, *, target: str, path: str, sha256: str,
                        seconds: int = 300) -> str:
        """A single-operation grant as JSON, or Refused if not authorised.

        BOUND TO THE OPERATION, not to the session. target, path and the
        content hash all travel inside the grant, and the host checks each
        one against the write actually asked for. So a grant obtained for one
        file cannot be replayed against another, and a grant obtained for one
        set of bytes cannot authorise different bytes at the same path.
        """
        if not self.is_authorized():
            self.audit.record("file_grant_refused", {
                "target": str(target), "path": str(path),
                "why": "no live owner session; authorise again",
            })
            raise Refused(
                "a file write needs a live owner login with password and "
                "authenticator. Authorise and retry.")
        wanted = max(1, int(seconds))
        if wanted > self.MAX_FILE_GRANT_SECONDS:
            # REFUSE, DO NOT SILENTLY SHORTEN. A caller that asked for an
            # hour should be told it cannot have one, not handed five minutes
            # and left believing it has an hour.
            self.audit.record("file_grant_refused", {
                "target": str(target), "path": str(path),
                "why": "asked for %ds, cap is %ds" % (wanted, self.MAX_FILE_GRANT_SECONDS),
            })
            raise Refused(
                "a grant may run at most %d seconds; %d was asked for."
                % (self.MAX_FILE_GRANT_SECONDS, wanted))
        digest = str(sha256 or "").strip().lower()
        if not re.fullmatch(r"[0-9a-f]{64}", digest):
            self.audit.record("file_grant_refused", {
                "target": str(target), "path": str(path),
                "why": "the content hash is not a sha256",
            })
            raise Refused("the content hash must be a 64-character sha256.")

        with self._session_lock:
            # THE GRANT CANNOT OUTLIVE THE TICKET IT RESTS ON. A grant that
            # expired later than the session would be a grant the host might
            # still accept after the owner's authorisation had lapsed.
            expires = min(float(self._clock()) + wanted, float(self._expires_at))
            grant = {
                "user": self._username,
                "ticket": self._ticket,
                "expires": expires,
                "target": str(target),
                "path": str(path),
                "sha256": digest,
            }
        self.audit.record("file_grant_minted", {
            "user": grant["user"],
            "target": grant["target"],
            "path": grant["path"],
            "sha256": digest,
            "expires": expires,
            # The ticket is deliberately absent from this record. The audit
            # says a grant was minted and for what; it is not a second copy
            # of the credential.
        })
        return json.dumps(grant, separators=(",", ":"))

    # ── P3 write execution ───────────────────────────────────────────────
    def call(self, method: str, path: str, data: dict = None, timeout: int = 15):
        """Execute an authorised Proxmox API call. Refuses unauthenticated or unsafe calls."""
        method = (method or "").upper()
        bad_path = _path_refusal(path)

        if not self.is_authorized():
            self.audit.record("write_refused", {
                "method": method,
                "path": path,
                "why": "no active authorised ticket (unauthenticated, expired, or revoked)",
            })
            raise Refused(
                "PROX VOX write refused: no active authorised Proxmox ticket. "
                "Call authorize() with live password + TOTP first."
            )

        if method not in ALLOWED_METHODS:
            self.audit.record("write_refused", {
                "method": method,
                "path": path,
                "username": self._username,
                "why": "unsupported method",
            })
            raise Refused("PROX VOX write refused unsupported method %r." % method)

        if bad_path:
            self.audit.record("write_refused", {
                "method": method,
                "path": path,
                "username": self._username,
                "why": "unsafe path",
            })
            raise Refused(
                "PROX VOX write refused path %r before building a request: %s."
                % (path, bad_path)
            )

        url = self.base_url + "/api2/json/" + path.strip().lstrip("/")
        encoded_body = None
        if data is not None:
            encoded_body = urllib.parse.urlencode(data, doseq=True).encode("utf-8")

        request = urllib.request.Request(url, data=encoded_body, method=method)
        request.add_header("Cookie", "PVEAuthCookie=%s" % self._ticket)
        request.add_header("CSRFPreventionToken", self._csrf_token)
        if encoded_body is not None:
            request.add_header("Content-Type", "application/x-www-form-urlencoded")

        # Audit BEFORE execution so a crash mid-call leaves evidence on disk.
        self.audit.record("write_call", {
            "method": method,
            "path": path,
            "username": self._username,
        })

        try:
            with self._opener(request, timeout=timeout) as response:
                body = response.read().decode("utf-8", "replace")
                status = getattr(response, "status", 200)
        except urllib.error.HTTPError as err:
            self.audit.record("write_result", {
                "method": method,
                "path": path,
                "username": self._username,
                "status": err.code,
            })
            if err.code == 401:
                self._clear_session()
            raise
        except CertificatePinError as err:
            self._clear_session()
            self.audit.record("tls_refused", {
                "method": method, "path": path, "why": str(err),
            })
            raise Refused(str(err)) from err
        except Exception as err:
            self.audit.record("write_result", {
                "method": method,
                "path": path,
                "username": self._username,
                "error": type(err).__name__,
            })
            raise

        self.audit.record("write_result", {
            "method": method,
            "path": path,
            "username": self._username,
            "status": status,
            "bytes": len(body),
        })
        try:
            return json.loads(body)
        except ValueError:
            return {"raw": body}

    def post(self, path: str, data: dict = None, timeout: int = 15):
        return self.call("POST", path, data=data, timeout=timeout)

    def put(self, path: str, data: dict = None, timeout: int = 15):
        return self.call("PUT", path, data=data, timeout=timeout)

    def delete(self, path: str, data: dict = None, timeout: int = 15):
        return self.call("DELETE", path, data=data, timeout=timeout)

    # ── P3b: the ticket-bound in-container write path ───────────────────
    def open_container_console(self, node: str, vmid: int, connector=None,
                               timeout: int = 15):
        """Open an LXC console using this live PVE ticket, never another credential."""
        node_name = str(node or "").strip()
        try:
            guest = int(vmid)
        except (TypeError, ValueError) as err:
            raise Refused("A safe node name and positive container id are required.") from err
        if not re.fullmatch(r"[A-Za-z0-9_.-]+", node_name) or guest < 1:
            raise Refused("A safe node name and positive container id are required.")
        if not self.is_authorized():
            self.audit.record("container_write_refused", {
                "node": node_name, "vmid": guest, "why": "no active TOTP ticket",
            })
            raise Refused("Container console refused: authorize with TOTP first.")

        response = self.post(
            "/nodes/%s/lxc/%d/termproxy" % (node_name, guest), timeout=timeout
        )
        data = response.get("data") if isinstance(response, dict) else None
        if not isinstance(data, dict) or not data.get("port") or not data.get("ticket"):
            raise Refused("Proxmox did not return a usable LXC terminal proxy.")
        if connector is None:                                   # pragma: no cover
            from .container_console import connect_console
            connector = connect_console
        console = connector(
            base_url=self.base_url, node=node_name, vmid=guest,
            port=int(data["port"]), vncticket=str(data["ticket"]),
            pve_ticket=self._ticket,
            certificate_sha256=self._certificate_sha256, timeout=timeout,
        )
        self._container_consoles.append(console)
        self.audit.record("container_console_open", {
            "node": node_name, "vmid": guest, "username": self._username,
        })
        return console

    def run_container_command(self, console, command: str):
        """Run one audited command while both the console and TOTP ticket live."""
        if not self.is_authorized() or console not in self._container_consoles:
            self.audit.record("container_write_refused", {
                "why": "console is not tied to the active TOTP ticket",
            })
            raise Refused("Container write refused: the TOTP ticket is absent or revoked.")
        text = str(command or "")
        if not text.strip() or "\x00" in text:
            raise Refused("A non-empty command without NUL bytes is required.")
        self.audit.record("container_write_call", {
            "username": self._username,
            "command_sha256": hashlib.sha256(text.encode()).hexdigest(),
        })
        return console.run(text)

    def __repr__(self) -> str:
        return "<WriteClient %s authorized=%s>" % (self.base_url, self.is_authorized())

    __str__ = __repr__

# ===== SNAPSMACK EOF =====
