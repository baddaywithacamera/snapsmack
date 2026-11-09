"""SS-1099: a forum reply to a published ticket is DELIVERED, not left to rot.

Before this, `tw reply` only queued into an outbox and a 'separate armed sender'
that never existed was meant to deliver it -- so reporters got no answer and the
Roach Motel board sat at 8 replies against ~190 threads. These tests pin the two
halves of the fix:

  - `tw deliver-forum` drains queued forum replies to each ticket's thread,
    resolving the thread from the board when the store lost it, and marks the
    outbox row sent so nothing double-posts; an unpublished ticket is skipped,
    its reply left queued.
  - `tw reply` delivers immediately when it has a key and a published thread.

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


class FakeForum:
    def __init__(self, threads_by_title=None, posts_by_thread=None):
        self._by_title = threads_by_title or {}
        self._posts = {int(k): v for k, v in (posts_by_thread or {}).items()}
        self.replied = []           # (thread_id, body)

    def reply(self, thread_id, body):
        self.replied.append((int(thread_id), body))
        return 900 + len(self.replied)

    def search(self, query, category_id=0, page=1):
        hits = [{"id": tid, "title": title}
                for title, tid in self._by_title.items()
                if title.startswith(query)]
        return {"threads": hits}

    def thread(self, thread_id):
        bodies = self._posts.get(int(thread_id), [])
        op = {"body": bodies[0] if bodies else ""}
        replies = [{"body": b} for b in bodies[1:]]
        return {"thread": op, "replies": replies}


@pytest.fixture()
def store(tmp_path):
    s = storemod.Store(path=str(tmp_path / "t.db"), actor=model.CLAUDE)
    yield s
    s.close()


def _args(**over):
    base = dict(id="SS-1", channel="forum", text=None, text_file=None,
                unwrap=False, as_who=model.CLAUDE, json=True, db=None,
                actor=model.CLAUDE, state="queued",
                forum_url="https://forum.invalid/api", forum_key="k")
    base.update(over)
    return type("Args", (), base)()


def _ticket(store, **over):
    fields = dict(title="A thing is broken", body="The thing is broken.",
                  reporter="", usability="stopped")
    fields.update(over)
    return store.create(**fields)


def test_deliver_forum_sends_a_published_tickets_reply_and_marks_it_sent(store, monkeypatch):
    t = _ticket(store)
    store.update(t.id, forum_thread=77)          # published, store knows the thread
    store.enqueue(t.id, "forum", "Fixed in the next build.\n\n-- the SnapSmack assistant")
    forum = FakeForum()
    monkeypatch.setattr(tw.forummod, "Forum", lambda *a, **k: forum)
    monkeypatch.setattr(tw, "_store", lambda args: store)

    code = tw.cmd_deliver_forum(_args())
    assert code == 0
    assert forum.replied and forum.replied[0][0] == 77      # reply hit thread 77
    assert store.outbox("queued") == []                     # nothing left queued
    assert len(store.outbox("sent")) == 1                   # and it is recorded sent


def test_deliver_forum_backfills_the_thread_from_the_board_when_the_store_lost_it(store, monkeypatch):
    t = _ticket(store, title="masthead font dead")
    store.enqueue(t.id, "forum", "Fixed.\n\n-- the SnapSmack assistant")
    forum = FakeForum(threads_by_title={f"{t.id} · masthead font dead": 132})
    monkeypatch.setattr(tw.forummod, "Forum", lambda *a, **k: forum)
    monkeypatch.setattr(tw, "_store", lambda args: store)

    code = tw.cmd_deliver_forum(_args())
    assert code == 0
    assert forum.replied and forum.replied[0][0] == 132      # found via search
    assert int(store.get(t.id).forum_thread) == 132          # and backfilled


def test_deliver_forum_skips_an_unpublished_ticket_and_keeps_its_reply(store, monkeypatch):
    t = _ticket(store)
    store.enqueue(t.id, "forum", "Fixed.\n\n-- the SnapSmack assistant")
    forum = FakeForum()                                     # search finds nothing
    monkeypatch.setattr(tw.forummod, "Forum", lambda *a, **k: forum)
    monkeypatch.setattr(tw, "_store", lambda args: store)

    code = tw.cmd_deliver_forum(_args())
    assert code == 0
    assert forum.replied == []                               # nowhere to post
    assert len(store.outbox("queued")) == 1                  # reply stays, not lost


def test_reply_delivers_immediately_to_a_published_ticket(store, monkeypatch):
    t = _ticket(store)
    store.update(t.id, forum_thread=55)
    forum = FakeForum()
    monkeypatch.setattr(tw.forummod, "Forum", lambda *a, **k: forum)
    monkeypatch.setattr(tw, "_store", lambda args: store)

    code = tw.cmd_reply(_args(id=t.id, channel="forum", text="Looking into it."))
    assert code == 0
    assert forum.replied and forum.replied[0][0] == 55       # sent now, not queued
    assert store.outbox("queued") == []


def test_reply_still_queues_an_email_without_sending(store, monkeypatch):
    t = _ticket(store)
    store.update(t.id, forum_thread=55)
    forum = FakeForum()
    monkeypatch.setattr(tw.forummod, "Forum", lambda *a, **k: forum)
    monkeypatch.setattr(tw, "_store", lambda args: store)

    code = tw.cmd_reply(_args(id=t.id, channel="email", text="Looking into it."))
    assert code == 0
    assert forum.replied == []                               # email is the owner's to send
    assert len(store.outbox("queued")) == 1


# ---- the forum-routing rescue (Sean, 2026-11-08: updates go into forum threads) ----

def test_deliver_forum_rescues_an_email_update_on_a_published_ticket_with_no_inbox(store, monkeypatch):
    """An update parked as email, on a forum ticket with no address, belongs on the thread."""
    t = _ticket(store, reporter="")                 # no email address on file
    store.update(t.id, forum_thread=201)            # but it is published to the forum
    store.enqueue(t.id, "email", "Fixed in v0.7.978D.\n\n-- the SnapSmack assistant")
    forum = FakeForum()
    monkeypatch.setattr(tw.forummod, "Forum", lambda *a, **k: forum)
    monkeypatch.setattr(tw, "_store", lambda args: store)

    code = tw.cmd_deliver_forum(_args())
    assert code == 0
    assert forum.replied and forum.replied[0][0] == 201     # the email update reached the thread
    assert store.outbox("queued") == []                     # nothing left parked
    assert len(store.outbox("sent")) == 1


def test_deliver_forum_leaves_a_real_email_address_for_the_owner(store, monkeypatch):
    """A reply to a ticket with an actual address is genuine mail, not a board post."""
    t = _ticket(store, reporter="someone@example.com")
    store.update(t.id, forum_thread=202)
    store.enqueue(t.id, "email", "A private note to the reporter.")
    forum = FakeForum()
    monkeypatch.setattr(tw.forummod, "Forum", lambda *a, **k: forum)
    monkeypatch.setattr(tw, "_store", lambda args: store)

    code = tw.cmd_deliver_forum(_args())
    assert code == 0
    assert forum.replied == []                              # never auto-posted to the public board
    assert len(store.outbox("queued")) == 1                 # left for the owner to send


def test_deliver_forum_leaves_an_email_update_with_no_thread_alone(store, monkeypatch):
    """No thread means nowhere to post; the row stays, it is not guessed onto a board."""
    t = _ticket(store, reporter="")
    store.enqueue(t.id, "email", "Status: looking into it.")
    forum = FakeForum()                                     # search finds no thread
    monkeypatch.setattr(tw.forummod, "Forum", lambda *a, **k: forum)
    monkeypatch.setattr(tw, "_store", lambda args: store)

    code = tw.cmd_deliver_forum(_args())
    assert code == 0
    assert forum.replied == []
    assert len(store.outbox("queued")) == 1


def test_deliver_forum_does_not_repost_an_update_already_on_the_thread(store, monkeypatch):
    """Idempotent: an update the thread already carries is reconciled, never doubled."""
    t = _ticket(store, reporter="")
    store.update(t.id, forum_thread=77)
    body = "CMS SIDE LANDED, FOLDED INTO 0.7.970D.\n\n-- the SnapSmack assistant"
    store.enqueue(t.id, "forum", body)
    forum = FakeForum(posts_by_thread={77: ["the original ticket text",
                                            "CMS SIDE LANDED, FOLDED INTO 0.7.970D. (earlier inline update)"]})
    monkeypatch.setattr(tw.forummod, "Forum", lambda *a, **k: forum)
    monkeypatch.setattr(tw, "_store", lambda args: store)

    code = tw.cmd_deliver_forum(_args())
    assert code == 0
    assert forum.replied == []                              # already there -> not reposted
    assert store.outbox("queued") == []                     # but reconciled
    assert len(store.outbox("sent")) == 1

# ===== SNAPSMACK EOF =====
