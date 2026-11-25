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

# BACKUPS DO NOT LIVE BESIDE THE FILE. Codex caught this: a .proxvox-bak of a
# PHP file inside a web root can be fetched as source by anyone who guesses
# the name, which turns a safety copy into a disclosure. They go here instead,
# outside every document root, created 0700.
BACKUP_DIR = "/var/backups/proxvox"

# ─────────────────────────────────────────────────────────────────────────────
# THE ALLOWLIST. Sean sees this list before it goes live and adding to it needs
# his yes. Each entry is (target, glob). target is "host" or a container vmid
# as a string. A glob is matched SEGMENT BY SEGMENT -- a * never crosses a
# slash -- and it is checked against the path BOTH as given and after symlink
# resolution. This comment used to claim "after resolution" while the code
# only normalised the string, which is the exact fault this repo calls out:
# a code comment is not evidence. Codex caught it in review.
# ─────────────────────────────────────────────────────────────────────────────
# IT SHIPS EMPTY, AND THAT IS THE POINT.
#
# The PM's ruling, 2026-11-24 23:45: REMOVE /usr/local/bin/mariadb_backup.sh.
# A root-executed script is not a first-release write target; it can be added
# later with a per-change hash approval from Sean. Agreed, and it is gone.
#
# My other three entries were WRONG, and Codex caught it. I had guessed
# /var/www/*/public_html/* and /var/www/*/htdocs/* for CT102. The provisioner
# measured the real layout on the live container: CT102 serves from
# /var/www/sites, and CT106 from /var/www/<site> with no wrapper. Neither of my
# patterns would have matched anything, and the one that nearly did would have
# granted far more than it read. CT101 is named in no provisioner at all, so I
# have no verified path for it and will not invent one -- the PM said the roots
# come from the read inventory, not guesses, and I cannot run that inventory
# without Sean's session.
#
# So the list is EMPTY until a path is confirmed against the host and approved.
# An empty allowlist means this wrapper refuses every write, which is the
# correct state for something that is not installed and has no approved
# target. The structure, the grant and the guards are all reviewable without
# it, and a wrong path here is worse than no path.
#
# To add one: confirm it with the read-only broker's inventory, post it in the
# room, get Sean's yes, and add (target, pattern) with the reason beside it.
ALLOWED = (
    # CT101, snapsmack.ca -- the project and release site, not a CMS install.
    # VERIFIED, NOT GUESSED: Codex ran the read-only inventory on 2026-11-25
    # (SS-1507, room seq 7538). /etc/apache2/sites-enabled/snapsmack.ca.conf
    # names snapsmack.ca and www.snapsmack.ca with DocumentRoot
    # /var/www/snapsmack.ca, and he read the file itself back at SHA-256
    # 7141f5ed07da5555aa34ce38bb348dfdf4d2f555e53e9be1357f0a3ddf10ea0f.
    #
    # ONE FILE, NOT THE TREE. The PM's spec said "CT101 snapsmack.ca web
    # root", and the only approved use is the Desktop Tools section of the
    # homepage. A whole web root would grant far more than that needs, and the
    # narrowest entry that serves the actual job is the single file. Widening
    # it later is a decision somebody makes on purpose.
    ("101", "/var/www/snapsmack.ca/index.php"),
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
    """Raise unless (target, path) is in ALLOWED and not in NEVER_WRITE."""
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


def _put_host(path, content, dry_run):
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


def _put_container(vmid, path, content, dry_run):
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
    _verify_ticket(grant.get("ticket"), user, verifier=verifier)

    moment = time.time() if now is None else now
    try:
        expires = float(grant.get("expires", 0))
    except (TypeError, ValueError):
        raise Refused("the grant's expiry is not a number")
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
    return {"user": user, "expires": expires}


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
    if args and args[0] in {"put-file", "put-file-dry"} and len(args) >= 6:
        # REPLACE the grant, do not truncate at it. The protocol's longest
        # put-file is six arguments, so for every valid command these are the
        # same thing -- but a malformed longer one should still be logged in
        # full apart from the grant, because a command the wrapper is about to
        # refuse as malformed is exactly the one worth seeing whole.
        args = args[:5] + ["(grant presented, not logged)"] + args[6:]
    line = " ".join(args)
    return line[:400]


def _audit_result(data):
    if not isinstance(data, dict):
        return {}
    return {key: data[key] for key in _AUDIT_RESULT_FIELDS if key in data}


def _audit(event, detail):
    """Append one record. A log that cannot be written never blocks a refusal.

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
            os.O_WRONLY | os.O_CREAT | os.O_APPEND | getattr(os, "O_BINARY", 0),
            0o600,
        )
        try:
            os.write(handle, (json.dumps(record, separators=(",", ":")) + "\n").encode("utf-8"))
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
    if op in {"put-file", "put-file-dry"} and len(args) in (4, 5, 6):
        dry_run = op == "put-file-dry"
        where = args[1]
        path = _absolute(args[2])
        target = "host" if where == "host" else _vmid(where)
        _check_allowed(target, path)
        declared = args[3]
        # args[4], when present, is the hash the caller EXPECTS the file to
        # hold right now -- Codex asked for this. It turns a blind overwrite
        # into a compare-and-set, so a file somebody else changed since the
        # caller read it is refused instead of silently replaced. "-" means
        # the caller expects the file not to exist yet.
        expected_live = args[4] if len(args) >= 5 else None
        grant_json = args[5] if len(args) >= 6 else None

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
            _check_grant(grant_json, target=target, path=path, sha256=declared)

        content = _read_stdin_content(declared)
        if expected_live is not None:
            actual_live = _sha256_of(target, path)
            wanted = "" if expected_live == "-" else str(expected_live).lower()
            if actual_live != wanted:
                raise Refused(
                    "the file is not what the caller last read: expected %s, "
                    "found %s. Somebody or something changed it in between, so "
                    "nothing was written."
                    % (wanted[:12] or "(absent)", actual_live[:12] or "(absent)"))
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
