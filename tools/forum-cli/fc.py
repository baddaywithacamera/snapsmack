"""FORUM CLI — the SnapSmack forum from a command line.

Built so the desktop tools, Claude and Codex can all work the forum without a
browser, through one client rather than each growing its own. Every command
prints plain text by default and JSON with --json.

    fc whoami                     which key is this, and what may it do?
    fc boards                     the active boards
    fc threads --board 3          what is being discussed
    fc thread 42                  one thread, with its replies
    fc post --board 3 --title T --body B
    fc reply 42 --body B
    fc react 42 --emoji X         follow / lock / pin / move
    fc search "upload fails"
    fc notifications              replies to you, and to threads you follow
    fc agent                      what an agent can do here, refusals first

The forum is SnapSmack's own: projects/forum-server/api/forum/ on snapsmack.ca.
This speaks to it through tools/_shared/snap_forum.py, the same client TICKET
WICKET uses, so a fix to one is a fix to both.

Credentials come from --forum-url/--forum-key, or SNAPSMACK_FORUM_URL and
SNAPSMACK_FORUM_KEY. Nothing is written to disk: a key belongs to the
environment that holds it, not to a config file this tool leaves behind.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import argparse
import json
import os
import sys

BASE = os.path.dirname(os.path.abspath(__file__))
for _p in (BASE, os.path.abspath(os.path.join(BASE, "..", "_shared"))):
    if _p not in sys.path:
        sys.path.insert(0, _p)

from forumcli import agent                      # noqa: E402
from snap_forum import Forum, ForumError        # noqa: E402

DEFAULT_URL = "https://snapsmack.ca/api/forum/"


def _out(args, payload, text):
    if getattr(args, "json", False):
        print(json.dumps(payload, indent=2, default=str))
    else:
        print(text)


def _forum(args):
    url = args.forum_url or os.environ.get("SNAPSMACK_FORUM_URL") or DEFAULT_URL
    key = args.forum_key or os.environ.get("SNAPSMACK_FORUM_KEY") or ""
    return Forum(url, key)


def _body(args):
    """--body or --body-file. A bug report is usually already in a file, and
    pasting one through a shell mangles the characters that matter in it."""
    if getattr(args, "body_file", None):
        with open(args.body_file, encoding="utf-8", errors="replace") as fh:
            return fh.read().strip()
    return (getattr(args, "body", None) or "").strip()


# ── commands ─────────────────────────────────────────────────────────────────

def cmd_whoami(args):
    me = _forum(args).me()
    can = me.get("can") or {}
    inst = me.get("install") or {}
    who = inst.get("domain") or "the God key"
    lines = [who + " — " + str(me.get("role_name", "?"))
             + " (role " + str(me.get("role", "?")) + ")", ""]
    for label in ("post", "moderate", "administer"):
        lines.append("  " + ("yes" if can.get(label) else "no ") + "  " + label)
    _out(args, me, "\n".join(lines))
    return 0


def cmd_boards(args):
    boards = _forum(args).categories()
    lines = [str(len(boards)) + " board(s)", ""]
    for b in boards:
        lines.append("  [" + str(b.get("id")) + "] " + str(b.get("name"))
                     + "  " + str(b.get("thread_count", 0)) + " threads, "
                     + str(b.get("reply_count", 0)) + " replies")
    _out(args, boards, "\n".join(lines))
    return 0


def cmd_board_new(args):
    f = _forum(args)
    if not f.am_i_admin():
        print("This key cannot create boards: that needs role 3 (admin). "
              "Check with `fc whoami`.", file=sys.stderr)
        return 3
    bid = f.ensure_board(args.name, args.description or "", args.sort)
    _out(args, {"id": bid, "name": args.name},
         "Board " + args.name + " is id " + str(bid) + ".")
    return 0


ROLES = {"user": 0, "power": 1, "moderator": 2, "admin": 3}
ROLE_NAMES = {v: k for k, v in ROLES.items()}


def _role_value(text):
    """A role as a number, given either the number or the name.

    Both are accepted because both get typed: the API speaks 0-3 and a person
    promoting a moderator thinks "moderator", not "2".
    """
    text = (text or "").strip().lower()
    if text in ROLES:
        return ROLES[text]
    if text.isdigit() and int(text) in ROLE_NAMES:
        return int(text)
    raise ForumError(
        "Unknown role " + repr(text) + ". One of: "
        + ", ".join(k + " (" + str(v) + ")" for k, v in ROLES.items()) + ".")


def _install_id(forum, needle):
    """An install id, from the id or from the domain.

    Nobody knows a blog by its number, and a wrong number here promotes the
    wrong site. So a domain is resolved against the roster and an ambiguous
    one refuses rather than picking the first match.
    """
    needle = (needle or "").strip()
    if needle.isdigit():
        return int(needle)
    rows = forum.installs()
    low = needle.lower()
    exact = [r for r in rows if str(r.get("domain", "")).strip().lower() == low]
    hits = exact or [r for r in rows if low in str(r.get("domain", "")).lower()]
    if not hits:
        raise ForumError(
            "No blog on the forum matches " + repr(needle)
            + ". `fc installs` lists them.")
    if len(hits) > 1:
        names = ", ".join(str(r.get("domain")) for r in hits[:6])
        raise ForumError(
            repr(needle) + " matches " + str(len(hits)) + " blogs (" + names
            + "). Nothing sent — name one exactly, or give its id.")
    return int(hits[0].get("id") or 0)


def cmd_installs(args):
    rows = _forum(args).installs()
    lines = [str(len(rows)) + " blog(s) on the forum", ""]
    for r in rows:
        role = int(r.get("role") or 0)
        flags = " BANNED" if r.get("is_banned") else ""
        lines.append("  [" + str(r.get("id")) + "] " + str(r.get("domain", ""))
                     + "  " + ROLE_NAMES.get(role, str(role))
                     + " (" + str(role) + ")" + flags
                     + "  last seen " + str(r.get("last_seen_at", "?")))
    _out(args, rows, "\n".join(lines))
    return 0


def cmd_role(args):
    f = _forum(args)
    if not f.am_i_admin():
        print("This key cannot set roles: that needs role 3 (admin) or the "
              "Smack Central key. Check with `fc whoami`.", file=sys.stderr)
        return 3
    level = _role_value(args.level)
    target = _install_id(f, args.install)
    res = f.set_role(target, level)
    _out(args, res, "Install " + str(target) + " is now "
         + ROLE_NAMES.get(level, str(level)) + " (role " + str(level) + ").")
    return 0


def cmd_board_set(args):
    """Rename, reorder, or hide a board.

    The hide guard is the reason this refuses anything at all. is_active=0
    takes the board out of every listing, and the threads in it go with it --
    there is no delete here, so hiding is what gets used instead, and a board
    with 40 threads in it looks exactly like an empty one on the command line.
    """
    f = _forum(args)
    if not f.am_i_admin():
        print("This key cannot manage boards: that needs role 3 (admin) or "
              "the Smack Central key. Check with `fc whoami`.", file=sys.stderr)
        return 3

    fields = {}
    if args.name:
        fields["name"] = args.name
    if args.description is not None:
        fields["description"] = args.description
    if args.sort is not None:
        fields["sort_order"] = args.sort
    if args.hide:
        fields["is_active"] = False
    elif args.show:
        fields["is_active"] = True

    if not fields:
        print("Nothing to change. Give --name, --description, --sort, --hide "
              "or --show.", file=sys.stderr)
        return 2

    if args.hide:
        board = None
        for cat in f.categories():
            if int(cat.get("id") or 0) == args.id:
                board = cat
                break
        if board is None:
            print("Board " + str(args.id) + " is not in the active board list: "
                  "it is already hidden, or there is no such board. Nothing "
                  "sent.", file=sys.stderr)
            return 1
        count = int(board.get("thread_count") or 0)
        if count and not args.even_with_threads:
            print("Board " + str(args.id) + " (" + str(board.get("name"))
                  + ") holds " + str(count) + " thread(s), and hiding it takes "
                  "them out of every listing with it. There is no delete here, "
                  "so nothing undoes that but --show. Move the threads first, "
                  "or pass --even-with-threads. Nothing sent.", file=sys.stderr)
            return 1

    res = f.update_board(args.id, **fields)
    _out(args, res, "Board " + str(args.id) + " updated: "
         + ", ".join(k + "=" + repr(v) for k, v in sorted(fields.items())) + ".")
    return 0


def _byline(row):
    """Author name, with MOD after it when the account carries moderator power.

    The API sends author_role (0 user, 1 power, 2 moderator, 3 admin) and
    mirrors it in author_is_moderator. Read the role when it is there and fall
    back to the flag, so this keeps working against a forum that has not been
    updated yet -- an older one sends neither and simply shows the name.
    """
    name = str(row.get("display_name", ""))
    role = row.get("author_role")
    is_mod = int(role) >= 2 if role is not None else bool(row.get("author_is_moderator"))
    return name + "  [MOD]" if is_mod else name


def cmd_threads(args):
    data = _forum(args).threads(args.board or 0, args.page, args.tag or "")
    rows = data.get("threads") or data.get("items") or []
    lines = [str(len(rows)) + " thread(s)", ""]
    for t in rows:
        marks = ("P" if t.get("is_pinned") else "") \
            + ("L" if t.get("is_locked") else "") \
            + ("*" if t.get("unread") else "")
        lines.append("  [" + str(t.get("id")) + "] " + marks.ljust(3) + " "
                     + str(t.get("title", "")))
        lines.append("        " + str(t.get("reply_count", 0)) + " replies — "
                     + _byline(t))
    _out(args, data, "\n".join(lines))
    return 0


def cmd_thread(args):
    data = _forum(args).thread(args.id)
    t = data.get("thread") or {}
    lines = ["[" + str(t.get("id")) + "] " + str(t.get("title", "")),
             "  by " + _byline(t) + "  " + str(t.get("created_at", "")),
             "", (t.get("body") or "").strip(), ""]
    for r in (data.get("replies") or []):
        lines.append("  --- [" + str(r.get("id")) + "] " + _byline(r)
                     + "  " + str(r.get("created_at", "")))
        lines.append("  " + (r.get("body") or "").strip().replace("\n", "\n  "))
    _out(args, data, "\n".join(lines))
    return 0


def cmd_post(args):
    body = _body(args)
    if not body or not args.title:
        print("--title and --body (or --body-file) are both required.", file=sys.stderr)
        return 2
    f = _forum(args)
    tid = f.post_ticket(args.board, args.title, body)
    if args.flag and args.flag != "none":
        f.set_flag(tid, args.flag)
    _out(args, {"id": tid}, "Thread " + str(tid) + " posted.")
    return 0


def cmd_reply(args):
    body = _body(args)
    if not body:
        print("--body or --body-file is required.", file=sys.stderr)
        return 2
    rid = _forum(args).reply(args.id, body)
    _out(args, {"id": rid},
         "Reply " + str(rid) + " posted to thread " + str(args.id) + ".")
    return 0


def cmd_edit(args):
    body = _body(args)
    if not body:
        print("--body or --body-file is required.", file=sys.stderr)
        return 2
    _forum(args).edit_thread(args.id, body)
    _out(args, {"status": "updated", "id": args.id},
         "Thread " + str(args.id) + " rewritten.")
    return 0


def cmd_react(args):
    f = _forum(args)
    target = "reply" if args.reply else "thread"
    if args.remove:
        res = f.unreact(args.id, target)
    else:
        if not args.emoji:
            print("--emoji is required unless --remove.", file=sys.stderr)
            return 2
        res = f.react(args.id, args.emoji, target)
    _out(args, res, target + " " + str(args.id) + ": " + str(res.get("status", "ok")))
    return 0


def cmd_follow(args):
    f = _forum(args)
    if args.off:
        f.unfollow(args.id)
        _out(args, {"status": "unfollowed"},
             "No longer following thread " + str(args.id) + ".")
    else:
        f.follow(args.id)
        _out(args, {"status": "following"}, "Following thread " + str(args.id) + ".")
    return 0


def cmd_lock(args):
    res = _forum(args).lock(args.id, not args.off)
    _out(args, res, "Thread " + str(args.id)
         + (" unlocked." if args.off else " locked."))
    return 0


def cmd_pin(args):
    res = _forum(args).pin(args.id, not args.off)
    _out(args, res, "Thread " + str(args.id)
         + (" unpinned." if args.off else " pinned."))
    return 0


def cmd_move(args):
    res = _forum(args).move_thread(args.id, args.board)
    _out(args, res, "Thread " + str(args.id) + " moved to board "
         + str(args.board) + ".")
    return 0


def cmd_search(args):
    data = _forum(args).search(args.query, args.board or 0, args.page)
    rows = data.get("results") or []
    lines = [str(len(rows)) + " match(es) for " + repr(args.query), ""]
    for r in rows:
        lines.append("  [" + str(r.get("thread_id", r.get("id"))) + "] "
                     + str(r.get("title", "")))
    _out(args, data, "\n".join(lines))
    return 0


def cmd_notifications(args):
    f = _forum(args)
    if args.mark_read:
        f.mark_notifications_read()
        _out(args, {"status": "read"}, "All notifications marked read.")
        return 0
    data = f.notifications(args.page)
    rows = data.get("notifications") or []
    lines = [str(data.get("unread_count", len(rows))) + " unread", ""]
    for n in rows:
        lines.append("  [" + str(n.get("thread_id")) + "] "
                     + str(n.get("thread_title", "")) + " — "
                     + str(n.get("actor_name", "")))
    _out(args, data, "\n".join(lines))
    return 0


def cmd_agent(args):
    _out(args, agent.sheet(), agent.text())
    return 0


# ── parser ───────────────────────────────────────────────────────────────────

def build_parser():
    # The three global flags are declared TWICE on purpose: once on the root so
    # `fc --json whoami` works, and once on every subcommand so `fc whoami
    # --json` does too. argparse accepts a parent's flags only before the
    # subcommand, and every example on the agent sheet puts --json last --
    # which is where anyone, human or agent, naturally types it.
    common = argparse.ArgumentParser(add_help=False)
    common.add_argument("--forum-url", default="")
    common.add_argument("--forum-key", default="")
    common.add_argument("--json", action="store_true")

    p = argparse.ArgumentParser(prog="fc", description="FORUM CLI", parents=[common])
    _sub = p.add_subparsers(dest="cmd")

    class sub:  # noqa: N801 - a shim so every subparser inherits the flags
        @staticmethod
        def add_parser(name, **kw):
            kw.setdefault("parents", [common])
            return _sub.add_parser(name, **kw)

    sub.add_parser("whoami").set_defaults(func=cmd_whoami)
    sub.add_parser("boards").set_defaults(func=cmd_boards)
    sub.add_parser("agent").set_defaults(func=cmd_agent)

    bn = sub.add_parser("board-new")
    bn.add_argument("name")
    bn.add_argument("--description", default="")
    bn.add_argument("--sort", type=int, default=0)
    bn.set_defaults(func=cmd_board_new)

    bs = sub.add_parser("board-set")
    bs.add_argument("id", type=int)
    bs.add_argument("--name", default="")
    bs.add_argument("--description", default=None)
    bs.add_argument("--sort", type=int, default=None)
    # --hide and --show cannot both be meant, and whichever won silently
    # would be the wrong one half the time.
    vis = bs.add_mutually_exclusive_group()
    vis.add_argument("--hide", action="store_true")
    vis.add_argument("--show", action="store_true")
    bs.add_argument("--even-with-threads", dest="even_with_threads",
                    action="store_true")
    bs.set_defaults(func=cmd_board_set)

    sub.add_parser("installs").set_defaults(func=cmd_installs)

    ro = sub.add_parser("role")
    ro.add_argument("install")
    ro.add_argument("--level", required=True)
    ro.set_defaults(func=cmd_role)

    th = sub.add_parser("threads")
    th.add_argument("--board", type=int, default=0)
    th.add_argument("--tag", default="")
    th.add_argument("--page", type=int, default=1)
    th.set_defaults(func=cmd_threads)

    one = sub.add_parser("thread")
    one.add_argument("id", type=int)
    one.set_defaults(func=cmd_thread)

    po = sub.add_parser("post")
    po.add_argument("--board", type=int, required=True)
    po.add_argument("--title", default="")
    po.add_argument("--body", default="")
    po.add_argument("--body-file", dest="body_file", default="")
    po.add_argument("--flag", default="none",
                    choices=["none", "chat", "support", "question", "brag"])
    po.set_defaults(func=cmd_post)

    rp = sub.add_parser("reply")
    rp.add_argument("id", type=int)
    rp.add_argument("--body", default="")
    rp.add_argument("--body-file", dest="body_file", default="")
    rp.set_defaults(func=cmd_reply)

    ed = sub.add_parser("edit")
    ed.add_argument("id", type=int)
    ed.add_argument("--body", default="")
    ed.add_argument("--body-file", dest="body_file", default="")
    ed.set_defaults(func=cmd_edit)

    rc = sub.add_parser("react")
    rc.add_argument("id", type=int)
    rc.add_argument("--emoji", default="")
    rc.add_argument("--reply", action="store_true")
    rc.add_argument("--remove", action="store_true")
    rc.set_defaults(func=cmd_react)

    fo = sub.add_parser("follow")
    fo.add_argument("id", type=int)
    fo.add_argument("--off", action="store_true")
    fo.set_defaults(func=cmd_follow)

    lk = sub.add_parser("lock")
    lk.add_argument("id", type=int)
    lk.add_argument("--off", action="store_true")
    lk.set_defaults(func=cmd_lock)

    pn = sub.add_parser("pin")
    pn.add_argument("id", type=int)
    pn.add_argument("--off", action="store_true")
    pn.set_defaults(func=cmd_pin)

    mv = sub.add_parser("move")
    mv.add_argument("id", type=int)
    mv.add_argument("--board", type=int, required=True)
    mv.set_defaults(func=cmd_move)

    se = sub.add_parser("search")
    se.add_argument("query")
    se.add_argument("--board", type=int, default=0)
    se.add_argument("--page", type=int, default=1)
    se.set_defaults(func=cmd_search)

    nt = sub.add_parser("notifications")
    nt.add_argument("--mark-read", dest="mark_read", action="store_true")
    nt.add_argument("--page", type=int, default=1)
    nt.set_defaults(func=cmd_notifications)
    return p


def main(argv=None):
    args = build_parser().parse_args(argv)
    if not getattr(args, "func", None):
        build_parser().print_help()
        return 2
    try:
        return int(args.func(args) or 0)
    except ForumError as exc:
        print(str(exc), file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
# ===== SNAPSMACK EOF =====
