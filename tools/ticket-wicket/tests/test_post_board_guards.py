"""The three refusals that stop `tw.py post` making a mess of the forum.

WHY THESE EXIST. On 29 October 2026, in one evening, this command:

  * created three DUPLICATE threads for tickets that already had one --
    SS-1006 (48 and 89), SS-1033 (71 and 87), SS-1064 (86 and 93) -- and
    overwrote forum_thread each time, so each ticket ended up pointing at the
    copy instead of the original that carried the replies; and
  * had been quietly creating a board nobody asked for since 17 October,
    because its --board default was "Tickets" while AGENTS.md names the ROACH
    MOTEL board, and ensure_board creates a board when the name does not
    match. The owner found that board on his own board list and asked why it
    was there.

The duplicates were written down as a known hazard an hour before the third
one happened. That is the argument for a refusal in the code rather than for
being more careful.

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

import tw                                     # noqa: E402
from ticketwicket import model, store as storemod   # noqa: E402


class FakeForum:
    """Records what it was asked to do. ensure_board raises, because the point
    of the change is that this command can no longer reach it."""

    def __init__(self, boards):
        self._boards = boards
        self.posted = []

    def categories(self):
        return [{"id": i, "name": n} for n, i in self._boards.items()]

    def ensure_board(self, *a, **k):
        raise AssertionError(
            "tw.py post must never call ensure_board: that is how a board "
            "nobody asked for came to exist.")

    def post_ticket(self, category_id, title, body):
        self.posted.append((category_id, title))
        return 500 + len(self.posted)


@pytest.fixture()
def store(tmp_path):
    s = storemod.Store(path=str(tmp_path / "t.db"), actor=model.CLAUDE)
    yield s
    s.close()


def _args(**over):
    base = dict(id="SS-1", board="Roach Motel", again=False, dry_run=False,
                forum_url="https://forum.invalid/api", forum_key="k",
                json=False, db=None, actor=model.CLAUDE)
    base.update(over)
    return type("Args", (), base)()


def _ticket(store, **over):
    fields = dict(title="A thing is broken", body="The thing is broken.",
                  reporter="", usability="stopped")
    fields.update(over)
    return store.create(**fields)


# ── the board default matches the written rule ───────────────────────────────

def test_the_post_board_default_is_the_board_agents_md_names():
    """"Tickets" was the default from the day the tool was written, and
    AGENTS.md has always said ROACH MOTEL. That disagreement is the whole
    cause."""
    parser = tw.build_parser() if hasattr(tw, "build_parser") else None
    if parser is None:
        # The parser is built inside main(); read the default off the source
        # rather than skipping the assertion entirely.
        src = open(os.path.join(BASE, "tw.py"), encoding="utf-8").read()
        assert 'po.add_argument("--board", default="Roach Motel")' in src
        assert 'default="Tickets"' not in src
        return
    args = parser.parse_args(["post", "SS-1"])
    assert args.board == "Roach Motel"


# ── a board is looked up, never created ──────────────────────────────────────

def test_board_id_finds_an_existing_board_ignoring_case():
    forum = FakeForum({"Roach Motel": 3, "The Lounge": 83})
    assert tw._board_id(forum, "roach motel") == 3
    assert tw._board_id(forum, "  Roach Motel  ") == 3


def test_board_id_returns_nothing_for_a_board_that_does_not_exist():
    forum = FakeForum({"Roach Motel": 3})
    assert tw._board_id(forum, "Tickets") == 0


def test_posting_to_a_missing_board_refuses_instead_of_creating_it(
        store, monkeypatch, capsys):
    forum = FakeForum({"Roach Motel": 3})          # no "Tickets"
    monkeypatch.setattr(tw.forummod, "Forum", lambda *a, **k: forum)
    monkeypatch.setattr(tw, "_store", lambda args: store)
    ticket = _ticket(store)

    code = tw.cmd_post(_args(id=ticket.id, board="Tickets"))

    assert code == 1
    assert forum.posted == []                      # nothing published
    err = capsys.readouterr().err
    assert "no board called" in err
    assert "fc.py board-new" in err                # told how to do it on purpose


def test_posting_to_a_board_that_exists_still_works(store, monkeypatch):
    forum = FakeForum({"Roach Motel": 3})
    monkeypatch.setattr(tw.forummod, "Forum", lambda *a, **k: forum)
    monkeypatch.setattr(tw, "_store", lambda args: store)
    ticket = _ticket(store)

    code = tw.cmd_post(_args(id=ticket.id))

    assert code == 0
    assert len(forum.posted) == 1
    assert forum.posted[0][0] == 3                 # the board it looked up


# ── the duplicate guard, which is the one that actually bit ──────────────────

def test_a_ticket_that_is_already_published_is_not_published_again(
        store, monkeypatch, capsys):
    forum = FakeForum({"Roach Motel": 3})
    monkeypatch.setattr(tw.forummod, "Forum", lambda *a, **k: forum)
    monkeypatch.setattr(tw, "_store", lambda args: store)
    ticket = _ticket(store)
    store.update(ticket.id, forum_thread=48)

    code = tw.cmd_post(_args(id=ticket.id))

    assert code == 1
    assert forum.posted == []
    err = capsys.readouterr().err
    assert "already forum thread 48" in err
    assert "reply" in err                          # points at the right command


def test_the_existing_thread_number_is_not_overwritten_by_a_refusal(
        store, monkeypatch):
    """The original fault was not only the duplicate: post repointed the
    ticket at the copy, orphaning the thread that had the replies."""
    forum = FakeForum({"Roach Motel": 3})
    monkeypatch.setattr(tw.forummod, "Forum", lambda *a, **k: forum)
    monkeypatch.setattr(tw, "_store", lambda args: store)
    ticket = _ticket(store)
    store.update(ticket.id, forum_thread=48)

    tw.cmd_post(_args(id=ticket.id))

    assert store.get(ticket.id).forum_thread == 48


def test_again_is_there_for_the_rare_real_second_thread(store, monkeypatch):
    forum = FakeForum({"Roach Motel": 3})
    monkeypatch.setattr(tw.forummod, "Forum", lambda *a, **k: forum)
    monkeypatch.setattr(tw, "_store", lambda args: store)
    ticket = _ticket(store)
    store.update(ticket.id, forum_thread=48)

    code = tw.cmd_post(_args(id=ticket.id, again=True))

    assert code == 0
    assert len(forum.posted) == 1

# ===== SNAPSMACK EOF =====
