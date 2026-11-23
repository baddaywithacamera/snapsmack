#!/usr/bin/env python3
"""Root-owned forced-command reader for the PROX VOX host account.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import base64
import fnmatch
import hashlib
import json
import os
import posixpath
import shlex
import subprocess
import sys


REMOTE_COMMAND_TIMEOUT_SECONDS = 60

# These are the files whose contents can authorize a write or impersonate the
# host. Reads return metadata plus SHA-256, never content. The same list is
# applied to the Proxmox host and to paths resolved inside every container.
WRITE_KEY_PATHS = (
    "/etc/pve/priv/tfa.cfg",
    "/etc/pve/priv/authkey.key",
    "/etc/pve/priv/pve-root-ca.key",
    "/etc/pve/priv/acme/*/account.key",
    "/etc/pve/priv/ceph*.keyring",
    "/etc/pve/nodes/*/pve-ssl.key",
    "/etc/pve/nodes/*/pveproxy-ssl.key",
    "/etc/corosync/authkey",
    "/etc/ssh/ssh_host_*_key",
    "/root/.ssh/id_*",
    "/home/*/.ssh/id_*",
    "/etc/ssl/private/*",
    "/etc/pki/tls/private/*",
    "/etc/letsencrypt/live/*/privkey.pem",
    "/etc/letsencrypt/archive/*/privkey*.pem",
    "*.p12",
    "*.pfx",
)

PRIVATE_KEY_MARKERS = (
    b"-----BEGIN OPENSSH PRIVATE KEY-----",
    b"-----BEGIN PRIVATE KEY-----",
    b"-----BEGIN ENCRYPTED PRIVATE KEY-----",
    b"-----BEGIN RSA PRIVATE KEY-----",
    b"-----BEGIN EC PRIVATE KEY-----",
    b"-----BEGIN DSA PRIVATE KEY-----",
    b"PuTTY-User-Key-File-",
)

def _absolute(path):
    raw = str(path or "")
    if not raw.startswith("/") or "\x00" in raw:
        raise ValueError("an absolute path is required")
    return os.path.realpath(raw)


def _container_path(path):
    raw = str(path or "")
    if not raw.startswith("/") or "\x00" in raw or "\\" in raw:
        raise ValueError("an absolute POSIX container path is required")
    return posixpath.normpath(raw)


def _vmid(value):
    guest = int(value)
    if guest < 100 or guest > 999999999:
        raise ValueError("an existing numeric CT id is required")
    return str(guest)


def _run(argv):
    try:
        result = subprocess.run(
            argv, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
            stderr=subprocess.PIPE, shell=False, check=False,
            timeout=REMOTE_COMMAND_TIMEOUT_SECONDS,
        )
    except subprocess.TimeoutExpired as err:
        raise ValueError(
            "read operation timed out after %d seconds"
            % REMOTE_COMMAND_TIMEOUT_SECONDS
        ) from err
    if result.returncode != 0:
        error = result.stderr.decode("utf-8", "replace")
        raise ValueError("read operation failed: " + error.strip()[:500])
    return result.stdout


def _is_write_key_path(path):
    normalized = posixpath.normpath(str(path or ""))
    if normalized.endswith(".pub"):
        return False
    return any(fnmatch.fnmatchcase(normalized, pattern) for pattern in WRITE_KEY_PATHS)


def _looks_like_private_key(prefix):
    return any(marker in prefix for marker in PRIVATE_KEY_MARKERS)


def _sha256_stream(stream, prefix=b""):
    digest = hashlib.sha256(prefix)
    while True:
        chunk = stream.read(1024 * 1024)
        if not chunk:
            break
        digest.update(chunk)
    return digest.hexdigest()


def _metadata_payload(path, *, sha256):
    payload = _stat_payload(path)
    payload.update({
        "sha256": sha256,
        "content_policy": "metadata-sha256-only",
    })
    return payload


def _stat_payload(path):
    row = os.stat(path)
    return {
        "path": path, "size": row.st_size, "mode": row.st_mode,
        "mtime": row.st_mtime, "directory": os.path.isdir(path),
    }


def dispatch(command):
    args = shlex.split(str(command or ""), posix=True)
    if args == ["inventory"]:
        raw = _run([
            "/usr/bin/pvesh", "get", "/cluster/resources", "--type", "vm",
            "--output-format", "json",
        ])
        rows = json.loads(raw.decode("utf-8"))
        return {"containers": [row for row in rows if row.get("type") == "lxc"]}

    if len(args) >= 2 and args[0] in {"host-list", "host-stat", "host-read"}:
        path = _absolute(args[1])
        if args[0] == "host-list" and len(args) == 2:
            return {"path": path, "entries": sorted(os.listdir(path))}
        if args[0] == "host-stat" and len(args) == 2:
            return _stat_payload(path)
        if args[0] == "host-read" and len(args) == 2:
            with open(path, "rb") as stream:
                prefix = stream.read(4096)
                if _is_write_key_path(path) or _looks_like_private_key(prefix):
                    return _metadata_payload(path, sha256=_sha256_stream(stream, prefix))
                data = prefix + stream.read()
            return {"path": path, "content_base64": base64.b64encode(data).decode("ascii")}

    if len(args) >= 3 and args[0] in {"ct-list", "ct-stat", "ct-read"}:
        guest = _vmid(args[1])
        path = _container_path(args[2])
        prefix = ["/usr/sbin/pct", "exec", guest, "--"]
        if args[0] == "ct-list" and len(args) == 3:
            raw = _run(prefix + [
                "/usr/bin/find", path, "-mindepth", "1", "-maxdepth", "1",
                "-printf", "%f\0",
            ])
            return {"vmid": int(guest), "path": path,
                    "entries": sorted(item.decode("utf-8", "surrogateescape")
                                      for item in raw.split(b"\0") if item)}
        if args[0] == "ct-stat" and len(args) == 3:
            raw = _run(prefix + ["/usr/bin/stat", "-Lc", "%s\t%f\t%Y\t%F", "--", path])
            size, mode, mtime, kind = raw.decode("utf-8", "replace").strip().split("\t", 3)
            return {"vmid": int(guest), "path": path, "size": int(size),
                    "mode": int(mode, 16), "mtime": int(mtime), "kind": kind}
        if args[0] == "ct-read" and len(args) == 3:
            resolved_raw = _run(prefix + ["/usr/bin/readlink", "-f", "--", path])
            resolved = resolved_raw.decode("utf-8", "surrogateescape").strip()
            if not resolved.startswith("/"):
                raise ValueError("container path could not be resolved")
            sample = _run(prefix + ["/usr/bin/head", "-c", "4096", "--", resolved])
            if _is_write_key_path(resolved) or _looks_like_private_key(sample):
                raw = _run(prefix + ["/usr/bin/stat", "-Lc", "%s\t%f\t%Y\t%F", "--", resolved])
                size, mode, mtime, kind = raw.decode("utf-8", "replace").strip().split("\t", 3)
                digest = _run(prefix + ["/usr/bin/sha256sum", "--", resolved]).decode("ascii").split()[0]
                return {"vmid": int(guest), "path": path, "resolved_path": resolved,
                        "size": int(size), "mode": int(mode, 16), "mtime": int(mtime),
                        "kind": kind, "sha256": digest,
                        "content_policy": "metadata-sha256-only"}
            data = _run(prefix + ["/bin/cat", "--", resolved])
            return {"vmid": int(guest), "path": path, "resolved_path": resolved,
                    "content_base64": base64.b64encode(data).decode("ascii")}

    raise ValueError("operation is not in the read-only protocol")


def main():
    try:
        payload = {"ok": True, "data": dispatch(os.environ.get("SSH_ORIGINAL_COMMAND", ""))}
        code = 0
    except Exception as err:
        payload = {"ok": False, "error": str(err)[:500]}
        code = 2
    sys.stdout.write(json.dumps(payload, separators=(",", ":")) + "\n")
    return code


if __name__ == "__main__":
    raise SystemExit(main())

# ===== SNAPSMACK EOF =====
