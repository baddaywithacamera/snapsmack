"""A ticket is not published in a shape the forum renders wrongly.

WHAT SEAN SAW, 30 October 2026: a screenshot of live thread 94 (SS-1067). The
body had been hard-wrapped at about 72 columns and the forum honours every
newline, so each wrapped line arrived as its own short ragged line; the post
also said "**affected**" and showed the asterisks, because the renderer has
never read markdown.

Measured in the store that evening: 49 tickets carry a forum thread, 21 of
their bodies are hard-wrapped, and 3 of the 7 queued replies in the outbox
are too. The asterisks were worse than that -- they came from the POST
TEMPLATE, so every one of the 49 threads shows them.

Nothing in these tools ever wrapped anything. The wrapping was typed by
whoever wrote the draft, which is why the guard sits at the publishing
boundary and refuses, rather than quietly reformatting.

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

import tw                                             # noqa: E402
import snap_forum                                     # noqa: E402
from ticketwicket import anonymise, model, store as storemod   # noqa: E402

WRAPPED = (
    "The desktop test files are scripts that pytest collects nothing from,\n"
    "so a green run over that directory means much less than it looks like\n"
    "it does.\n"
    "\n"
    "Running it reports twenty-one tests collected and all of them passing,\n"
    "and only two files contribute any of them.\n"
)

UNWRAPPED = (
    "The desktop test files are scripts that pytest collects nothing from, so a green run over that directory means much less than it looks like it does.\n"
    "\n"
    "Running it reports twenty-one tests collected and all of them passing, and only two files contribute any of them.\n"
)


class FakeForum:
    def __init__(self, *_a, **_k):
        self.posted = []
        FakeForum.last = self

    def categories(self):
        return [{"id": 3, "name": "Roach Motel"}]

    def post_ticket(self, category_id, title, body):
        self.posted.append((category_id, title, body))
        return 500 + len(self.posted)


@pytest.fixture()
def store(tmp_path):
    s = storemod.Store(path=str(tmp_path / "t.db"), actor=model.CLAUDE)
    yield s
    s.close()


@pytest.fixture()
def forum(monkeypatch, store):
    f = FakeForum()
    monkeypatch.setattr(tw.forummod, "Forum", lambda *a, **k: f)
    monkeypatch.setattr(tw, "_store", lambda args: store)
    return f


def _args(**over):
    base = dict(id="SS-1", board="Roach Motel", again=False, dry_run=False,
                unwrap=False, forum_url="https://forum.invalid/api",
                forum_key="k", json=False, db=None, actor=model.CLAUDE,
                as_who=model.CLAUDE, text="", text_file="", channel="forum")
    base.update(over)
    return type("Args", (), base)()


# ── the template, which is what put asterisks on all 49 threads ─────────────

def test_the_public_post_contains_no_markdown_the_forum_ignores():
    t = model.Ticket(id="SS-1067", title="A thing", body="The thing is broken.",
                     band=model.DOOR_OPEN, state=model.NEW, affected=1)
    post = anonymise.forum_post(t)
    assert snap_forum.dead_markdown(post) == [], post


def test_the_post_still_names_the_ticket_and_the_affected_button():
    t = model.Ticket(id="SS-1067", title="A thing", body="The thing is broken.",
                     band=model.DOOR_OPEN, state=model.NEW, affected=1)
    post = anonymise.forum_post(t)
    assert "SS-1067" in post
    # The instruction has to survive losing its emphasis, or the reader is not
    # told how to say "me too".
    assert "AFFECTED" in post


# ── posting ─────────────────────────────────────────────────────────────────

def test_a_hard_wrapped_ticket_is_not_posted(store, forum, capsys):
    t = store.create(title="A thing", body=WRAPPED, reporter="",
                     usability="stopped")
    code = tw.cmd_post(_args(id=t.id))
    assert code == 1
    assert forum.posted == []
    err = capsys.readouterr().err
    assert "hard-wrapped" in err
    assert "--unwrap" in err
    assert "triage" in err                 # the other way to fix it


def test_a_refused_post_leaves_the_stored_ticket_alone(store, forum):
    t = store.create(title="A thing", body=WRAPPED, reporter="",
                     usability="stopped")
    tw.cmd_post(_args(id=t.id))
    after = store.get(t.id)
    assert after.body == WRAPPED           # not quietly reformatted
    assert (after.forum_thread or 0) == 0  # and not marked as published


def test_unwrap_posts_it_with_the_lines_joined(store, forum):
    t = store.create(title="A thing", body=WRAPPED, reporter="",
                     usability="stopped")
    code = tw.cmd_post(_args(id=t.id, unwrap=True))
    assert code == 0
    assert len(forum.posted) == 1
    sent = forum.posted[0][2]
    assert snap_forum.hard_wrapped_lines(sent) == []
    # The paragraphs are still two paragraphs.
    assert "\n\n" in sent


def test_an_unwrapped_ticket_posts_without_the_flag(store, forum):
    t = store.create(title="A thing", body=UNWRAPPED, reporter="",
                     usability="stopped")
    assert tw.cmd_post(_args(id=t.id)) == 0
    assert len(forum.posted) == 1


def test_unwrap_does_not_rescue_a_body_full_of_markdown(store, forum, capsys):
    """Stripping ** out of somebody's sentence can change what it says, so
    that stays a refusal for a person to fix."""
    t = store.create(title="A thing", body="This is **important** and it is.",
                     reporter="", usability="stopped")
    code = tw.cmd_post(_args(id=t.id, unwrap=True))
    assert code == 1
    assert forum.posted == []
    assert "does not render" in capsys.readouterr().err


# ── queued replies, which are written to be published ───────────────────────

def test_a_hard_wrapped_reply_is_not_even_queued(store, forum, capsys):
    t = store.create(title="A thing", body=UNWRAPPED, reporter="",
                     usability="stopped")
    code = tw.cmd_reply(_args(id=t.id, text=WRAPPED))
    assert code == 1
    assert "hard-wrapped" in capsys.readouterr().err
    assert store.outbox() == []        # nothing waiting to be sent


def test_an_unwrapped_reply_queues(store, forum):
    t = store.create(title="A thing", body=UNWRAPPED, reporter="",
                     usability="stopped")
    assert tw.cmd_reply(_args(id=t.id, text="Fixed in the next build.")) == 0
    assert len(store.outbox()) == 1   # so the refusal above is a real absence


def test_unwrap_queues_the_reply_with_its_lines_joined(store, forum):
    t = store.create(title="A thing", body=UNWRAPPED, reporter="",
                     usability="stopped")
    assert tw.cmd_reply(_args(id=t.id, text=WRAPPED, unwrap=True)) == 0
    queued = store.outbox()
    assert len(queued) == 1
    body = queued[0]["body"] if isinstance(queued[0], dict) else queued[0].body
    assert snap_forum.hard_wrapped_lines(body) == []
    assert model.SIGNATURES[model.CLAUDE] in body   # still signed

# ===== SNAPSMACK EOF =====
