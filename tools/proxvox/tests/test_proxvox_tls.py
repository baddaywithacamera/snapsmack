"""Real self-signed HTTPS coverage for PROX VOX certificate pinning.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import datetime
import hashlib
import ipaddress
import json
import os
import ssl
import sys
import tempfile
import threading
import unittest
import urllib.parse
import urllib.request
from http.server import BaseHTTPRequestHandler, HTTPServer

from cryptography import x509
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import rsa
from cryptography.x509.oid import NameOID

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

from proxvox.audit import AuditBuffer  # noqa: E402
from proxvox.broker import ReadOnlyBroker, Refused  # noqa: E402
from proxvox.credentials import NoToken, load_token  # noqa: E402
from proxvox.tls_pin import (  # noqa: E402
    CHANGED_CERTIFICATE_MESSAGE,
    load_pin_config,
    save_pin_config,
)
from proxvox.write_client import WriteClient  # noqa: E402


GUID = "11111111-2222-3333-4444-555555555555"


def _certificate_files(directory: str):
    key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
    name = x509.Name([x509.NameAttribute(NameOID.COMMON_NAME, "127.0.0.1")])
    now = datetime.datetime.now(datetime.timezone.utc)
    certificate = (
        x509.CertificateBuilder()
        .subject_name(name)
        .issuer_name(name)
        .public_key(key.public_key())
        .serial_number(x509.random_serial_number())
        .not_valid_before(now - datetime.timedelta(minutes=1))
        .not_valid_after(now + datetime.timedelta(days=1))
        .add_extension(
            x509.SubjectAlternativeName([x509.IPAddress(ipaddress.ip_address("127.0.0.1"))]),
            critical=False,
        )
        .sign(key, hashes.SHA256())
    )
    certificate_path = os.path.join(directory, "server-cert.pem")
    key_path = os.path.join(directory, "server-key.pem")
    with open(certificate_path, "wb") as stream:
        stream.write(certificate.public_bytes(serialization.Encoding.PEM))
    with open(key_path, "wb") as stream:
        stream.write(
            key.private_bytes(
                serialization.Encoding.PEM,
                serialization.PrivateFormat.TraditionalOpenSSL,
                serialization.NoEncryption(),
            )
        )
    fingerprint = hashlib.sha256(
        certificate.public_bytes(serialization.Encoding.DER)
    ).hexdigest()
    return certificate_path, key_path, fingerprint


class _TLSProxmox(BaseHTTPRequestHandler):
    seen = []

    def _send(self, payload):
        body = json.dumps(payload).encode("utf-8")
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        type(self).seen.append(("GET", self.path))
        self._send({"data": [{"node": "proxmox", "status": "online"}]})

    def do_POST(self):
        length = int(self.headers.get("Content-Length", "0"))
        fields = urllib.parse.parse_qs(self.rfile.read(length).decode("utf-8"))
        type(self).seen.append(("POST", self.path))
        if "tfa-challenge" in fields:
            self._send({"data": {
                "ticket": "PVE:full-ticket",
                "CSRFPreventionToken": "csrf-token",
            }})
        else:
            self._send({"data": {"ticket": "PVE:!tfa!challenge"}})

    def log_message(self, *args):
        pass


class ProxVoxTLS(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.server_dir = tempfile.mkdtemp(prefix="proxvox-tls-server-")
        cert, key, cls.fingerprint = _certificate_files(cls.server_dir)
        cls.server = HTTPServer(("127.0.0.1", 0), _TLSProxmox)
        context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        context.load_cert_chain(cert, key)
        cls.server.socket = context.wrap_socket(cls.server.socket, server_side=True)
        cls.port = cls.server.server_address[1]
        cls.base_url = "https://127.0.0.1:%d" % cls.port
        cls.thread = threading.Thread(target=cls.server.serve_forever, daemon=True)
        cls.thread.start()

    @classmethod
    def tearDownClass(cls):
        cls.server.shutdown()
        cls.server.server_close()

    def setUp(self):
        _TLSProxmox.seen = []
        self.directory = tempfile.mkdtemp(prefix="proxvox-tls-test-")
        self.audit = AuditBuffer(os.path.join(self.directory, "audit.jsonl"))

    def broker(self, fingerprint):
        return ReadOnlyBroker(
            self.base_url,
            "proxvox@pve!p1-readonly",
            "secret-not-logged",
            self.audit,
            GUID,
            actual_guid=GUID,
            certificate_sha256=fingerprint,
        )

    def write_client(self, fingerprint):
        return WriteClient(
            self.base_url,
            self.audit,
            GUID,
            actual_guid=GUID,
            certificate_sha256=fingerprint,
        )

    def test_read_broker_accepts_matching_self_signed_certificate(self):
        result = self.broker(self.fingerprint).get("/nodes")
        self.assertEqual(result["data"][0]["node"], "proxmox")
        self.assertEqual(_TLSProxmox.seen, [("GET", "/api2/json/nodes")])

    def test_read_broker_refuses_changed_certificate_before_http(self):
        wrong = "0" * 64
        with self.assertRaisesRegex(Refused, "Proxmox certificate changed"):
            self.broker(wrong).get("/nodes")
        self.assertEqual(_TLSProxmox.seen, [])
        record = self.audit.records()[-1]
        self.assertEqual(record["action"], "tls_refused")
        self.assertEqual(record["detail"]["why"], CHANGED_CERTIFICATE_MESSAGE)

    def test_read_broker_refuses_when_no_pin_is_configured(self):
        with self.assertRaisesRegex(Refused, "No Proxmox certificate pin"):
            self.broker("")
        self.assertEqual(_TLSProxmox.seen, [])
        self.assertEqual(self.audit.records()[-1]["action"], "tls_refused")

    def test_write_client_uses_same_pin_for_both_tfa_stages(self):
        client = self.write_client(self.fingerprint)
        result = client.authorize("proxvox@pve", "password", "123456")
        self.assertEqual(result["username"], "proxvox@pve")
        self.assertTrue(client.is_authorized())
        self.assertEqual([method for method, _ in _TLSProxmox.seen], ["POST", "POST"])

    def test_write_client_refuses_wrong_or_missing_pin(self):
        with self.assertRaisesRegex(Refused, "Proxmox certificate changed"):
            self.write_client("f" * 64).authorize(
                "proxvox@pve", "password", "123456"
            )
        self.assertEqual(_TLSProxmox.seen, [])
        self.assertEqual(self.audit.records()[-1]["action"], "tls_refused")

        with self.assertRaisesRegex(Refused, "No Proxmox certificate pin"):
            self.write_client("")
        self.assertEqual(_TLSProxmox.seen, [])

    def test_https_custom_opener_cannot_bypass_the_pin(self):
        with self.assertRaisesRegex(Refused, "will not bypass"):
            ReadOnlyBroker(
                self.base_url,
                "proxvox@pve!p1-readonly",
                "secret-not-logged",
                self.audit,
                GUID,
                actual_guid=GUID,
                certificate_sha256=self.fingerprint,
                opener=urllib.request.urlopen,
            )
        with self.assertRaisesRegex(Refused, "will not bypass"):
            WriteClient(
                self.base_url,
                self.audit,
                GUID,
                actual_guid=GUID,
                certificate_sha256=self.fingerprint,
                opener=urllib.request.urlopen,
            )
        self.assertEqual(_TLSProxmox.seen, [])
        self.assertEqual(self.audit.records()[-1]["action"], "tls_refused")

    def test_local_config_round_trip_contains_endpoint_and_pin_only(self):
        path = os.path.join(self.directory, "config.json")
        save_pin_config(path, self.base_url, self.fingerprint.upper())
        self.assertEqual(load_pin_config(path), {
            "base_url": self.base_url,
            "certificate_sha256": self.fingerprint,
        })
        with open(path, encoding="utf-8") as stream:
            written = stream.read()
        self.assertNotIn("secret", written.lower())
        self.assertNotIn("token", written.lower())

    def test_credential_manager_gui_nul_value_is_refused(self):
        with self.assertRaisesRegex(NoToken, "Credential Manager GUI") as caught:
            load_token(reader=lambda *_: "a\x00b\x00c\x00")
        self.assertIn("snap_native_creds", str(caught.exception))


if __name__ == "__main__":
    unittest.main()

# ===== SNAPSMACK EOF =====
