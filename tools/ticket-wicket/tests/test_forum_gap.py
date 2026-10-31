"""What the public board still says, versus what the ticket says now.

A ticket is published to the Roach Motel board ONCE and then keeps changing.
Nothing re-publishes it, so the board drifts away from the record. Measured on
the real store, 31 October 2026: 119 tickets, 49 published, and 27 of those 49
had changed since the board last heard -- 19 of them showing a state that is no
longer true, including nine resolved or closed tickets the board still presents
as open.

Nobody knew that number before it was counted, which is the argument for a
command rather than a habit.

THE BOARD'S STATE IS RECOVERABLE, which is the one clever part. store.update()
records a change as "state: 'triaged' -> 'resolved'", so the state as published
is the OLD half of the FIRST state change after the last publication. The
command reads it out of the audit trail rather than guessing or re-fetching the
thread -- it touches no network at all.

Run:  .python-build/python.exe -m pytest tools/ticket-wicket/tests -q

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import os
import sys

import pytest

BASE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if BASE not in sys.path:
    sys.path.insert(0, BASE)

import tw                                              # noqa: E402
from ticketwicket import model, store as storemod      # noqa: E402


@pytest.fixture()
def store(tmp_path):
    s = storemod.Store(path=str(tmp_path / "t.db"), actor=model.CLAUDE)
    yield s
    s.close()


def _args(**over):
    base = dict(json=True, db=None, actor=model.CLAUDE, as_who=model.CLAUDE)
    base.update(over)
    return type("Args", (), base)()


def gap(store, monkeypatch, capsys):
    """Run the command and give back its rows."""
    import json
    monkeypatch.setattr(tw, "_store", lambda args: store)
    code = tw.cmd_forum_gap(_args())
    assert code == 0
    return json.loads(capsys.readouterr().out)


def test_a_ticket_nobody_published_is_never_listed(store, monkeypatch, capsys):
    store.create(title="Never posted", body="A thing.", reporter="")
    assert gap(store, monkeypatch, capsys) == []


def test_a_published_ticket_that_has_not_changed_is_not_listed(store, monkeypatch, capsys):
    t = store.create(title="Posted and left alone", body="A thing.", reporter="")
    store.update(t.id, forum_thread=42)
    store.add_entry(t.id, "post", "posted to forum thread 42")
    assert gap(store, monkeypatch, capsys) == []


def test_a_state_change_after_publication_is_reported_with_both_states(
        store, monkeypatch, capsys):
    t = store.create(title="Changed after posting", body="A thing.",
                     reporter="", usability="stopped")
    store.update(t.id, forum_thread=43)
    store.add_entry(t.id, "post", "posted to forum thread 43")
    store.update(t.id, state=model.RESOLVED)

    rows = gap(store, monkeypatch, capsys)
    assert len(rows) == 1
    row = rows[0]
    assert row["id"] == t.id
    assert row["thread"] == 43
    assert row["state"] == model.RESOLVED
    # The board still shows what it was told, which is the whole point.
    assert row["board_state"] == model.NEW
    assert row["changes"] >= 1


def test_a_state_change_BEFORE_publication_is_not_mistaken_for_the_board_state(
        store, monkeypatch, capsys):
    """new -> triaged -> POST -> resolved.

    The board was told 'triaged', so that is what it still shows. A reading
    that scanned the WHOLE log would find the new->triaged change first and
    report 'new', which is a state the board never displayed. Every other test
    here happens to publish before the first state change, so the two readings
    coincide and the mutation that removed the slice went unnoticed until this
    case was written.
    """
    t = store.create(title="Changed twice", body="A thing.", reporter="")
    store.update(t.id, state=model.TRIAGED)          # before publication
    store.update(t.id, forum_thread=50)
    store.add_entry(t.id, "post", "posted to forum thread 50")
    store.update(t.id, state=model.RESOLVED)         # after publication

    rows = gap(store, monkeypatch, capsys)
    assert len(rows) == 1
    assert rows[0]["state"] == model.RESOLVED
    assert rows[0]["board_state"] == model.TRIAGED, (
        "the board was told triaged; reporting %r means the whole log was "
        "scanned instead of only what happened after publication"
        % rows[0]["board_state"])


def test_a_body_edit_counts_as_drift_even_with_no_state_change(
        store, monkeypatch, capsys):
    t = store.create(title="Body edited", body="First words.", reporter="")
    store.update(t.id, forum_thread=44)
    store.add_entry(t.id, "post", "posted to forum thread 44")
    store.update(t.id, body="Different words entirely.")

    rows = gap(store, monkeypatch, capsys)
    assert len(rows) == 1
    # Nothing changed the state, so the board's state is not wrong -- but the
    # body it shows is. Reported with an empty board_state rather than a guess.
    assert rows[0]["board_state"] == ""
    assert rows[0]["changes"] >= 1


def test_a_forum_reply_counts_as_the_board_having_heard(store, monkeypatch, capsys):
    t = store.create(title="Replied on the board", body="A thing.", reporter="")
    store.update(t.id, forum_thread=45)
    store.add_entry(t.id, "post", "posted to forum thread 45")
    store.update(t.id, state=model.TRIAGED)
    store.add_entry(t.id, "reply", "an update", channel="forum")

    # Everything before the forum reply is published; nothing after it.
    assert gap(store, monkeypatch, capsys) == []


def test_an_email_reply_does_not_count_as_the_board_having_heard(
        store, monkeypatch, capsys):
    """An email to the reporter is not on the board. Counting it would make a
    ticket answered privately look published while the thread still shows the
    original."""
    t = store.create(title="Answered by email", body="A thing.", reporter="")
    store.update(t.id, forum_thread=46)
    store.add_entry(t.id, "post", "posted to forum thread 46")
    store.update(t.id, state=model.TRIAGED)
    store.add_entry(t.id, "reply", "answered the reporter", channel="email")

    rows = gap(store, monkeypatch, capsys)
    assert len(rows) == 1
    assert rows[0]["board_state"] == model.NEW


def test_a_thread_number_with_no_post_entry_is_reported_not_skipped(
        store, monkeypatch, capsys):
    """This really happens: SS-1067 was on the board as thread 94 while the
    store recorded no publication for it. A ticket in that state must not be
    silently treated as up to date."""
    t = store.create(title="Thread but no receipt", body="A thing.", reporter="")
    store.update(t.id, forum_thread=94)

    rows = gap(store, monkeypatch, capsys)
    assert len(rows) == 1
    assert rows[0]["published"] == ""
    assert "no post entry" in rows[0]["note"]


def test_a_security_ticket_is_flagged_so_it_is_never_swept_up(
        store, monkeypatch, capsys):
    t = store.create(title="A hole", body="A thing.", reporter="", security=True)
    store.update(t.id, forum_thread=47)
    store.add_entry(t.id, "post", "posted to forum thread 47")
    store.update(t.id, state=model.RESOLVED)

    rows = gap(store, monkeypatch, capsys)
    assert len(rows) == 1
    assert rows[0]["security"] is True


def test_the_text_output_names_the_security_rule(store, monkeypatch, capsys):
    """Whoever reads this list is deciding what to publish, so the one thing
    that can never be swept up has to be on the screen, not in a ticket."""
    import json
    t = store.create(title="A hole", body="A thing.", reporter="", security=True)
    store.update(t.id, forum_thread=48)
    store.add_entry(t.id, "post", "posted to forum thread 48")
    store.update(t.id, state=model.RESOLVED)

    monkeypatch.setattr(tw, "_store", lambda args: store)
    assert tw.cmd_forum_gap(_args(json=False)) == 0
    out = capsys.readouterr().out
    assert "security-flagged" in out
    assert "owner's decision" in out


def test_nothing_drifted_says_so_rather_than_printing_an_empty_table(
        store, monkeypatch, capsys):
    store.create(title="Never posted", body="A thing.", reporter="")
    monkeypatch.setattr(tw, "_store", lambda args: store)
    assert tw.cmd_forum_gap(_args(json=False)) == 0
    assert "Nothing has drifted" in capsys.readouterr().out


def test_it_reaches_no_network(store, monkeypatch, capsys):
    """The whole point of reading the audit trail instead of the thread: this
    can be run at any time, on a laptop, without touching a live board."""
    import ticketwicket.forum as forummod

    def explode(*a, **k):
        raise AssertionError("forum-gap must not construct a forum client")

    monkeypatch.setattr(forummod, "Forum", explode)
    t = store.create(title="Changed", body="A thing.", reporter="")
    store.update(t.id, forum_thread=49)
    store.add_entry(t.id, "post", "posted to forum thread 49")
    store.update(t.id, state=model.RESOLVED)
    assert len(gap(store, monkeypatch, capsys)) == 1

# ===== SNAPSMACK EOF =====
