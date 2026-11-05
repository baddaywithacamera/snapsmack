"""The armed sender must not be armable by accident.

I wrote tw-send.py with environment defaults for --forum-url and --forum-key to
match tw.py, then ran `tw-send.py --arm` WITH NO KEY expecting a refusal. The
shell had SNAPSMACK_FORUM_KEY exported, argparse filled it in, and two replies
went to the live ROACH MOTEL board -- SS-1020 to thread 61, SS-1029 to thread
68. The drafts were right; the moment was not. I published while testing that
publishing was blocked.

tw.py can afford environment defaults because TW.PY CANNOT SEND. This file
holds the line for the one tool that can.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import importlib.util
import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
TOOL = os.path.join(os.path.dirname(HERE), "tw-send.py")


def _load():
    spec = importlib.util.spec_from_file_location("tw_send", TOOL)
    mod = importlib.util.module_from_spec(spec)
    sys.modules["tw_send"] = mod
    spec.loader.exec_module(mod)
    return mod


def test_source_reads_no_credential_from_the_environment():
    """The interlock, asserted on the source because it is an ABSENCE.

    A test cannot observe a default that is not there by calling the parser, so
    this reads the file: no os.environ lookup may feed either credential flag.
    """
    src = open(TOOL, encoding="utf-8").read()
    for flag in ("--forum-url", "--forum-key"):
        line = next(l for l in src.splitlines() if 'p.add_argument("%s"' % flag in l)
        assert "os.environ" not in line, (
            "%s takes a default from the environment again. An exported key must "
            "never be able to arm this tool." % flag)


def test_arming_without_a_typed_key_refuses(monkeypatch, capsys):
    """Even with the credential exported, --arm alone must not send."""
    mod = _load()
    monkeypatch.setenv("SNAPSMACK_FORUM_URL", "https://example.invalid")
    monkeypatch.setenv("SNAPSMACK_FORUM_KEY", "exported-key-that-must-be-ignored")
    rc = mod.main(["--arm"])
    assert rc == 2, "arming with no typed key must refuse, got exit %r" % rc
    err = capsys.readouterr().err
    assert "REFUSING TO SEND" in err
    assert "NOT read from the environment" in err


def test_email_rows_are_never_sent():
    """Mail is the owner's. A row on the email channel is classified, not sent."""
    mod = _load()
    ok, why = mod.classify({"channel": "email", "ticket": "SS-1"}, object())
    assert ok is False
    assert "mail is the owner" in why.lower() or "sending mail" in why.lower()


def test_a_ticket_with_no_thread_is_refused_with_the_fix_named():
    """A reply needs a thread. Guessing one files words under another ticket."""
    mod = _load()

    class T:
        pulse = False
        forum_thread = 0

    ok, why = mod.classify({"channel": "forum", "ticket": "SS-1076"}, T())
    assert ok is False
    assert "tw.py post SS-1076" in why, "the refusal must name the command that fixes it"


def test_a_pulse_ticket_is_never_auto_answered():
    """model.Ticket's own rule: once a pulse is set, nothing auto-sends, ever."""
    mod = _load()

    class T:
        pulse = True
        forum_thread = 61

    ok, why = mod.classify({"channel": "forum", "ticket": "SS-1"}, T())
    assert ok is False
    assert "PULSE" in why


def test_a_real_forum_row_with_a_thread_is_deliverable():
    """The positive case, or the four refusals above could be refusing everything."""
    mod = _load()

    class T:
        pulse = False
        forum_thread = 61

    ok, why = mod.classify({"channel": "forum", "ticket": "SS-1020"}, T())
    assert ok is True
    assert "61" in why
# ===== SNAPSMACK EOF =====
