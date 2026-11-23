#!/usr/bin/env python3
"""Root-owned forced-command reader for the PROX VOX host account.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import base64
import json
import os
import posixpath
import shlex
import subprocess
import sys

MAXIMUM_BYTES = 16 * 1024 * 1024


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


def _limit(value):
    amount = int(value)
    if amount < 1 or amount > MAXIMUM_BYTES:
        raise ValueError("read limit must be between 1 and 16777216 bytes")
    return amount


def _run(argv, maximum=MAXIMUM_BYTES):
    process = subprocess.Popen(
        argv, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
        stderr=subprocess.PIPE, shell=False,
    )
    output = process.stdout.read(maximum + 1)
    if len(output) > maximum:
        process.kill()
        process.wait(timeout=5)
        raise ValueError("result exceeds the explicit read limit")
    error = process.stderr.read(4096).decode("utf-8", "replace")
    code = process.wait(timeout=30)
    if code != 0:
        raise ValueError("read operation failed: " + error.strip()[:500])
    return output


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
        if args[0] == "host-read" and len(args) == 3:
            limit = _limit(args[2])
            with open(path, "rb") as stream:
                data = stream.read(limit + 1)
            if len(data) > limit:
                raise ValueError("file exceeds the explicit read limit")
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
        if args[0] == "ct-read" and len(args) == 4:
            limit = _limit(args[3])
            data = _run(prefix + ["/bin/cat", "--", path], maximum=limit)
            return {"vmid": int(guest), "path": path,
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
