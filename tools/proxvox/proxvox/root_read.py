"""Audited client for the host's root-owned forced read-only wrapper.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import base64
import json
import shlex
import socket

from .broker import Refused
from .credentials import load_container_secret


REMOTE_COMMAND_TIMEOUT_SECONDS = 60


class RootReadBroker:
    def __init__(self, host, username, audit, credential_reader=None, ssh_factory=None):
        self.host = str(host)
        self.username = str(username)
        self.audit = audit
        self._credential_reader = credential_reader
        self._ssh_factory = ssh_factory

    def _connect(self, password):
        if self._ssh_factory is not None:
            return self._ssh_factory(self.host, self.username, password)
        try:                                                    # pragma: no cover
            import paramiko
        except ImportError as err:
            raise RuntimeError("PROX VOX root reads require paramiko.") from err
        client = paramiko.SSHClient()
        client.load_system_host_keys()
        client.set_missing_host_key_policy(paramiko.RejectPolicy())
        client.connect(
            self.host, username=self.username, password=password,
            allow_agent=False, look_for_keys=False, timeout=15,
        )
        return client

    def _call(self, operation, *arguments):
        command = shlex.join([operation] + [str(item) for item in arguments])
        target_path = str(arguments[-1]) if arguments else ""
        self.audit.record("root_read_attempt", {
            "operation": operation, "target": "ct" if operation.startswith("ct-") else "host",
            "path": target_path,
        })
        try:
            password = load_container_secret("pve_host_ssh", self._credential_reader)
            client = self._connect(password)
        except Exception as err:
            self.audit.record("root_read_error", {
                "operation": operation, "error": type(err).__name__,
            })
            raise
        try:
            _stdin, stdout, stderr = client.exec_command(
                command, timeout=REMOTE_COMMAND_TIMEOUT_SECONDS,
            )
            raw = stdout.read().decode("utf-8", "replace")
            status = stdout.channel.recv_exit_status()
            error = stderr.read().decode("utf-8", "replace")
        except (socket.timeout, TimeoutError) as err:
            self.audit.record("root_read_timeout", {
                "operation": operation, "path": target_path,
                "timeout_seconds": REMOTE_COMMAND_TIMEOUT_SECONDS,
            })
            raise Refused(
                "Root read timed out after %d seconds; the remote command was closed."
                % REMOTE_COMMAND_TIMEOUT_SECONDS
            ) from err
        except Exception as err:
            self.audit.record("root_read_error", {
                "operation": operation, "error": type(err).__name__,
            })
            raise
        finally:
            client.close()
        try:
            payload = json.loads(raw)
        except ValueError as err:
            self.audit.record("root_read_refused", {
                "operation": operation, "why": "invalid wrapper response",
            })
            raise Refused("The root read wrapper returned invalid JSON.") from err
        if status != 0 or not isinstance(payload, dict) or payload.get("ok") is not True:
            why = str(payload.get("error") if isinstance(payload, dict) else error or "read refused")[:500]
            self.audit.record("root_read_refused", {"operation": operation, "why": why})
            raise Refused("Root read refused: " + why)
        self.audit.record("root_read_result", {
            "operation": operation, "path": target_path, "ok": True,
            "content_policy": (payload.get("data") or {}).get("content_policy", "content"),
        })
        return payload.get("data")

    def inventory(self):
        return self._call("inventory")["containers"]

    def listdir(self, path, vmid=None):
        data = self._call("host-list", path) if vmid is None else self._call("ct-list", int(vmid), path)
        return data["entries"]

    def stat(self, path, vmid=None):
        return self._call("host-stat", path) if vmid is None else self._call("ct-stat", int(vmid), path)

    def read_file(self, path, vmid=None):
        """Return bytes for ordinary files, or metadata+SHA-256 for write keys."""
        data = (self._call("host-read", path) if vmid is None
                else self._call("ct-read", int(vmid), path))
        if data.get("content_policy") == "metadata-sha256-only":
            return data
        try:
            return base64.b64decode(data["content_base64"], validate=True)
        except (KeyError, TypeError, ValueError) as err:
            self.audit.record("root_read_refused", {
                "operation": "read", "why": "invalid base64 response",
            })
            raise Refused("The root read wrapper returned invalid file data.") from err

# ===== SNAPSMACK EOF =====
