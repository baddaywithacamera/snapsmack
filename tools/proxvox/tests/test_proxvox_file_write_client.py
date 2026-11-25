"""The scoped file writer's CLIENT, driven against the REAL host wrapper.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.

WHY THESE TESTS LOOK LIKE THIS. The host wrapper sat on this branch for days
with nothing in the package able to call it. Its own 55 tests all passed,
because they called dispatch() directly -- so the one thing never checked was
whether any client could form a command it would accept. That is the same
fault as SS-1563 (a renderer with no caller, thirteen mutants dying against
dead code) and the same fault I made in POCKET ALBUM twice in one morning.

So the runner here does not fake the writer. It takes the command line the
REAL ScopedFileWriter builds, puts it in SSH_ORIGINAL_COMMAND, and calls the
REAL proxvox_writer.dispatch() with the client's own base64 on stdin. If the
two ever disagree about the protocol -- argument order, the "-" placeholder,
where the grant sits -- these tests fail. Nothing is asserted about the shape
of a call; the call is made.
"""

import base64
import hashlib
import importlib.util
import io
import json
import os
import shlex
import subprocess
import sys
import time

import pytest

_HERE = os.path.dirname(os.path.abspath(__file__))
_ROOT = os.path.dirname(_HERE)
_WRITER = os.path.join(_ROOT, "server", "proxvox_writer.py")
if _ROOT not in sys.path:
    sys.path.insert(0, _ROOT)

from proxvox.broker import Refused                            # noqa: E402
from proxvox.file_write_client import ScopedFileWriter, MAX_WRITE_BYTES  # noqa: E402


def _load_writer():
    spec = importlib.util.spec_from_file_location("proxvox_writer_e2e", _WRITER)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class FakeAudit:
    """Records what the client logged, in order, so the record can be checked."""

    def __init__(self):
        self.entries = []

    def record(self, action, detail=None):
        self.entries.append((action, dict(detail or {})))
        return {"action": action}

    def actions(self):
        return [action for action, _ in self.entries]

    def detail(self, action):
        for name, detail in self.entries:
            if name == action:
                return detail
        return None

    def blob(self):
        return json.dumps(self.entries, default=str)


@pytest.fixture()
def w(monkeypatch, tmp_path):
    """The real host wrapper, with its host-only paths pointed at tmp_path."""
    module = _load_writer()
    monkeypatch.setattr(module, "BACKUP_DIR", str(tmp_path / "backups").replace(os.sep, "/"))
    monkeypatch.setattr(module, "AUDIT_LOG", str(tmp_path / "audit" / "writer.jsonl").replace(os.sep, "/"))
    # Same documented platform relaxation the writer's own suite uses: the
    # leading-slash rule is swapped for the platform's notion of absolute so
    # the write MECHANICS can run on a Windows checkout. Every other check --
    # null bytes, normalisation, ".." -- is untouched, and the production rule
    # is proved by the writer's own refusal tests against the real _absolute().
    real = module._absolute

    def _either(path):
        raw = str(path or "")
        if raw.startswith("/"):
            return real(raw)
        if not os.path.isabs(raw):
            raise module.Refused("a path must be absolute")
        if "\0" in raw:
            raise module.Refused("a path may not contain a null byte")
        posix = raw.replace(os.sep, "/")
        for seg in posix.split("/"):
            if seg == "..":
                raise module.Refused("a path may not contain ..")
        return posix

    monkeypatch.setattr(module, "_absolute", _either)
    return module


@pytest.fixture()
def host_file(tmp_path, w, monkeypatch):
    path = tmp_path / "index.php"
    path.write_bytes(b"<?php // before\n")
    monkeypatch.setattr(w, "ALLOWED", (("host", str(path).replace(os.sep, "/")),))
    return path


def _posix(path):
    return str(path).replace(os.sep, "/")


def _real_runner(w, monkeypatch, *, ticket_ok=True):
    """Hand the client's own command straight to the real dispatch().

    This is the whole point of the file: no stub writer, no re-implemented
    protocol. Whatever the client sends is what the host parses.
    """
    monkeypatch.setattr(w, "_verify_ticket_against_local_api",
                        lambda t, u: bool(ticket_ok))
    sent = []

    def runner(host, account, identity, command, stdin_bytes, timeout):
        sent.append({"host": host, "account": account, "identity": identity,
                     "command": command, "stdin": stdin_bytes})

        class _In:
            buffer = io.BytesIO(stdin_bytes or b"")

        old_stdin = sys.stdin
        old_env = os.environ.get("SSH_ORIGINAL_COMMAND")
        sys.stdin = _In()
        os.environ["SSH_ORIGINAL_COMMAND"] = command
        try:
            payload = {"ok": True, "data": w.dispatch(command)}
            code = 0
        except Exception as err:                  # the host answers, not raises
            payload = {"ok": False, "error": str(err)[:500]}
            code = 2
        finally:
            sys.stdin = old_stdin
            if old_env is None:
                os.environ.pop("SSH_ORIGINAL_COMMAND", None)
            else:
                os.environ["SSH_ORIGINAL_COMMAND"] = old_env
        return code, (json.dumps(payload) + "\n").encode("utf-8"), b""

    return runner, sent


def _grant(*, target, path, content, user="root@pam", expires_in=120,
           ticket="t" * 32):
    return json.dumps({
        "ticket": ticket, "user": user, "target": target, "path": _posix(path),
        "sha256": hashlib.sha256(content).hexdigest(),
        "expires": time.time() + expires_in,
    })


# ── the client and the host agree about the protocol ────────────────────────

def test_scope_reaches_the_real_host_and_returns_the_hosts_own_list(w, monkeypatch, host_file):
    runner, sent = _real_runner(w, monkeypatch)
    audit = FakeAudit()
    answer = ScopedFileWriter("pve.local", audit, "/key", runner=runner).scope()
    assert sent and sent[0]["command"] == "scope"
    assert sent[0]["account"] == "proxvox-root-rw"
    # The list came from the HOST, and says so.
    assert answer["allowed"] == [{"target": "host", "pattern": _posix(host_file)}]
    assert "cannot widen it" in answer["note"]
    assert audit.actions() == ["file_write_attempt", "file_write_done"]


def test_a_dry_run_rehearses_without_a_grant_and_changes_nothing(w, monkeypatch, host_file):
    runner, sent = _real_runner(w, monkeypatch)
    audit = FakeAudit()
    client = ScopedFileWriter("pve.local", audit, "/key", runner=runner)
    answer = client.put_file_dry("host", _posix(host_file), b"<?php // after\n")
    assert answer["dry_run"] is True
    assert host_file.read_bytes() == b"<?php // before\n"
    # THE ATTEMPT IS LOGGED BEFORE THE CALL, which is Sean's ruling.
    assert audit.actions()[0] == "file_write_attempt"
    assert audit.detail("file_write_attempt")["sha256"] == \
        hashlib.sha256(b"<?php // after\n").hexdigest()


def test_a_live_write_with_a_grant_actually_writes_the_file(w, monkeypatch, host_file):
    runner, sent = _real_runner(w, monkeypatch)
    audit = FakeAudit()
    client = ScopedFileWriter("pve.local", audit, "/key", runner=runner)
    content = b"<?php // after\n"
    grant = _grant(target="host", path=host_file, content=content)
    before = hashlib.sha256(host_file.read_bytes()).hexdigest()

    answer = client.put_file("host", _posix(host_file), content, grant,
                             expected_live=before)

    assert host_file.read_bytes() == content
    # FIELD NAMES READ OFF THE WRITER'S RETURN STATEMENTS. My first draft
    # asserted answer["after"], which the host never returns.
    assert answer["sha256_after"] == hashlib.sha256(content).hexdigest()
    assert answer["verified"] is True
    assert audit.actions() == ["file_write_attempt", "file_write_done"]


def test_the_grant_rides_in_the_slot_the_host_reads_it_from(w, monkeypatch, host_file):
    """The positional protocol, checked by using it rather than by reading it.

    put-file is `target path sha256 [expected] [grant]` and the host reads the
    grant from position six, so the expectation can never be omitted while a
    grant is present -- the grant would land where a hash is read.
    """
    runner, sent = _real_runner(w, monkeypatch)
    content = b"placeholder test\n"
    grant = _grant(target="host", path=host_file, content=content)
    before = hashlib.sha256(host_file.read_bytes()).hexdigest()
    client = ScopedFileWriter("pve.local", FakeAudit(), "/key", runner=runner)

    client.put_file("host", _posix(host_file), content, grant, expected_live=before)

    args = sent[0]["command"].split(" ")
    assert args[0] == "put-file"
    assert args[4] == before, "the expectation must sit in position five"
    assert host_file.read_bytes() == content


def test_a_live_write_must_say_what_it_expected_to_find(w, monkeypatch, host_file):
    """The flaw this file found by driving the REAL host wrapper.

    `-` is not a neutral placeholder: the host reads it as "I expect no file
    here at all". So a live write sent with no expectation was refused
    whenever the file already existed, which is the ordinary case. Neither
    module was wrong when read on its own; they disagreed, and only running
    one against the other showed it. The fix is the stricter reading -- every
    live write is a compare-and-set -- rather than adding a neutral token to
    the host, which would have permitted blind overwrites.
    """
    runner, sent = _real_runner(w, monkeypatch)
    audit = FakeAudit()
    content = b"blind\n"
    grant = _grant(target="host", path=host_file, content=content)
    client = ScopedFileWriter("pve.local", audit, "/key", runner=runner)

    with pytest.raises(Refused, match="what it expected to find"):
        client.put_file("host", _posix(host_file), content, grant, expected_live=None)

    assert sent == [], "nothing should reach the host"
    assert host_file.read_bytes() == b"<?php // before\n"
    assert audit.actions() == ["file_write_refused"]


def test_a_dash_expectation_writes_a_genuinely_new_file(w, monkeypatch, tmp_path):
    """Which is what `-` is actually for, and it still works."""
    runner, _ = _real_runner(w, monkeypatch)
    fresh = tmp_path / "brand-new.php"
    monkeypatch.setattr(w, "ALLOWED", (("host", _posix(fresh)),))
    content = b"<?php // new\n"
    grant = _grant(target="host", path=fresh, content=content)
    client = ScopedFileWriter("pve.local", FakeAudit(), "/key", runner=runner)

    client.put_file("host", _posix(fresh), content, grant, expected_live="-")

    assert fresh.read_bytes() == content


# ── the host's refusals arrive as refusals, in the host's own words ─────────

def test_a_path_outside_the_hosts_allowlist_is_refused_by_the_host(w, monkeypatch, host_file, tmp_path):
    runner, _ = _real_runner(w, monkeypatch)
    other = tmp_path / "not-allowed.php"
    other.write_bytes(b"x")
    audit = FakeAudit()
    client = ScopedFileWriter("pve.local", audit, "/key", runner=runner)

    with pytest.raises(Refused):
        client.put_file_dry("host", _posix(other), b"y")

    assert not other.read_bytes() == b"y"
    assert "file_write_refused" in audit.actions()


def test_a_grant_for_another_path_does_not_authorise_this_one(w, monkeypatch, host_file, tmp_path):
    runner, _ = _real_runner(w, monkeypatch)
    content = b"replayed\n"
    elsewhere = _grant(target="host", path=tmp_path / "somewhere-else.php", content=content)
    client = ScopedFileWriter("pve.local", FakeAudit(), "/key", runner=runner)

    with pytest.raises(Refused, match="different path"):
        client.put_file("host", _posix(host_file), content, elsewhere, expected_live="-")

    assert host_file.read_bytes() == b"<?php // before\n"


def test_a_grant_for_other_bytes_does_not_authorise_these(w, monkeypatch, host_file):
    runner, _ = _real_runner(w, monkeypatch)
    approved = _grant(target="host", path=host_file, content=b"what sean approved\n")
    client = ScopedFileWriter("pve.local", FakeAudit(), "/key", runner=runner)

    with pytest.raises(Refused, match="different content"):
        client.put_file("host", _posix(host_file), b"something else entirely\n", approved, expected_live="-")

    assert host_file.read_bytes() == b"<?php // before\n"


def test_an_expired_grant_is_refused(w, monkeypatch, host_file):
    runner, _ = _real_runner(w, monkeypatch)
    content = b"late\n"
    stale = _grant(target="host", path=host_file, content=content, expires_in=-1)
    client = ScopedFileWriter("pve.local", FakeAudit(), "/key", runner=runner)

    with pytest.raises(Refused, match="expired"):
        client.put_file("host", _posix(host_file), content, stale, expected_live="-")

    assert host_file.read_bytes() == b"<?php // before\n"


def test_a_ticket_the_host_cannot_verify_is_refused(w, monkeypatch, host_file):
    runner, _ = _real_runner(w, monkeypatch, ticket_ok=False)
    content = b"unverified\n"
    grant = _grant(target="host", path=host_file, content=content)
    client = ScopedFileWriter("pve.local", FakeAudit(), "/key", runner=runner)

    with pytest.raises(Refused):
        client.put_file("host", _posix(host_file), content, grant, expected_live="-")

    assert host_file.read_bytes() == b"<?php // before\n"


def test_a_file_changed_since_it_was_read_is_refused(w, monkeypatch, host_file):
    """Compare-and-set, driven end to end: two writers cannot clobber silently."""
    runner, _ = _real_runner(w, monkeypatch)
    content = b"mine\n"
    grant = _grant(target="host", path=host_file, content=content)
    stale_hash = hashlib.sha256(b"what i thought was there\n").hexdigest()
    client = ScopedFileWriter("pve.local", FakeAudit(), "/key", runner=runner)

    with pytest.raises(Refused, match="not what the caller last read"):
        client.put_file("host", _posix(host_file), content, grant,
                        expected_live=stale_hash)

    assert host_file.read_bytes() == b"<?php // before\n"


def test_a_live_write_without_a_grant_never_leaves_the_client(w, monkeypatch, host_file):
    """Refused here, so no unauthorised attempt is even sent to the host."""
    runner, sent = _real_runner(w, monkeypatch)
    audit = FakeAudit()
    client = ScopedFileWriter("pve.local", audit, "/key", runner=runner)

    with pytest.raises(Refused, match="fresh owner login"):
        client.put_file("host", _posix(host_file), b"x", grant=None,
                        expected_live=hashlib.sha256(host_file.read_bytes()).hexdigest())

    assert sent == [], "nothing should have been sent"
    assert audit.actions() == ["file_write_refused"]


# ── the record, and what must never be in it ───────────────────────────────

def test_the_clients_audit_never_contains_the_ticket(w, monkeypatch, host_file):
    runner, _ = _real_runner(w, monkeypatch)
    content = b"secret-bearing write\n"
    grant = _grant(target="host", path=host_file, content=content,
                   ticket="PVE:SUPER-SECRET-TICKET-VALUE")
    audit = FakeAudit()
    client = ScopedFileWriter("pve.local", audit, "/key", runner=runner)

    client.put_file("host", _posix(host_file), content, grant,
                    expected_live=hashlib.sha256(host_file.read_bytes()).hexdigest())

    assert "SUPER-SECRET" not in audit.blob()
    assert "PVE:" not in audit.blob()


def test_the_hosts_own_log_records_the_attempt_and_never_the_ticket(w, monkeypatch, host_file, tmp_path):
    """The host keeps its own record, because the client's copy can vanish."""
    content = b"audited\n"
    grant = _grant(target="host", path=host_file, content=content,
                   ticket="PVE:SUPER-SECRET-TICKET-VALUE")
    monkeypatch.setattr(w, "_verify_ticket_against_local_api", lambda t, u: True)

    # QUOTED, because the grant is JSON and contains spaces. Unquoted, shlex
    # splits it into a dozen tokens and the host answers "operation is not in
    # the write protocol" -- which is what the first version of this test hit,
    # and it is exactly why the client shlex.quote()s every argument.
    command = "put-file host %s %s %s %s" % (
        shlex.quote(_posix(host_file)),
        hashlib.sha256(content).hexdigest(),
        hashlib.sha256(host_file.read_bytes()).hexdigest(),
        shlex.quote(grant))

    class _In:
        buffer = io.BytesIO(base64.b64encode(content))

    monkeypatch.setattr("sys.stdin", _In())
    monkeypatch.setenv("SSH_ORIGINAL_COMMAND", command)
    assert w.main() == 0

    log = (tmp_path / "audit" / "writer.jsonl").read_text(encoding="utf-8")
    lines = [json.loads(line) for line in log.strip().splitlines()]
    assert [entry["event"] for entry in lines] == ["attempt", "done"]
    assert "SUPER-SECRET" not in log and "PVE:" not in log
    assert "(grant presented, not logged)" in lines[0]["detail"]["command"]
    # One JSON object per line, so the log stays readable after many writes.
    assert all(line.strip().startswith("{") for line in log.strip().splitlines())


def test_the_hosts_log_separates_a_refusal_from_a_fault(w, monkeypatch, tmp_path):
    monkeypatch.setenv("SSH_ORIGINAL_COMMAND", "nonsense-operation")
    assert w.main() == 2
    log = (tmp_path / "audit" / "writer.jsonl").read_text(encoding="utf-8")
    events = [json.loads(line)["event"] for line in log.strip().splitlines()]
    assert events == ["attempt", "refused"], \
        "a refusal is the wrapper working; it must not be logged as a fault"


def test_an_audit_that_cannot_be_written_does_not_block_the_answer(w, monkeypatch, host_file):
    """A full disk must not become a denial of service on Sean's own server."""
    monkeypatch.setattr(w, "AUDIT_LOG", "/proc/definitely-not-writable/x.jsonl")
    monkeypatch.setenv("SSH_ORIGINAL_COMMAND", "scope")
    assert w.main() == 0


# ── the transport's own failures are never read as success ─────────────────

def test_a_non_protocol_answer_is_not_treated_as_a_completed_write(host_file):
    """sshd prints its own errors on this channel. Those are not successes."""
    def runner(*args, **kwargs):
        return 255, b"", b"Permission denied (publickey).\n"

    audit = FakeAudit()
    client = ScopedFileWriter("pve.local", audit, "/key", runner=runner)
    with pytest.raises(Refused, match="write protocol"):
        client.put_file_dry("host", _posix(host_file), b"x")
    assert "file_write_failed" in audit.actions()


def test_a_timeout_says_the_outcome_is_unknown_rather_than_failed(host_file):
    def runner(*args, **kwargs):
        raise subprocess.TimeoutExpired(cmd="ssh", timeout=150)

    client = ScopedFileWriter("pve.local", FakeAudit(), "/key", runner=runner)
    with pytest.raises(Refused, match="Nothing is known"):
        client.put_file_dry("host", _posix(host_file), b"x")


def test_an_oversized_file_is_refused_before_it_crosses_the_network(host_file):
    sent = []

    def runner(*args, **kwargs):
        sent.append(args)
        return 0, b'{"ok":true,"data":{}}', b""

    client = ScopedFileWriter("pve.local", FakeAudit(), "/key", runner=runner)
    with pytest.raises(Refused, match="over the"):
        client.put_file_dry("host", _posix(host_file), b"x" * (MAX_WRITE_BYTES + 1))
    assert sent == []


def test_the_real_transport_forbids_agent_forwarding_and_password_auth():
    """Read the shipped argv, since the production path cannot be exercised here."""
    import inspect

    from proxvox import file_write_client

    source = inspect.getsource(file_write_client._ssh_runner)
    for needed in ('"BatchMode=yes"', '"StrictHostKeyChecking=yes"',
                   '"IdentitiesOnly=yes"', '"ForwardAgent=no"'):
        assert needed in source, needed
    # NOT CHECKED by execution: this one test reads source, because running
    # real ssh needs the provisioned host. It is the only check here that
    # does, and it is marked so nobody mistakes it for an executed one.

# ===== SNAPSMACK EOF =====
