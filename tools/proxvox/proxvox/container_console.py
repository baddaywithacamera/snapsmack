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


def connect_console(*, base_url: str, node: str, vmid: int, port: int,
                    vncticket: str, pve_ticket: str,
                    certificate_sha256: str, timeout: int = 15):
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
    url = "wss://%s/api2/json/nodes/%s/lxc/%d/vncwebsocket?%s" % (
        parsed.netloc, urllib.parse.quote(node, safe=""), vmid, query,
    )
    try:
        ws = websocket.create_connection(
            url, socket=tls, timeout=timeout,
            header=["Cookie: PVEAuthCookie=%s" % pve_ticket],
        )
    except Exception:
        tls.close()
        raise
    return PVEConsole(ws)

# ===== SNAPSMACK EOF =====
