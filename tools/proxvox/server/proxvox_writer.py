#!/usr/bin/env python3
"""Root-owned forced-command WRITER for the PROX VOX host account. (SS-1507)

Sean's go, 2026-11-24 22:43, relayed by the PM. He also asked, fairly, why
PROX VOX never had working write access: the write side was built as Proxmox
API access (proxvox/write_client.py, which does POST/PUT/DELETE against
/api2/json after a live TOTP login) and that API has no way to put a FILE
inside a container. So file writing never existed, and nobody had tried it
until the backup script needed changing.

THE ONE DESIGN DECISION THAT MATTERS, AND WHY IT IS HERE AND NOT ON THE
DESKTOP. The allowlist of writable paths lives in THIS FILE, on the host,
root-owned, delivered by the provisioner. It is NOT a setting the desktop
sends. If the allowlist lived in the client, then anyone who reached Sean's
desktop could write to any path on the Proxmox host and in every container,
because the host would simply do as it was told. The client cannot widen this
list by asking. That is the whole point of a forced command, and it is the
difference between a scope and a suggestion.

WHAT THIS CANNOT DO, said plainly because a guard believed to do more than it
does is worse than no guard:
  - It cannot write anywhere outside ALLOWED (below), and cannot create new
    paths outside it.
  - It cannot write a key, certificate or credential file. Those are refused
    by name even if someone adds a matching path to ALLOWED by mistake.
  - It cannot run an arbitrary command. MAINTENANCE is a closed set of fixed
    argument lists with no caller-supplied values at all.
  - It cannot delete anything. There is no delete operation, and no B2 or
    cloud capability of any kind -- Sean ruled that out and it is not here.
  - It does NOT stop someone already running as Sean on Sean's desktop during
    a live TOTP session. Nothing at this layer can. The honest control for
    that is the live authorisation in write_client.py, not a better path list.

EVERY WRITE IS: verify the declared hash of what arrived, refuse if it does
not match, back the old file up, write a temporary file beside it, fsync,
rename atomically, read the result back, and return both hashes. The caller
gets a before-hash and an after-hash and can prove what landed. Content never
appears in any output or audit line -- only paths, sizes and hashes.

Install: /usr/local/sbin/proxvox-writer, root:root 0755, reached by
  Match User proxvox-root-rw
      ForceCommand sudo -n /usr/local/sbin/proxvox-writer
on its own account and its own key, separate from proxvox-root-ro. The
read-only account stays read-only; this is not added to it.

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
import shutil
import stat
import subprocess
import sys
import time

REMOTE_COMMAND_TIMEOUT_SECONDS = 120
MAX_WRITE_BYTES = 8 * 1024 * 1024

# ─────────────────────────────────────────────────────────────────────────────
# THE ALLOWLIST. Sean sees this list before it goes live and adding to it needs
# his yes. Each entry is (target, glob). target is "host" or a container vmid
# as a string. A glob matches a WHOLE absolute path after resolution.
# ─────────────────────────────────────────────────────────────────────────────
ALLOWED = (
    # The Proxmox host: the backup script the PM named, and nothing else.
    ("host", "/usr/local/bin/mariadb_backup.sh"),
    # CT101 is snapsmack.ca -- the project/release site, not a CMS install.
    ("101", "/var/www/snapsmack.ca/*"),
    # CT102 holds the site roots.
    ("102", "/var/www/*/public_html/*"),
    ("102", "/var/www/*/htdocs/*"),
)

# Refused even if a matching pattern is added to ALLOWED by mistake. A write to
# any of these is either a credential replacement or a way to impersonate the
# host, and no maintenance task needs one. Mirrors the read wrapper's
# WRITE_KEY_PATHS, which withholds the same files' contents on the way out.
NEVER_WRITE = (
    "/etc/pve/*",
    "/etc/corosync/*",
    "/etc/ssh/ssh_host_*",
    "*/.ssh/*",
    "*/.htpasswd",
    "/etc/ssl/private/*",
    "/etc/pki/tls/private/*",
    "/etc/letsencrypt/*",
    "*.key",
    "*.pem",
    "*.p12",
    "*.pfx",
    "*/sc-config.php",
    "*/config.php",
    "*/wp-config.php",
    "*.env",
)

# ─────────────────────────────────────────────────────────────────────────────
# MAINTENANCE: named, fixed argument lists. The caller supplies a NAME and
# nothing else -- no paths, no flags, no values. Sean's first use is restoring
# CT101's console mode, which is what broke his jsconsole session.
# ─────────────────────────────────────────────────────────────────────────────
MAINTENANCE = {
    "ct101-console-mode-restore": [
        ["/usr/sbin/pct", "set", "101", "--cmode", "tty"],
    ],
}


class Refused(Exception):
    """A refusal, with the reason the caller is allowed to see."""


def _absolute(path):
    raw = str(path or "")
    if not raw.startswith("/"):
        raise Refused("a path must be absolute")
    if "\0" in raw:
        raise Refused("a path may not contain a null byte")
    norm = posixpath.normpath(raw)
    if norm != raw.rstrip("/") and norm != raw:
        # normpath collapsed something: .. or a doubled slash. Say so rather
        # than silently writing to the collapsed path.
        raise Refused("a path must already be normalised; %r is not" % raw)
    for seg in norm.split("/"):
        if seg == "..":
            raise Refused("a path may not contain ..")
    return norm


def _vmid(value):
    raw = str(value or "").strip()
    if not raw.isdigit():
        raise Refused("a container id must be digits")
    return raw


def _never_write(path):
    for pattern in NEVER_WRITE:
        if fnmatch.fnmatch(path, pattern):
            return pattern
    return ""


def _check_allowed(target, path):
    """Raise unless (target, path) is in ALLOWED and not in NEVER_WRITE."""
    blocked = _never_write(path)
    if blocked:
        raise Refused(
            "refusing to write %s: it matches %s, which is a credential or "
            "host-identity file. No maintenance task needs one."
            % (path, blocked))
    for allowed_target, pattern in ALLOWED:
        if allowed_target == target and fnmatch.fnmatch(path, pattern):
            return pattern
    raise Refused(
        "refusing to write %s on %s: it is not in the allowlist. The list "
        "lives on the host and the caller cannot widen it; adding a path is "
        "Sean's decision." % (path, "the host" if target == "host" else "CT" + target))


def _run(argv, stdin_bytes=None):
    proc = subprocess.run(
        argv, stdin=subprocess.PIPE if stdin_bytes is not None else None,
        stdout=subprocess.PIPE, stderr=subprocess.PIPE,
        timeout=REMOTE_COMMAND_TIMEOUT_SECONDS, input=stdin_bytes, check=False)
    if proc.returncode != 0:
        detail = (proc.stderr or b"").decode("utf-8", "replace").strip()
        raise Refused("command failed: %s" % (detail[:300] or argv[0]))
    return proc.stdout


def _ct(vmid):
    return ["/usr/sbin/pct", "exec", vmid, "--"]


def _sha256_of(target, path):
    """The file's hash, or '' when it does not exist yet."""
    if target == "host":
        if not os.path.exists(path):
            return ""
        digest = hashlib.sha256()
        with open(path, "rb") as handle:
            for chunk in iter(lambda: handle.read(1024 * 1024), b""):
                digest.update(chunk)
        return digest.hexdigest()
    probe = subprocess.run(_ct(target) + ["/usr/bin/test", "-e", path],
                           stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                           timeout=REMOTE_COMMAND_TIMEOUT_SECONDS, check=False)
    if probe.returncode != 0:
        return ""
    raw = _run(_ct(target) + ["/usr/bin/sha256sum", "--", path])
    return raw.decode("ascii", "replace").split()[0]


def _read_stdin_content(declared_sha256):
    """The bytes to write, verified against the hash the caller declared.

    VERIFIED BEFORE ANYTHING IS TOUCHED. A transfer that was cut short would
    otherwise overwrite a working file with a truncated one, and the backup
    would be the only thing standing between that and data loss. Checking
    first means a bad transfer changes nothing at all.
    """
    declared = str(declared_sha256 or "").strip().lower()
    if len(declared) != 64 or any(c not in "0123456789abcdef" for c in declared):
        raise Refused("a write must declare the content's sha256")
    blob = sys.stdin.buffer.read(MAX_WRITE_BYTES + 1)
    if len(blob) > MAX_WRITE_BYTES:
        raise Refused("content is larger than %d bytes" % MAX_WRITE_BYTES)
    try:
        content = base64.b64decode(blob, validate=True)
    except Exception:
        raise Refused("content must be base64 on stdin")
    actual = hashlib.sha256(content).hexdigest()
    if actual != declared:
        raise Refused(
            "content does not match the declared sha256; nothing was written. "
            "Declared %s, received %s." % (declared[:12], actual[:12]))
    return content


def _put_host(path, content, dry_run):
    before = _sha256_of("host", path)
    backup = ""
    if dry_run:
        return {"dry_run": True, "target": "host", "path": path,
                "sha256_before": before,
                "sha256_after_declared": hashlib.sha256(content).hexdigest(),
                "bytes": len(content), "would_back_up": bool(before)}
    # DONE IN PYTHON, NOT BY SHELLING OUT TO /bin/cp AND /bin/chmod.
    # The first version ran three subprocesses for the backup and the mode
    # copy. shutil and os do the same work with no PATH lookup, no argument
    # quoting and nothing to go wrong between processes -- which matters more
    # here than anywhere, because this runs as root. It also means the write
    # mechanics can be exercised on any platform instead of only on the host.
    # The CONTAINER path below still uses pct exec: there is no other way in.
    if before:
        backup = "%s.proxvox-bak-%d" % (path, int(time.time()))
        shutil.copy2(path, backup)
    tmp = "%s.proxvox-tmp-%d" % (path, os.getpid())
    # O_BINARY OR THE BYTES CHANGE UNDER YOU. Found by the readback check in
    # this file's own test, which is exactly what that check is for: without
    # it, os.open on Windows translates every \n to \r\n, so a shell script
    # written through this wrapper came back as CRLF and would not run. It is
    # a no-op on the Debian host (there is no O_BINARY there, hence the
    # getattr), and it is here because the desktop half of this tool is
    # developed and tested on Windows -- and because CRLF drift has already
    # cost this project a release night once.
    flags = os.O_WRONLY | os.O_CREAT | os.O_TRUNC | getattr(os, "O_BINARY", 0)
    handle = os.open(tmp, flags, 0o600)
    try:
        os.write(handle, content)
        os.fsync(handle)
    finally:
        os.close(handle)
    if before:
        # Keep the existing mode and ownership rather than inventing one.
        existing = os.stat(path)
        os.chmod(tmp, stat.S_IMODE(existing.st_mode))
        if hasattr(os, "chown"):
            os.chown(tmp, existing.st_uid, existing.st_gid)
    os.replace(tmp, path)
    after = _sha256_of("host", path)
    return {"dry_run": False, "target": "host", "path": path,
            "sha256_before": before, "sha256_after": after,
            "bytes": len(content), "backup_path": backup,
            "verified": after == hashlib.sha256(content).hexdigest()}


def _put_container(vmid, path, content, dry_run):
    before = _sha256_of(vmid, path)
    declared = hashlib.sha256(content).hexdigest()
    if dry_run:
        return {"dry_run": True, "target": "ct" + vmid, "path": path,
                "sha256_before": before, "sha256_after_declared": declared,
                "bytes": len(content), "would_back_up": bool(before)}
    backup = ""
    if before:
        backup = "%s.proxvox-bak-%d" % (path, int(time.time()))
        _run(_ct(vmid) + ["/bin/cp", "-p", "--", path, backup])
    tmp = "%s.proxvox-tmp-%d" % (path, os.getpid())
    # dd from stdin rather than echo: no shell, no argument-length limit, and
    # the bytes never appear in a process listing.
    _run(_ct(vmid) + ["/usr/bin/dd", "of=" + tmp, "status=none"],
         stdin_bytes=content)
    if before:
        _run(_ct(vmid) + ["/bin/chmod", "--reference", path, tmp])
        _run(_ct(vmid) + ["/bin/chown", "--reference", path, tmp])
    _run(_ct(vmid) + ["/bin/mv", "-f", "--", tmp, path])
    after = _sha256_of(vmid, path)
    return {"dry_run": False, "target": "ct" + vmid, "path": path,
            "sha256_before": before, "sha256_after": after,
            "bytes": len(content), "backup_path": backup,
            "verified": after == declared}


def dispatch(command):
    args = shlex.split(str(command or ""), posix=True)
    if not args:
        raise Refused("no operation given")

    op = args[0]

    # ── what may I write? The allowlist, readable so Sean can check it. ──
    if op == "scope" and len(args) == 1:
        return {
            "allowed": [{"target": t, "pattern": p} for t, p in ALLOWED],
            "never_write": list(NEVER_WRITE),
            "maintenance": sorted(MAINTENANCE),
            "max_write_bytes": MAX_WRITE_BYTES,
            "note": "This list lives on the host. The caller cannot widen it.",
        }

    # ── put-file[-dry] host|<vmid> <path> <sha256>, content base64 on stdin ──
    # Two op NAMES rather than a --dry-run flag, so a mistyped flag can never
    # turn a rehearsal into a write.
    if op in {"put-file", "put-file-dry"} and len(args) == 4:
        dry_run = op == "put-file-dry"
        where = args[1]
        path = _absolute(args[2])
        target = "host" if where == "host" else _vmid(where)
        _check_allowed(target, path)
        content = _read_stdin_content(args[3])
        if target == "host":
            return _put_host(path, content, dry_run)
        return _put_container(target, path, content, dry_run)

    # ── maint[-dry] <name> ── a closed set, no caller arguments ──
    if op in {"maint", "maint-dry"} and len(args) == 2:
        name = args[1]
        if name not in MAINTENANCE:
            raise Refused(
                "%r is not a maintenance task this wrapper knows. It runs a "
                "closed set of fixed commands and takes no arguments: %s"
                % (name, ", ".join(sorted(MAINTENANCE))))
        steps = MAINTENANCE[name]
        if op == "maint-dry":
            return {"dry_run": True, "task": name,
                    "would_run": [list(step) for step in steps]}
        ran = []
        for step in steps:
            _run(list(step))
            ran.append(list(step))
        return {"dry_run": False, "task": name, "ran": ran}

    raise Refused("operation is not in the write protocol")


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
