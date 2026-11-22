"""PROX VOX — the read-only broker. (SS-1507 P1)

P1 IS READING AND NOTHING ELSE. There is no write path in this file, and that
is deliberate rather than unfinished: the signed window, the local Proxmox
verifier and the per-action TOTP all arrive together in P3, and a broker that
could write before they exist would be the thing the design was written to
prevent.

The refusals are therefore structural, not configured:
  - any method but GET is refused before a request is built;
  - the read grant is the whole server (Sean, 2026-11-22), so the path is
    no longer restricted -- but a path that is not a Proxmox API path, or
    that would reach another host, is still refused;
  - the broker will not start on any machine but its pinned one;
  - the token is read from Windows Credential Manager, never a file, never an
    environment variable, and never logged.

WHAT A TOKEN CAN DO IS STILL THE SERVER'S ANSWER, NOT THIS FILE'S. The ACL on
the Proxmox side is what actually stops a write; these checks stop us ASKING.
Both matter. A client-side refusal keeps an accident from becoming a request,
and the server-side grant is what holds when the client is wrong or replaced.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import json
import urllib.error
import urllib.parse
import urllib.request

from . import host_guard

# SEAN'S RULING, 2026-11-22 15:50: the AI gets 100% READ access to the whole
# server at all times. So the three-path allowlist this started with is gone,
# and the read token is read-only at / -- all nodes, guests, storage, access
# and cluster. P1 is still GET-ONLY: there is no write method anywhere in it.
#
# THE METHOD IS NOW THE ONLY CONTROL IN HERE, and that is the point rather than
# an oversight. Saying so plainly because the previous comment in this spot
# argued the opposite (least privilege, each read gets its own grant) and a
# future reader should see that it was overruled on purpose, not lost.
GRANTED_SCOPES = ("/",)

# P1 is read-only, so this is the whole allowlist of verbs.
ALLOWED_METHODS = ("GET",)


class Refused(Exception):
    """The broker refused to make a call. Never raised by the server."""


# WHAT THE OLD ALLOWLIST WAS ALSO DOING, WHICH IS WHY THIS EXISTS.
# Refusing anything that did not start with /nodes, /vms or /storage happened
# to refuse a great deal else: "../.." climbing out of /api2/json/, a path
# beginning "//" (which a browser or urllib reads as protocol-relative and
# sends to ANOTHER HOST), and a full "https://..." URL pasted in as a path.
# None of that was deliberate; it fell out of the prefix match. Widening to /
# removes the accident, so the protections it was providing have to be written
# down and kept.
#
# A broker that can be talked into reading from a host that is not Sean's
# Proxmox is not a read-only broker, it is an open proxy with his token on it.
_BAD_IN_PATH = ("://", "\\", "\r", "\n", "\t")


def _path_refusal(path: str) -> str:
    """Return the reason this path is not safe to append, or '' if it is fine.

    Not a scope check any more -- every Proxmox GET path is granted. This is
    only about the path staying a path, and staying on Sean's server.
    """
    raw = (path or "").strip()
    if not raw:
        return "an empty path is not a read of anything"
    for bad in _BAD_IN_PATH:
        if bad in raw:
            return ("a path may not contain %r -- that is an attempt to reach "
                    "something other than a Proxmox API path" % bad)
    # CHECKED ON THE RAW VALUE, BEFORE NORMALISING, because lstrip("/") removes
    # EVERY leading slash -- so normalising first turned "//evil/x" into
    # "/evil/x" and the check below could never fire. The test caught that.
    if raw.startswith("//"):
        return ("a path starting with // is protocol-relative; it is not a "
                "Proxmox API path")

    p = "/" + raw.lstrip("/")
    for seg in p.split("/"):
        if seg == "..":
            return ("a path may not contain .. -- it would climb out of "
                    "/api2/json/ and read something that is not the API")
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
        bad_path = _path_refusal(path)

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
        if bad_path:
            self.audit.record("refused", {"method": method, "path": path,
                                          "why": "unsafe path"})
            raise Refused(
                "PROX VOX refused the path %r before building a request: %s."
                % (path, bad_path)
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
        self.audit.record("call", {"method": method, "path": path})

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
