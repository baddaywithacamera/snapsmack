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
import json
import os
import re
import shlex
import time

import pytest

_HERE = os.path.dirname(os.path.abspath(__file__))
_WRITER = os.path.join(os.path.dirname(_HERE), "server", "proxvox_writer.py")


def _load():
    spec = importlib.util.spec_from_file_location("proxvox_writer_under_test", _WRITER)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


@pytest.fixture()
def w(tmp_path):
    """The wrapper, with EVERY host path it writes to redirected into tmp_path.

    THIS FIXTURE EXISTS BECAUSE I SCATTERED DIRECTORIES ON SEAN'S DRIVE TWICE.
    The first time it was C:\\var\\backups, from BACKUP_DIR; the fix was to
    override that one constant in the fixtures that needed it. That fix was
    too narrow, and the second time proved it: moving the audit call into
    dispatch() meant every test in this file began writing to C:\\var\\log,
    and adding the spent-grant marker added C:\\var\\lib. Neither needed a new
    test to go wrong -- they went wrong in tests that already passed.

    Overriding them one at a time, in the fixtures that happen to need them,
    is a rule I have to remember on every future change. Redirecting all of
    them here is a rule the code keeps. Sean's drive-hygiene rule is that
    nothing scatters outside the project, and this is the only version of
    that which survives me forgetting.

    Any NEW absolute host path added to the wrapper must be added here too,
    and the test below fails if one is not.
    """
    module = _load()
    for name, leaf in (("BACKUP_DIR", "backups"),
                       ("SPENT_GRANTS", "spent-grants"),
                       ("WRITE_NONCES", "nonce-store/write-nonces"),
                       ("WRITE_SESSIONS", "session-store/write-sessions"),
                       ("AUDIT_LOG", "audit/writer.jsonl"),
                       ("MAINTENANCE_LOCKS", "run/maintenance")):
        setattr(module, name, str(tmp_path / leaf).replace(os.sep, "/"))
    module._check_write_session = lambda user, ticket: time.time() + 3600
    return module


def test_no_host_path_escapes_the_test_directory(w, tmp_path):
    """Every absolute host path in the wrapper is redirected by the fixture.

    A guard, not a check of behaviour: it is what makes the fixture above a
    rule rather than a habit. Add a constant that writes somewhere absolute
    and forget to redirect it, and this fails here instead of appearing as a
    folder on Sean's drive.
    """
    source = open(_WRITER, encoding="utf-8").read()
    declared = set(re.findall(r'^([A-Z][A-Z_]*)\s*=\s*"(/[^"]*)"', source, re.MULTILINE))
    for name, value in sorted(declared):
        # PVE_CA_* are READ, never written, and must stay the real host paths.
        if name.startswith("PVE_CA"):
            continue
        current = str(getattr(w, name, ""))
        assert str(tmp_path).replace(os.sep, "/") in current, (
            "%s is still the host path %s; redirect it in the w() fixture"
            % (name, value))


def _stdin(monkeypatch, content: bytes):
    """Content arrives base64 on stdin, exactly as the forced command gets it."""
    blob = base64.b64encode(content)

    class _In:
        buffer = io.BytesIO(blob)

    monkeypatch.setattr("sys.stdin", _In())


# ── the allowlist is reportable, so Sean can read it back ────────────────────

def test_every_allowlisted_path_was_measured_not_guessed(w):
    """The list holds exactly one entry and it came from a live read.

    The history is the reason this test exists. I first shipped four entries:
    mariadb_backup.sh, which the PM ruled OUT as a root-executed script, and
    three CT102/CT101 patterns I GUESSED. The provisioner had already measured
    CT102 serving from /var/www/sites, so my /var/www/*/public_html/* matched
    nothing real, and CT101 appeared in no provisioner at all.

    Codex then ran the read-only inventory on CT101 and found the Apache
    DocumentRoot. THAT is where this entry comes from.
    """
    scope = w.dispatch("scope")
    assert [row["pattern"] for row in scope["allowed"]] == [
        "/var/www/snapsmack.ca/index.php"]
    assert scope["allowed"][0]["target"] == "101"
    assert "Without a live owner session" in scope["note"]
    flat = " ".join(row["pattern"] for row in scope["allowed"])
    assert "mariadb_backup" not in flat
    assert "public_html" not in flat and "htdocs" not in flat


def test_the_allowlisted_entry_is_one_file_and_not_the_web_root(w):
    """The approved use is one section of one page, so the entry is the file.
    A whole document root would grant far more than the job needs."""
    scope = w.dispatch("scope")
    pattern = scope["allowed"][0]["pattern"]
    assert not pattern.endswith("*"), "the entry is a tree, not a file"
    for other in ("/var/www/snapsmack.ca/wp-config.php",
                  "/var/www/snapsmack.ca/sub/page.php",
                  "/var/www/snapsmack.ca/index.php.bak"):
        with pytest.raises(Exception) as caught:
            w.dispatch("put-file 101 %s %s" % (other, "0" * 64))
        assert str(caught.value), other


def test_everything_outside_the_one_entry_is_refused(w):
    """Otherwise "one narrow entry" is a claim about a constant."""
    for where, path in (("host", "/usr/local/bin/mariadb_backup.sh"),
                        ("102", "/var/www/sites/example/index.php"),
                        ("106", "/var/www/example/index.php"),
                        ("101", "/var/www/other-site/index.php")):
        with pytest.raises(Exception) as caught:
            w.dispatch("put-file %s %s %s" % (where, path, "0" * 64))
        assert "not in the allowlist" in str(caught.value)


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
    # BACKUP_DIR IS AN ABSOLUTE HOST PATH (/var/backups/proxvox). Left alone,
    # these tests would create C:arackups on a Windows checkout -- which
    # mine did once, and Sean's drive-hygiene rule is that nothing scatters
    # outside the project. Pointed at the per-test temp directory instead.
    monkeypatch.setattr(w, "BACKUP_DIR", str(tmp_path / "backups").replace(os.sep, "/"))
    path = tmp_path / "mariadb_backup.sh"
    path.write_bytes(b"#!/bin/sh\noriginal\n")
    monkeypatch.setattr(w, "ALLOWED", (("host", str(path).replace(os.sep, "/")),))
    return path


def _grant(w, monkeypatch, *, target, path, sha256, user="root@pam",
           expires_in=120, ticket="x" * 32, valid=True):
    """A grant as the desktop client would hand one over.

    The Proxmox API verification is injected: these tests must never need a
    Proxmox box, and the point of the grant is that the HOST checks it, not
    that the client asserts it.
    """
    monkeypatch.setattr(w, "_verify_ticket_against_local_api",
                        lambda t, u: bool(valid))
    return json.dumps({
        "ticket": ticket, "user": user, "target": target,
        "path": str(path).replace(os.sep, "/"), "sha256": sha256,
        "expires": time.time() + expires_in,
    })


def _cmd(path, content, *, w=None, monkeypatch=None, expected_live=None,
         grant=None):
    """The put-file command line.

    With w and monkeypatch it mints a matching grant, which is what a LIVE
    write now needs: the first version authorised by SSH account alone, which
    was Codex's P0.
    """
    posix = str(path).replace(os.sep, "/")
    sha = hashlib.sha256(content).hexdigest()
    base = "put-file host %s %s" % (posix, sha)
    if w is None:
        return base
    if expected_live is None:
        # Default to what the file actually holds now, which is what a caller
        # that has just read it would declare. "-" means "expect it absent".
        if os.path.exists(str(path)):
            live = hashlib.sha256(open(str(path), "rb").read()).hexdigest()
        else:
            live = "-"
    else:
        live = expected_live
    if grant is None:
        grant = _grant(w, monkeypatch, target="host", path=posix, sha256=sha)
    return _with_nonce(w, "%s %s %s" % (base, live, shlex.quote(grant)))


def _with_nonce(w, command):
    nonce = w.dispatch(command.replace("put-file ", "issue-file-nonce ", 1))["write_nonce"]
    return command + " " + nonce


def test_a_write_backs_up_the_old_file_and_verifies_the_new_one(w, target, monkeypatch):
    original = target.read_bytes()
    new = b"#!/bin/sh\nrepaired\n"
    _stdin(monkeypatch, new)

    result = w.dispatch(_cmd(target, new, w=w, monkeypatch=monkeypatch,
                            expected_live=hashlib.sha256(original).hexdigest()))

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
    result = w.dispatch(_cmd(target, secret, w=w, monkeypatch=monkeypatch))
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
        w.dispatch(_cmd(target, b"#!/bin/sh\nrepaired\n",
                        w=w, monkeypatch=monkeypatch))

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
    monkeypatch.setattr(w, "BACKUP_DIR", str(tmp_path / "backups").replace(os.sep, "/"))
    path = tmp_path / "new-file.sh"
    monkeypatch.setattr(w, "ALLOWED", (("host", str(path).replace(os.sep, "/")),))
    new = b"#!/bin/sh\nfresh\n"
    _stdin(monkeypatch, new)

    result = w.dispatch(_cmd(path, new, w=w, monkeypatch=monkeypatch))

    assert result["sha256_before"] == ""
    assert result["backup_path"] == ""
    assert result["verified"] is True
    assert path.read_bytes() == new


def test_content_larger_than_the_cap_is_refused(w, target, monkeypatch):
    monkeypatch.setattr(w, "MAX_WRITE_BYTES", 16)
    original = target.read_bytes()
    _stdin(monkeypatch, b"x" * 64)
    with pytest.raises(Exception) as caught:
        w.dispatch(_cmd(target, b"x" * 64, w=w, monkeypatch=monkeypatch))
    assert "larger than" in str(caught.value)
    assert target.read_bytes() == original


# ── maintenance is a closed set with no caller arguments ────────────────────

def test_there_are_no_maintenance_tasks_because_mine_was_a_guess(w):
    """The entry I removed, and why it should not have been written.

    It was pct set 101 --cmode tty. Nothing in the repository says tty is the
    wanted value; I chose it while writing the dict. Codex then MEASURED the
    live container: CT101 is cmode=shell. Those two behave differently for
    exactly what Sean complained about -- a console that starts a login he
    cannot get past -- so flipping it could be the fix or the cause, and it
    runs as root on a live container.

    It goes back when somebody states the target value and why.
    """
    assert w.MAINTENANCE == {}
    assert w.dispatch("scope")["maintenance"] == []
    for name in ("ct101-console-mode-restore", "anything-else"):
        with pytest.raises(Exception) as caught:
            w.dispatch("maint " + name)
        assert "not a maintenance task" in str(caught.value)


def test_every_maintenance_task_is_a_fixed_argument_list(w):
    """No task may take a value from the caller, now or later.

    A task that interpolated anything would be an arbitrary-command hole
    wearing a task's name, so the shape is checked rather than described.
    """
    if not w.MAINTENANCE:
        pytest.skip("no maintenance tasks are defined; the test above "
                    "asserts that emptiness deliberately")
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


# ── CODEX'S SECURITY READ OF 9e9920b3: THE FOUR I COULD FIX ────────────────
#
# He FAILED it for installation and use, and every finding was real. Nothing
# had been installed, so nothing was at risk; these are the fixes with the
# checks that were missing the first time.


def test_a_star_in_the_allowlist_does_not_cross_a_slash(w):
    """Codex's P1, measured before the fix.

    fnmatch's * matches "/", so the entry /var/www/*/public_html/* ALSO matched
    /var/www/a/b/c/public_html/deep/evil.php -- a pattern that reads as "one
    site directory" granted arbitrary depth. Segment matching stops that.
    """
    pattern = "/var/www/*/public_html/*"
    assert w._segment_match("/var/www/site/public_html/index.php", pattern)
    assert not w._segment_match("/var/www/a/b/c/public_html/deep/evil.php", pattern)
    assert not w._segment_match("/var/www/site/public_html/sub/deeper.php", pattern)
    # And the explicit form still exists for a pattern that MEANS any depth.
    assert w._segment_match("/var/www/site/public_html/sub/deeper.php",
                            "/var/www/*/public_html/**")


def test_the_live_allowlist_is_checked_segment_wise(w, monkeypatch):
    """Driven through the real refusal, not just the matcher."""
    monkeypatch.setattr(w, "ALLOWED", (("102", "/var/www/*/public_html/*"),))
    with pytest.raises(Exception) as caught:
        w.dispatch("put-file 102 /var/www/a/b/c/public_html/deep/evil.php " + "0" * 64)
    assert "not in the allowlist" in str(caught.value)


def test_a_symlinked_target_is_refused(w, tmp_path, monkeypatch):
    """Codex's P0: the allowlist was checked against the STRING.

    A symlink sitting at an allowlisted path and pointing somewhere else would
    have passed the check and been written through. Skipped where the platform
    will not make a link without privileges, because a test that silently does
    nothing is worse than one that says it did nothing.
    """
    _posix_absolute_for_this_platform(w, monkeypatch)
    elsewhere = tmp_path / "elsewhere.txt"
    elsewhere.write_bytes(b"do not touch\n")
    link = tmp_path / "allowed.sh"
    try:
        os.symlink(elsewhere, link)
    except (OSError, NotImplementedError, AttributeError):
        pytest.skip("this platform will not create a symlink here")
    monkeypatch.setattr(w, "ALLOWED", (("host", str(link).replace(os.sep, "/")),))
    monkeypatch.setattr(w, "BACKUP_DIR", str(tmp_path / "backups").replace(os.sep, "/"))
    _stdin(monkeypatch, b"new\n")

    with pytest.raises(Exception) as caught:
        w.dispatch(_cmd(link, b"new\n", w=w, monkeypatch=monkeypatch))

    assert "symbolic link" in str(caught.value)
    assert elsewhere.read_bytes() == b"do not touch\n", "it followed the link"


def test_a_symlinked_parent_is_refused(w, tmp_path, monkeypatch):
    """The same hole one level up, which is the easier one to plant."""
    _posix_absolute_for_this_platform(w, monkeypatch)
    real_dir = tmp_path / "real"
    real_dir.mkdir()
    (real_dir / "x.sh").write_bytes(b"original\n")
    link_dir = tmp_path / "linked"
    try:
        os.symlink(real_dir, link_dir, target_is_directory=True)
    except (OSError, NotImplementedError, AttributeError):
        pytest.skip("this platform will not create a directory symlink here")
    target = link_dir / "x.sh"
    monkeypatch.setattr(w, "ALLOWED", (("host", str(target).replace(os.sep, "/")),))
    monkeypatch.setattr(w, "BACKUP_DIR", str(tmp_path / "backups").replace(os.sep, "/"))
    _stdin(monkeypatch, b"new\n")

    with pytest.raises(Exception) as caught:
        w.dispatch(_cmd(target, b"new\n", w=w, monkeypatch=monkeypatch))

    assert "symbolic link" in str(caught.value)
    assert (real_dir / "x.sh").read_bytes() == b"original\n"


def test_the_backup_does_not_land_beside_a_web_served_file(w, target, tmp_path, monkeypatch):
    """Codex's P1: a .proxvox-bak of a PHP file in a web root is fetchable.

    A safety copy that anyone can download as source is a disclosure, so the
    backup goes to a private directory outside every document root.
    """
    new = b"#!/bin/sh\nrepaired\n"
    _stdin(monkeypatch, new)
    result = w.dispatch(_cmd(target, new, w=w, monkeypatch=monkeypatch))

    assert result["backup_path"]
    assert not list(target.parent.glob("*.proxvox-bak-*")), \
        "the backup was written beside the file it is protecting"
    assert "backups" in result["backup_path"]
    assert open(result["backup_path"], "rb").read() == b"#!/bin/sh\noriginal\n"


def test_the_temp_file_is_opened_exclusively(w, target, monkeypatch):
    """Codex's P0: the temp name is predictable.

    Without O_EXCL a pre-planted file at that name -- or a symlink -- is opened
    and written through. With it, a collision is an error instead of a silent
    takeover.
    """
    planted = "%s.proxvox-tmp-%d" % (str(target).replace(os.sep, "/"), os.getpid())
    with open(planted, "wb") as handle:
        handle.write(b"planted\n")
    original = target.read_bytes()
    _stdin(monkeypatch, b"new\n")

    with pytest.raises(Exception):
        w.dispatch(_cmd(target, b"new\n", w=w, monkeypatch=monkeypatch))

    assert target.read_bytes() == original
    assert open(planted, "rb").read() == b"planted\n", "it wrote through the planted file"


def test_the_container_write_path_can_actually_run(w, monkeypatch):
    """Codex's P1 functional, and the one that embarrasses me.

    _run passed stdin=PIPE alongside input=, which subprocess rejects with
    "stdin and input arguments may not both be used" BEFORE the command runs.
    So the container write had never worked, and no test of mine caught it
    because I only exercised the HOST path. This drives _run with content and
    asserts the bytes reach the command's stdin.
    """
    seen = {}

    def _fake(argv, **kwargs):
        seen["argv"] = argv
        seen["kwargs"] = kwargs

        class _Done:
            returncode = 0
            stdout = b""
            stderr = b""
        return _Done()

    monkeypatch.setattr(w.subprocess, "run", _fake)
    w._run(["/usr/bin/dd", "of=/tmp/x"], stdin_bytes=b"payload")

    assert seen["kwargs"].get("input") == b"payload"
    assert "stdin" not in seen["kwargs"], \
        "stdin= alongside input= is the ValueError that broke this path"


def test_the_symlink_refusal_fires_even_where_links_cannot_be_made(w, target, monkeypatch):
    """The two tests above SKIP on Windows, so the guard was unproven here.

    A skipped test is not a passing one, and "unknown is never a pass" is the
    rule. This drives the refusal by forcing the one thing it asks the OS --
    is this a link -- so the BRANCH is covered on every platform. The real
    symlink tests still cover the OS integration wherever links can be made,
    which includes the Debian host this actually runs on.
    """
    _stdin(monkeypatch, b"new\n")
    original = target.read_bytes()
    monkeypatch.setattr(w.os.path, "islink", lambda p: True)

    with pytest.raises(Exception) as caught:
        w.dispatch(_cmd(target, b"new\n", w=w, monkeypatch=monkeypatch))

    assert "symbolic link" in str(caught.value)
    assert target.read_bytes() == original


def test_a_parent_link_is_checked_segment_by_segment(w, target, monkeypatch):
    """And the parent walk is driven too, by making only a parent look linked."""
    _stdin(monkeypatch, b"new\n")
    # The walk builds POSIX names from "/" downwards, which is right for the
    # Debian host but does not reproduce a Windows path. So the condition is
    # "anything ABOVE the target looks like a link", which is what the branch
    # actually asks, rather than a path string this platform would never form.
    whole = str(target).replace(os.sep, "/")
    monkeypatch.setattr(w.os.path, "islink", lambda p: str(p) != whole)

    with pytest.raises(Exception) as caught:
        w.dispatch(_cmd(target, b"new\n", w=w, monkeypatch=monkeypatch))

    assert "above it is a symbolic link" in str(caught.value)


# ── THE GRANT: CODEX'S FIRST P0 ─────────────────────────────────────────────
#
# The first version authorised by SSH ACCOUNT ALONE. The TOTP lived in the
# desktop client, so the host could not tell an authorised write from a stolen
# key: anyone reaching the account wrote whatever the allowlist permitted.
#
# A live write now carries a grant, and the HOST verifies it -- the Proxmox
# ticket against its own local API, then the user, the expiry, and the target,
# path and content hash against the operation actually asked for. Every check
# below is driven by making exactly one thing wrong, so each one is shown to be
# load-bearing rather than decorative.


@pytest.fixture()
def granted_target(tmp_path, w, monkeypatch):
    """An allowlisted real file, with the grant machinery testable."""
    _posix_absolute_for_this_platform(w, monkeypatch)
    monkeypatch.setattr(w, "BACKUP_DIR", str(tmp_path / "backups").replace(os.sep, "/"))
    path = tmp_path / "target.sh"
    path.write_bytes(b"original\n")
    monkeypatch.setattr(w, "ALLOWED", (("host", str(path).replace(os.sep, "/")),))
    return path


def _attempt(w, monkeypatch, path, content, **grant_kwargs):
    """Try a live write with a grant that differs in one stated way."""
    posix = str(path).replace(os.sep, "/")
    sha = hashlib.sha256(content).hexdigest()
    fields = {"target": "host", "path": posix, "sha256": sha}
    fields.update(grant_kwargs)
    grant = _grant(w, monkeypatch, **fields)
    _stdin(monkeypatch, content)
    live = hashlib.sha256(open(str(path), "rb").read()).hexdigest()
    return w.dispatch(_with_nonce(w, "put-file host %s %s %s %s"
                                 % (posix, sha, live, shlex.quote(grant))))


def test_a_live_write_with_a_good_grant_succeeds(w, granted_target, monkeypatch):
    """The half that makes every refusal below mean something."""
    result = _attempt(w, monkeypatch, granted_target, b"repaired\n")
    assert result["verified"] is True
    assert granted_target.read_bytes() == b"repaired\n"


def test_a_live_write_with_no_grant_is_refused(w, granted_target, monkeypatch):
    posix = str(granted_target).replace(os.sep, "/")
    content = b"repaired\n"
    _stdin(monkeypatch, content)
    with pytest.raises(Exception) as caught:
        w.dispatch("put-file host %s %s" % (posix, hashlib.sha256(content).hexdigest()))
    assert "needs a grant" in str(caught.value)
    assert granted_target.read_bytes() == b"original\n"


def test_a_dry_run_needs_no_grant(w, granted_target, monkeypatch):
    """Deliberate: a rehearsal must not spend an authorisation, and a dry run
    changes nothing, which is what makes that safe."""
    content = b"repaired\n"
    _stdin(monkeypatch, content)
    result = w.dispatch("put-file-dry host %s %s"
                        % (str(granted_target).replace(os.sep, "/"),
                           hashlib.sha256(content).hexdigest()))
    assert result["dry_run"] is True
    assert granted_target.read_bytes() == b"original\n"


@pytest.mark.parametrize("kwargs,because", [
    ({"valid": False}, "the Proxmox API says the ticket is not live"),
    ({"user": "someone@pve"}, "the ticket belongs to a non-owner account"),
    ({"ticket": "short"}, "there is no real ticket"),
    ({"expires_in": -5}, "the grant has expired"),
    ({"expires_in": 60 * 60 * 24}, "the grant runs longer than the cap"),
    ({"target": "102"}, "the grant authorises a different target"),
    ({"path": "/var/www/somewhere/else.php"}, "the grant authorises another path"),
    ({"sha256": "0" * 64}, "the grant authorises different content"),
])
def test_one_wrong_thing_in_the_grant_refuses_the_write(
        w, granted_target, monkeypatch, kwargs, because):
    with pytest.raises(Exception) as caught:
        _attempt(w, monkeypatch, granted_target, b"repaired\n", **kwargs)
    assert str(caught.value), because
    assert granted_target.read_bytes() == b"original\n", \
        "the write landed anyway: " + because


def test_a_malformed_grant_is_refused_rather_than_ignored(w, granted_target, monkeypatch):
    posix = str(granted_target).replace(os.sep, "/")
    content = b"repaired\n"
    sha = hashlib.sha256(content).hexdigest()
    live = hashlib.sha256(b"original\n").hexdigest()
    for bad in ("not json at all", "[]", '"a string"', "{}"):
        _stdin(monkeypatch, content)
        with pytest.raises(Exception):
            w.dispatch("put-file host %s %s %s %s"
                       % (posix, sha, live, shlex.quote(bad)))
    assert granted_target.read_bytes() == b"original\n"


def test_the_expiry_cap_is_a_cap_and_not_a_default(w):
    """A long-lived grant must not be mintable by asking for one."""
    assert w.MAX_GRANT_SECONDS <= 900
    with pytest.raises(Exception) as caught:
        w._check_grant(
            json.dumps({"ticket": "x" * 32, "user": "root@pam", "target": "host",
                        "path": "/x", "sha256": "a" * 64,
                        "expires": time.time() + 99999}),
            target="host", path="/x", sha256="a" * 64,
            verifier=lambda t, u: True)
    assert "cap" in str(caught.value)


def test_only_owner_accounts_may_authorise(w):
    """Named accounts, not "any valid ticket". A valid ticket for a service
    user is still not Sean."""
    assert w.OWNER_USERS
    for user in ("backup@pve", "www-data@pam", "", "root@pve"):
        with pytest.raises(Exception) as caught:
            w._verify_ticket("x" * 32, user, verifier=lambda t, u: True)
        assert "owner account" in str(caught.value)


# ── CODEX'S EXPECTED-LIVE-HASH REQUEST ──────────────────────────────────────

def test_a_file_changed_since_the_caller_read_it_is_not_overwritten(
        w, granted_target, monkeypatch):
    """Compare-and-set, not blind overwrite.

    Codex asked for an expected-live hash before mutation. Without it, two
    writers or a human editing on the box between the read and the write lose
    one of the changes silently.
    """
    posix = str(granted_target).replace(os.sep, "/")
    content = b"repaired\n"
    sha = hashlib.sha256(content).hexdigest()
    grant = _grant(w, monkeypatch, target="host", path=posix, sha256=sha)
    _stdin(monkeypatch, content)

    stale = hashlib.sha256(b"what the caller thought was there\n").hexdigest()
    with pytest.raises(Exception) as caught:
        command = "put-file host %s %s %s %s" % (posix, sha, stale, shlex.quote(grant))
        w.dispatch(_with_nonce(w, command))

    assert "not what the caller last read" in str(caught.value)
    assert granted_target.read_bytes() == b"original\n"


def test_expecting_absence_refuses_when_the_file_is_there(w, granted_target, monkeypatch):
    posix = str(granted_target).replace(os.sep, "/")
    content = b"repaired\n"
    sha = hashlib.sha256(content).hexdigest()
    grant = _grant(w, monkeypatch, target="host", path=posix, sha256=sha)
    _stdin(monkeypatch, content)
    with pytest.raises(Exception) as caught:
        command = "put-file host %s %s - %s" % (posix, sha, shlex.quote(grant))
        w.dispatch(_with_nonce(w, command))
    assert "not what the caller last read" in str(caught.value)
    assert granted_target.read_bytes() == b"original\n"


# ── THE CONTAINER PATH, WHICH HAD NO GUARDS AND HAD NEVER RUN ──────────────
#
# It was written second and never executed, which is how the stdin bug lived
# and why it had none of the host path's protections. The PM's item 5(b) asked
# for parity. There is no pct on a Windows checkout, so every container
# command is captured through a fake subprocess and the ARGUMENT LISTS are
# asserted -- which is what the guards actually are. That is weaker than
# running it on the host and it is said so here rather than implied.


class _FakePct:
    """Captures every pct call and answers the probes the writer makes."""

    def __init__(self, *, links=(), exists=True):
        self.calls = []
        self.links = set(links)
        self.exists = exists

    def run(self, argv, **kwargs):
        self.calls.append((list(argv), kwargs.get("input")))
        joined = " ".join(argv)

        class _Done:
            returncode = 0
            stdout = b""
            stderr = b""

        done = _Done()
        if "/usr/bin/test" in argv and "-L" in argv:
            done.returncode = 0 if argv[-1] in self.links else 1
        elif "/usr/bin/test" in argv and "-e" in argv:
            done.returncode = 0 if self.exists else 1
        elif "/usr/bin/sha256sum" in argv:
            done.stdout = b"a" * 64 + b"  file\n"
        return done

    def argv_strings(self):
        return [" ".join(argv) for argv, _ in self.calls]


def _container_attempt(w, monkeypatch, fake, content=b"payload\n"):
    path = "/var/www/sites/example/index.php"
    monkeypatch.setattr(w, "ALLOWED", (("102", path),))
    monkeypatch.setattr(w.subprocess, "run", fake.run)
    sha = hashlib.sha256(content).hexdigest()
    grant = _grant(w, monkeypatch, target="102", path=path, sha256=sha)
    _stdin(monkeypatch, content)
    command = "put-file 102 %s %s %s %s" % (path, sha, "a" * 64, shlex.quote(grant))
    return w.dispatch(_with_nonce(w, command)), path


def test_a_symlink_anywhere_in_a_container_path_is_refused(w, monkeypatch):
    fake = _FakePct(links={"/var/www/sites/example"})
    with pytest.raises(Exception) as caught:
        _container_attempt(w, monkeypatch, fake)
    assert "symbolic link" in str(caught.value)
    # And it stopped BEFORE writing anything.
    assert not any("dd" in line for line in fake.argv_strings())


def test_the_container_backup_goes_outside_the_web_root(w, monkeypatch):
    fake = _FakePct()
    _container_attempt(w, monkeypatch, fake)
    copies = [line for line in fake.argv_strings() if "/bin/cp" in line]
    assert copies, "no backup was taken"
    assert w.BACKUP_DIR in copies[0], \
        "the backup stayed in the web root, where it can be fetched as source"
    assert "/var/www/sites/example/index.php.proxvox-bak" not in copies[0]


def test_the_container_temp_file_refuses_to_clobber(w, monkeypatch):
    """conv=excl is the container's O_EXCL: dd must not write through a
    pre-planted name."""
    fake = _FakePct()
    _container_attempt(w, monkeypatch, fake)
    dd = [line for line in fake.argv_strings() if "/usr/bin/dd" in line]
    assert dd, "nothing was written"
    assert "conv=excl" in dd[0]


def test_the_container_write_passes_the_content_on_stdin(w, monkeypatch):
    """The bug that proved the path had never run: stdin= alongside input=."""
    fake = _FakePct()
    _container_attempt(w, monkeypatch, fake, content=b"the payload\n")
    for argv, sent in fake.calls:
        if "/usr/bin/dd" in argv:
            assert sent == b"the payload\n"
            break
    else:
        pytest.fail("dd was never called with the content")


def test_a_container_write_also_needs_a_grant(w, monkeypatch):
    path = "/var/www/sites/example/index.php"
    fake = _FakePct()
    monkeypatch.setattr(w, "ALLOWED", (("102", path),))
    monkeypatch.setattr(w.subprocess, "run", fake.run)
    content = b"payload\n"
    _stdin(monkeypatch, content)
    with pytest.raises(Exception) as caught:
        w.dispatch("put-file 102 %s %s" % (path, hashlib.sha256(content).hexdigest()))
    assert "needs a grant" in str(caught.value)
    assert not any("dd" in line for line in fake.argv_strings())


def test_the_container_path_never_uses_a_shell(w, monkeypatch):
    """Every call is an argument LIST through pct exec. A shell anywhere here
    would make the path a command-injection surface."""
    fake = _FakePct()
    _container_attempt(w, monkeypatch, fake)
    assert fake.calls
    for argv, _ in fake.calls:
        assert isinstance(argv, list)
        assert argv[0] == "/usr/sbin/pct", argv[0]
        assert "exec" in argv and "--" in argv
        for arg in argv:
            assert ";" not in arg and "&&" not in arg and "|" not in arg, arg

def test_host_writer_session_requires_real_challenge_and_supports_revoke(
    tmp_path, monkeypatch,
):
    from contextlib import closing
    from urllib.parse import parse_qs
    import urllib.request

    writer = _load()
    monkeypatch.setattr(writer, "WRITE_SESSIONS", str(tmp_path / "sessions"))
    monkeypatch.setattr(writer, "AUDIT_LOG", str(tmp_path / "audit.log"))
    monkeypatch.setattr(writer, "_pve_ssl_context", lambda: object())
    ticket = "PVE:root@pam:fixture-ticket"
    challenge = "PVE:!tfa!fixture-challenge"
    fields = []

    class Response(io.BytesIO):
        status = 200

    class LocalPve:
        def open(self, request, timeout):
            assert request.full_url == "https://127.0.0.1:8006/api2/json/access/ticket"
            assert timeout == 15
            fields.append(parse_qs(request.data.decode("ascii")))
            return closing(Response(json.dumps({"data": {
                "username": "root@pam",
                "ticket": ticket,
                "CSRFPreventionToken": "fixture-csrf",
            }}).encode()))

    monkeypatch.setattr(urllib.request, "build_opener", lambda *handlers: LocalPve())
    monkeypatch.setattr(urllib.request, "HTTPSHandler", lambda **kwargs: object())

    def dispatch(op, user="root@pam", **body):
        monkeypatch.setattr(writer.sys, "stdin", io.TextIOWrapper(
            io.BytesIO(json.dumps(body).encode())
        ))
        return writer.dispatch(f"{op} {user}")

    path = (tmp_path / "index.php").as_posix()
    content_hash = hashlib.sha256(b"fixture content").hexdigest()
    monkeypatch.setattr(writer, "ALLOWED", ())
    monkeypatch.setattr(writer, "_absolute", lambda value: value)
    monkeypatch.setattr(writer, "WRITE_NONCES", str(tmp_path / "nonces"))
    monkeypatch.setattr(writer, "SPENT_GRANTS", str(tmp_path / "spent"))
    monkeypatch.setattr(writer, "_verify_ticket_against_local_api", lambda ticket, user: True)
    grant = json.dumps({"user": "root@pam", "target": "host", "path": path,
                        "sha256": content_hash, "ticket": ticket, "expires": time.time() + 120})
    issue = f"issue-file-nonce host {shlex.quote(path)} {content_hash} - {shlex.quote(grant)}"

    with pytest.raises(writer.Refused, match="no active host write session"):
        dispatch("check-write-session", ticket=ticket)
    with pytest.raises(writer.Refused, match="no active host write session"):
        writer.dispatch(issue)
    with pytest.raises(writer.Refused, match="fresh Proxmox"):
        dispatch("authorize-write-session", challenge=ticket, code="012345")
    with pytest.raises(writer.Refused, match="cannot authorize"):
        dispatch("authorize-write-session", "somebody@pve", challenge=challenge, code="012345")
    assert fields == []
    session = dispatch("authorize-write-session", challenge=challenge, code="012345")
    assert fields == [{
        "username": ["root@pam"], "tfa-challenge": [challenge], "password": ["totp:012345"],
    }]
    assert session["ticket"] == ticket
    assert dispatch("check-write-session", ticket=ticket)["expires_at"] == session["expires_at"]
    nonce = writer.dispatch(issue)["write_nonce"]
    put = issue.replace("issue-file-nonce", "put-file", 1)
    monkeypatch.setattr(writer.sys, "stdin", io.TextIOWrapper(
        io.BytesIO(base64.b64encode(b"fixture content"))
    ))
    assert writer.dispatch(f"{put} {nonce}")["verified"]
    assert (tmp_path / "index.php").read_bytes() == b"fixture content"
    nonce = writer.dispatch(issue)["write_nonce"]
    with pytest.raises(writer.Refused, match="belongs to another account"):
        dispatch("check-write-session", "sean@pam", ticket=ticket)
    assert dispatch("revoke-write-session", ticket=ticket) == {"revoked": True}
    with pytest.raises(writer.Refused, match="no active host write session"):
        dispatch("check-write-session", ticket=ticket)
    with pytest.raises(writer.Refused, match="no active host write session"):
        writer.dispatch(f"{put} {nonce}")
    log = (tmp_path / "audit.log").read_text()
    assert all(secret not in log for secret in (ticket, challenge, "012345", "fixture-csrf"))


def test_host_writer_session_expires_even_if_wall_clock_moves_back(tmp_path, monkeypatch):
    writer = _load()
    monkeypatch.setattr(writer, "WRITE_SESSIONS", str(tmp_path / "sessions"))
    ticket = "PVE:root@pam:fixture-ticket"
    monkeypatch.setattr(writer, "_exchange_totp", lambda *args: (ticket, "fixture-csrf"))
    monotonic_start = time.monotonic()
    session = writer._start_write_session("root@pam", {})
    monkeypatch.setattr(writer.time, "time", lambda: session["expires_at"] - 120)
    monkeypatch.setattr(writer.time, "monotonic", lambda: monotonic_start + 3601)
    with pytest.raises(writer.Refused, match="authorization expired"):
        writer._check_write_session("root@pam", ticket)


def test_attested_maintenance_binds_command_and_audits_before_mutation(w, monkeypatch):
    from contextlib import nullcontext

    user = "root@pam"
    ticket = "PVE:root@pam:test-maintenance-ticket"
    commands = []
    state = "LoadState=loaded\nActiveState=active\nInvocationID=old"
    audit = []
    active = True
    audit_available = True

    def session(account, supplied):
        if not active or (account, supplied) != (user, ticket):
            raise w.Refused("no active host write session")
        return time.time() + 120

    def run(argv):
        nonlocal state
        if "show" in argv:
            return state.encode()
        assert audit[-1][0] == "maintenance-intent"
        assert audit[-1][1] == {"target": "101", "argv": argv, "before": state}
        commands.append(argv)
        state = state.replace("=old", "=new")
        return b""

    def record(event, detail, *, durable=False):
        if event != "attempt":
            assert durable
        audit.append((event, detail))
        return audit_available

    monkeypatch.setattr(w, "_check_write_session", session)
    monkeypatch.setattr(w, "_maintenance_lock", lambda target: nullcontext())
    monkeypatch.setattr(w, "_run", run)
    monkeypatch.setattr(w, "_audit", record)
    plan = {"ticket": ticket, "target": "101", "action": "restart", "subject": "test.service"}

    def call(op, **values):
        monkeypatch.setattr(w, "_read_session_request", lambda: dict(plan, **values))
        return w.dispatch(f"{op} {user}")

    preview = call("preview-maintenance")
    assert preview["argv"] == [
        "/usr/sbin/pct", "exec", "101", "--", "/usr/bin/systemctl", "restart",
        "--", "test.service",
    ]
    expected = preview["expected"]
    nonce = call("issue-maintenance-nonce", expected=expected)["write_nonce"]
    with pytest.raises(w.Refused, match="different operation"):
        call("run-maintenance", expected=expected, nonce=nonce, subject="another.service")
    active = False
    with pytest.raises(w.Refused, match="no active"):
        call("run-maintenance", expected=expected, nonce=nonce)
    active = True
    audit_available = False
    with pytest.raises(w.Refused, match="could not persist"):
        call("run-maintenance", expected=expected, nonce=nonce)
    assert not commands
    audit_available = True
    nonce = call("issue-maintenance-nonce", expected=expected)["write_nonce"]
    before = state
    result = call("run-maintenance", expected=expected, nonce=nonce)
    assert result["after"].endswith("=new")
    assert audit[-1] == ("maintenance-result", result)
    assert commands == [preview["argv"]]
    state = before
    with pytest.raises(w.Refused, match="already used"):
        call("run-maintenance", expected=expected, nonce=nonce)
    assert ticket not in repr(audit) and nonce not in repr(audit)


def test_dedicated_pve_account_still_needs_host_attested_totp(tmp_path, monkeypatch):
    from contextlib import closing
    from urllib.parse import parse_qs
    import urllib.request

    w = _load()
    monkeypatch.setattr(w, "WRITE_SESSIONS", str(tmp_path / "sessions"))
    challenge = "PVE:!tfa!dedicated-fixture"
    ticket = "PVE:proxvox@pve:owner-fixture"
    seen = []

    class LocalPve:
        def open(self, request, timeout):
            seen.append(parse_qs(request.data.decode("ascii")))
            response = io.BytesIO(json.dumps({"data": {
                "username": "proxvox@pve", "ticket": ticket, "CSRFPreventionToken": "fixture",
            }}).encode())
            response.status = 200
            return closing(response)

    monkeypatch.setattr(w, "_pve_ssl_context", lambda: object())
    monkeypatch.setattr(urllib.request, "build_opener", lambda *args: LocalPve())
    monkeypatch.setattr(urllib.request, "HTTPSHandler", lambda **kwargs: object())
    with pytest.raises(w.Refused, match="fresh Proxmox"):
        w._start_write_session("proxvox@pve", {"challenge": ticket, "code": "012345"})
    result = w._start_write_session("proxvox@pve", {"challenge": challenge, "code": "012345"})
    assert result["ticket"] == ticket
    assert seen == [{"username": ["proxvox@pve"], "tfa-challenge": [challenge], "password": ["totp:012345"]}]
    assert w._check_write_session("proxvox@pve", ticket) == result["expires_at"]
    with pytest.raises(w.Refused, match="belongs to another"):
        w._check_write_session("sean@pam", ticket)


def test_host_proxmox_context_keeps_chain_verification_for_legacy_ca(w, monkeypatch, tmp_path):
    import ssl
    from types import SimpleNamespace

    cafile = tmp_path / "pve-ca.pem"
    cafile.touch()
    monkeypatch.setattr(w, "PVE_CA_CANDIDATES", (str(cafile),))
    original_flags = ssl.VERIFY_X509_TRUSTED_FIRST | ssl.VERIFY_X509_STRICT
    context = SimpleNamespace(
        verify_flags=original_flags, verify_mode=ssl.CERT_REQUIRED, check_hostname=True,
    )
    seen = []

    def create_context(*, cafile):
        seen.append(cafile)
        return context

    monkeypatch.setattr(ssl, "create_default_context", create_context)
    assert w._pve_ssl_context() is context
    assert seen == [str(cafile)]
    assert context.verify_mode == ssl.CERT_REQUIRED
    assert context.verify_flags == original_flags & ~ssl.VERIFY_X509_STRICT
    assert context.check_hostname is False


def test_host_tls_accepts_chain_from_ca_without_key_usage(w, monkeypatch, tmp_path):
    import shutil
    import ssl
    import subprocess

    openssl = shutil.which("openssl")
    if not openssl:
        pytest.skip("OpenSSL is required to issue a disposable Proxmox-style chain")
    ca_config = tmp_path / "ca.cnf"
    ca_config.write_text(
        "[req]\nprompt=no\ndistinguished_name=dn\nx509_extensions=ca\n"
        "[dn]\nCN=Proxmox fixture CA\n"
        "[ca]\nbasicConstraints=critical,CA:TRUE\nsubjectKeyIdentifier=hash\n"
        "authorityKeyIdentifier=keyid:always\n",
        encoding="ascii",
    )
    leaf_config = tmp_path / "leaf.cnf"
    leaf_config.write_text(
        "basicConstraints=critical,CA:FALSE\nkeyUsage=digitalSignature,keyEncipherment\n"
        "extendedKeyUsage=serverAuth\nsubjectKeyIdentifier=hash\n"
        "authorityKeyIdentifier=keyid:always\nsubjectAltName=DNS:proxmox.fixture\n",
        encoding="ascii",
    )

    def issue(*args):
        subprocess.run([openssl, *args], cwd=tmp_path, check=True, capture_output=True)

    issue("req", "-new", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1",
          "-config", "ca.cnf", "-keyout", "ca.key", "-out", "ca.pem")
    issue("req", "-new", "-newkey", "rsa:2048", "-nodes", "-subj", "/CN=proxmox.fixture",
          "-keyout", "leaf.key", "-out", "leaf.csr")
    issue("x509", "-req", "-in", "leaf.csr", "-CA", "ca.pem", "-CAkey", "ca.key",
          "-CAcreateserial", "-days", "1", "-extfile", "leaf.cnf", "-out", "leaf.pem")

    ca = str(tmp_path / "ca.pem")
    server = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    server.load_cert_chain(tmp_path / "leaf.pem", tmp_path / "leaf.key")

    def handshake(context):
        from_client, to_client = ssl.MemoryBIO(), ssl.MemoryBIO()
        from_server, to_server = ssl.MemoryBIO(), ssl.MemoryBIO()
        client_conn = context.wrap_bio(to_client, from_client, server_hostname="proxmox.fixture")
        server_conn = server.wrap_bio(to_server, from_server, server_side=True)
        complete = set()
        for _ in range(12):
            for name, conn, output, peer in (
                ("client", client_conn, from_client, to_server),
                ("server", server_conn, from_server, to_client),
            ):
                if name not in complete:
                    try:
                        conn.do_handshake()
                        complete.add(name)
                    except ssl.SSLWantReadError:
                        pass
                if output.pending:
                    peer.write(output.read())
            if len(complete) == 2:
                return
        pytest.fail("disposable TLS handshake did not finish")

    if ssl.OPENSSL_VERSION_INFO >= (3, 0):
        strict = ssl.create_default_context(cafile=ca)
        strict.verify_flags |= ssl.VERIFY_X509_STRICT
        with pytest.raises(ssl.SSLCertVerificationError) as rejected:
            handshake(strict)
        assert rejected.value.verify_code == 92


    monkeypatch.setattr(w, "PVE_CA_CANDIDATES", (ca,))
    fixed = w._pve_ssl_context()
    handshake(fixed)
    untrusted = ssl.SSLContext(ssl.PROTOCOL_TLS_CLIENT)
    with pytest.raises(ssl.SSLCertVerificationError):
        handshake(untrusted)


# ===== SNAPSMACK EOF =====
