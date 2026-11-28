"""Audited client for the host's root-owned forced read-only wrapper.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import base64
import io
from .secrets import blocked_read_reason
import json
import shlex
import socket

from .broker import Refused
from .credentials import load_container_secret


REMOTE_COMMAND_TIMEOUT_SECONDS = 60
READ_CHUNK_BYTES = 1024 * 1024


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
        if operation.startswith("ct-"):
            target_path = str(arguments[1]) if len(arguments) > 1 else ""
        elif operation == "inventory":
            target_path = "/"
        else:
            target_path = str(arguments[0]) if arguments else ""
        self.audit.record("root_read_attempt", {
            "operation": operation, "target": "ct" if operation.startswith("ct-") else "host",
            "path": target_path,
        })
        try:
            password = load_container_secret("pve_host_ssh", self._credential_reader)
            client = self._connect(password)
        except Exception as err:
            self.audit.record("root_read_error", {
                "operation": operation, "path": target_path,
                "error": type(err).__name__,
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
                "operation": operation, "path": target_path,
                "error": type(err).__name__,
            })
            raise
        finally:
            client.close()
        try:
            payload = json.loads(raw)
        except ValueError as err:
            self.audit.record("root_read_refused", {
                "operation": operation, "path": target_path,
                "why": "invalid wrapper response",
            })
            raise Refused("The root read wrapper returned invalid JSON.") from err
        if status != 0 or not isinstance(payload, dict) or payload.get("ok") is not True:
            why = str(payload.get("error") if isinstance(payload, dict) else error or "read refused")[:500]
            self.audit.record("root_read_refused", {
                "operation": operation, "path": target_path, "why": why,
            })
            raise Refused("Root read refused: " + why)
        self.audit.record("root_read_result", {
            "operation": operation, "path": target_path, "ok": True,
            "content_policy": (payload.get("data") or {}).get("content_policy", "content"),
        })
        return payload.get("data")

    def _required_field(self, data, field, operation, path):
        """Refuse and audit a successful-looking wrapper reply with bad shape."""
        if not isinstance(data, dict) or field not in data:
            self.audit.record("root_read_refused", {
                "operation": operation, "path": str(path),
                "why": "invalid wrapper data",
            })
            raise Refused(
                "The root read wrapper response did not contain %s." % field
            )
        return data[field]

    def inventory(self):
        data = self._call("inventory")
        return self._required_field(data, "containers", "inventory", "/")

    def listdir(self, path, vmid=None):
        data = self._call("host-list", path) if vmid is None else self._call("ct-list", int(vmid), path)
        operation = "host-list" if vmid is None else "ct-list"
        return self._required_field(data, "entries", operation, path)

    def stat(self, path, vmid=None):
        return self._call("host-stat", path) if vmid is None else self._call("ct-stat", int(vmid), path)

    def read_file(self, path, vmid=None):
        """Return bytes for ordinary files, or metadata+SHA-256 for write keys."""
        target = io.BytesIO()
        result = self.read_to(path, target, vmid=vmid)
        return result if isinstance(result, dict) else target.getvalue()

    def read_to(self, path, target, vmid=None):
        """Stream an ordinary file into a binary writer with bounded memory."""
        # SEAN, 2026-11-28 15:02. The read blocklist, checked before the call
        # leaves this machine. read_file() goes through here, so guarding this
        # one covers both. Logged, not silently dropped.
        blocked = blocked_read_reason(path)
        if blocked:
            # RECORDED UNGUARDED, like every other method in this file. My
            # first version wrapped this in getattr(self, "audit", None),
            # which created exactly one silent path: no recorder, no log line,
            # just a refusal nobody could account for. Muse flagged it (9414).
            # audit is a REQUIRED constructor argument and the other seven
            # record() calls do not check it either, so checking it bought a
            # hole and nothing else.
            self.audit.record("host_read_blocked", {
                "path": str(path), "vmid": vmid, "why": blocked,
            })
            raise Refused(blocked)
        data = (self._call("host-read", path) if vmid is None
                else self._call("ct-read", int(vmid), path))
        if not isinstance(data, dict):
            operation = "host-read" if vmid is None else "ct-read"
            self._required_field(data, "content_policy", operation, path)
        if data.get("content_policy") == "metadata-sha256-only":
            return data
        if data.get("content_policy") == "chunked":
            offset = 0
            while True:
                chunk = (self._call("host-read-chunk", path, offset, READ_CHUNK_BYTES)
                         if vmid is None else self._call(
                             "ct-read-chunk", int(vmid), path, offset, READ_CHUNK_BYTES))
                try:
                    raw = base64.b64decode(chunk["content_base64"], validate=True)
                except (KeyError, TypeError, ValueError) as err:
                    self.audit.record("root_read_refused", {
                        "operation": "read", "path": str(path),
                        "why": "invalid chunk response",
                    })
                    raise Refused("The root read wrapper returned invalid chunk data.") from err
                try:
                    target.write(raw)
                except Exception as err:
                    self.audit.record("root_read_error", {
                        "operation": "read", "path": str(path),
                        "error": type(err).__name__,
                    })
                    raise
                offset += len(raw)
                if chunk.get("eof") or not raw:
                    break
            return offset
        try:
            target.write(base64.b64decode(data["content_base64"], validate=True))
            return target.tell() if hasattr(target, "tell") else None
        except (KeyError, TypeError, ValueError) as err:
            self.audit.record("root_read_refused", {
                "operation": "read", "path": str(path),
                "why": "invalid base64 response",
            })
            raise Refused("The root read wrapper returned invalid file data.") from err
        except Exception as err:
            self.audit.record("root_read_error", {
                "operation": "read", "path": str(path),
                "error": type(err).__name__,
            })
            raise

# ===== SNAPSMACK EOF =====
