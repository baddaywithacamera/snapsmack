"""The scoped PROX VOX file writer refuses everything it was not given.

Sean's go, 2026-11-24 22:43, relayed by the PM, with his question attached:
why did PROX VOX never have working write access? Because the write side was
built as PROXMOX API access and that API cannot put a FILE inside a container,
so file writing never existed and nobody tried it until the backup script
needed changing.

WHAT THESE CHECKS ARE REALLY FOR. The allowlist lives on the HOST, in
server/proxvox_writer.py, delivered root-owned by the provisioner. If it lived
in the desktop client, anyone who reached Sean's desktop could write anywhere
on the host and in every container, because the host would just do as it was
told. So the tests that matter are the REFUSALS, and the one that matters most
is the hash mismatch: a transfer cut short must change nothing at all, rather
than overwrite a working file with a truncated one and leave the backup as the
only thing between that and data loss.

Run:  python -m pytest tools/proxvox/tests/test_proxvox_writer.py -q

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import base64
import hashlib
import importlib.util
import io
import os

import pytest

_HERE = os.path.dirname(os.path.abspath(__file__))
_WRITER = os.path.join(os.path.dirname(_HERE), "server", "proxvox_writer.py")


def _load():
    spec = importlib.util.spec_from_file_location("proxvox_writer_under_test", _WRITER)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


@pytest.fixture()
def w():
    return _load()


def _stdin(monkeypatch, content: bytes):
    """Content arrives base64 on stdin, exactly as the forced command gets it."""
    blob = base64.b64encode(content)

    class _In:
        buffer = io.BytesIO(blob)

    monkeypatch.setattr("sys.stdin", _In())


# ── the allowlist is reportable, so Sean can read it back ────────────────────

def test_scope_reports_the_allowlist_and_says_who_owns_it(w):
    scope = w.dispatch("scope")
    assert scope["allowed"], "an empty allowlist would be a writer with no scope"
    assert any(row["target"] == "host"
               and row["pattern"] == "/usr/local/bin/mariadb_backup.sh"
               for row in scope["allowed"])
    assert "ct101-console-mode-restore" in scope["maintenance"]
    assert "cannot widen" in scope["note"]


def test_the_allowlist_does_not_contain_a_catch_all(w):
    """A pattern like /* or * would make every other check here decorative."""
    for row in w.dispatch("scope")["allowed"]:
        pattern = row["pattern"]
        assert pattern not in {"*", "/*", "/**"}, pattern
        assert pattern.startswith("/"), pattern
        assert len(pattern.strip("/*")) > 3, pattern


# ── refusals. Nothing is written in any of these. ───────────────────────────

@pytest.mark.parametrize("command,because", [
    ("put-file host /etc/shadow " + "0" * 64, "not in the allowlist"),
    ("put-file host /etc/pve/priv/authkey.key " + "0" * 64, "a credential file"),
    ("put-file 101 /var/www/snapsmack.ca/../../etc/passwd " + "0" * 64, "climbs out with .."),
    ("put-file 999 /var/www/snapsmack.ca/x.php " + "0" * 64, "a container not listed"),
    ("put-file host relative/path " + "0" * 64, "a relative path"),
    ("put-file host /usr/local/bin/mariadb_backup.sh notahash", "a malformed hash"),
    ("maint rm-rf-everything", "an unknown maintenance task"),
    ("maint", "a maintenance call with no name"),
    ("exec /bin/sh", "an operation that does not exist"),
    ("", "no operation at all"),
    ("put-file host /usr/local/bin/mariadb_backup.sh", "a put with no declared hash"),
])
def test_it_refuses(w, command, because):
    with pytest.raises(Exception) as caught:
        w.dispatch(command)
    assert str(caught.value), because


def test_never_write_beats_the_allowlist(w, monkeypatch):
    """Belt and braces, and it is the one that survives somebody else's edit.

    If a credential path is ever added to ALLOWED by mistake, NEVER_WRITE has
    to win. Driven by actually adding one, rather than trusting the ordering.
    """
    monkeypatch.setattr(w, "ALLOWED", (("host", "/etc/ssl/private/*"),))
    with pytest.raises(Exception) as caught:
        w.dispatch("put-file host /etc/ssl/private/site.key " + "0" * 64)
    assert "credential" in str(caught.value) or "host-identity" in str(caught.value)


# ── the write itself, on a real temporary file ──────────────────────────────

def _posix_absolute_for_this_platform(w, monkeypatch):
    """Let the WRITE MECHANICS run on a Windows checkout.

    The wrapper runs on Debian, where every real path starts with "/", and
    _absolute() is right to demand that. These write tests need a real file on
    a real filesystem, and on Windows that path starts with a drive letter.

    This swaps ONLY the leading-slash requirement for the platform's own
    notion of absolute, and keeps every other check -- null bytes,
    normalisation and "..". The production rule is NOT relaxed: it is proved
    separately by the refusal tests above, which drive the real _absolute()
    and include both the relative-path and the ".." cases. Written down
    because a test that quietly loosens the thing it tests is worse than no
    test at all.
    """
    real = w._absolute

    def _either(path):
        raw = str(path or "")
        if raw.startswith("/"):
            return real(raw)
        if not os.path.isabs(raw):
            raise w.Refused("a path must be absolute")
        if "\0" in raw:
            raise w.Refused("a path may not contain a null byte")
        posix = raw.replace(os.sep, "/")
        for seg in posix.split("/"):
            if seg == "..":
                raise w.Refused("a path may not contain ..")
        return posix

    monkeypatch.setattr(w, "_absolute", _either)


@pytest.fixture()
def target(tmp_path, w, monkeypatch):
    """A real file on disk, allowlisted for this test only."""
    _posix_absolute_for_this_platform(w, monkeypatch)
    path = tmp_path / "mariadb_backup.sh"
    path.write_bytes(b"#!/bin/sh\noriginal\n")
    monkeypatch.setattr(w, "ALLOWED", (("host", str(path).replace(os.sep, "/")),))
    return path


def _cmd(path, content):
    return "put-file host %s %s" % (
        str(path).replace(os.sep, "/"), hashlib.sha256(content).hexdigest())


def test_a_write_backs_up_the_old_file_and_verifies_the_new_one(w, target, monkeypatch):
    original = target.read_bytes()
    new = b"#!/bin/sh\nrepaired\n"
    _stdin(monkeypatch, new)

    result = w.dispatch(_cmd(target, new))

    assert result["dry_run"] is False
    assert result["verified"] is True
    assert target.read_bytes() == new
    assert result["sha256_before"] == hashlib.sha256(original).hexdigest()
    assert result["sha256_after"] == hashlib.sha256(new).hexdigest()
    # The backup exists and holds what was there before.
    assert result["backup_path"]
    assert open(result["backup_path"], "rb").read() == original


def test_the_result_carries_hashes_and_never_content(w, target, monkeypatch):
    """Sean reads these lines. A path and two hashes prove what landed; the
    file's contents in a log or a room post would be a leak."""
    secret = b"#!/bin/sh\n# token=do-not-log-me\n"
    _stdin(monkeypatch, secret)
    result = w.dispatch(_cmd(target, secret))
    flat = repr(result)
    assert "do-not-log-me" not in flat
    assert result["sha256_after"] in flat
    assert str(len(secret)) in repr(result["bytes"])


def test_a_hash_mismatch_writes_nothing_at_all(w, target, monkeypatch):
    """THE ONE THAT MATTERS. A transfer cut short must not land.

    Without this check the writer would overwrite a working script with a
    truncated one and the backup would be the only thing between that and data
    loss. Verified by content AND by hash, because a file that is the right
    length with the wrong bytes would pass a size check.
    """
    original = target.read_bytes()
    truncated = b"#!/bin/sh\nrepai"
    _stdin(monkeypatch, truncated)

    with pytest.raises(Exception) as caught:
        # Declare the hash of the FULL content while sending the truncated half.
        w.dispatch(_cmd(target, b"#!/bin/sh\nrepaired\n"))

    assert "does not match" in str(caught.value)
    assert target.read_bytes() == original, "the original was modified anyway"
    assert not list(target.parent.glob("*.proxvox-bak-*")), "it backed up before checking"
    assert not list(target.parent.glob("*.proxvox-tmp-*")), "it left a temp file behind"


def test_a_dry_run_changes_nothing_and_says_what_would_happen(w, target, monkeypatch):
    original = target.read_bytes()
    new = b"#!/bin/sh\nrepaired\n"
    _stdin(monkeypatch, new)

    result = w.dispatch("put-file-dry host %s %s"
                        % (str(target).replace(os.sep, "/"),
                           hashlib.sha256(new).hexdigest()))

    assert result["dry_run"] is True
    assert result["would_back_up"] is True
    assert result["sha256_after_declared"] == hashlib.sha256(new).hexdigest()
    assert target.read_bytes() == original
    assert not list(target.parent.glob("*.proxvox-bak-*"))


def test_dry_and_live_are_different_op_names_not_a_flag(w, target, monkeypatch):
    """A mistyped flag must not be able to turn a rehearsal into a write.

    put-file --dry-run would be one typo away from a live write, so the dry
    run has its own name and an unknown trailing word is refused outright.
    """
    new = b"x\n"
    _stdin(monkeypatch, new)
    with pytest.raises(Exception):
        w.dispatch("put-file %s %s --dry-run"
                   % (str(target).replace(os.sep, "/"),
                      hashlib.sha256(new).hexdigest()))
    assert target.read_bytes() != new


def test_writing_a_file_that_does_not_exist_yet_makes_no_backup(w, tmp_path, monkeypatch):
    _posix_absolute_for_this_platform(w, monkeypatch)
    path = tmp_path / "new-file.sh"
    monkeypatch.setattr(w, "ALLOWED", (("host", str(path).replace(os.sep, "/")),))
    new = b"#!/bin/sh\nfresh\n"
    _stdin(monkeypatch, new)

    result = w.dispatch(_cmd(path, new))

    assert result["sha256_before"] == ""
    assert result["backup_path"] == ""
    assert result["verified"] is True
    assert path.read_bytes() == new


def test_content_larger_than_the_cap_is_refused(w, target, monkeypatch):
    monkeypatch.setattr(w, "MAX_WRITE_BYTES", 16)
    original = target.read_bytes()
    _stdin(monkeypatch, b"x" * 64)
    with pytest.raises(Exception) as caught:
        w.dispatch(_cmd(target, b"x" * 64))
    assert "larger than" in str(caught.value)
    assert target.read_bytes() == original


# ── maintenance is a closed set with no caller arguments ────────────────────

def test_maintenance_dry_run_shows_the_exact_command(w):
    result = w.dispatch("maint-dry ct101-console-mode-restore")
    assert result["dry_run"] is True
    assert result["would_run"] == [["/usr/sbin/pct", "set", "101", "--cmode", "tty"]]


def test_every_maintenance_task_is_a_fixed_argument_list(w):
    """No task may take a value from the caller, now or later.

    A task that interpolated anything would be an arbitrary-command hole
    wearing a task's name, so the shape is checked rather than described.
    """
    for name, steps in w.MAINTENANCE.items():
        assert steps, name
        for step in steps:
            assert isinstance(step, list), name
            assert step[0].startswith("/"), "%s: %r is not an absolute binary" % (name, step[0])
            for arg in step:
                assert isinstance(arg, str), name
                assert "%" not in arg and "{" not in arg, \
                    "%s: %r looks interpolated" % (name, arg)


def test_there_is_no_delete_and_no_cloud_operation(w):
    """Sean ruled B2 deletion out. It is not here, and this says so."""
    for op in ["delete", "rm", "unlink", "b2-delete", "b2", "lifecycle", "purge"]:
        with pytest.raises(Exception):
            w.dispatch(op + " anything")
    source = io.open(_WRITER, encoding="utf-8").read()
    assert "b2" not in source.lower().replace("b2 or", "").replace("no b2", "")


def test_the_envelope_is_json_and_a_refusal_is_not_ok(w, monkeypatch, capsys):
    """The client parses this. A refusal must be ok:false, not a traceback."""
    monkeypatch.setenv("SSH_ORIGINAL_COMMAND", "exec /bin/sh")
    code = w.main()
    out = capsys.readouterr().out
    assert code == 2
    import json as _json
    payload = _json.loads(out)
    assert payload["ok"] is False
    assert payload["error"]

# ===== SNAPSMACK EOF =====
