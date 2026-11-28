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
from .secrets import redact_command
from .tls_pin import CertificatePinError, pinned_https_opener

ALLOWED_METHODS = ("POST", "PUT", "DELETE", "GET")
PROXMOX_TICKET_TTL_SECONDS = 7200

# SEAN'S RULING, 2026-11-27, restated 2026-11-28 after he typed his second TOTP
# of one morning and asked "what happened to 24 hours?": ONE TOTP opens a
# 24-hour write window covering every PROX VOX operation. Not one session per
# operation, and not one per job.
#
# WHY THAT NEEDED MORE THAN RAISING THE NUMBER ABOVE. A Proxmox /access/ticket
# is valid for TWO HOURS and that is Proxmox's lifetime, not ours -- setting
# this constant to 86400 would just mean the client believed in a ticket the
# server had already stopped honouring. The window is held open by RENEWING the
# ticket instead, which Proxmox does by accepting the CURRENT TICKET in place
# of the password. So the renewal credential is the ticket already in memory:
# no stored password, no stored TOTP, no stored write token, which are Sean's
# 2026-11-22 conditions and they still hold.
#
# IT IS STILL TIME-BOXED. The window closes at this bound whatever happens, a
# failed renewal fails CLOSED, and revoke() is still instant.
PROXVOX_WRITE_WINDOW_SECONDS = 86400


class WriteClient:
    """Short-lived, live-authorised Proxmox write client (Option A + P5 revoke)."""

    def __init__(self, base_url: str, audit, pinned_guid: str,
                 actual_guid: str = None, opener=None, verify_host: bool = True,
                 clock=None, ticket_ttl: int = PROXMOX_TICKET_TTL_SECONDS,
                 certificate_sha256: str = "", expiry_timer_factory=None,
                 window_seconds: int = PROXVOX_WRITE_WINDOW_SECONDS,
                 renew_margin: int = 300):
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
        # The outer bound on one authorisation. A ticket lives 2 hours; the
        # window may span many tickets and still only ever one TOTP.
        self._window_seconds = max(1, int(window_seconds))
        # Renew THIS EARLY, so a renewal that fails does not leave a dead
        # ticket in the middle of somebody's write.
        self._renew_margin = max(1, int(renew_margin))
        self._expiry_timer_factory = expiry_timer_factory or threading.Timer
        self._certificate_sha256 = certificate_sha256
        self._session_lock = threading.RLock()

        self._username: str = ""
        self._ticket: str = ""
        self._csrf_token: str = ""
        self._expires_at: float = 0.0
        self._window_ends_at: float = 0.0
        self._renewals: int = 0
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
            self._window_ends_at = 0.0

    def _schedule_expiry(self):
        with self._session_lock:
            self._expiry_generation += 1
            generation = self._expiry_generation

            # DECIDED HERE, NOT WHEN IT FIRES. The wake is either a renewal
            # or the end of the window, and which one it is follows from the
            # time remaining AT SCHEDULING. Re-deriving it from the clock
            # inside the callback made the behaviour depend on the clock
            # advancing between scheduling and firing, which is true of a real
            # clock and not of a test one -- so the window could never be
            # observed closing. The intent belongs in the schedule.
            wake_in = max(1, self._ticket_ttl - self._renew_margin)
            window_left = self._window_ends_at - float(self._clock())
            final = window_left <= wake_in
            if final:
                wake_in = max(1, int(window_left))

            def wake():
                with self._session_lock:
                    if generation != self._expiry_generation or not self._ticket:
                        return
                    user = self._username
                    if final:
                        self.audit.record("write_expired", {
                            "username": user,
                            "why": "the authorised write window closed",
                            "renewals": self._renewals,
                        })
                        self._clear_session()
                        return
                self._renew(user)

            timer = self._expiry_timer_factory(wake_in, wake)
            if hasattr(timer, "daemon"):
                timer.daemon = True
            self._expiry_timer = timer
            timer.start()

    def is_authorized(self) -> bool:
        with self._session_lock:
            if not self._ticket or not self._csrf_token:
                return False
            now = float(self._clock())
            # BOTH bounds. The ticket is the inner one and is renewed; the
            # window is the outer one and is never extended.
            if now >= self._window_ends_at or now >= self._expires_at:
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
            # THE WINDOW IS SET BY THE TOTP AND BY NOTHING ELSE. _renew() must
            # never touch it, or a renewal would extend the window and one TOTP
            # would last for ever.
            self._window_ends_at = now + self._window_seconds
            self._renewals = 0
            self._schedule_expiry()

        self.audit.record("write_auth_result", {
            "username": user,
            "ok": True,
            "status": status,
            "expires_at": self._expires_at,
            "window_ends_at": self._window_ends_at,
        })
        return {"username": user, "expires_at": self._expires_at,
                "window_ends_at": self._window_ends_at}

    # ── the 24-hour window: renew the ticket, never the window ───────────
    def _renew(self, user: str, timeout: int = 15) -> bool:
        """Exchange the live ticket for a fresh one. No password, no TOTP.

        Proxmox accepts a VALID TICKET in place of the password on
        /access/ticket, which is how one TOTP can hold a 24-hour window open
        without anything being stored. The credential used here is the ticket
        already in memory, so Sean's 2026-11-22 conditions are untouched: no
        stored password, no stored TOTP, no stored write token.

        FAILS CLOSED. Anything other than a fresh ticket and CSRF token clears
        the session, so a renewal that cannot happen ends the window rather
        than leaving a client that believes in a dead ticket.
        """
        with self._session_lock:
            ticket = self._ticket
            if not ticket:
                return False

        url = self.base_url + "/api2/json/access/ticket"
        form = urllib.parse.urlencode({"username": user, "password": ticket})
        request = urllib.request.Request(url, data=form.encode("utf-8"), method="POST")
        request.add_header("Content-Type", "application/x-www-form-urlencoded")

        # Recorded BEFORE the request, like every other call here.
        self.audit.record("write_renew_attempt", {
            "username": user,
            "renewals_so_far": self._renewals,
        })
        try:
            with self._opener(request, timeout=timeout) as response:
                body = response.read().decode("utf-8", "replace")
                status = getattr(response, "status", 200)
        except Exception as err:            # transport, HTTP, or pinning
            self.audit.record("write_renew_failed", {
                "username": user,
                "why": type(err).__name__,
            })
            self._clear_session()
            return False

        try:
            payload = json.loads(body).get("data") or {}
        except Exception:
            self.audit.record("write_renew_failed", {
                "username": user, "status": status,
                "why": "invalid JSON from /access/ticket",
            })
            self._clear_session()
            return False

        fresh = str(payload.get("ticket") or "").strip()
        csrf = str(payload.get("CSRFPreventionToken") or "").strip()
        if not fresh or not csrf:
            self.audit.record("write_renew_failed", {
                "username": user, "status": status,
                "why": "no ticket or CSRFPreventionToken in the renewal",
            })
            self._clear_session()
            return False

        now = float(self._clock())
        with self._session_lock:
            if not self._ticket:               # revoked while we were asking
                return False
            self._ticket = fresh
            self._csrf_token = csrf
            self._expires_at = now + self._ticket_ttl
            self._renewals += 1
            # _window_ends_at IS NOT TOUCHED. That is the whole safety
            # property: renewing extends the TICKET and never the window, so
            # one TOTP cannot outlive its 24 hours.
            renewals = self._renewals
            window_ends_at = self._window_ends_at
            self._schedule_expiry()

        self.audit.record("write_renewed", {
            "username": user,
            "renewals": renewals,
            "expires_at": now + self._ticket_ttl,
            "window_ends_at": window_ends_at,
        })
        return True

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

    def open_host_console(self, node: str, connector=None, timeout: int = 15):
        """Open a shell on the Proxmox HOST, not inside a container.

        Sean's 2026-11-28 12:56 ruling: inside the authorised window PROX VOX
        gives a general shell in any container AND ON THE HOST. Everything
        here already reached into containers -- /nodes/{node}/lxc/{vmid}/
        termproxy -- and there was no host path at all, so "and on the host"
        was not buildable by the shared app however its menu was arranged.

        It is the SAME Proxmox mechanism one level up: /nodes/{node}/termproxy.
        Deliberately a SEPARATE method rather than a vmid=0 special case on the
        container one, so a typo in a container id can never silently land a
        command on the host instead of in the guest.
        """
        node_name = str(node or "").strip()
        if not re.fullmatch(r"[A-Za-z0-9_.-]+", node_name):
            raise Refused("A safe node name is required for a host shell.")
        if not self.is_authorized():
            self.audit.record("host_write_refused", {
                "node": node_name, "why": "no active TOTP ticket",
            })
            raise Refused("Host console refused: authorize with TOTP first.")

        response = self.post("/nodes/%s/termproxy" % node_name, timeout=timeout)
        data = response.get("data") if isinstance(response, dict) else None
        if not isinstance(data, dict) or not data.get("port") or not data.get("ticket"):
            raise Refused("Proxmox did not return a usable host terminal proxy.")
        if connector is None:                                   # pragma: no cover
            from .container_console import connect_console
            connector = connect_console
        console = connector(
            base_url=self.base_url, node=node_name, vmid=0,
            port=int(data["port"]), vncticket=str(data["ticket"]),
            pve_ticket=self._ticket,
            certificate_sha256=self._certificate_sha256, timeout=timeout,
            # host=True IS THE WHOLE FIX. Without it the shared connector
            # builds /nodes/{node}/lxc/0/vncwebsocket -- a console on LXC
            # container 0, not the node shell. vmid=0 is passed only so the
            # audit and the connector signature stay the same shape as the
            # container path; it is the FLAG that decides the URL.
            host=True,
        )
        # Tracked in the SAME list as container consoles, so revoke() and the
        # window closing shut a host shell exactly as they shut a guest one.
        # A second list would have been a second thing to remember to close.
        self._container_consoles.append(console)
        self.audit.record("host_console_open", {
            "node": node_name, "username": self._username,
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
        # THE LOG MUST SAY WHAT WAS RUN, NOT JUST THAT SOMETHING WAS.
        #
        # This recorded ONLY a sha256 of the command. That was arguably enough
        # while every operation had its own TOTP and Sean watched each dialog
        # go by: he saw the action, and the hash tied the log to it. Sean's
        # 2026-11-28 12:56 ruling replaced that with a 24-HOUR UNATTENDED
        # WINDOW across the host, every container and every database -- and the
        # log is now the ONLY record of what happened on his servers.
        #
        # A hash cannot be read. It can only CONFIRM a command somebody has
        # already guessed. Nobody can review a day's work from a column of
        # digests, which makes "append-only log retained" (the condition the
        # open-door ruling kept, OPAUDIT 042) worth nothing in practice.
        #
        # So the text goes in. The hash stays beside it, because it is what
        # proves the text was not edited afterwards.
        #
        # THE COST THIS PARAGRAPH USED TO DESCRIBE IS PAID. It said a typed
        # password landed in the buffer and there was no blocklist to redact
        # against. Sean answered at 14:56 with one word, "blank", and
        # redact_command() below does it. Kept as a note rather than deleted,
        # because the reasoning is why the text is logged at all.
        # SEAN, 2026-11-28 14:56: "blank". The command text goes in the log so
        # a day of unattended work can be read, and the SECRET VALUES in it are
        # blanked before anything is written. The sha256 is of the ORIGINAL
        # command, not the redacted text, so the entry is still provable even
        # though what it shows has been edited -- which is the only reason
        # redacting a log is honest rather than just tidy.
        self.audit.record("container_write_call", {
            "username": self._username,
            "command": redact_command(text),
            "command_sha256": hashlib.sha256(text.encode()).hexdigest(),
        })
        return console.run(text)

    def __repr__(self) -> str:
        return "<WriteClient %s authorized=%s>" % (self.base_url, self.is_authorized())

    __str__ = __repr__

# ===== SNAPSMACK EOF =====
