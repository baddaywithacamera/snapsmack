"""PROX VOX certificate pinning for the Proxmox HTTPS endpoint.

The Proxmox host uses a self-signed certificate.  PROX VOX therefore treats
the certificate's SHA-256 fingerprint as the trust anchor: setup records the
fingerprint in the local-only config, and every HTTPS connection compares the
presented certificate before sending an HTTP request.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import hashlib
import http.client
import json
import os
import re
import socket
import ssl
import sys
import tempfile
import urllib.parse
import urllib.request


CHANGED_CERTIFICATE_MESSAGE = (
    "Proxmox certificate changed. If you renewed it, re-pin; otherwise do not proceed."
)
NO_PIN_MESSAGE = (
    "No Proxmox certificate pin is configured. Run PROX VOX setup and record "
    "the server certificate SHA-256 fingerprint before connecting."
)
REDIRECT_REFUSED_MESSAGE = (
    "PROX VOX refuses redirects from the pinned Proxmox endpoint. "
    "The configured HTTPS endpoint must answer directly."
)


class CertificatePinError(Exception):
    """The HTTPS peer did not present the locally pinned certificate."""


def default_config_path() -> str:
    """Return SnapSmack's local-only config path for PROX VOX."""
    shared = os.path.abspath(
        os.path.join(os.path.dirname(__file__), "..", "..", "_shared")
    )
    if shared not in sys.path:
        sys.path.insert(0, shared)
    import snap_home
    return snap_home.config_path("proxvox", "config.json")


def normalize_fingerprint(value: str) -> str:
    """Return a lower-case 64-hex SHA-256 fingerprint or raise clearly."""
    fingerprint = re.sub(r"[\s:]", "", str(value or "")).lower()
    if not re.fullmatch(r"[0-9a-f]{64}", fingerprint):
        raise CertificatePinError(NO_PIN_MESSAGE)
    return fingerprint


def _pinning_context() -> ssl.SSLContext:
    """Build the explicit TLS context used only with a verified pin.

    CA validation is intentionally disabled because the self-signed leaf is
    the configured trust anchor.  PinnedHTTPSConnection verifies that leaf
    immediately after the handshake and before urllib sends the request.
    """
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_CLIENT)
    context.check_hostname = False
    context.verify_mode = ssl.CERT_NONE
    context.minimum_version = ssl.TLSVersion.TLSv1_2
    return context


class _PinnedHTTPSConnection(http.client.HTTPSConnection):
    def __init__(self, host, *, pinned_fingerprint: str, timeout=None, **kwargs):
        kwargs.pop("context", None)
        kwargs.pop("check_hostname", None)
        super().__init__(host, timeout=timeout, context=_pinning_context(), **kwargs)
        self._pinned_fingerprint = pinned_fingerprint

    def connect(self):
        super().connect()
        certificate = self.sock.getpeercert(binary_form=True) if self.sock else None
        actual = hashlib.sha256(certificate or b"").hexdigest()
        if actual != self._pinned_fingerprint:
            self.close()
            raise CertificatePinError(CHANGED_CERTIFICATE_MESSAGE)


class _PinnedHTTPSHandler(urllib.request.HTTPSHandler):
    def __init__(self, fingerprint: str):
        super().__init__()
        self._fingerprint = fingerprint

    def https_open(self, request):
        def connection(host, **kwargs):
            return _PinnedHTTPSConnection(
                host, pinned_fingerprint=self._fingerprint, **kwargs
            )

        return self.do_open(connection, request)


class _NoRedirectHandler(urllib.request.HTTPRedirectHandler):
    """Refuse redirects before urllib can forward credentials elsewhere."""

    def redirect_request(self, request, fp, code, msg, headers, newurl):
        raise CertificatePinError(REDIRECT_REFUSED_MESSAGE)


def pinned_https_opener(base_url: str, fingerprint: str):
    """Return urllib's open callable, locked to one HTTPS certificate."""
    parsed = urllib.parse.urlsplit(str(base_url or ""))
    if parsed.scheme.lower() != "https":
        raise CertificatePinError("PROX VOX requires an HTTPS Proxmox URL.")
    pin = normalize_fingerprint(fingerprint)
    # Supplying our redirect-handler subclass prevents build_opener() from
    # installing urllib's permissive default.  The default preserves the
    # Authorization header and follows HTTPS -> HTTP, which can disclose the
    # API token and accept another server's body as Proxmox data.  Proxmox's
    # API does not require redirects, so every redirect is a trust failure.
    return urllib.request.build_opener(
        _PinnedHTTPSHandler(pin), _NoRedirectHandler()
    ).open


def inspect_server_fingerprint(base_url: str, timeout: int = 15) -> str:
    """Read the presented certificate fingerprint for an explicit setup step.

    This does not trust or save it.  The operator must compare/approve it and
    pass it to save_pin_config().
    """
    parsed = urllib.parse.urlsplit(str(base_url or ""))
    if parsed.scheme.lower() != "https" or not parsed.hostname:
        raise ValueError("A complete HTTPS Proxmox URL is required.")
    port = parsed.port or 443
    with socket.create_connection((parsed.hostname, port), timeout=timeout) as raw:
        with _pinning_context().wrap_socket(raw, server_hostname=parsed.hostname) as tls:
            certificate = tls.getpeercert(binary_form=True)
    return hashlib.sha256(certificate).hexdigest()


def save_pin_config(path: str, base_url: str, fingerprint: str) -> None:
    """Atomically write the non-secret, local-only PROX VOX TLS config."""
    pin = normalize_fingerprint(fingerprint)
    target = os.path.abspath(path)
    parent = os.path.dirname(target)
    os.makedirs(parent, exist_ok=True)
    payload = {"base_url": str(base_url).rstrip("/"), "certificate_sha256": pin}
    handle, temporary = tempfile.mkstemp(prefix=".proxvox-config-", dir=parent)
    try:
        with os.fdopen(handle, "w", encoding="utf-8", newline="\n") as stream:
            json.dump(payload, stream, indent=2, sort_keys=True)
            stream.write("\n")
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(temporary, target)
    except Exception:
        try:
            os.unlink(temporary)
        except OSError:
            pass
        raise


def load_pin_config(path: str) -> dict:
    """Load the local TLS endpoint and pin, refusing absent/invalid pins."""
    try:
        with open(path, encoding="utf-8") as stream:
            payload = json.load(stream)
    except (OSError, ValueError) as err:
        raise CertificatePinError(NO_PIN_MESSAGE) from err
    if not isinstance(payload, dict):
        raise CertificatePinError(NO_PIN_MESSAGE)
    return {
        "base_url": str(payload.get("base_url") or "").rstrip("/"),
        "certificate_sha256": normalize_fingerprint(payload.get("certificate_sha256")),
    }

# ===== SNAPSMACK EOF =====
