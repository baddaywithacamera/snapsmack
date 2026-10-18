"""FORUM CLI — what it promises and what it refuses.

Nothing here touches the network. Every test drives the parser and a fake
client, because a CLI that an agent depends on fails in two ways: it forgets a
command the agent sheet promises, or it sends a field the API does not read.
The second one is what broke lock() for its whole life -- "locked" where the
API reads "is_locked" -- and it was invisible until somebody tried it.
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
# ===== SNAPSMACK EOF =====
