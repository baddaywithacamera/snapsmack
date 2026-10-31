"""FORUM CLI — what it promises and what it refuses.

Nothing here touches the network. Every test drives the parser and a fake
client, because a CLI that an agent depends on fails in two ways: it forgets a
command the agent sheet promises, or it sends a field the API does not read.
The second one is what broke lock() for its whole life -- "locked" where the
API reads "is_locked" -- and it was invisible until somebody tried it.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import json
import os
import sys

import pytest

BASE = os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), ".."))
for _p in (BASE, os.path.abspath(os.path.join(BASE, "..", "_shared"))):
    if _p not in sys.path:
        sys.path.insert(0, _p)

import fc  # noqa: E402
import snap_forum  # noqa: E402
from forumcli import agent  # noqa: E402


class FakeForum:
    """Records every call instead of making it."""

    def __init__(self, *_a, **_k):
        self.calls = []
        FakeForum.last = self

    def _record(self, name, *a, **k):
        self.calls.append((name, a, k))

    def me(self):
        self._record("me")
        return {"install": {"id": 1, "domain": "example.ca", "display_name": "Example",
                            "ss_version": "0.7.903D"},
                "role": 2, "role_name": "moderator",
                "can": {"post": True, "moderate": True, "administer": False}}

    def categories(self):
        self._record("categories")
        return [{"id": 3, "name": "Tickets", "thread_count": 2, "reply_count": 5}]

    def threads(self, cat=0, page=1, tag=""):
        self._record("threads", cat, page, tag)
        return {"threads": [{"id": 42, "title": "Upload fails", "reply_count": 1,
                             "display_name": "Example", "is_pinned": 1}]}

    def thread(self, tid):
        self._record("thread", tid)
        return {"thread": {"id": tid, "title": "Upload fails", "body": "It fails.",
                           "display_name": "Example", "created_at": "2026-10-18"},
                "replies": [{"id": 7, "body": "Fixed.", "display_name": "PGSB",
                             "created_at": "2026-10-18"}]}

    def post_ticket(self, board, title, body):
        self._record("post_ticket", board, title, body)
        return 99

    def set_flag(self, tid, flag):
        self._record("set_flag", tid, flag)

    def edit_thread(self, tid, body):
        self._record("edit_thread", tid, body)

    def reply(self, tid, body):
        self._record("reply", tid, body)
        return 101

    def lock(self, tid, locked=True):
        self._record("lock", tid, locked)
        return {"status": "updated"}

    def pin(self, tid, pinned=True):
        self._record("pin", tid, pinned)
        return {"status": "updated"}

    def move_thread(self, tid, cat):
        self._record("move_thread", tid, cat)
        return {"status": "moved", "category_id": cat}

    def follow(self, tid):
        self._record("follow", tid)

    def unfollow(self, tid):
        self._record("unfollow", tid)

    def react(self, tid, emoji, target="thread"):
        self._record("react", tid, emoji, target)
        return {"status": "added"}

    def unreact(self, tid, target="thread"):
        self._record("unreact", tid, target)
        return {"status": "removed"}

    def search(self, q, cat=0, page=1):
        self._record("search", q, cat, page)
        return {"results": [{"thread_id": 42, "title": "Upload fails"}]}

    def notifications(self, page=1):
        self._record("notifications", page)
        return {"unread_count": 1, "notifications": [
            {"thread_id": 42, "thread_title": "Upload fails", "actor_name": "PGSB"}]}

    def mark_notifications_read(self):
        self._record("mark_notifications_read")
        return {"status": "ok"}

    def am_i_admin(self):
        self._record("am_i_admin")
        return False


@pytest.fixture(autouse=True)
def fake(monkeypatch):
    monkeypatch.setattr(fc, "Forum", FakeForum)
    monkeypatch.delenv("SNAPSMACK_FORUM_KEY", raising=False)
    monkeypatch.delenv("SNAPSMACK_FORUM_URL", raising=False)
    # A fresh recorder for every test. Some commands refuse BEFORE they build
    # a client, so without this FakeForum.last still holds the calls the
    # previous test made, and "nothing was sent" passes on test order rather
    # than on behaviour. That is how one of these tests first went green.
    FakeForum()
    return FakeForum


def run(argv):
    return fc.main(argv)


# ── the sheet is the contract an agent reads ─────────────────────────────────

def test_every_command_the_sheet_promises_exists():
    parser = fc.build_parser()
    actions = [a for a in parser._subparsers._group_actions if a.choices]
    known = set(actions[0].choices)
    promised = {c["name"] for c in agent.COMMANDS}
    missing = promised - known
    assert not missing, "the agent sheet promises commands that do not exist: " + str(missing)


def test_every_command_that_exists_is_on_the_sheet():
    parser = fc.build_parser()
    actions = [a for a in parser._subparsers._group_actions if a.choices]
    known = set(actions[0].choices)
    promised = {c["name"] for c in agent.COMMANDS}
    hidden = known - promised
    assert not hidden, "commands exist that the sheet hides from an agent: " + str(hidden)


def test_the_sheet_leads_with_refusals():
    text = agent.text()
    assert text.index("NOT do") < text.index("Commands:")


# ── the calls go out in the shape the API reads ──────────────────────────────

def test_whoami_asks_and_reports_what_the_key_may_do(capsys):
    assert run(["whoami", "--json"]) == 0
    out = json.loads(capsys.readouterr().out)
    assert out["role_name"] == "moderator"
    assert out["can"]["moderate"] is True
    assert out["can"]["administer"] is False


def test_lock_and_unlock_go_through_the_client_not_a_raw_field():
    run(["lock", "42"])
    assert FakeForum.last.calls[-1] == ("lock", (42, True), {})
    run(["lock", "42", "--off"])
    assert FakeForum.last.calls[-1] == ("lock", (42, False), {})


def test_move_names_the_target_board():
    run(["move", "42", "--board", "5"])
    assert FakeForum.last.calls[-1] == ("move_thread", (42, 5), {})


def test_post_applies_the_flag_only_when_one_was_asked_for():
    run(["post", "--board", "3", "--title", "T", "--body", "B"])
    assert [c[0] for c in FakeForum.last.calls] == ["post_ticket"]
    run(["post", "--board", "3", "--title", "T", "--body", "B", "--flag", "support"])
    assert [c[0] for c in FakeForum.last.calls] == ["post_ticket", "set_flag"]


def test_post_reads_a_body_from_a_file(tmp_path):
    f = tmp_path / "bug.txt"
    f.write_text("Backslashes \\ and quotes \" survive.", encoding="utf-8")
    run(["post", "--board", "3", "--title", "T", "--body-file", str(f)])
    assert "Backslashes \\ and quotes" in FakeForum.last.calls[-1][1][2]


def test_react_can_remove_without_naming_an_emoji():
    run(["react", "42", "--remove"])
    assert FakeForum.last.calls[-1] == ("unreact", (42, "thread"), {})
    run(["react", "7", "--emoji", "x", "--reply"])
    assert FakeForum.last.calls[-1] == ("react", (7, "x", "reply"), {})


def test_follow_off_unfollows():
    run(["follow", "42", "--off"])
    assert FakeForum.last.calls[-1] == ("unfollow", (42,), {})


# ── refusals ─────────────────────────────────────────────────────────────────

def test_board_new_refuses_without_admin_and_says_which_role(capsys):
    assert run(["board-new", "Tickets"]) == 3
    assert "role 3" in capsys.readouterr().err


def test_post_without_a_body_refuses_rather_than_posting_nothing(capsys, monkeypatch):
    """It must refuse BEFORE it builds a client, so an empty post cannot leave
    the machine even if the network is up."""
    built = []
    monkeypatch.setattr(fc, "Forum", lambda *a, **k: built.append(1))
    assert run(["post", "--board", "3", "--title", "T"]) == 2
    assert built == []
    assert "required" in capsys.readouterr().err


def test_there_is_no_delete():
    parser = fc.build_parser()
    actions = [a for a in parser._subparsers._group_actions if a.choices]
    assert "delete" not in set(actions[0].choices)


# ── every command speaks JSON, because an agent reads JSON ───────────────────

@pytest.mark.parametrize("argv", [
    ["whoami"], ["boards"], ["threads"], ["thread", "42"], ["search", "x"],
    ["notifications"], ["agent"],
])
def test_read_commands_emit_valid_json(argv, capsys):
    assert run(argv + ["--json"]) == 0
    json.loads(capsys.readouterr().out)

# ── the id a create hands back ──────────────────────────────────────────────
#
# 23 Oct 2026: 32 threads posted to the skins board, every one of them created
# correctly, every one of them reporting id 0. The API answers a create with
# {"thread_id": N} and {"reply_id": N}; the client read .get("id") and fell
# back to 0. Exit code 0 throughout, so nothing anywhere said a word.
#
# The id is not cosmetic. `post --flag` feeds it straight to set_flag, so a
# flagged post PATCHed thread 0 instead of the thread it had just made.

def test_create_reads_the_key_the_api_actually_sends():
    assert snap_forum._created_id({"thread_id": 42, "flag": "none"}, "thread") == 42
    assert snap_forum._created_id({"reply_id": 7}, "reply") == 7


def test_create_still_accepts_a_bare_id():
    """If the API is ever changed to answer with "id", this keeps working."""
    assert snap_forum._created_id({"id": 42}, "thread") == 42
    assert snap_forum._created_id({"thread": {"id": 42}}, "thread") == 42


def test_create_refuses_to_invent_a_zero():
    """There is no honest zero. A create with no id is a failure, and the
    caller must not carry on as though it had an id."""
    for payload in ({}, {"id": 0}, {"thread_id": None}, {"status": "ok"}):
        with pytest.raises(snap_forum.ForumError):
            snap_forum._created_id(payload, "thread")


# ── edit, because there is no delete ────────────────────────────────────────

def test_edit_sends_only_the_body():
    """PATCH /threads/{id} picks its branch by which field is present, so an
    edit that also sent a flag or a pin state would do two things at once."""
    assert run(["edit", "42", "--body", "corrected"]) == 0
    assert ("edit_thread", (42, "corrected"), {}) in FakeForum.last.calls


def test_edit_refuses_an_empty_body(monkeypatch, capsys):
    built = []
    monkeypatch.setattr(fc, "Forum", lambda *a, **k: built.append(1))
    assert run(["edit", "42"]) == 2
    assert built == []
    assert "required" in capsys.readouterr().err


# ── a new board lands at the bottom, and reports its real id ────────────────
#
# GET /categories orders by sort_order and POST /categories defaults it to 0,
# so a board created without one sorts ABOVE all five seeded boards (1..5).
# The create also answers {"category_id": N}, which ensure_board read as "id"
# and turned into 0 -- the same bug as threads and replies, in a third place.

class AdminFake(FakeForum):
    def am_i_admin(self):
        self._record("am_i_admin")
        return True

    def ensure_board(self, name, description="", sort_order=0):
        self._record("ensure_board", name, description, sort_order)
        return 6

    def categories(self):
        self._record("categories")
        # 3 has threads in it, 94 is the board nobody asked for. Both cases
        # matter: hiding the first takes two threads with it.
        return [{"id": 3, "name": "Roach Motel", "thread_count": 2, "reply_count": 5},
                {"id": 94, "name": "Tickets", "thread_count": 0, "reply_count": 0}]

    def installs(self):
        self._record("installs")
        # Two domains where one is a suffix of the other, because that is the
        # shape that makes a substring match promote the wrong site.
        return [{"id": 1, "domain": "snapsmack.ca", "role": 3, "last_seen_at": "2026-10-30"},
                {"id": 7, "domain": "photowalk.ing", "role": 0, "last_seen_at": "2026-10-30"},
                {"id": 8, "domain": "walk.photowalk.ing", "role": 0, "last_seen_at": "2026-10-29"}]

    def set_role(self, install_id, role):
        self._record("set_role", install_id, role)
        return {"status": "updated", "install_id": install_id, "role": role}

    def update_board(self, category_id, **fields):
        self._record("update_board", category_id, fields)
        return {"status": "updated", "id": category_id}


def test_board_new_passes_the_sort_order_through(monkeypatch):
    monkeypatch.setattr(fc, "Forum", AdminFake)
    assert run(["board-new", "The Lounge", "--sort", "6"]) == 0
    assert ("ensure_board", ("The Lounge", "", 6), {}) in AdminFake.last.calls


def test_board_create_reads_the_key_the_api_actually_sends():
    assert snap_forum._created_id({"category_id": 6, "slug": "the-lounge"},
                                  "category") == 6


def test_the_agent_sheet_names_the_sort_trap():
    """An agent that does not know about --sort creates a board at the top of
    the list, which looks like a mistake and is not obviously undoable here."""
    board_new = [c for c in agent.COMMANDS if c["name"] == "board-new"][0]
    assert "--sort N" in board_new["args"]
    assert "ABOVE" in board_new["notes"]


def test_the_agent_sheet_lists_edit():
    """An agent only knows what the sheet tells it. A command the sheet does
    not mention may as well not exist."""
    assert "edit" in {c["name"] for c in agent.COMMANDS}


def test_a_moderator_byline_carries_mod():
    """Readers can only trust an answer if they can see who is answering.

    The forum sends author_role (2 is moderator) and mirrors it in
    author_is_moderator. Both are read, because a forum that has not taken
    this change yet sends neither and must still print a plain name rather
    than crash or claim everyone is staff.
    """
    assert fc._byline({"display_name": "Sean", "author_role": 2}) == "Sean  [MOD]"
    assert fc._byline({"display_name": "Sean", "author_role": 3}) == "Sean  [MOD]"
    assert fc._byline({"display_name": "a reader", "author_role": 0}) == "a reader"
    assert fc._byline({"display_name": "a reader", "author_role": 1}) == "a reader"


def test_a_byline_falls_back_to_the_flag_then_to_the_plain_name():
    # Role absent, flag present: an older forum that only kept is_moderator.
    assert fc._byline({"display_name": "Sean", "author_is_moderator": 1}) == "Sean  [MOD]"
    assert fc._byline({"display_name": "Sean", "author_is_moderator": 0}) == "Sean"
    # Neither field: a forum from before any of this. Print the name, say
    # nothing about status, and above all do not fail.
    assert fc._byline({"display_name": "Sean"}) == "Sean"
    assert fc._byline({}) == ""

# -- promoting a blog: the roster, and the two ways to name one --------------
#
# PATCH installs/{id}/role has existed since 17 October and nothing could
# reach it. Granting a moderator meant a database UPDATE by hand, which is
# also how the forum ended up with its roles set by whoever had the password.


@pytest.fixture()
def admin(monkeypatch):
    monkeypatch.setattr(fc, "Forum", AdminFake)
    return AdminFake


def test_role_needs_a_level_spelled_out(admin):
    """There is no sensible default rank to hand somebody."""
    with pytest.raises(SystemExit) as exc:
        run(["role", "photowalk.ing"])
    assert exc.value.code == 2


def test_role_takes_the_name_or_the_number(admin):
    assert run(["role", "7", "--level", "moderator"]) == 0
    assert ("set_role", (7, 2), {}) in AdminFake.last.calls
    assert run(["role", "7", "--level", "2"]) == 0
    assert ("set_role", (7, 2), {}) in AdminFake.last.calls


def test_role_resolves_a_domain_against_the_roster(admin):
    assert run(["role", "photowalk.ing", "--level", "power"]) == 0
    assert ("set_role", (7, 1), {}) in AdminFake.last.calls


def test_an_ambiguous_domain_promotes_nobody(admin, capsys):
    """"photowalk" matches photowalk.ing and walk.photowalk.ing. Picking the
    first match would hand moderator powers to whichever row sorted first."""
    assert run(["role", "photowalk", "--level", "moderator"]) == 1
    assert not [c for c in AdminFake.last.calls if c[0] == "set_role"]
    err = capsys.readouterr().err
    assert "matches 2 blogs" in err
    assert "Nothing sent" in err


def test_a_domain_nobody_has_promotes_nobody(admin, capsys):
    assert run(["role", "nosuch.example", "--level", "moderator"]) == 1
    assert not [c for c in AdminFake.last.calls if c[0] == "set_role"]
    assert "No blog on the forum matches" in capsys.readouterr().err


def test_an_unknown_level_is_refused_and_promotes_nobody(admin, capsys):
    assert run(["role", "photowalk.ing", "--level", "superuser"]) == 1
    assert not [c for c in AdminFake.last.calls if c[0] == "set_role"]
    assert "Unknown role" in capsys.readouterr().err


def test_role_refuses_on_a_key_that_is_not_admin(capsys):
    """FakeForum is role 2. The API would 403; saying so first costs nothing
    and tells the caller which question to ask."""
    assert run(["role", "7", "--level", "moderator"]) == 3
    assert not [c for c in FakeForum.last.calls if c[0] == "set_role"]
    assert "fc whoami" in capsys.readouterr().err


# -- hiding a board: the only way back, and what it takes with it -----------


def test_board_set_with_nothing_to_set_is_refused(admin, capsys):
    assert run(["board-set", "94"]) == 2
    assert not [c for c in AdminFake.last.calls if c[0] == "update_board"]
    assert "Nothing to change" in capsys.readouterr().err


def test_board_set_sends_only_the_fields_given(admin):
    """PATCH /categories falls back to the stored value for every absent key,
    so sending all four would overwrite three of them with a guess."""
    assert run(["board-set", "94", "--name", "Roach Motel Archive"]) == 0
    sent = [c for c in AdminFake.last.calls if c[0] == "update_board"][-1]
    assert sent[1] == (94, {"name": "Roach Motel Archive"})


def test_hiding_an_empty_board_goes_through(admin):
    assert run(["board-set", "94", "--hide"]) == 0
    sent = [c for c in AdminFake.last.calls if c[0] == "update_board"][-1]
    assert sent[1] == (94, {"is_active": False})


def test_hiding_a_board_that_still_has_threads_is_refused(admin, capsys):
    """There is no delete here, so hiding is what gets used instead -- and on
    the command line a board with two threads in it looks exactly like an
    empty one."""
    assert run(["board-set", "3", "--hide"]) == 1
    assert not [c for c in AdminFake.last.calls if c[0] == "update_board"]
    err = capsys.readouterr().err
    assert "2 thread(s)" in err
    assert "--even-with-threads" in err


def test_the_threads_guard_can_be_overridden_on_purpose(admin):
    assert run(["board-set", "3", "--hide", "--even-with-threads"]) == 0
    sent = [c for c in AdminFake.last.calls if c[0] == "update_board"][-1]
    assert sent[1] == (3, {"is_active": False})


def test_hiding_a_board_that_is_not_in_the_active_list_is_refused(admin, capsys):
    """categories() lists ACTIVE boards only, so a board missing from it is
    already hidden -- or was never there, and the id is a typo."""
    assert run(["board-set", "404", "--hide"]) == 1
    assert not [c for c in AdminFake.last.calls if c[0] == "update_board"]
    assert "not in the active board list" in capsys.readouterr().err


def test_showing_a_hidden_board_does_not_need_it_to_be_listed(admin):
    """The reverse: --show must work on exactly the boards categories()
    cannot see, which is the whole point of it."""
    assert run(["board-set", "404", "--show"]) == 0
    sent = [c for c in AdminFake.last.calls if c[0] == "update_board"][-1]
    assert sent[1] == (404, {"is_active": True})


def test_hide_and_show_together_is_an_error(admin):
    with pytest.raises(SystemExit) as exc:
        run(["board-set", "94", "--hide", "--show"])
    assert exc.value.code == 2


def test_board_set_refuses_on_a_key_that_is_not_admin(capsys):
    assert run(["board-set", "94", "--hide"]) == 3
    assert not [c for c in FakeForum.last.calls if c[0] == "update_board"]
    assert "fc whoami" in capsys.readouterr().err


# -- the client itself, where the payload is built --------------------------


def test_update_board_will_not_send_a_field_the_api_cannot_read():
    """slug is immutable there and anything unrecognised falls through every
    branch, so it is caught here rather than answered with a 400."""
    f = snap_forum.Forum("https://forum.invalid/api", "k")
    with pytest.raises(snap_forum.ForumError) as exc:
        f.update_board(94, slug="tickets")
    assert "slug" in str(exc.value)


def test_update_board_refuses_an_empty_change():
    f = snap_forum.Forum("https://forum.invalid/api", "k")
    with pytest.raises(snap_forum.ForumError) as exc:
        f.update_board(94)
    assert "Nothing to change" in str(exc.value)


def test_the_sheet_warns_that_hiding_takes_the_threads_with_it():
    assert any("--even-with-threads" in r for r in agent.REFUSALS)

# -- the shape a body has to be in before it is published -------------------
#
# Thread 94, 30 October 2026: hard-wrapped at ~72 columns, and the forum
# honours every newline, so Sean's screenshot showed a stack of short ragged
# lines. The same post said **affected** and printed the asterisks. 21 of the
# 49 ticket threads on the board are hard-wrapped. No tool here ever wrapped
# anything -- the wrapping was typed -- so this is a refusal at the moment
# before a body becomes public, not a formatter.

WRAPPED = (
    "The desktop test files are scripts that pytest collects nothing from,\n"
    "so a green run over that directory means much less than it looks like\n"
    "it does.\n"
    "\n"
    "Running it reports twenty-one tests collected and all of them passing,\n"
    "and only two files contribute any of them.\n"
)


def test_a_wrapped_paragraph_is_found_and_the_line_numbers_are_right():
    assert snap_forum.hard_wrapped_lines(WRAPPED) == [1, 2, 5]


def test_a_body_written_one_paragraph_per_line_is_not_flagged():
    body = ("A paragraph long enough to be suspicious if it were wrapped, and "
            "it is not wrapped at all.\n\nAnd a second one, also long enough "
            "to be a candidate for the check above.\n")
    assert snap_forum.hard_wrapped_lines(body) == []
    assert snap_forum.body_shape_complaints(body) == []


def test_the_shapes_an_author_meant_are_left_alone():
    """A bullet, a numbered item, a quote, an image placeholder and an indented
    block are all line breaks somebody chose. Joining them would be the tool
    breaking the formatting rather than fixing it."""
    for kept in [
        "A line long enough to look like a wrap, every bit of it, yes\n- a bullet after it",
        "A line long enough to look like a wrap, every bit of it, yes\n1. a numbered item",
        "A line long enough to look like a wrap, every bit of it, yes\n> a quoted line",
        "A line long enough to look like a wrap, every bit of it, yes\n[img:2]",
        "A line long enough to look like a wrap, every bit of it, yes\n    indented code",
        "- a bullet long enough to look like a wrap, every bit of it, truly\nand text under it",
    ]:
        assert snap_forum.hard_wrapped_lines(kept) == [], kept


def test_a_short_line_is_not_a_wrap():
    assert snap_forum.hard_wrapped_lines("short\nlines\nhere") == []


def test_unwrap_joins_only_the_wrapped_lines():
    out = snap_forum.unwrap_body(WRAPPED)
    assert snap_forum.hard_wrapped_lines(out) == []
    assert out.count("\n\n") == 1              # the paragraph break survives
    assert "nothing from, so a green run" in out  # joined with one space
    assert len(out.split("\n")) < len(WRAPPED.split("\n"))


def test_unwrap_is_idempotent_and_leaves_a_good_body_untouched():
    once = snap_forum.unwrap_body(WRAPPED)
    assert snap_forum.unwrap_body(once) == once
    good = "One line, one paragraph, and long enough to be a candidate.\n"
    assert snap_forum.unwrap_body(good) == good


def test_unwrap_keeps_a_list_as_a_list():
    body = ("Here is a list of the things that are wrong with this, at length:\n"
            "- the first thing, which is a long enough line to be suspicious\n"
            "- the second thing, which is also a long enough line to be so\n")
    assert snap_forum.unwrap_body(body) == body


def test_the_markdown_the_renderer_ignores_is_named():
    assert snap_forum.dead_markdown("this is **bold**") == ["**bold**"]
    assert snap_forum.dead_markdown("# A heading\ntext") == ["a # heading"]
    assert snap_forum.dead_markdown("run `pytest` now") == ["`code`"]
    # Only the fence: the inline pattern needs content between two
    # backticks on ONE line, and a fenced block has none.
    assert snap_forum.dead_markdown("```\ncode\n```") == ["a ``` fence"]
    assert snap_forum.dead_markdown("see [the docs](https://x.test)") == ["a [label](link)"]


def test_a_bullet_list_is_not_mistaken_for_markdown():
    """The renderer shows "* item" as "* item", which is what a reader expects
    a bullet to look like. Only the forms that LOOK like formatting and arrive
    as punctuation are worth refusing."""
    assert snap_forum.dead_markdown("* one\n* two") == []
    assert snap_forum.dead_markdown("2 * 3 is 6") == []


# -- and the three commands that publish one -------------------------------


def test_post_refuses_a_hard_wrapped_body_and_sends_nothing(capsys):
    assert run(["post", "--board", "3", "--title", "T", "--body", WRAPPED]) == 1
    assert not [c for c in FakeForum.last.calls if c[0] == "post_ticket"]
    err = capsys.readouterr().err
    assert "hard-wrapped" in err
    assert "--unwrap" in err


def test_post_with_unwrap_sends_the_joined_body():
    assert run(["post", "--board", "3", "--title", "T", "--body", WRAPPED,
                "--unwrap"]) == 0
    sent = [c for c in FakeForum.last.calls if c[0] == "post_ticket"][-1]
    assert snap_forum.hard_wrapped_lines(sent[1][2]) == []


def test_unwrap_will_not_quietly_strip_markdown(capsys):
    assert run(["post", "--board", "3", "--title", "T",
                "--body", "This is **important** and it is.", "--unwrap"]) == 1
    assert not [c for c in FakeForum.last.calls if c[0] == "post_ticket"]
    assert "does not render" in capsys.readouterr().err


def test_reply_refuses_a_hard_wrapped_body_too(capsys):
    assert run(["reply", "42", "--body", WRAPPED]) == 1
    assert not [c for c in FakeForum.last.calls if c[0] == "reply"]
    assert "hard-wrapped" in capsys.readouterr().err


def test_edit_refuses_a_hard_wrapped_body_too(capsys):
    """An edit is the only way to correct something already posted, so a
    wrapped replacement would be fixing one fault with another."""
    assert run(["edit", "42", "--body", WRAPPED]) == 1
    assert not [c for c in FakeForum.last.calls if c[0] == "edit_thread"]
    assert "hard-wrapped" in capsys.readouterr().err


def test_a_good_body_still_posts():
    body = "One paragraph on one line, long enough to be a candidate for it.\n"
    assert run(["post", "--board", "3", "--title", "T", "--body", body]) == 0
    assert [c for c in FakeForum.last.calls if c[0] == "post_ticket"]

# ===== SNAPSMACK EOF =====
