"""PVE LXC websocket console used only by a live TOTP-backed WriteClient.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import hashlib
import socket
import urllib.parse
import uuid

from .tls_pin import (
    CHANGED_CERTIFICATE_MESSAGE, CertificatePinError, _pinning_context,
    normalize_fingerprint,
)


class PVEConsole:
    def __init__(self, websocket, maximum_output: int = 1_048_576):
        self._websocket = websocket
        self._maximum_output = maximum_output

    def run(self, command: str) -> str:
        marker = "__PROXVOX_DONE_%s__" % uuid.uuid4().hex
        wrapped = "%s\nprintf '\\n%s:%%s\\n' $?\n" % (command, marker)
        self._websocket.send(wrapped)
        chunks = []
        size = 0
        while True:
            chunk = self._websocket.recv()
            if isinstance(chunk, bytes):
                chunk = chunk.decode("utf-8", "replace")
            chunk = str(chunk)
            chunks.append(chunk)
            size += len(chunk.encode("utf-8", "replace"))
            joined = "".join(chunks)
            if marker + ":" in joined:
                return joined
            if size > self._maximum_output:
                raise RuntimeError("Container command output exceeded the safety limit.")

    def close(self):
        self._websocket.close()


def console_websocket_url(netloc: str, node: str, vmid: int, query: str,
                          host: bool = False) -> str:
    """The console websocket URL, for a container or for the HOST.

    PULLED OUT AS A PURE FUNCTION BECAUSE IT WAS WRONG AND NOTHING COULD SEE
    IT. This URL was built inline, after the socket and the TLS pin check, so
    the only way to reach it was a real connection to a real Proxmox -- which
    is why the one line that mattered sat behind `pragma: no cover`.

    SS-1658 added a host shell and passed vmid=0 into this, and the inline
    version hard-coded /lxc/%d, so live it would have opened a console on LXC
    CONTAINER 0 instead of the node. My tests injected a fake connector and
    asserted vmid==0, so they never built a URL at all and never saw it. Muse
    found it by reading the path I had marked as uncovered (room 9390).

    HOST IS AN EXPLICIT FLAG, not vmid==0. The write client already refuses to
    treat a container id of 0 as "the host" -- open_host_console is a separate
    method precisely so a typo cannot land a command on the host -- and then I
    handed this a sentinel anyway. A flag says what it means.
    """
    node_part = urllib.parse.quote(str(node), safe="")
    if host:
        return "wss://%s/api2/json/nodes/%s/vncwebsocket?%s" % (netloc, node_part, query)
    return "wss://%s/api2/json/nodes/%s/lxc/%d/vncwebsocket?%s" % (
        netloc, node_part, int(vmid), query,
    )


def connect_console(*, base_url: str, node: str, vmid: int, port: int,
                    vncticket: str, pve_ticket: str,
                    certificate_sha256: str, timeout: int = 15,
                    host: bool = False):
    """Connect the websocket over the same socket whose certificate is pinned."""
    try:
        import websocket
    except ImportError as err:                                  # pragma: no cover
        raise RuntimeError("PROX VOX P3b requires websocket-client.") from err
    parsed = urllib.parse.urlsplit(base_url)
    pin = normalize_fingerprint(certificate_sha256)
    raw = socket.create_connection((parsed.hostname, parsed.port or 443), timeout)
    tls = _pinning_context().wrap_socket(raw, server_hostname=parsed.hostname)
    actual = hashlib.sha256(tls.getpeercert(binary_form=True) or b"").hexdigest()
    if actual != pin:
        tls.close()
        raise CertificatePinError(CHANGED_CERTIFICATE_MESSAGE)
    query = urllib.parse.urlencode({"port": port, "vncticket": vncticket})
    url = console_websocket_url(parsed.netloc, node, vmid, query, host=host)
    try:
        ws = websocket.create_connection(
            url, socket=tls, timeout=timeout,
            header=["Cookie: PVEAuthCookie=%s" % pve_ticket],
        )
    except Exception:
        tls.close()
        # websocket-client includes the full URL in some exception strings;
        # that URL carries the one-use vncticket. Fail without re-emitting it.
        raise RuntimeError("Proxmox console websocket connection failed.") from None
    return PVEConsole(ws)

# ===== SNAPSMACK EOF =====
