"""PROX VOX — the read-only broker. (SS-1507 P1)

P1 IS READING AND NOTHING ELSE. There is no write path in this file, and that
is deliberate rather than unfinished: the signed window, the local Proxmox
verifier and the per-action TOTP all arrive together in P3, and a broker that
could write before they exist would be the thing the design was written to
prevent.

The refusals are therefore structural, not configured:
  - any method but GET is refused before a request is built;
  - any path outside the granted scopes is refused;
  - the broker will not start on any machine but its pinned one;
  - the token is read from Windows Credential Manager, never a file, never an
    environment variable, and never logged.

WHAT A TOKEN CAN DO IS STILL THE SERVER'S ANSWER, NOT THIS FILE'S. The ACL on
the Proxmox side is what actually stops a write; these checks stop us ASKING.
Both matter. A client-side refusal keeps an accident from becoming a request,
and the server-side grant is what holds when the client is wrong or replaced.
"""

import json
import urllib.error
import urllib.parse
import urllib.request

from . import host_guard

# The three paths P1 was granted, and the only ones it will ask for. Scoped
# deliberately rather than taking PVEAuditor at / -- least privilege, and the
# PM confirmed it. A read the broker turns out to need gets its own path and
# its own grant; it does not get here by widening to the root.
GRANTED_SCOPES = ("/nodes", "/vms", "/storage")

# P1 is read-only, so this is the whole allowlist of verbs.
ALLOWED_METHODS = ("GET",)


class Refused(Exception):
    """The broker refused to make a call. Never raised by the server."""


def _scope_of(path: str) -> str:
    p = "/" + path.strip().lstrip("/")
    for scope in GRANTED_SCOPES:
        if p == scope or p.startswith(scope + "/"):
            return scope
    return ""


class ReadOnlyBroker:
    def __init__(self, base_url: str, token_id: str, token_secret: str,
                 audit, pinned_guid: str, actual_guid: str = None,
                 opener=None, verify_host: bool = True):
        if verify_host:
            host_guard.require_host(pinned_guid, actual_guid)
        self.base_url = base_url.rstrip("/")
        self.token_id = token_id
        self._secret = token_secret
        self.audit = audit
        # Injected so the tests drive a real request through a real HTTP
        # server rather than a stubbed-out client. The default is urllib.
        self._opener = opener or urllib.request.urlopen

    # ── the only thing P1 does ───────────────────────────────────────────
    def get(self, path: str, timeout: int = 15):
        """Read one Proxmox path. Refuses anything it was not granted."""
        return self.call("GET", path, timeout=timeout)

    def call(self, method: str, path: str, timeout: int = 15):
        method = (method or "").upper()
        scope = _scope_of(path)

        # Refusals are recorded BEFORE they are raised. A refused call is the
        # most interesting thing in an audit log, not the least, and leaving
        # refusals out would make the log read as though nothing happened.
        if method not in ALLOWED_METHODS:
            self.audit.record("refused", {"method": method, "path": path,
                                          "why": "P1 is read-only"})
            raise Refused(
                "PROX VOX P1 is read-only: %s was refused before any request "
                "was built. Writes arrive in P3, behind a signed window and a "
                "fresh code." % method
            )
        if not scope:
            self.audit.record("refused", {"method": method, "path": path,
                                          "why": "outside the granted scopes"})
            raise Refused(
                "%s is outside the paths PROX VOX was granted (%s). Refusing. "
                "If this read is needed, it gets its own grant."
                % (path, ", ".join(GRANTED_SCOPES))
            )

        url = self.base_url + "/api2/json/" + path.strip().lstrip("/")
        request = urllib.request.Request(url, method=method)
        # PVEAPIToken is Proxmox's own scheme: the secret travels in the
        # header and nowhere else. It is never put in the URL, where it would
        # land in the server's access log and in any proxy between here and
        # there.
        request.add_header("Authorization",
                           "PVEAPIToken=%s=%s" % (self.token_id, self._secret))

        # Recorded before the call, so a crash mid-request still leaves the
        # attempt on the record.
        self.audit.record("call", {"method": method, "path": path, "scope": scope})

        try:
            with self._opener(request, timeout=timeout) as response:
                body = response.read().decode("utf-8", "replace")
                status = getattr(response, "status", 200)
        except urllib.error.HTTPError as err:
            self.audit.record("result", {"path": path, "status": err.code})
            raise
        except Exception as err:
            # The class name, never the message: a urllib message can carry
            # the full URL, and a future caller may put a secret in one.
            self.audit.record("result", {"path": path, "error": type(err).__name__})
            raise

        self.audit.record("result", {"path": path, "status": status,
                                     "bytes": len(body)})
        try:
            return json.loads(body)
        except ValueError:
            return {"raw": body}

    # ── keeping the secret out of everything ─────────────────────────────
    def __repr__(self) -> str:
        return "<ReadOnlyBroker %s token=%s secret=hidden>" % (self.base_url, self.token_id)

    __str__ = __repr__

# ===== SNAPSMACK EOF =====
