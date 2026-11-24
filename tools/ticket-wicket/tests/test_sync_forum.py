"""`tw.py sync-forum` — the four things that must hold when it runs itself.

WHY THIS EXISTS. Sean asked for the tickets to be synchronised with the support
forum: add the open ones, put the updates in as replies, close the closed ones.
Doing that by hand is three commands per ticket across 540 tickets, so it is
one command — and a command that writes to a PUBLIC board in bulk is exactly
the kind that needs its refusals tested rather than trusted.

The four:

  1. AN OPEN SECURITY TICKET IS NOT ANNOUNCED. The store holds 51 DOOR OPEN
     tickets and 7 are on the board, while every other band sits between 62%
     and 89%. That 14% is a practice, not a backlog, and the DOOR OPEN tickets
     that are published are the FIXED ones. So sync holds an OPEN DOOR OPEN
     ticket back unless --include-security says otherwise, and the test drives
     both directions rather than reading the flag.

  2. THE CLOSURE NOTE IS DETERMINISTIC. Running the sync twice must not put two
     closing notes on a public thread. cmd_reply's _already_on_thread() probes
     the first content line of 24 characters or more, so the note has to
     compose the same bytes every time — no timestamps, no counts. If that
     slips, the second run doubles every closure on the board.

  3. THE CLOSURE NOTE SURVIVES THE SHAPE CHECK. The forum honours every newline
     and renders no markdown. A closure note that is hard-wrapped or carries
     asterisks is refused by cmd_reply, which would mean 38 refusals and no
     closures. Checked against the real checker, not a copy of its rules.

  4. A PULSE TICKET IS LEFT ALONE. Anything with a pulse reaches a human.
     cmd_reply already refuses one; sync must not even try, and must say it
     held it.

Run:  python -m pytest tools/ticket-wicket/tests/test_sync_forum.py -q

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import json
import os
import sys

BASE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if BASE not in sys.path:
    sys.path.insert(0, BASE)

SHARED = os.path.join(os.path.dirname(BASE), "_shared")
if SHARED not in sys.path:
    sys.path.insert(0, SHARED)

import argparse                                      # noqa: E402

import tw                                            # noqa: E402
import snap_forum                                    # noqa: E402
from ticketwicket import model, store as storemod     # noqa: E402


def _db(tmp_path):
    """Store() takes the database FILE, not the directory holding it.

    --db's help text says "ticket store (default C:/snapsmack/ticket-wicket)",
    which reads like a directory, and default_path() does join tickets.db onto
    one — but Store.__init__ hands whatever it is straight to sqlite3.connect.
    Passing the directory gives "unable to open database file".
    """
    return os.path.join(str(tmp_path), "tickets.db")


def _args(db, **over):
    """The parser's own defaults, so the test cannot drift from the real CLI."""
    ns = tw.build_parser().parse_args(["sync-forum"])
    ns.db = db
    ns.as_who = model.CLAUDE
    ns.forum_url = "https://forum.test"
    ns.forum_key = "test-key"
    ns.json = True
    for k, v in over.items():
        setattr(ns, k, v)
    return ns


def _store(tmp_path):
    return storemod.Store(path=_db(tmp_path), actor=model.CLAUDE)


# ── 1. an open security ticket is not announced ─────────────────────────────

def _door_open_ticket(s):
    t = s.create(title="a hole", body="There is a way in that should not be there.")
    s.update(t.id, security=True, usability="stopped", affected=5)
    return s.get(t.id)


def test_open_security_ticket_is_held_back_by_default(tmp_path, monkeypatch):
    s = _store(tmp_path)
    t = _door_open_ticket(s)
    assert t.band == "DOOR OPEN", t.band          # the premise of the test
    assert t.state in model.OPEN_STATES
    s.close()

    tried = []
    monkeypatch.setattr(tw, "cmd_post", lambda a: tried.append(a.id) or 0)

    rc = tw.cmd_sync_forum(_args(_db(tmp_path), phase="add"))
    assert rc == 0
    assert tried == [], "an OPEN security ticket was offered to the board"


def test_include_security_is_the_one_way_to_overrule_it(tmp_path, monkeypatch):
    s = _store(tmp_path)
    t = _door_open_ticket(s)
    s.close()

    tried = []
    monkeypatch.setattr(tw, "cmd_post", lambda a: tried.append(a.id) or 0)

    rc = tw.cmd_sync_forum(_args(_db(tmp_path), phase="add",
                                 include_security=True))
    assert rc == 0
    assert tried == [t.id], "--include-security did not reach the board"


def test_a_fixed_security_ticket_is_not_held_back(tmp_path, monkeypatch):
    """The practice is hold while OPEN, publish when FIXED. Both halves."""
    s = _store(tmp_path)
    t = _door_open_ticket(s)
    s.update(t.id, state=model.RESOLVED)
    s.close()

    tried = []
    monkeypatch.setattr(tw, "cmd_post", lambda a: tried.append(a.id) or 0)

    tw.cmd_sync_forum(_args(_db(tmp_path), phase="add"))
    # Not posted by the ADD phase either, because ADD only handles open
    # tickets — but it must not be counted as a security hold.
    assert tried == []


# ── 2 and 3. the closure note ───────────────────────────────────────────────

class _T:
    """Just enough ticket for the composer."""

    def __init__(self, state, links=None, merged_into=""):
        self.id = "SS-9001"
        self.state = state
        self.links = links or {}
        self.merged_into = merged_into


def test_closure_note_is_byte_identical_across_runs():
    """Pinned to the exact bytes, and that detail is the whole test.

    This first compared two live calls and asserted they matched. A mutation
    that appended str(time.time()) to the note SURVIVED it: Windows' clock has
    about 15ms of resolution, so two calls microseconds apart read the same
    number and the note looked stable. What has to hold is sameness across
    RUNS -- separate processes, minutes or days apart -- which no pair of calls
    in one process can show. So the expected note is written out here. If this
    fails because the wording changed on purpose, update the literal; if it
    fails because something time-like, counted or random crept in, that is the
    bug it is here for, and a second sync would put a second closure on a
    public thread.
    """
    note = tw._closure_note(
        _T(model.RESOLVED, {"build": "0.7.1002D", "commit": "abc1234"}))
    assert note == (
        "THIS TICKET IS CLOSED. The report stays on the board and stays "
        "readable; nothing here is removed."
        "\n\n"
        "STATE: RESOLVED. The problem described above was fixed."
        "\n\n"
        "What carries it: BUILD 0.7.1002D; COMMIT abc1234."
    ), repr(note)


def test_closure_note_does_not_depend_on_link_order():
    """Two stores can hold the same links in a different order."""
    a = tw._closure_note(_T(model.RESOLVED, {"build": "0.7.1002D", "commit": "abc1234"}))
    b = tw._closure_note(_T(model.RESOLVED, {"commit": "abc1234", "build": "0.7.1002D"}))
    assert a == b


def test_closure_note_carries_a_probe_line_long_enough_to_match():
    """_already_on_thread needs a content line of 24+ characters to probe on.

    A shorter opening line would make it fall back to joining the whole body,
    and a body that differs by one link would then stop matching — which is a
    second closure on a public thread.
    """
    line = tw.CLOSURE_OPENING.splitlines()[0]
    assert len(line) >= 24, len(line)


def test_closure_note_passes_the_real_shape_checker():
    for state in (model.RESOLVED, model.CLOSED, model.WITHDRAWN):
        for links in ({}, {"build": "0.7.1002D"},
                      {"build": "0.7.1002D", "opaudit": "027", "pr": "14"}):
            body = tw._closure_note(_T(state, links))
            assert snap_forum.body_shape_complaints(body) == [], (state, links)


def test_closure_note_does_not_invent_a_build_it_does_not_have():
    body = tw._closure_note(_T(model.RESOLVED, {}))
    assert "does not claim one" in body
    body2 = tw._closure_note(_T(model.RESOLVED, {"build": "0.7.1002D"}))
    assert "0.7.1002D" in body2


def test_withdrawn_does_not_read_as_a_rebuke():
    body = tw._closure_note(_T(model.WITHDRAWN))
    assert "still a real thing to report" in body


# ── 4. a pulse ticket is left alone ─────────────────────────────────────────

def test_pulse_ticket_is_never_replied_to_and_is_reported(tmp_path, monkeypatch):
    s = _store(tmp_path)
    t = s.create(title="angry", body="This keeps happening and I have had enough.")
    s.update(t.id, pulse=True, state=model.RESOLVED, forum_thread=77)
    s.close()

    tried = []
    monkeypatch.setattr(tw, "cmd_reply", lambda a: tried.append(a.id) or 0)

    captured = {}
    monkeypatch.setattr(tw, "_out", lambda a, data, text: captured.update(data))

    rc = tw.cmd_sync_forum(_args(_db(tmp_path), phase="close"))
    assert rc == 0
    assert tried == [], "sync tried to reply to a pulse ticket"
    assert t.id in captured["held_pulse"], captured


def test_a_closed_published_ticket_is_offered_a_closure(tmp_path, monkeypatch):
    """The ordinary case, so the pulse test above is not passing by accident."""
    s = _store(tmp_path)
    t = s.create(title="borders", body="The borders went missing on the archive.")
    s.update(t.id, state=model.RESOLVED, forum_thread=78)
    s.close()

    seen = {}

    def fake_reply(a):
        seen["id"] = a.id
        seen["text"] = a.text
        seen["channel"] = a.channel
        print("{}")
        return 0

    monkeypatch.setattr(tw, "cmd_reply", fake_reply)
    rc = tw.cmd_sync_forum(_args(_db(tmp_path), phase="close"))
    assert rc == 0
    assert seen["id"] == t.id
    assert seen["channel"] == "forum"
    assert tw.CLOSURE_OPENING.splitlines()[0] in seen["text"]


def test_dry_run_publishes_nothing(tmp_path, monkeypatch):
    s = _store(tmp_path)
    t = s.create(title="borders", body="The borders went missing on the archive.")
    s.update(t.id, state=model.RESOLVED, forum_thread=79)
    u = s.create(title="slow", body="The archive page takes a long time to open.")
    s.close()

    calls = []
    monkeypatch.setattr(tw, "cmd_post",
                        lambda a: calls.append(("post", a.id, a.dry_run)) or 0)
    monkeypatch.setattr(tw, "cmd_reply", lambda a: calls.append(("reply", a.id)) or 0)
    monkeypatch.setattr(tw, "cmd_deliver_forum",
                        lambda a: calls.append(("deliver", None)) or 0)

    rc = tw.cmd_sync_forum(_args(_db(tmp_path), dry_run=True))
    assert rc == 0

    # post IS consulted on a dry run: it is what prints the composed body that
    # WOULD go up, and it publishes nothing of its own accord when dry_run is
    # set. What matters is that the flag reaches it — a dry run that quietly
    # dropped it would publish the whole board.
    posts = [c for c in calls if c[0] == "post"]
    assert posts == [("post", u.id, True)], calls

    # The two that WRITE are never reached at all. deliver-forum is the armed
    # sender and cmd_reply delivers on sight when it has the key, so neither
    # can be allowed to run behind a --dry-run.
    assert not [c for c in calls if c[0] in ("reply", "deliver")], calls


# ── 5. one bad ticket does not end the run ──────────────────────────────────
#
# WHAT HAPPENED. The first real run of sync-forum died on ticket 21 of 85 with
# an unhandled urllib HTTPError -- 400 BODY_TOO_LONG, from a ticket whose body
# is 31,062 characters -- and took the remaining 64 tickets, the 12 queued
# updates and all 38 closures with it. 20 threads had already been created, so
# the board was left half synchronised and the report was a traceback.

def test_a_raising_ticket_is_recorded_and_the_run_continues(tmp_path, monkeypatch):
    s = _store(tmp_path)
    bad = s.create(title="enormous", body="The archive page will not load at all.")
    good = s.create(title="small", body="A caption sits under the wrong photograph.")
    s.close()

    seen = []

    def flaky(a):
        seen.append(a.id)
        if a.id == bad.id:
            raise RuntimeError("400 Bad Request: BODY_TOO_LONG")
        return 0

    monkeypatch.setattr(tw, "cmd_post", flaky)
    captured = {}
    monkeypatch.setattr(tw, "_out", lambda a, data, text: captured.update(data))

    rc = tw.cmd_sync_forum(_args(_db(tmp_path), phase="add"))
    assert rc == 0, "the run itself must not fail because one ticket did"
    assert bad.id in seen and good.id in seen, seen
    assert captured["added"] == [good.id], captured["added"]
    refused = {r["ticket"]: r["why"] for r in captured["refused"]}
    assert bad.id in refused
    assert "BODY_TOO_LONG" in refused[bad.id], refused


def test_quietly_turns_a_raise_into_a_refusal():
    def boom(_a):
        raise RuntimeError("kaboom")

    rc, out, err = tw._quietly(boom, argparse.Namespace())
    assert rc == 1
    assert "RuntimeError" in err and "kaboom" in err


# ── 6. an over-long body is refused here, not by the server ─────────────────

def test_post_refuses_a_body_over_the_forum_limit(tmp_path, monkeypatch, capsys):
    s = _store(tmp_path)
    t = s.create(title="enormous",
                 body="The archive will not load. " + ("x" * tw.FORUM_BODY_LIMIT))
    s.close()

    class Unreachable:
        def __init__(self, *a, **k):
            raise AssertionError("the forum was called with an over-long body")

    monkeypatch.setattr(tw.forummod, "Forum", Unreachable)

    args = tw.build_parser().parse_args(["post", t.id])
    args.db = _db(tmp_path)
    args.as_who = model.CLAUDE
    args.forum_url = "https://forum.test"
    args.forum_key = "test-key"
    args.json = False
    args.dry_run = False
    args.unwrap = True

    rc = tw.cmd_post(args)
    assert rc == 1
    assert "REFUSING TO POST" in capsys.readouterr().err


def test_the_limit_is_the_forums_number_not_a_guess():
    """20,000 is quoted from the API's own refusal, so it is not ours to drift."""
    assert tw.FORUM_BODY_LIMIT == 20000


# ── 7. --hold keeps one ticket out of a bulk run ────────────────────────────
#
# Sean, 24 November 2026, mid-sync: "Please don't publish open holes." The
# armed sender was all-or-nothing, so one update still being decided meant
# holding back eleven that were ready.

def test_hold_keeps_a_ticket_out_of_the_add_phase(tmp_path, monkeypatch):
    s = _store(tmp_path)
    keep = s.create(title="one", body="A caption sits under the wrong photograph.")
    hold = s.create(title="two", body="The archive page will not load at all.")
    s.close()

    tried = []
    monkeypatch.setattr(tw, "cmd_post", lambda a: tried.append(a.id) or 0)
    rc = tw.cmd_sync_forum(_args(_db(tmp_path), phase="add", hold=[hold.id]))
    assert rc == 0
    assert tried == [keep.id], tried


def test_hold_keeps_a_ticket_out_of_the_close_phase_too(tmp_path, monkeypatch):
    """Not ready to publish means not ready to close in public either."""
    s = _store(tmp_path)
    hold = s.create(title="two", body="The archive page will not load at all.")
    s.update(hold.id, state=model.RESOLVED, forum_thread=81)
    s.close()

    tried = []
    monkeypatch.setattr(tw, "cmd_reply", lambda a: tried.append(a.id) or 0)
    rc = tw.cmd_sync_forum(_args(_db(tmp_path), phase="close", hold=[hold.id]))
    assert rc == 0
    assert tried == [], tried


class _RecordingForum:
    """Records every call, so a test can assert the board was never touched."""

    calls = []

    def __init__(self, *a, **k):
        type(self).calls = []

    def thread(self, tid):
        type(self).calls.append(("thread", tid))
        return {"thread": {"body": ""}, "replies": []}

    def reply(self, tid, body):
        type(self).calls.append(("reply", tid))
        return 999

    def threads(self, *a, **k):
        type(self).calls.append(("threads", a))
        return []

    def boards(self, *a, **k):
        return []


def test_hold_means_the_forum_is_never_asked_at_all(tmp_path, monkeypatch):
    """The absence of the action, not a side effect that failure shares with it.

    This first asserted only that the held row was still QUEUED afterwards. A
    mutation that disabled the filter entirely SURVIVED that: delivery was
    attempted, the fake URL refused it, and the row stayed queued exactly as if
    it had been held. Queued-afterwards is true of "held" and of "tried and
    failed" alike, so it could not tell them apart. What distinguishes them is
    whether the board was contacted, so that is what is checked.
    """
    s = _store(tmp_path)
    t = s.create(title="two", body="The archive page will not load at all.")
    s.update(t.id, forum_thread=82)
    s.enqueue(t.id, "forum", "An update about the thing.\n\nclaude")
    s.close()

    monkeypatch.setattr(tw.forummod, "Forum", _RecordingForum)

    args = tw.build_parser().parse_args(["deliver-forum"])
    args.db = _db(tmp_path)
    args.as_who = model.CLAUDE
    args.forum_url = "https://forum.test"
    args.forum_key = "test-key"
    args.json = True
    args.hold = [t.id]

    rc = tw.cmd_deliver_forum(args)
    assert rc == 0
    assert _RecordingForum.calls == [], _RecordingForum.calls

    # And it is still there to send once released.
    s2 = _store(tmp_path)
    still = [r for r in s2.outbox("queued") if r.get("ticket") == t.id]
    assert len(still) == 1, "the held update was consumed instead of kept"
    s2.close()


def test_without_hold_the_same_update_does_reach_the_board(tmp_path, monkeypatch):
    """So the test above is not passing because delivery never works here."""
    s = _store(tmp_path)
    t = s.create(title="two", body="The archive page will not load at all.")
    s.update(t.id, forum_thread=82)
    s.enqueue(t.id, "forum", "An update about the thing.\n\nclaude")
    s.close()

    monkeypatch.setattr(tw.forummod, "Forum", _RecordingForum)

    args = tw.build_parser().parse_args(["deliver-forum"])
    args.db = _db(tmp_path)
    args.as_who = model.CLAUDE
    args.forum_url = "https://forum.test"
    args.forum_key = "test-key"
    args.json = True
    args.hold = []

    tw.cmd_deliver_forum(args)
    assert ("reply", 82) in _RecordingForum.calls, _RecordingForum.calls


# ── 5. A FAILED DELIVERY MUST NOT READ AS A QUIET SUCCESS ───────────────────
#
# Codex's review of this branch, 24 November 2026: the update phase threw away
# cmd_deliver_forum's return code and its error text, so a delivery that failed
# outright reported as a successful sync with nothing to deliver. Both outcomes
# printed the same line -- "UPDATES delivered: 0" -- and both exited 0. Nobody
# downstream, person or script, can tell them apart, and the wrong one of the
# two means updates the owner believes are on the board are not on it.
#
# Reading the sender turned up a THIRD shape of the same fault, which the
# review did not reach: cmd_deliver_forum catches a per-row delivery exception,
# files it under "skipped", and returns 0 regardless. sync then counted every
# skipped row as ALREADY IN PLACE -- so a thread that REFUSED a reply was
# reported as a thread that already had it. That is the worst of the three,
# because it is not merely silent, it asserts the opposite of what happened.


def test_a_failed_delivery_is_reported_as_a_refusal_not_an_empty_sync(
        tmp_path, monkeypatch):
    """The whole-command failure: rc was captured and never looked at."""
    s = _store(tmp_path)
    s.close()

    def _explodes(a):
        print("the armed sender needs a forum key", file=sys.stderr)
        return 2

    monkeypatch.setattr(tw, "cmd_deliver_forum", _explodes)

    rc = tw.cmd_sync_forum(_args(_db(tmp_path), phase="update"))
    assert rc != 0, "a failed delivery exited 0, so nothing downstream can see it"


def test_the_failure_text_reaches_the_report(tmp_path, monkeypatch, capsys):
    """A non-zero exit says something broke; it does not say what.

    Without the error text the operator is told a number and left to guess
    between a missing key, a refused board and a network that is down.
    """
    s = _store(tmp_path)
    s.close()

    def _explodes(a):
        print("the armed sender needs a forum key", file=sys.stderr)
        return 2

    monkeypatch.setattr(tw, "cmd_deliver_forum", _explodes)

    tw.cmd_sync_forum(_args(_db(tmp_path), phase="update", json=True))
    out = json.loads(capsys.readouterr().out)
    why = " ".join(r["why"] for r in out["refused"])
    assert "forum key" in why, out["refused"]


def test_a_row_the_board_refused_is_not_counted_as_already_in_place(
        tmp_path, monkeypatch, capsys):
    """The per-row failure, which is the one that misreports the board.

    "Skipped" covered two unlike things: rows that need nothing doing (already
    on the thread, not published yet, held by name) and rows the board
    REFUSED. Only the first kind is "already in place".

    Driven through the REAL sender with a board that refuses, rather than a
    hand-written payload. A fake payload would only prove that sync can read a
    shape I invented; what has to hold is that the two halves agree, and that
    is exactly the coupling that was broken.
    """

    class _RefusingForum(_RecordingForum):
        def reply(self, tid, body):
            raise RuntimeError("HTTP 500 from the board")

    s = _store(tmp_path)
    t = s.create(title="a thing", body="The thing does not work the way it should.")
    s.update(t.id, usability="stopped", affected=2, forum_thread=44)
    s.enqueue(t.id, "forum", "An update about the thing." + "\n\n" + "claude")
    s.close()

    monkeypatch.setattr(tw.forummod, "Forum", _RefusingForum)

    rc = tw.cmd_sync_forum(_args(_db(tmp_path), phase="update", json=True))
    out = json.loads(capsys.readouterr().out)

    refused = {r["ticket"]: r["why"] for r in out["refused"]}
    assert t.id in refused, "a refused delivery was not reported as refused"
    assert "HTTP 500" in refused[t.id], refused
    assert t.id not in out["already"], "a refused delivery was filed as already in place"
    assert out["updated"] == []
    assert rc != 0

    # And it is still queued, so releasing it later still sends it.
    s2 = _store(tmp_path)
    assert len([r for r in s2.outbox("queued") if r.get("ticket") == t.id]) == 1
    s2.close()


def test_a_genuine_skip_is_still_just_a_skip(tmp_path, monkeypatch, capsys):
    """The other half. Without it, "report failures" is satisfied by calling
    everything a failure, which would make the exit code useless."""
    s = _store(tmp_path)
    t = s.create(title="a thing", body="The thing does not work the way it should.")
    s.update(t.id, usability="stopped", affected=2)      # never published
    s.enqueue(t.id, "forum", "An update about the thing." + "\n\n" + "claude")
    s.close()

    monkeypatch.setattr(tw.forummod, "Forum", _RecordingForum)

    rc = tw.cmd_sync_forum(_args(_db(tmp_path), phase="update", json=True))
    out = json.loads(capsys.readouterr().out)

    assert out["refused"] == [], out["refused"]
    assert t.id in out["already"], out["already"]
    assert rc == 0


def test_a_clean_empty_update_run_still_succeeds(tmp_path, monkeypatch):
    """The half that makes the three above mean something.

    Without it they are all satisfied by a command that fails every time,
    which would be useless in a different way and would stop the sync being
    runnable at all.
    """
    s = _store(tmp_path)
    s.close()

    monkeypatch.setattr(tw, "cmd_deliver_forum",
                        lambda a: print(json.dumps({"delivered": [], "skipped": []})) or 0)

    assert tw.cmd_sync_forum(_args(_db(tmp_path), phase="update")) == 0


# ── 6. THE OPEN-SECURITY HOLD APPLIES TO UPDATES, NOT JUST TO NEW TICKETS ───
#
# Also Codex's. The ADD phase held an OPEN DOOR OPEN ticket back, and the
# UPDATE phase then delivered a queued reply about that same ticket with no
# band check at all. Sean's instruction was "please don't publish open holes".
# A reply describing an unfixed hole publishes it as surely as the first post
# does, and on a thread that is ALREADY on the board there is no first post to
# hold back -- so the ADD guard never even gets a chance to apply.
#
# The guard goes in the armed sender rather than in sync, because the sender
# is the publishing boundary: every caller is covered, not just this one.


def _published_door_open(s):
    t = s.create(title="a hole", body="There is a way in that should not be there.")
    s.update(t.id, security=True, usability="stopped", affected=5,
             forum_thread=91)
    return s.get(t.id)


def _deliver_args(tmp_path, **over):
    a = tw.build_parser().parse_args(["deliver-forum"])
    a.db = _db(tmp_path)
    a.as_who = model.CLAUDE
    a.forum_url = "https://forum.test"
    a.forum_key = "test-key"
    a.json = True
    for k, v in over.items():
        setattr(a, k, v)
    return a


def test_an_update_on_an_open_security_ticket_is_not_delivered(
        tmp_path, monkeypatch):
    """The ABSENCE of the call, not a side effect that failure would share.

    Asserting only "the row is still queued" would pass just as well if the
    sender had tried and the board had refused it. The forum must never be
    asked at all.
    """
    s = _store(tmp_path)
    t = _published_door_open(s)
    assert t.band == "DOOR OPEN", t.band
    assert t.state in model.OPEN_STATES
    s.enqueue(t.id, "forum", "Still looking into this." + "\n\n" + "claude")
    s.close()

    monkeypatch.setattr(tw.forummod, "Forum", _RecordingForum)

    rc = tw.cmd_deliver_forum(_deliver_args(tmp_path))
    assert rc == 0
    assert _RecordingForum.calls == [], _RecordingForum.calls

    s2 = _store(tmp_path)
    still = [r for r in s2.outbox("queued") if r.get("ticket") == t.id]
    assert len(still) == 1, "the held update was consumed instead of kept"
    s2.close()


def test_include_security_delivers_that_same_update(tmp_path, monkeypatch):
    """The other direction, so the hold above is a decision and not a breakage."""
    s = _store(tmp_path)
    t = _published_door_open(s)
    s.enqueue(t.id, "forum", "Still looking into this." + "\n\n" + "claude")
    s.close()

    monkeypatch.setattr(tw.forummod, "Forum", _RecordingForum)

    tw.cmd_deliver_forum(_deliver_args(tmp_path, include_security=True))
    assert ("reply", 91) in _RecordingForum.calls, _RecordingForum.calls


def test_a_fixed_security_ticket_still_gets_its_update(tmp_path, monkeypatch):
    """Hold while OPEN, publish when FIXED -- the rule the ADD phase uses.

    Without this the hold could be "never deliver to a DOOR OPEN thread",
    which would strand the closure note on every security ticket ever fixed.
    """
    s = _store(tmp_path)
    t = _published_door_open(s)
    s.update(t.id, state=model.RESOLVED)
    s.enqueue(t.id, "forum", "Fixed in the next build." + "\n\n" + "claude")
    s.close()

    monkeypatch.setattr(tw.forummod, "Forum", _RecordingForum)

    tw.cmd_deliver_forum(_deliver_args(tmp_path))
    assert ("reply", 91) in _RecordingForum.calls, _RecordingForum.calls


def test_an_ordinary_open_ticket_is_untouched_by_the_security_hold(
        tmp_path, monkeypatch):
    """The hold must key on the BAND, not on 'open'. A hold that caught every
    open ticket would deliver nothing at all and still pass every test above."""
    s = _store(tmp_path)
    t = s.create(title="a thing", body="The thing does not work the way it should.")
    s.update(t.id, usability="stopped", affected=2, forum_thread=91)
    assert s.get(t.id).band != "DOOR OPEN"
    s.enqueue(t.id, "forum", "Looking at this now." + "\n\n" + "claude")
    s.close()

    monkeypatch.setattr(tw.forummod, "Forum", _RecordingForum)

    tw.cmd_deliver_forum(_deliver_args(tmp_path))
    assert ("reply", 91) in _RecordingForum.calls, _RecordingForum.calls


# ── TWO THINGS NOTHING WAS HOLDING, FOUND BY MUTATING THE FIXES ─────────────
#
# Both mutants below SURVIVED the tests above. Neither gap was hypothetical:
# the first lets the armed sender tell any caller that a refused delivery
# succeeded, and sync only happened to catch it by reading a different field.
# The second lets the security hold work perfectly and never be reported, so
# an operator would see a lower delivered count with no reason given for it --
# the quiet failure this whole review is about, wearing a different hat.


def test_the_sender_itself_exits_non_zero_when_the_board_refuses(
        tmp_path, monkeypatch):
    """The sender's exit code is a contract for every caller, not just sync.

    MUTANT THAT SURVIVED WITHOUT THIS: `return 1 if failed else 0` -> `return
    0`. sync still reported the failure, because it reads the failed list, so
    every other test passed -- and anything else calling deliver-forum, a
    hook or a person at a prompt, was told it had worked.
    """

    class _RefusingForum(_RecordingForum):
        def reply(self, tid, body):
            raise RuntimeError("HTTP 500 from the board")

    s = _store(tmp_path)
    t = s.create(title="a thing", body="The thing does not work the way it should.")
    s.update(t.id, usability="stopped", affected=2, forum_thread=44)
    s.enqueue(t.id, "forum", "An update about the thing." + "\n\n" + "claude")
    s.close()

    monkeypatch.setattr(tw.forummod, "Forum", _RefusingForum)
    assert tw.cmd_deliver_forum(_deliver_args(tmp_path)) != 0


def test_the_sender_still_exits_zero_when_it_merely_held_something(
        tmp_path, monkeypatch):
    """A hold is a decision, not a failure. Without this half, the test above
    is satisfied by a sender that fails whenever it does not deliver."""
    s = _store(tmp_path)
    t = _published_door_open(s)
    s.enqueue(t.id, "forum", "Still looking into this." + "\n\n" + "claude")
    s.close()

    monkeypatch.setattr(tw.forummod, "Forum", _RecordingForum)
    assert tw.cmd_deliver_forum(_deliver_args(tmp_path)) == 0


def test_sync_reports_an_update_held_for_open_security(tmp_path, monkeypatch, capsys):
    """Holding it is half the job; saying so is the other half.

    MUTANT THAT SURVIVED WITHOUT THIS: sync dropping the sender's
    held_security list. The update was correctly withheld and the operator was
    told nothing -- a count that is lower than expected with no reason beside
    it, which is how somebody ends up re-running with --include-security to
    see what happens.
    """
    s = _store(tmp_path)
    t = _published_door_open(s)
    s.enqueue(t.id, "forum", "Still looking into this." + "\n\n" + "claude")
    s.close()

    monkeypatch.setattr(tw.forummod, "Forum", _RecordingForum)

    rc = tw.cmd_sync_forum(_args(_db(tmp_path), phase="update", json=True))
    out = json.loads(capsys.readouterr().out)

    assert t.id in out["held_security"], out
    assert out["updated"] == []
    assert t.id not in out["already"], "a held update was reported as already done"
    assert rc == 0, "a hold is a decision, not a failure"


def test_a_ticket_held_in_both_phases_is_counted_once(tmp_path, monkeypatch, capsys):
    """Muse's nit on this branch, and it is a counting bug, not a cosmetic one.

    Extending the open-security hold to the update phase made it possible for
    ONE ticket to be held twice in a single run: once as a post the ADD phase
    will not make, once as a queued reply the sender will not deliver.
    held_pulse was already deduped and held_security was not, so the report
    said two and the number stopped being a count of tickets.

    Driven with a full-phase run against a ticket that qualifies for both
    holds, rather than by calling sorted(set(...)) and agreeing with myself.
    """
    s = _store(tmp_path)
    t = s.create(title="a hole", body="There is a way in that should not be there.")
    s.update(t.id, security=True, usability="stopped", affected=5)
    assert s.get(t.id).band == "DOOR OPEN"
    assert (s.get(t.id).forum_thread or 0) == 0     # so ADD would want it
    s.enqueue(t.id, "forum", "Still looking into this." + "\n\n" + "claude")
    s.close()

    monkeypatch.setattr(tw.forummod, "Forum", _RecordingForum)

    rc = tw.cmd_sync_forum(_args(_db(tmp_path), phase="all", json=True))
    out = json.loads(capsys.readouterr().out)

    assert out["held_security"].count(t.id) == 1, out["held_security"]
    assert out["added"] == [] and out["updated"] == []
    assert _RecordingForum.calls == [], "a held ticket reached the board anyway"
    assert rc == 0

# ===== SNAPSMACK EOF =====
