#!/usr/bin/env python3
"""Root-owned forced-command WRITER for the PROX VOX host account. (SS-1507)

The host completes the owner's Proxmox TOTP challenge on verified local TLS.
The resulting writer session lasts up to one hour, is checked again immediately
before file replacement, and can be revoked on the host. A Proxmox ticket
obtained any other way cannot open a writer session. Each file write requires a
host-issued, one-use nonce for the exact target, bytes and expected live hash.

During that session the writer can replace regular files on the Proxmox host and
its containers without a path allowlist. The unauthenticated rehearsal remains
limited to the configured preview paths. This protocol does not run caller
commands, delete files, or control Backblaze.

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
from contextlib import contextmanager
import fnmatch
import hashlib
import json
import os
import posixpath
import re
import secrets
import shlex
import shutil
import stat
import subprocess
import sys
import time

REMOTE_COMMAND_TIMEOUT_SECONDS = 120
MAX_WRITE_BYTES = 8 * 1024 * 1024

# BACKUPS DO NOT LIVE BESIDE THE FILE. Codex caught this: a .proxvox-bak of a
# PHP file inside a web root can be fetched as source by anyone who guesses
# the name, which turns a safety copy into a disclosure. They go here instead,
# outside every document root, created 0700.
BACKUP_DIR = "/var/backups/proxvox"

# Paths that can be rehearsed without a host-attested writer session.
ALLOWED = (
    ("101", "/var/www/snapsmack.ca/index.php"),
)

# Refused paths for unauthenticated rehearsal.
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
# EMPTY, BECAUSE MY ONE ENTRY WAS A GUESS AND I SHOULD NOT HAVE WRITTEN IT.
#
# It was ["pct", "set", "101", "--cmode", "tty"]. Nothing in this repository
# says tty is the wanted value -- I chose it while writing the dict. Codex then
# MEASURED the live container on 2026-11-25: CT101 is currently cmode=shell.
#
# Those two values behave differently for the thing Sean actually complained
# about, which is a console that starts its own login he cannot get past. So a
# task that flips it could just as easily be the fix or the cause, and it runs
# as root on a live container. I am not shipping a privileged guess.
#
# It goes back in when somebody states the target value and why. Until then
# this wrapper runs no maintenance at all, which is the same honest position
# as the allowlist: a capability appears when it is approved, not when it is
# plausible.
MAINTENANCE = {}


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


def _segment_match(path, pattern):
    """Match a path against a glob WITHOUT letting * cross a slash.

    fnmatch's * matches "/" happily, which Codex caught in review: the
    allowlist entry /var/www/*/public_html/* also matched
    /var/www/a/b/c/public_html/deep/evil.php, so a pattern that reads like
    "one site directory" granted arbitrary depth. Measured before the fix.

    So each segment is matched against the corresponding pattern segment and a
    * stops at the separator, which is what the patterns were always meant to
    say. "**" is accepted as the explicit way to say "any depth below here",
    so a pattern that wants it has to ask.
    """
    path_parts = [seg for seg in str(path).split("/") if seg != ""]
    pat_parts = [seg for seg in str(pattern).split("/") if seg != ""]
    for index, pat_seg in enumerate(pat_parts):
        if pat_seg == "**":
            return index == len(pat_parts) - 1 and len(path_parts) >= index
        if index >= len(path_parts):
            return False
        if not fnmatch.fnmatch(path_parts[index], pat_seg):
            return False
    return len(path_parts) == len(pat_parts)


def _is_sha256(value):
    """A 64-character lowercase hex digest, and nothing else."""
    text = str(value or "").strip().lower()
    return len(text) == 64 and all(c in "0123456789abcdef" for c in text)


def _never_write(path):
    for pattern in NEVER_WRITE:
        # NEVER_WRITE is deliberately matched the OLD, wider way: these are
        # refusals, and a refusal that reaches further than its pattern reads
        # is the safe direction. /etc/pve/* must cover /etc/pve/priv/authkey.
        if fnmatch.fnmatch(path, pattern) or _segment_match(path, pattern):
            return pattern
    return ""


def _check_allowed(target, path):
    """Limit requests that do not present host-attested authorization."""
    blocked = _never_write(path)
    if blocked:
        raise Refused(
            "refusing to write %s: it matches %s, which is a credential or "
            "host-identity file. No maintenance task needs one."
            % (path, blocked))
    for allowed_target, pattern in ALLOWED:
        if allowed_target == target and _segment_match(path, pattern):
            return pattern
    raise Refused(
        "refusing to write %s on %s: it is not in the allowlist. The list "
        "lives on the host and the caller cannot widen it; adding a path is "
        "Sean's decision." % (path, "the host" if target == "host" else "CT" + target))


def _run(argv, stdin_bytes=None):
    # NO stdin= ALONGSIDE input=. subprocess raises "stdin and input arguments
    # may not both be used" BEFORE the command runs, so the container write
    # path could never have worked. Codex found it in review; no test of mine
    # caught it because I only ever exercised the HOST path, where this
    # argument is unused. That is the whole lesson: an untested branch is not
    # a working branch, however carefully it reads.
    kwargs = {"stdout": subprocess.PIPE, "stderr": subprocess.PIPE,
              "timeout": REMOTE_COMMAND_TIMEOUT_SECONDS, "check": False}
    if stdin_bytes is not None:
        kwargs["input"] = stdin_bytes
    proc = subprocess.run(argv, **kwargs)
    # NO allow_failure OPTION. I added one for the container's
    # compare-and-rename and then took the shell-free route instead, which
    # left the option with no caller -- and an unused branch in a security
    # wrapper is the shape of fault that has bitten me three times today.
    # Removed rather than kept "in case".
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
        if not stat.S_ISREG(os.stat(path).st_mode):
            raise Refused("the file writer requires a regular destination file")
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
    _run(_ct(target) + ["/usr/bin/test", "-f", path])
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


def _refuse_symlinks(path):
    """Refuse a symlink at the target or anywhere above it.

    Codex's P0: the allowlist was checked against the STRING, so a symlink at
    an allowlisted path pointing at /etc/shadow would have passed it and been
    written through. The honest fix at this size is to refuse links outright
    rather than to resolve and re-check: a maintenance target that is a
    symlink is a surprise worth stopping on, and refusing is simpler to
    verify than a resolution routine I would have to get exactly right.

    Anchored descriptor traversal (openat with O_NOFOLLOW per segment) is the
    thorough answer and is NOT what this does. Said plainly because a guard
    believed to do more than it does is worse than no guard: this closes the
    symlinked-target and symlinked-parent cases, and it does not make the
    traversal atomic against a path swapped mid-call.
    """
    if os.path.islink(path):
        raise Refused(
            "refusing to write %s: it is a symbolic link. A maintenance "
            "target that is a link is a surprise, and following it would "
            "write somewhere the allowlist never approved." % path)
    parent = posixpath.dirname(path) or "/"
    walked = ""
    for seg in parent.strip("/").split("/"):
        if not seg:
            continue
        walked = walked + "/" + seg
        if os.path.islink(walked):
            raise Refused(
                "refusing to write %s: %s above it is a symbolic link, so the "
                "real destination is not the path the allowlist approved."
                % (path, walked))


def _private_backup(path):
    """A backup path outside every document root, created 0700."""
    os.makedirs(BACKUP_DIR, mode=0o700, exist_ok=True)
    flat = path.strip("/").replace("/", "_")
    return posixpath.join(BACKUP_DIR, "%s.%d" % (flat, int(time.time())))


def _expectation_holds(target, path, expected):
    """Is the file right now what the caller said it would be?

    `expected` is "-" for "there should be no file here", or a sha256.
    Returns (ok, actual). Used immediately before the replacement rather than
    only at the start of the request -- see _refuse_if_changed below.
    """
    actual = _sha256_of(target, path)
    wanted = "" if str(expected) == "-" else str(expected).lower()
    return actual == wanted, actual


def _refuse_if_changed(target, path, expected):
    """THE COMPARE HALF OF COMPARE-AND-SET, RUN NEXT TO THE REPLACE.

    Codex's finding on cd1b963f: the expectation was checked in dispatch() and
    the replacement happened later in _put_host / _put_container, with the
    content read from stdin in between and, for a container, several separate
    pct exec round trips after that. Everything between the two was a window
    in which the file could change -- so the promise "a file somebody else
    changed since you read it is refused" held for a careless caller and not
    for a concurrent one.

    It cannot be made a single atomic operation without holding a lock inside
    the container, which is a bigger change than this release wants. What it
    CAN be, and now is, is checked immediately before the rename instead of a
    few hundred milliseconds and several processes earlier. dispatch() still
    checks early as well: that one is a courtesy, so an obviously stale write
    is refused before the content is read. This one is the guarantee.
    """
    if expected is None:
        return
    ok, actual = _expectation_holds(target, path, expected)
    if not ok:
        raise Refused(
            "the file is not what the caller last read: expected %s, found %s. "
            "Somebody or something changed it in between, so nothing was "
            "written." % ((str(expected) == "-" and "(absent)")
                          or str(expected).lower()[:12],
                          actual[:12] or "(absent)"))


def _put_host(path, content, dry_run, expected=None, require_live_session=None):
    _refuse_symlinks(path)
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
        backup = _private_backup(path)
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
    # O_EXCL AND O_NOFOLLOW. The temp name is predictable, so without O_EXCL
    # a pre-planted file -- or a symlink pointing anywhere -- would be opened
    # and written through. Codex's P0. O_EXCL means a collision is an error
    # rather than a silent takeover.
    flags = (os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_TRUNC
             | getattr(os, "O_BINARY", 0) | getattr(os, "O_NOFOLLOW", 0))
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
    # IMMEDIATELY BEFORE THE REPLACE, not at the start of the request.
    try:
        _refuse_if_changed("host", path, expected)
        if require_live_session is not None:
            require_live_session()
    except Refused:
        # The temp file is ours and half-finished; leave nothing behind.
        try:
            os.unlink(tmp)
        except OSError:
            pass
        raise
    os.replace(tmp, path)
    after = _sha256_of("host", path)
    return {"dry_run": False, "target": "host", "path": path,
            "sha256_before": before, "sha256_after": after,
            "bytes": len(content), "backup_path": backup,
            "verified": after == hashlib.sha256(content).hexdigest()}


def _refuse_container_symlinks(vmid, path):
    """The host symlink refusal, inside a container.

    Same reasoning as _refuse_symlinks: a link at the target, or at any parent,
    means the real destination is not the path the allowlist approved. `test
    -L` per component rather than readlink -f, because refusing is simpler to
    verify than resolving and re-checking, and a maintenance target that is a
    link is a surprise worth stopping on.

    Not atomic against a path swapped between this check and the write, which
    is the same honest limit the host path carries.
    """
    walked = ""
    for seg in path.strip("/").split("/"):
        if not seg:
            continue
        walked = walked + "/" + seg
        probe = subprocess.run(
            _ct(vmid) + ["/usr/bin/test", "-L", walked],
            stdout=subprocess.PIPE, stderr=subprocess.PIPE,
            timeout=REMOTE_COMMAND_TIMEOUT_SECONDS, check=False)
        if probe.returncode == 0:
            raise Refused(
                "refusing to write %s in CT%s: %s is a symbolic link, so the "
                "real destination is not the path the allowlist approved."
                % (path, vmid, walked))


def _put_container(vmid, path, content, dry_run, expected=None, require_live_session=None):
    before = _sha256_of(vmid, path)
    declared = hashlib.sha256(content).hexdigest()
    if dry_run:
        return {"dry_run": True, "target": "ct" + vmid, "path": path,
                "sha256_before": before, "sha256_after_declared": declared,
                "bytes": len(content), "would_back_up": bool(before)}
    # THE SAME GUARDS AS THE HOST PATH. The PM's item 5(b): the container half
    # had none of them, because it was written second and never run at all --
    # which is also how the stdin bug survived. Each one is the container
    # equivalent of the host check above.
    _refuse_container_symlinks(vmid, path)
    backup = ""
    if before:
        # Outside every document root, like the host path, and for the same
        # reason: a .proxvox-bak beside a PHP file is fetchable as source.
        _run(_ct(vmid) + ["/bin/mkdir", "-p", "-m", "700", "--", BACKUP_DIR])
        backup = posixpath.join(
            BACKUP_DIR, "%s.%d" % (path.strip("/").replace("/", "_"), int(time.time())))
        _run(_ct(vmid) + ["/bin/cp", "-p", "--", path, backup])
    tmp = "%s.proxvox-tmp-%d" % (path, os.getpid())
    # dd from stdin rather than echo: no shell, no argument-length limit, and
    # the bytes never appear in a process listing. conv=excl is the container's
    # O_EXCL: dd refuses rather than writing through a pre-planted name.
    _run(_ct(vmid) + ["/usr/bin/dd", "of=" + tmp, "conv=excl", "status=none"],
         stdin_bytes=content)
    if before:
        _run(_ct(vmid) + ["/bin/chmod", "--reference", path, tmp])
        _run(_ct(vmid) + ["/bin/chown", "--reference", path, tmp])
    # THE COMPARISON SITS IMMEDIATELY BEFORE THE RENAME, as its own pct exec.
    #
    # Codex asked for the comparison and the replacement under ONE operation.
    # I wrote that first -- a single /bin/sh -c doing sha256sum, the test and
    # the mv as consecutive statements, with the path and hash as positional
    # arguments so nothing was interpolated into the script. Then this file's
    # own test refused it: test_the_container_path_never_uses_a_shell asserts
    # every container call is an argument LIST through pct exec with no shell
    # anywhere, because a shell here would make a path a command-injection
    # surface.
    #
    # THAT TEST IS RIGHT AND I AM NOT EDITING IT. A stated security invariant
    # does not lose to a review comment, and "made green by editing the test"
    # is the specific failure OPAUDIT 026 records. Positional arguments would
    # have made my version injection-safe, but "no shell at all" is a simpler
    # guarantee than "a shell used carefully", and somebody chose the simpler
    # one deliberately.
    #
    # So: shell-free, and the window shrinks from several round trips plus a
    # stdin read down to one pct exec. It is NOT atomic, and I am not going to
    # describe it as though it were. Closing it completely needs either the
    # shell (forbidden here) or a lock inside the container (a bigger change
    # than this release wants). The trade-off is in the room for a ruling
    # rather than settled by me, because it is two reviewers' requirements in
    # conflict, not a loose end.
    try:
        _refuse_if_changed(vmid, path, expected)
        if require_live_session is not None:
            require_live_session()
    except Refused:
        try:
            _run(_ct(vmid) + ["/bin/rm", "-f", "--", tmp])
        except (OSError, Refused):
            pass
        raise
    _run(_ct(vmid) + ["/bin/mv", "-f", "--", tmp, path])
    after = _sha256_of(vmid, path)
    return {"dry_run": False, "target": "ct" + vmid, "path": path,
            "sha256_before": before, "sha256_after": after,
            "bytes": len(content), "backup_path": backup,
            "verified": after == declared}


# ─────────────────────────────────────────────────────────────────────────────
# THE GRANT: A WRITE IS BOUND TO A FRESH OWNER AUTHORISATION, ON THE HOST.
#
# Codex's P0, and he was right: the first version authorised by SSH ACCOUNT
# ALONE. The TOTP lived in the desktop client, so the host could not tell an
# authorised write from a stolen key -- anyone who reached the account wrote
# whatever the allowlist permitted.
#
# NO NEW AUTH, per Sean's instruction. The client already logs in to Proxmox
# with password plus TOTP in a TOTP-required realm and holds a short-lived
# Proxmox TICKET. It passes that ticket here, and the HOST verifies it against
# its own local Proxmox API. So the thing the host trusts is not the SSH
# account and not anything the client asserts: it is a live Proxmox session
# that could only have been created by someone holding Sean's password and his
# authenticator.
#
# The grant binds four things, and all four are checked against the operation
# actually requested rather than taken as a description of it:
#   user     the ticket's owner, which must be an approved owner account
#   target   host or a container id
#   path     the exact path, and the sha256 of the content to be written
#   expiry   a timestamp, capped so a long-lived grant cannot be minted
#
# WHAT THIS STILL DOES NOT DO. It does not protect against someone operating
# Sean's desktop DURING a live session; nothing at this layer can, and saying
# otherwise would be the kind of guard that is worse than none. It also trusts
# the local Proxmox API, which is the same thing the rest of the box trusts.
# ─────────────────────────────────────────────────────────────────────────────

# Who may authorise a write. A ticket for any other user is refused even when
# the ticket itself is perfectly valid.
OWNER_USERS = ("root@pam", "sean@pam")

# A grant may not be minted further ahead than this, however far the caller
# asks. Without a cap, "expires" is decoration.
MAX_GRANT_SECONDS = 900


def _verify_ticket(ticket, user, verifier=None):
    """Confirm the Proxmox ticket is live and belongs to `user`.

    The verifier is injected so this is testable without a Proxmox box. In
    production it asks the LOCAL API, which is the whole point: the host does
    not take the client's word for the authorisation.
    """
    if not isinstance(ticket, str) or len(ticket.strip()) < 16:
        raise Refused("a write needs a Proxmox ticket from a live owner login")
    if user not in OWNER_USERS:
        raise Refused(
            "%s is not an owner account; a write must be authorised by one of "
            "%s" % (user, ", ".join(OWNER_USERS)))
    check = verifier or _verify_ticket_against_local_api
    if not check(ticket, user):
        raise Refused(
            "the Proxmox ticket is not valid for %s. A write needs a FRESH "
            "owner login with password and authenticator; the SSH account on "
            "its own authorises nothing." % user)


# The Proxmox cluster CA, so the loopback call is verified rather than trusted.
# BOTH CANDIDATES ARE LISTED BECAUSE I AM NOT GUESSING THE PATH. My last guess
# about this node was `pct set --cmode tty`, and Codex measured the live
# container as cmode=shell -- a guess that would have made Sean's console
# worse. These are the two documented Proxmox locations; the live path is for
# Codex to confirm from the node, and until one of them is readable this
# verification REFUSES rather than falling back to an unverified connection.
PVE_CA_CANDIDATES = (
    "/etc/pve/pve-root-ca.pem",
    "/etc/pve/local/pve-ssl.pem",
)


def _pve_ssl_context():
    """A context that verifies the chain against the node's own CA, or None.

    WHY HOSTNAME CHECKING IS OFF AND CHAIN CHECKING IS ON. The call goes to
    127.0.0.1, and the node's certificate is issued for its own name, so a
    hostname check could only ever fail. The chain check is the part that
    matters: it is what stops another local listener on 8006 from answering
    as the Proxmox API and simply saying yes.

    PREVIOUSLY ssl._create_unverified_context(), which Muse flagged. That
    accepted ANY certificate, so the "verification" this wrapper rests on
    could have been answered by anything at all that held the port.
    """
    import ssl
    for candidate in PVE_CA_CANDIDATES:
        if not os.path.isfile(candidate):
            continue
        try:
            context = ssl.create_default_context(cafile=candidate)
        except Exception:
            continue
        context.check_hostname = False
        context.verify_mode = ssl.CERT_REQUIRED
        return context
    return None


def _verify_ticket_against_local_api(ticket, user):          # pragma: no cover
    """Ask the local Proxmox API WHO this ticket belongs to.

    HTTP 200 WAS NOT ENOUGH, and this is the fault Codex and Muse both found.
    The previous version read the ticket's access list and returned
    `response.status == 200`, which proves only that the ticket is valid for
    SOMEBODY. The user it was supposedly checked against came out of the
    grant, and the grant is written by the caller -- so anyone holding any
    valid Proxmox ticket could claim to be root@pam and be believed. That is a
    privilege escalation, not a loose end.
    #
    So this asks the one question with an unambiguous answer: it renews the
    ticket through /access/ticket, passing the ticket itself as the password,
    which Proxmox accepts only when the ticket genuinely belongs to the
    username given. The reply then CARRIES the principal, and that principal
    is compared as well. Two independent checks on the same call.

    Renewal mints a new ticket and is otherwise side-effect free: it does not
    extend, invalidate or alter the one presented, and the new one is
    discarded here unread.

    Not covered by the unit tests: it needs a Proxmox box. The logic a test
    CAN reach is in _verify_ticket(), which this only answers.
    """
    import urllib.error
    import urllib.parse
    import urllib.request

    context = _pve_ssl_context()
    if context is None:
        # FAIL CLOSED. An unverified connection here would mean the writer's
        # one proof of authorisation could be forged by any local listener.
        sys.stderr.write(
            "proxvox: refusing to verify a ticket over an unverified "
            "connection; none of %s is readable\n" % ", ".join(PVE_CA_CANDIDATES))
        return False

    body = urllib.parse.urlencode({"username": user, "password": ticket}).encode("ascii")
    request = urllib.request.Request(
        "https://127.0.0.1:8006/api2/json/access/ticket",
        data=body,
        headers={"Content-Type": "application/x-www-form-urlencoded"},
    )
    try:
        with urllib.request.urlopen(request, timeout=15, context=context) as response:
            if response.status != 200:
                return False
            answer = json.loads(response.read(65536).decode("utf-8", "replace"))
    except urllib.error.HTTPError:
        return False
    except Exception:
        return False

    data = answer.get("data") if isinstance(answer, dict) else None
    if not isinstance(data, dict):
        return False
    # THE PRINCIPAL THE API REPORTS, not the one the caller claimed.
    principal = str(data.get("username", ""))
    return principal != "" and principal == str(user)


def _check_grant(grant_json, *, target, path, sha256, now=None, verifier=None):
    """Parse and check the grant against the operation actually asked for."""
    try:
        grant = json.loads(grant_json)
    except Exception:
        raise Refused("the grant is not readable JSON")
    if not isinstance(grant, dict):
        raise Refused("the grant must be an object")

    user = str(grant.get("user", ""))
    ticket = grant.get("ticket")
    _verify_ticket(ticket, user, verifier=verifier)

    moment = time.time() if now is None else now
    try:
        expires = float(grant.get("expires", 0))
    except (TypeError, ValueError):
        raise Refused("the grant's expiry is not a number")

    # THE EXPIRY MUST BE A FINITE NUMBER, CHECKED BEFORE IT IS COMPARED.
    #
    # Codex's finding on cd1b963f, and measured rather than reasoned about:
    # Python's json.loads accepts the bare literals NaN, Infinity and
    # -Infinity, and NaN fails EVERY comparison. So a grant carrying
    # {"expires": NaN} was neither expired (nan <= moment is False) nor too
    # long (nan - moment > cap is False) and sailed through BOTH gates --
    # a never-expiring authorisation written by the caller. Infinity did not
    # pass, but it raised OverflowError inside the message formatting, which
    # main() reports as a FAULT rather than a refusal; it failed closed by
    # accident, and the audit recorded the wrong kind of event.
    #
    # Two gates that both answer "no objection" to a value that is not a
    # number are not two gates. This is the only check that can be first.
    if expires != expires or expires in (float("inf"), float("-inf")):
        raise Refused(
            "the grant's expiry must be a finite number of seconds since the "
            "epoch. NaN and Infinity are not expiry times.")

    if expires <= moment:
        raise Refused("the grant has expired; authorise again")
    if expires - moment > MAX_GRANT_SECONDS:
        raise Refused(
            "the grant runs %d seconds, longer than the %d-second cap. A "
            "long-lived grant is not a short-lived one with a bigger number."
            % (int(expires - moment), MAX_GRANT_SECONDS))

    # BOUND TO THIS OPERATION. A grant for one path must not authorise
    # another, and a grant for one payload must not authorise different bytes.
    if str(grant.get("target", "")) != str(target):
        raise Refused("the grant authorises %r, not %r"
                      % (grant.get("target"), target))
    if str(grant.get("path", "")) != str(path):
        raise Refused("the grant authorises a different path")
    if str(grant.get("sha256", "")).lower() != str(sha256).lower():
        raise Refused(
            "the grant authorises different content. The hash Sean approved is "
            "not the hash of what arrived, so nothing is written.")
    expires = min(expires, _check_write_session(user, ticket))
    return {"user": user, "expires": expires,
            "identity": _grant_identity(user, target, path, sha256, expires),
            "ticket": ticket,
            "ticket_fingerprint": hashlib.sha256(ticket.encode("utf-8")).hexdigest()}


def _grant_identity(user, target, path, sha256, expires):
    """What makes two grants THE SAME AUTHORISATION, rather than the same text.

    THE FIRST VERSION HASHED THE JSON, and Codex was right that it identified
    a representation rather than a grant. Measured: one logical grant spelled
    five ways -- keys sorted, default separators, a trailing space, a harmless
    extra key -- produced FIVE different digests. So "one use" was defeated by
    adding a space, and I had told the room grants were one-use when they were
    not.

    The identity is the five things the host actually checks and enforces. Any
    respelling of the same authorisation now collides, because none of the
    spelling is in here.

    THE TICKET IS DELIBERATELY NOT PART OF IT, for two reasons. It is a live
    credential and must not be hashed into a filename on the off chance that
    makes it guessable. And a FRESH login for the same operation is a new
    authorisation that should be allowed -- it carries a new expiry, so it has
    a different identity and is not refused as a replay.

    The expiry is formatted to a fixed precision so that 1795628046 and
    1795628046.0 are one value rather than two.
    """
    parts = (str(user), str(target), str(path), str(sha256).lower(),
             "%.6f" % float(expires))
    # NUL-separated and length-prefixed: no field's contents can impersonate a
    # separator and shift the meaning of the ones after it.
    blob = "".join("%d:%s\0" % (len(p), p) for p in parts)
    return hashlib.sha256(blob.encode("utf-8")).hexdigest()


#: Where spent grants are remembered, so one cannot be presented twice.
SPENT_GRANTS = "/var/lib/proxvox/spent-grants"
WRITE_NONCES = "/run/proxvox/write-nonces"
WRITE_NONCE_SECONDS = 60
WRITE_SESSIONS = "/run/proxvox/write-sessions"
WRITE_SESSION_SECONDS = 3600
MAINTENANCE_LOCKS = "/run/proxvox/maintenance"
ATTESTED_MAINTENANCE = {"preview-maintenance", "issue-maintenance-nonce", "run-maintenance"}
ATTESTED_DATABASE = {"preview-db-update", "issue-db-update-nonce", "run-db-update"}
SITE_CONFIG_MAX_BYTES = 65536


def _write_nonce_name(nonce):
    if not isinstance(nonce, str) or nonce != nonce.lower() or not _is_sha256(nonce):
        raise Refused("the host write nonce is invalid; request a fresh one")
    return os.path.join(WRITE_NONCES, nonce + ".json")


def _assert_private_nonce_directory():
    _assert_private_state_directory(WRITE_NONCES)


def _assert_private_state_directory(state_directory):
    for path in (os.path.dirname(state_directory), state_directory):
        directory = os.lstat(path)
        forbidden = 0o077 if path == state_directory else 0o022
        if not stat.S_ISDIR(directory.st_mode) or (
            os.name == "posix" and (directory.st_uid != os.geteuid() or directory.st_mode & forbidden)
        ):
            raise Refused("the host writer state directory is not secured")


def _read_session_request():
    raw = sys.stdin.buffer.read(8193)
    if len(raw) > 8192:
        raise Refused("write session request is too large")
    try:
        payload = json.loads(raw)
    except ValueError as err:
        raise Refused("invalid write session request") from err
    if not isinstance(payload, dict):
        raise Refused("invalid write session request")
    return payload


def _exchange_totp(user, challenge, code):
    import re
    import urllib.error
    import urllib.parse
    import urllib.request

    if user not in OWNER_USERS:
        raise Refused("the account cannot authorize a host writer session")
    if (not isinstance(challenge, str) or not challenge.startswith("PVE:!tfa!")
            or not isinstance(code, str) or not re.fullmatch(r"[0-9]{6}", code)):
        raise Refused("a fresh Proxmox TOTP challenge and code are required")
    context = _pve_ssl_context()
    if context is None:
        raise Refused("the host cannot verify local Proxmox TLS")

    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, req, fp, code, msg, headers, newurl):
            return None

    opener = urllib.request.build_opener(
        urllib.request.ProxyHandler({}), urllib.request.HTTPSHandler(context=context), NoRedirect()
    )
    request = urllib.request.Request(
        "https://127.0.0.1:8006/api2/json/access/ticket",
        data=urllib.parse.urlencode({
            "username": user, "tfa-challenge": challenge, "password": "totp:" + code,
        }).encode("ascii"),
        headers={"Content-Type": "application/x-www-form-urlencoded"},
    )
    try:
        with opener.open(request, timeout=15) as response:
            answer = json.loads(response.read(65536)) if response.status == 200 else {}
    except urllib.error.HTTPError as err:
        raise Refused("Proxmox rejected the TOTP challenge") from err
    except (OSError, ValueError) as err:
        raise Refused("the host could not complete Proxmox TOTP verification") from err
    data = answer.get("data") if isinstance(answer, dict) else None
    ticket = data.get("ticket") if isinstance(data, dict) else None
    csrf = data.get("CSRFPreventionToken") if isinstance(data, dict) else None
    if (not isinstance(ticket, str) or not ticket.startswith("PVE:" + user + ":")
            or not isinstance(csrf, str) or not csrf or data.get("username") != user):
        raise Refused("Proxmox did not return a full ticket for the authorized account")
    return ticket, csrf


def _write_session_path(ticket):
    if not isinstance(ticket, str) or not ticket.startswith("PVE:") or len(ticket) > 4096:
        raise Refused("a host-authorized write ticket is required")
    fingerprint = hashlib.sha256(ticket.encode("utf-8")).hexdigest()
    return os.path.join(WRITE_SESSIONS, fingerprint + ".json")


def _start_write_session(user, request):
    ticket, csrf = _exchange_totp(user, request.get("challenge"), request.get("code"))
    expires = time.time() + WRITE_SESSION_SECONDS
    until = time.monotonic() + WRITE_SESSION_SECONDS
    try:
        os.makedirs(WRITE_SESSIONS, mode=0o700, exist_ok=True)
        _assert_private_state_directory(WRITE_SESSIONS)
        with os.scandir(WRITE_SESSIONS) as entries:
            for entry in entries:
                try:
                    if entry.name.endswith(".json") and entry.stat(follow_symlinks=False).st_mtime < expires - 2 * WRITE_SESSION_SECONDS:
                        os.unlink(entry.path)
                except FileNotFoundError:
                    pass
        created = os.open(_write_session_path(ticket), os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
        with os.fdopen(created, "w", encoding="utf-8") as record:
            json.dump({"user": user, "expires": expires, "until": until}, record)
    except OSError as err:
        raise Refused("the host could not start a writer session") from err
    return {"ticket": ticket, "csrf": csrf, "expires_at": expires}


def _check_write_session(user, ticket):
    path = _write_session_path(ticket)
    try:
        _assert_private_state_directory(WRITE_SESSIONS)
        with open(path, encoding="utf-8") as record:
            session = json.load(record)
        if (session["user"] != user or time.time() >= session["expires"]
                or time.monotonic() >= session["until"]):
            raise Refused("host write authorization expired or belongs to another account")
    except (OSError, ValueError, TypeError, KeyError) as err:
        raise Refused("no active host write session; authorize again") from err
    return session["expires"]


def _revoke_write_session(user, ticket):
    _check_write_session(user, ticket)
    try:
        os.unlink(_write_session_path(ticket))
    except FileNotFoundError:
        pass
    except OSError as err:
        raise Refused("the host could not revoke the writer session") from err
    return {"revoked": True}


def _issue_write_nonce(operation, expires, now=None):
    """Bind a host-generated nonce to a validated operation with a short lifetime."""
    moment = time.time() if now is None else now
    deadline = min(float(expires), moment + WRITE_NONCE_SECONDS)
    if deadline <= moment:
        raise Refused("write authorization expired; authorize and retry")
    try:
        os.makedirs(WRITE_NONCES, mode=0o700, exist_ok=True)
        _assert_private_nonce_directory()
        # Cleanup is best effort. Validation checks expiry regardless of whether the file survives.
        with os.scandir(WRITE_NONCES) as entries:
            for entry in entries:
                try:
                    if entry.name.endswith((".json", ".used")) and entry.stat(follow_symlinks=False).st_mtime < moment - WRITE_NONCE_SECONDS:
                        os.unlink(entry.path)
                except FileNotFoundError:
                    pass
        nonce = secrets.token_hex(32)
        fd = os.open(_write_nonce_name(nonce), os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
        with os.fdopen(fd, "w", encoding="utf-8") as record:
            json.dump({"operation": operation, "expires": deadline}, record, separators=(",", ":"))
    except OSError as err:
        raise Refused("the host could not issue a write nonce") from err
    return nonce


def _consume_write_nonce(nonce, operation, now=None):
    """Only one competing caller can exclusively claim a matching nonce."""
    name = _write_nonce_name(nonce)
    moment = time.time() if now is None else now
    try:
        _assert_private_nonce_directory()
        with open(name, encoding="utf-8") as record:
            authorization = json.load(record)
        if authorization["operation"] != operation:
            raise Refused("the host write nonce belongs to a different operation")
        if authorization["expires"] <= moment:
            raise Refused("the host write nonce expired; request a fresh one")
        # Keep an exclusive spent marker through the expiry window. Windows
        # can report successful unlink twice while a competing read is open.
        claim = os.open(name + ".used", os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
        os.close(claim)
        if authorization["expires"] <= (time.time() if now is None else now):
            raise Refused("the host write nonce expired; request a fresh one")
    except FileExistsError as err:
        raise Refused("the host write nonce was already used") from err
    except FileNotFoundError as err:
        raise Refused("the host write nonce was already used or does not exist") from err
    except (OSError, ValueError, KeyError, TypeError) as err:
        raise Refused("the host could not verify the write nonce") from err


def _spend_grant(identity, expires, now=None):
    """Record a grant as spent, or refuse it because it already was.

    ONE USE, NOT ONE WINDOW. Codex's finding on cd1b963f, and it is the
    improvement I had already named as worth doing in GRANT-DESIGN.md, so
    there is no argument to have: without this the host remembers nothing,
    and the same grant can be replayed for as long as it lives. The write is
    idempotent -- same path, same content hash -- so a replay rewrites
    identical bytes rather than different ones, which is why this is a
    hardening and not a breach. But a credential that works twice is not a
    one-shot authorisation, and the whole design rests on it being one.

    WHAT IS STORED IS A HASH, never the grant. The grant carries a live
    Proxmox ticket; a directory of spent grants would be a directory of
    usable credentials, which is the opposite of what this is for.

    THE FILE IS THE LOCK. O_CREAT|O_EXCL either creates the marker or fails
    because it exists, in one atomic step, so two writes presenting the same
    grant at the same moment cannot both win -- which a read-then-write check
    could not promise.

    Spent markers are named with the expiry so they can be swept; a marker
    outliving its grant costs a few bytes and refuses a grant that was
    already unusable, which is the safe direction.
    """
    moment = time.time() if now is None else now
    # The IDENTITY comes from _grant_identity: what the grant authorises, not
    # how it was spelled. Hashing the JSON text here was the flaw Codex found.
    digest = str(identity or "")
    if len(digest) != 64 or any(c not in "0123456789abcdef" for c in digest):
        raise Refused("the grant identity is not a digest; nothing was written.")
    try:
        os.makedirs(SPENT_GRANTS, mode=0o700, exist_ok=True)
    except Exception as err:
        # FAIL CLOSED. Unable to remember a grant means unable to promise it
        # is used once, and the promise is the point.
        raise Refused(
            "cannot record the grant as spent, so the write is refused: %s" % err)

    marker = posixpath.join(SPENT_GRANTS, "%s.%d" % (digest, int(expires)))
    try:
        handle = os.open(marker, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    except FileExistsError:
        raise Refused(
            "this grant has already been used. A grant authorises ONE write; "
            "authorise again for another.")
    except Exception as err:
        raise Refused(
            "cannot record the grant as spent, so the write is refused: %s" % err)
    os.close(handle)

    # Sweep markers whose grants have expired. Bounded, best-effort, and never
    # a reason to fail a write that has already been authorised.
    try:
        for name in os.listdir(SPENT_GRANTS):
            parts = name.rsplit(".", 1)
            if len(parts) == 2 and parts[1].isdigit() and int(parts[1]) < moment - MAX_GRANT_SECONDS:
                try:
                    os.unlink(posixpath.join(SPENT_GRANTS, name))
                except Exception:
                    pass
    except Exception:
        pass
    return digest


# ─────────────────────────────────────────────────────────────────────────────
# THE HOST'S OWN RECORD.
#
# The desktop client keeps an AuditBuffer, but that record lives on the
# machine that asked for the write. It is the wrong place to keep the only
# copy: a client can crash before it flushes, be reinstalled, or simply be a
# different client next time, and none of that should be able to erase what
# happened to Sean's server. The host is the only party that sees every
# attempt, including the ones it refused, so the host keeps its own log.
#
# APPEND-ONLY, ROOT-ONLY, AND ONE JSON OBJECT PER LINE. 0600 in a directory
# created 0700, opened O_APPEND so two concurrent writes cannot interleave a
# partial line, and never truncated or rotated by this wrapper -- removing
# audit evidence is the one thing the repository instructions forbid outright.
#
# IT RECORDS WHAT HAPPENED, NEVER WHAT WAS WRITTEN. Paths, sizes, hashes,
# vmids and refusal reasons. Not file content, not the grant, not the ticket,
# not a password. The same rule the rest of this wrapper already follows: a
# log that quotes the thing it was protecting is a second copy of the secret.
AUDIT_LOG = "/var/log/proxvox/writer.jsonl"

# Fields that may be echoed from a dispatch result. An allowlist rather than a
# denylist, so a field added to a result later cannot leak into the log by
# default -- the shape of the log is decided here, not by the caller.
# THESE NAMES ARE READ OFF THE RETURN STATEMENTS IN THIS FILE, not invented.
# The first draft of this tuple guessed "before", "after", "backup" and
# "sha256"; the real fields are sha256_before, sha256_after, backup_path and
# bytes, so every one of those four would have recorded nothing while the log
# still looked populated. The same mistake as calling a repository method that
# does not exist: a name that is almost right fails silently.
_AUDIT_RESULT_FIELDS = (
    "dry_run", "target", "path", "bytes",
    "sha256_before", "sha256_after", "sha256_after_declared",
    "backup_path", "would_back_up", "verified",
    "task", "ran", "would_run",
)


@contextmanager
def _maintenance_lock(target):
    """Serialize state checks and dispatch for a single writer target."""
    import fcntl

    os.makedirs(MAINTENANCE_LOCKS, mode=0o700, exist_ok=True)
    _assert_private_state_directory(MAINTENANCE_LOCKS)
    path = os.path.join(MAINTENANCE_LOCKS, target + ".lock")
    fd = os.open(path, os.O_CREAT | os.O_WRONLY | os.O_NOFOLLOW, 0o600)
    try:
        fcntl.flock(fd, fcntl.LOCK_EX)
        yield
    finally:
        os.close(fd)


def _maintenance_plan(request):
    where = request.get("target")
    target = "host" if where == "host" else _vmid(where)
    prefix = [] if target == "host" else _ct(target)
    action = request.get("action")
    if not isinstance(action, str):
        raise Refused("invalid maintenance action")
    subject = request.get("subject")
    value = request.get("value")
    if not isinstance(subject, str) or any(c in subject for c in "\r\n\0"):
        raise Refused("invalid maintenance subject")
    if action in {"chown", "chmod"}:
        subject = _absolute(subject)
        check = prefix + ["/usr/bin/stat", "--format=%d:%i:%u:%g:%a:%f", "--", subject]
        if action == "chown" and isinstance(value, str) and re.fullmatch(
            r"[0-9]{1,10}:[0-9]{1,10}", value
        ):
            return target, prefix + ["/usr/bin/chown", "--no-dereference", "--", value, subject], check
        if action == "chmod" and isinstance(value, str) and re.fullmatch(r"[0-7]{3,4}", value):
            return target, prefix + ["/usr/bin/chmod", "--", value, subject], check
    if action in {"restart", "reload", "status"} and value is None and re.fullmatch(
        r"[A-Za-z0-9_][A-Za-z0-9_.@:-]{0,253}", subject
    ):
        check = prefix + ["/usr/bin/systemctl", "show", "--no-pager",
                          "--property=LoadState,ActiveState,SubState,InvocationID", "--", subject]
        command = check if action == "status" else prefix + ["/usr/bin/systemctl", action, "--", subject]
        return target, command, check
    raise Refused("expected chown with numeric UID:GID, chmod with an octal mode, or a systemd unit action")


def _attested_maintenance(op, user, request):
    ticket = request.get("ticket")
    _check_write_session(user, ticket)
    target, command, check = _maintenance_plan(request)
    action = request["action"]
    with _maintenance_lock(target):
        if action in {"chown", "chmod"}:
            path = command[-1]
            if target == "host":
                _refuse_symlinks(path)
            else:
                _refuse_container_symlinks(target, path)
        before = _run(check).decode("utf-8").strip()
        expected = hashlib.sha256(before.encode("utf-8")).hexdigest()
        if op == "preview-maintenance":
            return {"argv": command, "before": before, "expected": expected}
        if request.get("expected") != expected:
            raise Refused("maintenance target changed since preview")
        operation = hashlib.sha256(json.dumps(
            [user, _write_session_path(ticket), target, action, command, expected],
            separators=(",", ":"),
        ).encode("utf-8")).hexdigest()
        expires = _check_write_session(user, ticket)
        if op == "issue-maintenance-nonce":
            return {"write_nonce": _issue_write_nonce(operation, expires)}

        _consume_write_nonce(request.get("nonce"), operation)
        detail = {"target": target, "argv": command, "before": before}
        if not _audit("maintenance-intent", detail, durable=True):
            raise Refused("could not persist the maintenance audit; command was not run")
        _check_write_session(user, ticket)
        try:
            if action != "status":
                _run(command)
            after = _run(check).decode("utf-8").strip() if action != "status" else before
        except (Refused, OSError, subprocess.SubprocessError, UnicodeError) as err:
            _audit("maintenance-result", dict(detail, result="failed"), durable=True)
            raise Refused("maintenance failed; inspect target state before retrying") from err
        result = dict(detail, after=after, result="success")
        if not _audit("maintenance-result", result, durable=True):
            raise Refused("command ran but its result could not be audited; check target state")
        return result


def _read_site_db_config(target, path):
    if not (path.startswith(("/var/www/", "/srv/www/")) and path.endswith("/core/db.php")):
        raise Refused("Choose the site's core/db.php in a web root.")
    try:
        if target == "host":
            _refuse_symlinks(path)
            if not stat.S_ISREG(os.lstat(path).st_mode):
                raise Refused("The site database config is not a regular file.")
            with open(path, "rb") as config:
                data = config.read(SITE_CONFIG_MAX_BYTES + 1)
        else:
            _refuse_container_symlinks(target, path)
            _run(_ct(target) + ["/usr/bin/test", "-f", path])
            data = _run(_ct(target) + ["/usr/bin/head", "-c", str(SITE_CONFIG_MAX_BYTES + 1), "--", path])
    except (OSError, Refused, subprocess.SubprocessError):
        raise Refused("Could not read the selected site's database configuration.") from None
    if not data or len(data) > SITE_CONFIG_MAX_BYTES:
        raise Refused("The site database configuration is empty or too large.")
    return data


def _attested_database(op, user, request):
    from proxvox_database import (
        RowUpdate, UnsafeDatabaseUpdate, apply_row_update, connect_site_database, read_row_fingerprint,
    )

    ticket = request.get("ticket")

    def attest():
        return _check_write_session(user, ticket)

    attest()
    where = request.get("target")
    target = "host" if where == "host" else _vmid(where)
    path = _absolute(request.get("config_path"))
    try:
        change = RowUpdate(*(request.get(field) for field in
                           ("database", "table", "key_column", "key", "column", "value")))
        expected = request.get("expected")
        with _maintenance_lock(target):
            config = _read_site_db_config(target, path)
            config_digest = hashlib.sha256(config).hexdigest()
            attest()
            with connect_site_database(config, change.database, container=target != "host") as connection:
                del config
                if op == "preview-db-update":
                    return {"expected": read_row_fingerprint(connection, change)}
                if not isinstance(expected, str) or not _is_sha256(expected):
                    raise Refused("Preview the database row before requesting a write.")
                operation = hashlib.sha256(json.dumps(
                    ["db-update", user, _write_session_path(ticket), target, path, config_digest,
                     change.audit_record(), expected], separators=(",", ":"), sort_keys=True,
                ).encode("utf-8")).hexdigest()
                if op == "issue-db-update-nonce":
                    if read_row_fingerprint(connection, change) != expected:
                        raise Refused("The database row changed since preview.")
                    return {"write_nonce": _issue_write_nonce(operation, attest())}
                _consume_write_nonce(request.get("nonce"), operation)
                os.makedirs(BACKUP_DIR, mode=0o700, exist_ok=True)

                # A driver can expose connection/authentication details in its error
                # text. Never forward driver messages to the client or the audit.
                def record_intent(detail):
                    if not _audit("database-update-intent", dict(detail, target=target), durable=True):
                        raise Refused("Could not persist the database audit; the row was not written.")

                try:
                    result = apply_row_update(connection, change, expected, BACKUP_DIR,
                                              attest=attest, record_intent=record_intent)
                except Exception:
                    _audit("database-update-result", {
                        "target": target, "result": "failed or not confirmed",
                        **change.audit_record(),
                    }, durable=True)
                    raise
                if not _audit("database-update-result", dict(result, target=target, result="success"),
                              durable=True):
                    raise Refused("The row was committed but its result could not be audited.")
                return result
    except UnsafeDatabaseUpdate as err:
        raise Refused(str(err)) from None
    except Refused:
        raise
    except Exception:
        raise Refused("Database operation failed; verify the row before retrying.") from None


def _audit_command(command):
    """The command line with any grant blob removed.

    A grant carries a live Proxmox ticket. Logging the command verbatim would
    write that ticket to disk in cleartext, where it stays valid for as long as
    the ticket does. So the sixth argument of a put-file is replaced by a
    marker: the log records THAT a grant was presented, never its bytes.
    """
    try:
        args = shlex.split(str(command or ""), posix=True)
    except ValueError:
        return "(unparsable command)"
    if args and (args[0] in ATTESTED_MAINTENANCE | ATTESTED_DATABASE or args[0] in {
        "authorize-write-session", "check-write-session", "revoke-write-session"
    }):
        return args[0]
    if args and args[0] in {"put-file", "put-file-dry", "issue-file-nonce"} and len(args) >= 6:
        # REPLACE the grant, do not truncate at it. The protocol's longest
        # put-file is six arguments, so for every valid command these are the
        # same thing -- but a malformed longer one should still be logged in
        # full apart from the grant, because a command the wrapper is about to
        # refuse as malformed is exactly the one worth seeing whole.
        args = args[:5] + ["(grant presented, not logged)"] + args[6:]
        if len(args) >= 7:
            args[6] = "(nonce presented, not logged)"
    line = " ".join(args)
    return line[:400]


def _audit_result(data):
    if not isinstance(data, dict):
        return {}
    return {key: data[key] for key in _AUDIT_RESULT_FIELDS if key in data}


def _audit(event, detail, *, durable=False):
    """Append one record, optionally requiring it to reach disk before returning.

    An audit failure must not become a way to stop the wrapper answering --
    that would turn a full disk into a denial of service on Sean's own
    server. It is reported on stderr, which the forced command carries back,
    so a silent loss of the record is still visible.
    """
    record = {
        "at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "event": event,
        "ssh_user": os.environ.get("USER") or os.environ.get("LOGNAME") or "",
        "from": (os.environ.get("SSH_CONNECTION", "").split(" ") or [""])[0],
        "detail": detail,
    }
    try:
        # os.path, not posixpath, FOR THIS ONE CALL. On the Proxmox host
        # os.path IS posixpath, so this is the same code there; on the
        # developer's Windows machine posixpath.dirname of a local temporary
        # path returns '' and the record silently goes nowhere, which would
        # mean the audit is the one part of this wrapper no test can exercise.
        # Every PATH THIS WRAPPER WRITES TO on the server stays posixpath --
        # this is the wrapper's own log location, not a caller-supplied path.
        os.makedirs(os.path.dirname(AUDIT_LOG) or ".", mode=0o700, exist_ok=True)
        handle = os.open(
            AUDIT_LOG,
            (os.O_WRONLY | os.O_CREAT | os.O_APPEND
             | getattr(os, "O_BINARY", 0) | getattr(os, "O_NOFOLLOW", 0)),
            0o600,
        )
        try:
            metadata = os.fstat(handle)
            if not stat.S_ISREG(metadata.st_mode) or (
                os.name == "posix" and (
                    metadata.st_uid != os.geteuid() or metadata.st_mode & 0o077
                )
            ):
                raise OSError("the host writer audit file is not private")
            line = (json.dumps(record, separators=(",", ":")) + "\n").encode("utf-8")
            if os.write(handle, line) != len(line):
                raise OSError("the audit record was only partially written")
            if durable:
                os.fsync(handle)
        finally:
            os.close(handle)
        return True
    except Exception as err:                                  # pragma: no cover
        sys.stderr.write("proxvox: could not write the audit record: %s\n" % err)
        return False


def dispatch(command):
    # THE ATTEMPT IS LOGGED HERE, NOT ONLY IN main(). Muse's caveat on
    # aa169f80: _audit was called from main() alone, so anything reaching
    # dispatch() directly -- a test, a future local caller, anyone who imports
    # this module -- left no record at all. The host's log is meant to be the
    # copy that cannot be lost, and a log with a documented way to bypass it is
    # not that. main() still records the OUTCOME, because only main() sees
    # whether the operation returned or raised.
    _audit("attempt", {"command": _audit_command(command)})

    args = shlex.split(str(command or ""), posix=True)
    if not args:
        raise Refused("no operation given")

    op = args[0]

    if op in {"authorize-write-session", "check-write-session", "revoke-write-session"} and len(args) == 2:
        user = args[1]
        if user not in OWNER_USERS:
            raise Refused("the account cannot authorize a host writer session")
        request = _read_session_request()
        if op == "authorize-write-session":
            return _start_write_session(user, request)
        if op == "check-write-session":
            return {"expires_at": _check_write_session(user, request.get("ticket"))}
        return _revoke_write_session(user, request.get("ticket"))

    if op in ATTESTED_MAINTENANCE and len(args) == 2:
        if args[1] not in OWNER_USERS:
            raise Refused("the account cannot authorize a host writer session")
        return _attested_maintenance(op, args[1], _read_session_request())

    if op in ATTESTED_DATABASE and len(args) == 2:
        if args[1] not in OWNER_USERS:
            raise Refused("The account cannot authorize a host writer session.")
        return _attested_database(op, args[1], _read_session_request())

    # ── what may I write? The allowlist, readable so Sean can check it. ──
    if op == "scope" and len(args) == 1:
        return {
            "allowed": [{"target": t, "pattern": p} for t, p in ALLOWED],
            "never_write": list(NEVER_WRITE),
            "maintenance": sorted(MAINTENANCE),
            "attested_maintenance": ["chmod", "chown", "reload", "restart", "status"],
            "attested_database": ["row-update"],
            "max_write_bytes": MAX_WRITE_BYTES,
            "note": "Without a live owner session, only listed paths can be rehearsed. "
                    "With host-witnessed TOTP and an operation nonce, the file writer supports "
                    "regular files on the host and its containers. Attested maintenance "
                    "also requires a nonce bound to the requested command and preview state.",
        }

    # ── put-file[-dry] host|<vmid> <path> <sha256>, content base64 on stdin ──
    # Two op NAMES rather than a --dry-run flag, so a mistyped flag can never
    # turn a rehearsal into a write.
    if op in {"put-file", "put-file-dry", "issue-file-nonce"} and len(args) in (4, 5, 6, 7):
        dry_run = op == "put-file-dry"
        where = args[1]
        path = _absolute(args[2])
        target = "host" if where == "host" else _vmid(where)
        declared = args[3]
        # args[4], when present, is the hash the caller EXPECTS the file to
        # hold right now -- Codex asked for this. It turns a blind overwrite
        # into a compare-and-set, so a file somebody else changed since the
        # caller read it is refused instead of silently replaced. "-" means
        # the caller expects the file not to exist yet.
        expected_live = args[4] if len(args) >= 5 else None
        grant_json = args[5] if len(args) >= 6 else None
        nonce = args[6] if len(args) == 7 else None
        require_live_session = None
        if dry_run or not grant_json:
            _check_allowed(target, path)
        if (op == "issue-file-nonce" and len(args) != 6) or (dry_run and len(args) > 5):
            raise Refused("invalid arguments for the host write operation")

        # A LIVE WRITE NEEDS A GRANT. The dry run deliberately does not, so a
        # rehearsal can be done without spending an authorisation -- and a dry
        # run changes nothing, which is what makes that safe.
        if not dry_run:
            if not grant_json:
                raise Refused(
                    "a live write needs a grant from a fresh owner login. Run "
                    "the dry run to rehearse, or authorise and retry.")
            # A LIVE WRITE MUST SAY WHAT IT EXPECTED TO FIND.
            #
            # THIS MAKES AN EXISTING GUARANTEE EXPLICIT. IT DID NOT CLOSE AN
            # OPEN HOLE, and the distinction matters enough to write down.
            # Muse's finding (a) on aa169f80 said a direct SSH caller could
            # present a valid grant and blind-overwrite, because dispatch()
            # accepted a four-argument put-file. I tried it against the real
            # dispatch() at aa169f80 rather than agreeing: every shape the
            # protocol allows, with the file on disk checked after each.
            #
            #   4 args, no expectation, no grant  -> refused (no grant)
            #   5 args, grant in slot five        -> refused (no grant)
            #   6 args, expectation '-'           -> refused (file exists)
            #   6 args, correct expectation       -> wrote, as intended
            #
            # Identical before and after this change. The guarantee held
            # already, but only as an ACCIDENT OF ARGUMENT POSITIONS: a live
            # write needs a grant, grant_json is read from args[5], so args[4]
            # is necessarily occupied. Nothing said so, nothing tested it, and
            # one reordered argument or a seventh option would have removed it
            # silently. An emergent guarantee is one nobody knows they are
            # breaking, so it is now a stated rule with a test behind it.
            #
            # The dry run is exempt: it changes nothing, so there is nothing to
            # overwrite blindly, and demanding an expectation in order to
            # rehearse would mean reading the file before you are allowed to
            # ask whether you may.
            #
            # AN EMPTY EXPECTATION IS REFUSED TOO, and that one was ambiguous
            # rather than merely unstated. "" fell through to the comparison
            # as "", which is exactly what _sha256_of returns for a file that
            # does not exist -- so an empty string silently MEANT "expect
            # absent", by accident, alongside the "-" that means it on
            # purpose. Two spellings of the same intent, one of them a typo.
            # Only "-" says absent now; anything else must be a real hash.
            expectation = "" if expected_live is None else str(expected_live).strip()
            if expectation == "":
                raise Refused(
                    "a live write must declare the sha256 it expects the file "
                    "to hold right now, or '-' if it expects no file. Rehearse "
                    "with put-file-dry to read the current hash.")
            if expectation != "-" and not _is_sha256(expectation):
                raise Refused(
                    "the expected hash must be a 64-character sha256, or '-' "
                    "for a file that should not exist yet.")
            expected_live = expectation
            checked = _check_grant(grant_json, target=target, path=path, sha256=declared)
            if not _is_sha256(declared):
                raise Refused("the content hash must be a 64-character sha256")
            operation = hashlib.sha256(json.dumps(
                ["put-file", checked["identity"], checked["ticket_fingerprint"], expected_live.lower()],
                separators=(",", ":"),
            ).encode("utf-8")).hexdigest()
            if op == "issue-file-nonce":
                return {"write_nonce": _issue_write_nonce(operation, checked["expires"])}
            _consume_write_nonce(nonce, operation)
            # SPENT ONLY ONCE THE GRANT IS OTHERWISE GOOD, and before the
            # write. Marking it earlier would burn a grant on a command the
            # host was going to refuse anyway; marking it after the write
            # would leave a window in which a crash loses the record and the
            # grant works again.
            _spend_grant(checked["identity"], checked["expires"])
            def require_live_session():
                _check_write_session(checked["user"], checked["ticket"])
                if checked["expires"] <= time.time():
                    raise Refused("the grant expired before the file could be replaced")

        content = _read_stdin_content(declared)
        if target == "host":
            _refuse_symlinks(path)
        elif not dry_run:
            _refuse_container_symlinks(target, path)
        # THE EARLY CHECK IS A COURTESY. It refuses an obviously stale write
        # before anything is copied or created, which is worth having -- but it
        # is NOT the guarantee, because the content is read from stdin after
        # it and, for a container, several pct exec round trips follow. The
        # authoritative comparison happens immediately before the replacement,
        # inside _put_host and _put_container. Codex's finding on cd1b963f.
        _refuse_if_changed(target, path, expected_live)
        if target == "host":
            return _put_host(path, content, dry_run, expected_live, require_live_session)
        return _put_container(target, path, content, dry_run, expected_live, require_live_session)

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
    command = os.environ.get("SSH_ORIGINAL_COMMAND", "")
    # The attempt is recorded by dispatch() now, so that a caller reaching it
    # directly is recorded too; main() records the outcome, which only main()
    # can see. Sean's ruling stands either way: the attempt is written BEFORE
    # the operation runs, so a write that crashes the wrapper mid-way still
    # leaves its attempt on the host.
    try:
        data = dispatch(command)
        payload = {"ok": True, "data": data}
        code = 0
        _audit("done", {"command": _audit_command(command),
                        "result": _audit_result(data)})
    except Refused as err:
        payload = {"ok": False, "error": str(err)[:500]}
        code = 2
        _audit("refused", {"command": _audit_command(command),
                           "why": str(err)[:300]})
    except Exception as err:
        payload = {"ok": False, "error": str(err)[:500]}
        code = 2
        # A FAULT IS NOT A REFUSAL, and the log says which. A refusal is the
        # wrapper working; a fault is the wrapper broken, and reading them as
        # the same thing hides the second behind the first.
        _audit("failed", {"command": _audit_command(command),
                          "error": type(err).__name__ + ": " + str(err)[:300]})
    sys.stdout.write(json.dumps(payload, separators=(",", ":")) + "\n")
    return code


if __name__ == "__main__":
    raise SystemExit(main())

# ===== SNAPSMACK EOF =====
