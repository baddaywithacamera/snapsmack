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
    # EVERY HOST PATH THE WRAPPER WRITES TO, redirected in one place. Named
    # one at a time, this fixture missed SPENT_GRANTS when it was added and
    # put C:/var/lib/proxvox on Sean's drive -- the second time I have
    # scattered a directory there. The loop is the version of the rule that
    # survives me forgetting; test_proxvox_writer.py carries a guard that
    # fails when a new absolute path is added and not listed.
    for name, leaf in (("BACKUP_DIR", "backups"),
                       ("SPENT_GRANTS", "spent-grants"),
                       ("WRITE_NONCES", "nonce-store/write-nonces"),
                       ("WRITE_SESSIONS", "session-store/write-sessions"),
                       ("AUDIT_LOG", "audit/writer.jsonl")):
        monkeypatch.setattr(module, name, str(tmp_path / leaf).replace(os.sep, "/"))
    monkeypatch.setattr(module, "_check_write_session", lambda user, ticket: time.time() + 3600)
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


def _with_nonce(w, command):
    nonce = w.dispatch(command.replace("put-file ", "issue-file-nonce ", 1))["write_nonce"]
    return command + " " + nonce


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
    assert audit.actions() == ["file_write_attempt", "file_write_done"] * 2


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

    assert [shlex.split(call["command"])[0] for call in sent] == ["issue-file-nonce", "put-file"]
    args = shlex.split(sent[1]["command"])
    assert args[5] == grant
    assert len(args[6]) == 64
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


# ── the host enforces the compare-and-set, not only the client ─────────────

def _blind_argv(w, monkeypatch, host_file, content, tail):
    """A live put-file command, with whatever tail the caller wants to try."""
    monkeypatch.setattr(w, "_verify_ticket_against_local_api", lambda t, u: True)

    class _In:
        buffer = io.BytesIO(base64.b64encode(content))

    monkeypatch.setattr("sys.stdin", _In())
    argv = ["put-file", "host", _posix(host_file),
            hashlib.sha256(content).hexdigest()] + list(tail)
    return " ".join(shlex.quote(a) for a in argv)


def test_every_live_shape_without_an_expectation_is_refused(w, monkeypatch, host_file):
    """Every live put-file shape that omits an expectation, against dispatch().

    Muse's finding (a) said a four-argument put-file let a direct SSH caller
    blind-overwrite with a valid grant. I probed every shape the protocol
    allows at aa169f80 before agreeing, and it did NOT: a live write needs a
    grant, grant_json is read from args[5], so args[4] is necessarily
    occupied. The guarantee was real but ACCIDENTAL -- a consequence of
    argument positions that nothing stated and nothing tested, which one
    reordered argument would have removed in silence. These pin it.
    """
    content = b"blind overwrite\n"
    original = host_file.read_bytes()
    grant = _grant(target="host", path=host_file, content=content)

    for tail, why in [
        ([], "four arguments: no expectation and no grant"),
        ([grant], "five arguments: the grant in the expectation slot"),
        (["", grant], "six arguments, but the expectation says nothing"),
    ]:
        host_file.write_bytes(original)
        command = _blind_argv(w, monkeypatch, host_file, content, tail)
        with pytest.raises(Exception) as caught:
            w.dispatch(command)
        message = str(caught.value)
        assert ("grant" in message) or ("expects" in message), (why, message)
        assert host_file.read_bytes() == original, why


def test_a_dry_run_still_needs_no_expectation(w, monkeypatch, host_file):
    """The exemption, stated: you must be able to rehearse to LEARN the hash.

    Demanding an expectation in order to rehearse would mean reading the file
    before you are allowed to ask whether you may read it.
    """
    class _In:
        buffer = io.BytesIO(base64.b64encode(b"x"))

    monkeypatch.setattr("sys.stdin", _In())
    answer = w.dispatch("put-file-dry host %s %s" % (
        shlex.quote(_posix(host_file)), hashlib.sha256(b"x").hexdigest()))
    assert answer["dry_run"] is True
    assert host_file.read_bytes() == b"<?php // before\n"


def test_the_legitimate_write_still_goes_through(w, monkeypatch, host_file):
    """The control. Without it the three refusals above prove nothing."""
    content = b"legitimate\n"
    grant = _grant(target="host", path=host_file, content=content)
    command = _blind_argv(w, monkeypatch, host_file, content,
                          [hashlib.sha256(host_file.read_bytes()).hexdigest(), grant])
    answer = w.dispatch(_with_nonce(w, command))
    assert answer["verified"] is True
    assert host_file.read_bytes() == content


def test_host_nonce_rejects_missing_replayed_and_changed_operations(w, monkeypatch, host_file):
    original = host_file.read_bytes()
    content = b"fresh bytes"
    live = hashlib.sha256(original).hexdigest()
    grant = _grant(target="host", path=host_file, content=content)
    command = _blind_argv(w, monkeypatch, host_file, content, [live, grant])
    with pytest.raises(w.Refused, match="nonce is invalid"):
        w.dispatch(command)
    authorized = _with_nonce(w, command)
    with pytest.raises(w.Refused, match="different operation"):
        w.dispatch(authorized.replace(live, "f" * 64))
    assert host_file.read_bytes() == original
    assert w.dispatch(authorized)["verified"] is True
    with pytest.raises(w.Refused, match="already used"):
        w.dispatch(authorized)


def test_host_nonce_expires_and_has_one_atomic_consumer(w):
    from concurrent.futures import ThreadPoolExecutor

    expired = w._issue_write_nonce("operation", expires=102, now=100)
    with pytest.raises(w.Refused, match="expired"):
        w._consume_write_nonce(expired, "operation", now=102)
    nonce = w._issue_write_nonce("operation", expires=200, now=100)

    def consume():
        try:
            w._consume_write_nonce(nonce, "operation", now=101)
            return True
        except w.Refused:
            return False

    with ThreadPoolExecutor(max_workers=4) as executor:
        assert sum(executor.map(lambda _index: consume(), range(4))) == 1


def test_reaching_dispatch_directly_still_leaves_a_host_record(w, monkeypatch, tmp_path):
    """Muse's caveat: _audit ran only from main(), so this route left nothing.

    The host's log is meant to be the copy that cannot be lost, and a log with
    a documented way around it is not that.
    """
    try:
        w.dispatch("nonsense-operation")
    except Exception:
        pass
    log = (tmp_path / "audit" / "writer.jsonl").read_text(encoding="utf-8")
    events = [json.loads(line)["event"] for line in log.strip().splitlines()]
    assert events == ["attempt"], events


# ── a grant is one-use, and its expiry must be a number ───────────────────

def test_a_nan_expiry_is_refused(w, monkeypatch, host_file):
    """Codex's finding on cd1b963f, and it was worse than reported.

    json.loads accepts the bare literal NaN, and NaN fails EVERY comparison:
    nan <= moment is False, so it was not expired, and nan - moment > cap is
    False, so it was not too long. It passed both gates -- a never-expiring
    authorisation written by the caller. Two gates that both answer "no
    objection" to something that is not a number are not two gates.
    """
    monkeypatch.setattr(w, "_verify_ticket_against_local_api", lambda t, u: True)
    grant = ('{"ticket":"' + "t" * 40 + '","user":"root@pam","target":"host",'
             '"path":"/x","sha256":"' + "a" * 64 + '","expires":NaN}')
    with pytest.raises(w.Refused, match="finite"):
        w._check_grant(grant, target="host", path="/x", sha256="a" * 64)


def test_an_infinite_expiry_is_refused_as_a_refusal_not_a_crash(w, monkeypatch):
    """Infinity did not pass, but it raised OverflowError inside the message.

    main() reports that as a FAULT, so it failed closed by accident and the
    audit recorded the wrong kind of event. A refusal is the wrapper working;
    a fault is the wrapper broken, and the log must not confuse them.
    """
    monkeypatch.setattr(w, "_verify_ticket_against_local_api", lambda t, u: True)
    for literal in ("Infinity", "-Infinity"):
        grant = ('{"ticket":"' + "t" * 40 + '","user":"root@pam","target":"host",'
                 '"path":"/x","sha256":"' + "a" * 64 + '","expires":' + literal + '}')
        with pytest.raises(w.Refused):
            w._check_grant(grant, target="host", path="/x", sha256="a" * 64)


def test_a_grant_works_once_and_then_does_not(w, monkeypatch, host_file):
    """ONE USE, NOT ONE WINDOW. Codex's finding, and the improvement I had
    already named in GRANT-DESIGN.md as worth doing.

    Without this the host remembers nothing, so the same grant can be replayed
    for as long as it lives. The write is idempotent, so a replay rewrites
    identical bytes -- a hardening rather than a breach -- but a credential
    that works twice is not a one-shot authorisation, and the design rests on
    it being one.
    """
    content = b"written once\n"
    grant = _grant(target="host", path=host_file, content=content)
    runner, _ = _real_runner(w, monkeypatch)
    client = ScopedFileWriter("pve.local", FakeAudit(), "/key", runner=runner)
    before = hashlib.sha256(host_file.read_bytes()).hexdigest()

    client.put_file("host", _posix(host_file), content, grant, expected_live=before)
    assert host_file.read_bytes() == content

    # The same grant again, for the same file and the same bytes.
    with pytest.raises(Refused, match="already been used"):
        client.put_file("host", _posix(host_file), content, grant,
                        expected_live=hashlib.sha256(content).hexdigest())


def test_two_writes_cannot_both_spend_one_grant(w, tmp_path):
    """The marker IS the lock: O_CREAT|O_EXCL, so there is no read-then-write
    window in which two callers could both decide the grant was unspent."""
    identity = w._grant_identity("root@pam", "host", "/x", "a" * 64, time.time() + 120)
    expires = time.time() + 120
    assert w._spend_grant(identity, expires)
    with pytest.raises(w.Refused, match="already been used"):
        w._spend_grant(identity, expires)


def test_one_authorisation_spelled_differently_is_still_one_grant(w, tmp_path):
    """THE FLAW CODEX FOUND, pinned.

    The first version hashed the grant's JSON TEXT, so it identified a
    representation rather than an authorisation. Measured at the time: one
    logical grant spelled five ways -- keys sorted, default separators, a
    trailing space, a harmless extra key -- produced FIVE different digests.
    One-use was defeated by adding a space, and I had told the room grants
    were one-use when they were not.

    The identity is now the five things the host enforces, so none of the
    spelling reaches it.
    """
    expires = 1795628046.0
    spellings = [
        w._grant_identity("root@pam", "host", "/var/www/x.php", "a" * 64, expires),
        w._grant_identity("root@pam", "host", "/var/www/x.php", "A" * 64, expires),
        w._grant_identity("root@pam", "host", "/var/www/x.php", "a" * 64, 1795628046),
    ]
    assert len(set(spellings)) == 1, spellings

    # And a different authorisation is still a different grant.
    for changed in (
        w._grant_identity("sean@pam", "host", "/var/www/x.php", "a" * 64, expires),
        w._grant_identity("root@pam", "101", "/var/www/x.php", "a" * 64, expires),
        w._grant_identity("root@pam", "host", "/var/www/other.php", "a" * 64, expires),
        w._grant_identity("root@pam", "host", "/var/www/x.php", "b" * 64, expires),
        w._grant_identity("root@pam", "host", "/var/www/x.php", "a" * 64, expires + 1),
    ):
        assert changed != spellings[0]


def test_no_field_can_impersonate_the_separator(w):
    """Length-prefixed fields, so one value cannot shift the meaning of the
    next. Without the prefix, ("ab", "c") and ("a", "bc") could collide."""
    a = w._grant_identity("root@pam", "host", "/a", "b" * 64, 1.0)
    b = w._grant_identity("root@pam", "host", "/a\0" + "b" * 64, "c" * 64, 1.0)
    assert a != b


def test_a_fresh_login_for_the_same_file_is_not_refused_as_a_replay(w, tmp_path):
    """A NEW authorisation for the same write must still work.

    The ticket is deliberately not part of the identity -- it is a live
    credential and must not be hashed into a filename -- so what distinguishes
    a fresh login is its new expiry. If that were not true, Sean could write a
    file once and then be locked out of correcting it.
    """
    first = w._grant_identity("root@pam", "host", "/x", "a" * 64, 1795628046.0)
    second = w._grant_identity("root@pam", "host", "/x", "a" * 64, 1795628200.0)
    w._spend_grant(first, 1795628046.0)
    assert w._spend_grant(second, 1795628200.0)


def test_a_grant_that_cannot_be_recorded_refuses_the_write(w, monkeypatch, tmp_path):
    """FAIL CLOSED. Unable to remember a grant is unable to promise one use,
    and the promise is the whole point of the marker.

    THE UNWRITABLE LOCATION IS A FILE USED AS A PARENT DIRECTORY, which fails
    on Linux and on Windows alike. My first version pointed at
    /proc/definitely-not-writable -- unwritable on the host, but on a Windows
    checkout just an ordinary relative path that os.makedirs created as
    C:/proc without complaint. So the test asserted nothing AND scattered a
    folder on Sean's drive.
    """
    blocker = tmp_path / "not-a-directory"
    blocker.write_bytes(b"x")
    monkeypatch.setattr(w, "SPENT_GRANTS", str(blocker / "spent").replace(os.sep, "/"))
    identity = w._grant_identity("root@pam", "host", "/x", "a" * 64, time.time() + 120)
    with pytest.raises(w.Refused, match="cannot record the grant"):
        w._spend_grant(identity, time.time() + 120)


def test_the_spent_marker_never_contains_the_grant(w, tmp_path):
    """A directory of spent grants must not become a directory of usable
    Proxmox tickets. The marker holds a digest in its NAME and nothing in its
    body, and the ticket is not an input to that digest at all."""
    ticket = "PVE:SUPER-SECRET-TICKET"
    identity = w._grant_identity("root@pam", "host", "/x", "a" * 64, time.time() + 120)
    assert ticket not in identity
    w._spend_grant(identity, time.time() + 120)
    for name in os.listdir(w.SPENT_GRANTS):
        assert "SUPER-SECRET" not in name and "PVE" not in name
        assert "/x" not in name and "root@pam" not in name
        with open(os.path.join(w.SPENT_GRANTS, name), "rb") as handle:
            assert handle.read() == b""


def test_a_malformed_identity_is_refused_rather_than_written(w, tmp_path):
    """_spend_grant takes a digest, not a grant. If a caller ever hands it
    something else -- which is how the flawed version was wired -- it refuses
    instead of creating a marker nothing will ever match."""
    for bad in ("", "not-a-digest", "a" * 63, "g" * 64, '{"user":"root@pam"}'):
        with pytest.raises(w.Refused, match="not a digest"):
            w._spend_grant(bad, time.time() + 120)


# ── the compare sits next to the replace, not at the start of the request ──

def test_a_change_after_the_early_check_is_still_caught(w, monkeypatch, host_file):
    """Codex's finding: the expectation was checked in dispatch(), then the
    content was read from stdin and the write happened later, so everything
    in between was a window.

    This simulates something editing the file DURING that window: the early
    check passes, then the file changes, and the write must still refuse. The
    only thing that can catch it is the comparison next to the replacement.
    """
    original = host_file.read_bytes()
    expected = hashlib.sha256(original).hexdigest()
    content = b"my write\n"
    grant = _grant(target="host", path=host_file, content=content)
    monkeypatch.setattr(w, "_verify_ticket_against_local_api", lambda t, u: True)

    # Something else writes to the file at the moment the content is read --
    # which is after dispatch()'s early check has already passed.
    real_reader = w._read_stdin_content

    def read_then_meddle(declared):
        payload = real_reader(declared)
        host_file.write_bytes(b"somebody else got here first\n")
        return payload

    monkeypatch.setattr(w, "_read_stdin_content", read_then_meddle)

    class _In:
        buffer = io.BytesIO(base64.b64encode(content))

    monkeypatch.setattr("sys.stdin", _In())
    argv = ["put-file", "host", _posix(host_file),
            hashlib.sha256(content).hexdigest(), expected, grant]

    with pytest.raises(Exception, match="not what the caller last read"):
        w.dispatch(_with_nonce(w, " ".join(shlex.quote(a) for a in argv)))

    # The other writer's bytes survive; mine are not there.
    assert host_file.read_bytes() == b"somebody else got here first\n"


def test_the_refused_write_leaves_no_temp_file_behind(w, monkeypatch, host_file, tmp_path):
    """A refusal next to the rename happens after the temp file exists, so the
    temp file has to go. Otherwise every lost race leaves a .proxvox-tmp-NNN
    beside a file in a web root."""
    original = host_file.read_bytes()
    expected = hashlib.sha256(original).hexdigest()
    content = b"mine\n"
    grant = _grant(target="host", path=host_file, content=content)
    monkeypatch.setattr(w, "_verify_ticket_against_local_api", lambda t, u: True)
    real_reader = w._read_stdin_content

    def read_then_meddle(declared):
        payload = real_reader(declared)
        host_file.write_bytes(b"changed\n")
        return payload

    monkeypatch.setattr(w, "_read_stdin_content", read_then_meddle)

    class _In:
        buffer = io.BytesIO(base64.b64encode(content))

    monkeypatch.setattr("sys.stdin", _In())
    argv = ["put-file", "host", _posix(host_file),
            hashlib.sha256(content).hexdigest(), expected, grant]
    with pytest.raises(Exception):
        w.dispatch(_with_nonce(w, " ".join(shlex.quote(a) for a in argv)))

    leftovers = [p.name for p in tmp_path.iterdir() if "proxvox-tmp" in p.name]
    assert leftovers == [], leftovers


def test_the_container_replacement_uses_no_shell(w, monkeypatch, tmp_path):
    """The invariant my first fix broke, asserted from this side too.

    I wrote the compare-and-rename as a single /bin/sh -c, which is what the
    reviewer asked for and which this repo's own test forbids: every container
    call is an argument list, because a shell makes a path an injection
    surface. Pinned here as well so the next person reaching for a shell finds
    two tests rather than one.
    """
    source = open(os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))),
                               "server", "proxvox_writer.py"), encoding="utf-8").read()
    container_block = source[source.index("def _put_container"):]
    container_block = container_block[:container_block.index("\ndef ")]
    for forbidden in ('"/bin/sh"', "'/bin/sh'", '"-c"', "bash"):
        assert forbidden not in container_block, forbidden


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
    command = _with_nonce(w, command)
    monkeypatch.setenv("SSH_ORIGINAL_COMMAND", command)
    assert w.main() == 0

    log = (tmp_path / "audit" / "writer.jsonl").read_text(encoding="utf-8")
    lines = [json.loads(line) for line in log.strip().splitlines()]
    assert [entry["event"] for entry in lines] == ["attempt", "attempt", "done"]
    assert "SUPER-SECRET" not in log and "PVE:" not in log
    assert shlex.split(command)[-1] not in log
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


def test_an_audit_that_cannot_be_written_does_not_block_the_answer(w, monkeypatch, host_file, tmp_path):
    """A full disk must not become a denial of service on Sean's own server."""
    # Same correction as the spent-grant test below: /proc is unwritable on
    # the host but an ordinary new folder on a Windows checkout, so this wrote
    # C:/proc/definitely-not-writable/x.jsonl on Sean's drive every run since
    # aa169f80 and I did not notice. A FILE used as a parent fails on both.
    blocker = tmp_path / "audit-blocker"
    blocker.write_bytes(b"x")
    monkeypatch.setattr(w, "AUDIT_LOG", str(blocker / "x.jsonl").replace(os.sep, "/"))
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

def test_session_transport_keeps_secrets_out_of_commands_and_client_audit():
    challenge, code, ticket, csrf = ("PVE:!tfa!fixture", "012345", "fixture-ticket", "fixture-csrf")
    calls = []
    audit = FakeAudit()

    def runner(host, account, identity, command, stdin, timeout):
        calls.append((command, json.loads(stdin)))
        data = {"ticket": ticket, "csrf": csrf, "expires_at": 10000}
        if command.startswith("revoke-write-session"):
            data = {"revoked": True}
        return 0, json.dumps({"ok": True, "data": data}).encode(), b""

    writer = ScopedFileWriter("fixture", audit, "fixture-key", runner=runner)
    assert writer.authorize_session("root@pam", challenge, code)["ticket"] == ticket
    assert writer.revoke_session("root@pam", ticket) == {"revoked": True}
    assert calls == [
        ("authorize-write-session root@pam", {"challenge": challenge, "code": code}),
        ("revoke-write-session root@pam", {"ticket": ticket}),
    ]
    assert all(secret not in str(audit.entries) for secret in (challenge, code, ticket, csrf))


# ===== SNAPSMACK EOF =====
