"""PROX VOX — the client side of the scoped file writer. (SS-1507)

WHY THIS FILE EXISTS. tools/proxvox/server/proxvox_writer.py has been on the
branch for days with nothing able to call it: no code in this package ever
sent it a command. A wrapper nothing can reach is not a cautious wrapper, it
is dead code, and dead code passes every test it has. This is the caller.

WHAT IT DOES NOT DO, and none of these are oversights:

  - It does not decide what may be written. The allowlist lives on the HOST,
    inside the forced command, and `scope()` here only ASKS what that list
    says. A client-side copy of the allowlist would be a list the client could
    be talked into widening.

  - It sends the owner's TOTP response and session ticket only over SSH stdin
    and keeps neither in its audit log or on disk. File grants come from
    WriteClient.mint_file_grant() and are bound to a single operation.

  - It does not install, deploy, sign or publish anything, and it does not
    write to a live SnapSmack site. Sean authorises a host write in his own
    session; nothing here is unattended.

  - It does not retry. A refusal is an answer, and a writer that tries again
    after being refused is a writer arguing with its own safety check.

EVERY CALL AND EVERY REFUSAL IS RECORDED BEFORE IT RUNS, which is Sean's
ruling for the write client and holds here for the same reason: a record
written afterwards is missing exactly the attempts that crashed.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import base64
import hashlib
import json
import shlex
import subprocess

from .broker import Refused

#: The SSH account the host provisioner creates for the forced command. It has
#: no shell: sshd runs `sudo -n /usr/local/sbin/proxvox-writer` and nothing
#: else, so this account cannot be used to log in and poke about.
WRITER_ACCOUNT = "proxvox-root-rw"

#: Matches MAX_WRITE_BYTES in the host wrapper. Checked here as well so a
#: too-large file is refused before it is read and base64-expanded in memory,
#: not after it has crossed the network.
MAX_WRITE_BYTES = 8 * 1024 * 1024

SSH_TIMEOUT_SECONDS = 150
SESSION_OPERATIONS = {
    "authorize-write-session", "check-write-session", "revoke-write-session",
    "preview-maintenance", "issue-maintenance-nonce", "run-maintenance",
}


def _ssh_runner(host, account, identity, command, stdin_bytes, timeout):
    """The real transport. Replaced in tests; the production path is here only."""
    argv = [
        "ssh",
        "-o", "BatchMode=yes",
        "-o", "StrictHostKeyChecking=yes",
        # NO AGENT AND NO AGENT FORWARDING. An agent on this connection would
        # let the host ask this machine to sign for other hosts.
        "-o", "IdentitiesOnly=yes",
        "-o", "ForwardAgent=no",
        "-o", "ConnectTimeout=10",
        "-i", identity,
        "%s@%s" % (account, host),
        command,
    ]
    completed = subprocess.run(
        argv,
        input=stdin_bytes if stdin_bytes is not None else b"",
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        timeout=timeout,
    )
    return completed.returncode, completed.stdout, completed.stderr


class ScopedFileWriter:
    """Sends one operation at a time to the host's forced command."""

    def __init__(self, host, audit, identity, account=WRITER_ACCOUNT,
                 runner=None, timeout=SSH_TIMEOUT_SECONDS):
        self.host = str(host)
        self.audit = audit
        self.identity = str(identity)
        self.account = str(account)
        self._runner = runner or _ssh_runner
        self.timeout = int(timeout)

    # ── the host answers; this only asks ─────────────────────────────────
    def scope(self):
        """What the HOST says may be written. Not a local opinion."""
        return self._send("scope", [], None)

    def authorize_session(self, user, challenge, code):
        return self._session_request("authorize-write-session", user, challenge=challenge, code=code)

    def check_session(self, user, ticket):
        return self._session_request("check-write-session", user, ticket=ticket)

    def revoke_session(self, user, ticket):
        return self._session_request("revoke-write-session", user, ticket=ticket)

    def _session_request(self, op, user, **body):
        return self._send(op, [user], json.dumps(body).encode("utf-8"))

    def preview_maintenance(self, user, ticket, target, action, subject, value=None):
        return self._session_request(
            "preview-maintenance", user, ticket=ticket, target=target,
            action=action, subject=subject, value=value,
        )

    def run_maintenance(self, user, ticket, target, action, subject, expected, value=None):
        plan = dict(ticket=ticket, target=target, action=action,
                    subject=subject, value=value, expected=expected)
        issued = self._session_request("issue-maintenance-nonce", user, **plan)
        nonce = issued.get("write_nonce") if isinstance(issued, dict) else None
        if (not isinstance(nonce, str) or len(nonce) != 64
                or any(digit not in "0123456789abcdef" for digit in nonce)):
            raise Refused("the host did not issue a valid maintenance nonce")
        return self._session_request("run-maintenance", user, nonce=nonce, **plan)

    def put_file_dry(self, target, path, content, expected_live=None):
        """Rehearse. Changes nothing, and deliberately needs no grant.

        A rehearsal that needed an authorisation would mean spending a live
        owner login to find out whether a path is even allowed, so the first
        thing anyone learns would cost Sean a TOTP code.
        """
        return self._put(target, path, content, expected_live, grant=None, dry_run=True)

    def put_file(self, target, path, content, grant, expected_live):
        """Write, with a grant from a fresh owner login.

        `expected_live` IS REQUIRED, and it is required because of what the
        host does with the placeholder. The protocol's `-` does not mean "no
        expectation"; it means "I expect no file here at all". So a live write
        sent without an expectation was arriving as `-` and being refused
        whenever the file already existed -- the common case. I found that by
        driving the real host wrapper, not by reading either file.

        The honest fix is the stricter one rather than adding a neutral token
        to the host: every live write now has to say what it expected to find.
        Pass the hash the file held when it was read, or "-" if it should not
        exist yet. That makes each write a compare-and-set, so two writers
        cannot silently overwrite one another and a file something else
        changed in between is refused instead of replaced. A blind overwrite
        is the thing the check exists to prevent, so there is no way to ask
        for one.
        """
        if expected_live is None or str(expected_live) == "":
            self.audit.record("file_write_refused", {
                "host": self.host, "target": str(target), "path": str(path),
                "why": "no expected_live; a live write must be a compare-and-set",
            })
            raise Refused(
                "a live write must say what it expected to find: the sha256 the "
                "file held when it was read, or '-' if it should not exist yet.")
        if not grant:
            self.audit.record("file_write_refused", {
                "host": self.host, "target": str(target), "path": str(path),
                "why": "no grant; a live write needs a fresh owner login",
            })
            raise Refused(
                "a live write needs a grant from a fresh owner login. Rehearse "
                "with put_file_dry, or authorise and retry.")
        return self._put(target, path, content, expected_live, grant=grant, dry_run=False)

    # ── internals ────────────────────────────────────────────────────────
    def _put(self, target, path, content, expected_live, grant, dry_run):
        if isinstance(content, str):
            content = content.encode("utf-8")
        if not isinstance(content, (bytes, bytearray)):
            raise Refused("the content must be bytes or text.")
        content = bytes(content)
        if len(content) > MAX_WRITE_BYTES:
            self.audit.record("file_write_refused", {
                "host": self.host, "target": str(target), "path": str(path),
                "bytes": len(content),
                "why": "larger than the %d-byte limit" % MAX_WRITE_BYTES,
            })
            raise Refused(
                "the file is %d bytes, over the %d-byte limit."
                % (len(content), MAX_WRITE_BYTES))

        digest = hashlib.sha256(content).hexdigest()
        args = [str(target), str(path), digest]
        # The host issues a single-use nonce for the exact compare-and-set tuple.
        details = {"bytes": len(content), "sha256": digest, "target": str(target),
                   "path": str(path), "dry_run": bool(dry_run)}
        if expected_live is not None:
            args.append(str(expected_live))
        if grant is not None:
            args.append(grant)
            issued = self._send("issue-file-nonce", args, None, log_extra=details)
            nonce = issued.get("write_nonce") if isinstance(issued, dict) else None
            if (not isinstance(nonce, str) or len(nonce) != 64
                    or any(value not in "0123456789abcdef" for value in nonce)):
                raise Refused("the host did not issue a valid write nonce")
            args.append(nonce)

        return self._send(
            "put-file-dry" if dry_run else "put-file",
            args,
            base64.b64encode(content),
            log_extra=details,
        )

    def _send(self, op, args, stdin_bytes, log_extra=None):
        command = " ".join([op] + [shlex.quote(a) for a in args])
        detail = {"host": self.host, "account": self.account, "op": op}
        detail.update(log_extra or {})
        sensitive = op in SESSION_OPERATIONS
        # THE ATTEMPT IS RECORDED FIRST. A grant is never in this record: the
        # fields logged are chosen above, and the command line is not one of
        # them precisely because the grant travels inside it.
        self.audit.record("file_write_attempt", detail)

        try:
            code, out, err = self._runner(
                self.host, self.account, self.identity, command,
                stdin_bytes, self.timeout)
        except subprocess.TimeoutExpired:
            self.audit.record("file_write_failed", dict(detail, why="timed out"))
            raise Refused(
                "the host did not answer within %ds. Nothing is known about "
                "whether it wrote; read the file before trying again."
                % self.timeout)
        except OSError as err:
            reason = type(err).__name__ if sensitive else str(err)[:200]
            self.audit.record("file_write_failed", dict(detail, why=reason))
            raise Refused("could not reach the host: %s" % reason) from err

        text = (out or b"").decode("utf-8", "replace").strip()
        try:
            answer = json.loads(text)
        except Exception:
            # AN UNREADABLE ANSWER IS NOT A SUCCESS. sshd prints its own
            # errors on this channel, and treating anything non-JSON as "fine"
            # is how a permission failure reads as a completed write.
            self.audit.record("file_write_failed", dict(
                detail, why="the host did not answer in the write protocol",
                exit_code=code, stderr="" if sensitive else (err or b"").decode("utf-8", "replace")[:200]))
            if sensitive:
                raise Refused("the host did not answer in the write session protocol (exit %s)" % code)
            raise Refused(
                "the host did not answer in the write protocol (exit %s): %s"
                % (code, (text or (err or b"").decode("utf-8", "replace"))[:200]))

        if not isinstance(answer, dict) or "ok" not in answer:
            self.audit.record("file_write_failed", dict(
                detail, why="the answer is not a write-protocol object", exit_code=code))
            raise Refused("the host's answer is not a write-protocol object.")

        if not answer.get("ok"):
            why = str(answer.get("error", "refused"))
            self.audit.record("file_write_refused", dict(
                detail, why="host refused write session request" if sensitive else why[:300]
            ))
            # The host's own words, not a paraphrase. It knows why it refused
            # and the person reading this needs that sentence, not mine.
            raise Refused(why)

        data = answer.get("data")
        self.audit.record("file_write_done", dict(detail, result={} if sensitive else {
            key: value for key, value in data.items() if key != "write_nonce"
        } if isinstance(data, dict) else {}))
        return data

# ===== SNAPSMACK EOF =====
