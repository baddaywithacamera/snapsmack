"""TICKET WICKET — command line.

Sean: "the support tools need to have a CLI that you can slot into and work with
without needing me" and "Be sure to include CLI for yourself and CODEX".

So --as claude | chatgpt | sean | assistant is on every command, and the store
stamps it on everything. Everyone signs as themselves; nobody signs as another.

    tw queue                      what needs attention, worst band first
    tw show SS-1042               one ticket, whole history
    tw new --title "..."          open a ticket
    tw triage SS-1042 --security --usability stopped --affected 3
    tw post SS-1042               put it in the tracking forum (anonymised)
    tw reply SS-1042 --text "..." QUEUE a reply. does not send.
    tw ack SS-1042                queue the fixed acknowledgement
    tw escalate SS-1042 --why     hand to Sean, by name
    tw state SS-1042 --resolved
    tw link SS-1042 --build 0.7.901D
    tw merge SS-1042 --into SS-1037
    tw stale                      what is past the clock for its band
    tw outbox                     what is waiting to go out
    tw mod-check                  does my forum key actually moderate?

THERE IS NO `tw send`. The CLI queues; a separate armed sender delivers. A
compromised CLI — or a Claude talked into something by a malicious support
message — can write a bad draft and cannot deliver one.

THERE IS NO `tw delete`. close, withdraw, merge. (GL-6.)

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import argparse
import json
import os
import sys

BASE = os.path.dirname(os.path.abspath(__file__))
if BASE not in sys.path:
    sys.path.insert(0, BASE)

from ticketwicket import anonymise, forum as forummod, model, store as storemod


def _store(args):
    return storemod.Store(path=args.db, actor=args.as_who)


def _out(args, payload, text):
    if getattr(args, "json", False):
        print(json.dumps(payload, indent=2, default=str))
    else:
        print(text)


def _line(t) -> str:
    pulse = " [PULSE]" if t.pulse else ""
    thread = f" forum#{t.forum_thread}" if t.forum_thread else ""
    return f"  {t.id:<9} {t.band:<17} {t.state:<9} {t.title[:52]}{pulse}{thread}"


def cmd_queue(args):
    s = _store(args)
    tickets = s.queue(open_only=not args.all)
    if args.band:
        tickets = [t for t in tickets if t.band == args.band.upper()]
    text = "\n".join([f"{len(tickets)} tickets", ""] + [_line(t) for t in tickets])
    _out(args, [t.public_dict() for t in tickets], text or "Nothing open.")
    return 0


def cmd_show(args):
    s = _store(args)
    t = s.get(args.id)
    if not t:
        print(f"No such ticket: {args.id}", file=sys.stderr); return 2
    lines = [
        f"{t.id}  {t.band}  {t.state}" + ("  [PULSE]" if t.pulse else ""),
        f"  {t.title}", "",
        t.body or "(no public description yet)", "",
        f"  opened {t.created}   updated {t.updated}",
        f"  affected {t.affected}   usability {t.usability}"
        f"   security {t.security}   data at risk {t.data_at_risk}",
    ]
    if t.forum_thread:
        lines.append(f"  forum thread {t.forum_thread}")
    if t.merged_into:
        lines.append(f"  merged into {t.merged_into}")
    if t.links:
        lines.append("  links: " + ", ".join(f"{k}={v}" for k, v in t.links.items()))
    if args.private:
        lines += ["", "PRIVATE — never posted:",
                  f"  reporter: {t.reporter or '(none)'} via {t.reporter_channel or '-'}",
                  f"  original: {(t.original or '')[:400]}"]
    lines += ["", "History:"]
    for e in t.entries:
        lines.append(f"  {e.at}  {e.author:<9} {e.kind:<9} {e.text[:70]}")
    _out(args, t.public_dict(), "\n".join(lines))
    return 0


def cmd_new(args):
    s = _store(args)
    t = s.create(title=args.title, body=args.body, reporter=args.reporter,
                 reporter_channel=args.channel, original=args.original,
                 security=args.security, data_at_risk=args.data_at_risk,
                 usability=args.usability, affected=args.affected, pulse=args.pulse)
    _out(args, t.public_dict(), f"{t.id} opened as {t.band}")
    return 0


def cmd_triage(args):
    s = _store(args)
    fields = {}
    if args.security: fields["security"] = True
    if args.data_at_risk: fields["data_at_risk"] = True
    if args.usability: fields["usability"] = args.usability
    if args.affected is not None: fields["affected"] = args.affected
    if args.pulse: fields["pulse"] = True
    if args.body: fields["body"] = args.body
    fields["state"] = model.TRIAGED
    t = s.update(args.id, **fields)
    note = f"{t.id} is {t.band}"
    if t.pulse:
        note += "\n  PULSE set. Nothing auto-sends on this ticket now (GL-4)."
    _out(args, t.public_dict(), note)
    return 0


def cmd_post(args):
    """Put the ticket in the tracking forum. Anonymised, and refused if not."""
    s = _store(args)
    t = s.get(args.id)
    if not t:
        print(f"No such ticket: {args.id}", file=sys.stderr); return 2

    body = anonymise.forum_post(t)
    secrets = [x for x in (t.reporter, t.reporter_channel) if x]
    problems = anonymise.check(body, secrets)
    if problems:
        print("REFUSING TO POST — this ticket still identifies someone:\n  "
              + "\n  ".join(problems)
              + "\n\nTickets never contain info that identifies who submitted them. "
                "Rewrite the body about the problem, not the person.", file=sys.stderr)
        return 1

    if args.dry_run or not args.forum_url:
        _out(args, {"would_post": body, "posted": False},
             "DRY RUN — would post:\n\n" + body
             + "\n\n(no --forum-url given, or --dry-run)")
        return 0

    f = forummod.Forum(args.forum_url, args.forum_key)
    cat = f.ensure_board(args.board, "Tickets. One per problem. Nobody's name.")
    thread_id = f.post_ticket(cat, f"{t.id} · {t.title}", body)
    s.update(t.id, forum_thread=thread_id)
    s.add_entry(t.id, "post", f"posted to forum thread {thread_id}", "forum")
    _out(args, {"thread": thread_id, "posted": True},
         f"Posted {t.id} as forum thread {thread_id}")
    return 0


def cmd_reply(args):
    s = _store(args)
    text = args.text
    if args.text_file:
        with open(args.text_file, encoding="utf-8") as fh:
            text = fh.read()
    if not text:
        print("Nothing to say. --text or --text-file.", file=sys.stderr); return 2
    sig = model.SIGNATURES[args.as_who]
    body = f"{text.rstrip()}\n\n{sig}"
    try:
        row = s.enqueue(args.id, args.channel, body)
    except PermissionError as exc:
        print(str(exc), file=sys.stderr); return 1
    _out(args, {"outbox": row, "sent": False},
         f"Queued for {args.channel}, signed {sig}. "
         f"NOT SENT — the sender is armed separately.")
    return 0


def cmd_ack(args):
    """The one fixed text the always-awake tier may send. Not composed."""
    s = _store(args)
    row = s.enqueue(args.id, args.channel, model.ACKNOWLEDGEMENT,
                    author=model.ASSISTANT)
    _out(args, {"outbox": row}, "Acknowledgement queued:\n\n" + model.ACKNOWLEDGEMENT)
    return 0


def cmd_escalate(args):
    s = _store(args)
    s.update(args.id, pulse=True)
    s.add_entry(args.id, "escalate", args.why or "needs Sean")
    _out(args, {"escalated": args.id},
         f"{args.id} escalated to Sean: {args.why or 'needs a human'}\n"
         "  Nothing auto-sends on it now.")
    return 0


def cmd_state(args):
    s = _store(args)
    state = next((x for x in model.STATES if getattr(args, x.replace("-", "_"), False)),
                 "")
    if not state:
        print("Pick a state: --triaged --answered --waiting --blocked "
              "--resolved --closed", file=sys.stderr); return 2
    t = s.update(args.id, state=state)
    _out(args, t.public_dict(), f"{t.id} is now {t.state}")
    return 0


def cmd_link(args):
    s = _store(args)
    for key in ("build", "opaudit", "commit", "pr"):
        val = getattr(args, key, "")
        if val:
            s.set_link(args.id, key, val)
    t = s.get(args.id)
    _out(args, t.links, f"{t.id} links: "
         + ", ".join(f"{k}={v}" for k, v in t.links.items()))
    return 0


def cmd_merge(args):
    s = _store(args)
    s.merge(args.id, args.into)
    _out(args, {"merged": args.id, "into": args.into},
         f"{args.id} merged into {args.into}. Both stay readable; the reporter "
         "of the merged one still reported something real.")
    return 0


def cmd_stale(args):
    s = _store(args)
    rows = s.stale()
    lines = [f"{len(rows)} tickets past the clock for their band", ""]
    for t in rows:
        window = t.stale_after()
        lines.append(_line(t) + f"   (band allows {window.days}d)")
    if not rows:
        lines = ["Nothing stale. Every open ticket is inside its band's window."]
    _out(args, [t.public_dict() for t in rows], "\n".join(lines))
    return 0


def cmd_outbox(args):
    s = _store(args)
    rows = s.outbox(state=args.state)
    lines = [f"{len(rows)} waiting in the outbox", ""]
    for r in rows:
        lines.append(f"  #{r['rowid_']} {r['ticket']} via {r['channel']} "
                     f"by {r['author']}  queued {r['queued']}")
    lines += ["", "Nothing here has been sent. The sender is armed separately, "
                  "with Sean's TOTP, and only it holds a credential."]
    _out(args, rows, "\n".join(lines))
    return 0


def cmd_mod_check(args):
    """Can my key moderate? Asked rather than assumed."""
    if not args.forum_url:
        print("--forum-url needed.", file=sys.stderr); return 2
    f = forummod.Forum(args.forum_url, args.forum_key)
    try:
        ok = f.am_i_mod()
        boards = [c.get("name") for c in f.categories()]
    except forummod.ForumError as exc:
        _out(args, {"mod": False, "error": str(exc)}, f"Could not ask: {exc}")
        return 1
    _out(args, {"mod": ok, "boards": boards},
         ("This key MODERATES. It can create the Tickets board, post, lock and pin."
          if ok else
          "This key does NOT moderate. Sean grants it with "
          "PATCH installs/{id}/role in the forum admin.")
         + f"\nBoards: {', '.join(b for b in boards if b) or '(none)'}")
    return 0


def build_parser():
    p = argparse.ArgumentParser(prog="tw", description="TICKET WICKET")
    p.add_argument("--json", action="store_true")
    p.add_argument("--db", default="", help="ticket store (default C:/snapsmack/ticket-wicket)")
    p.add_argument("--as", dest="as_who", default=model.CLAUDE,
                   choices=[model.CLAUDE, model.CHATGPT, model.SEAN, model.ASSISTANT],
                   help="who is driving. everyone signs as themselves.")
    p.add_argument("--forum-url", default=os.environ.get("SNAPSMACK_FORUM_URL", ""))
    p.add_argument("--forum-key", default=os.environ.get("SNAPSMACK_FORUM_KEY", ""))
    sub = p.add_subparsers(dest="cmd", required=True)

    q = sub.add_parser("queue"); q.add_argument("--all", action="store_true")
    q.add_argument("--band", default=""); q.set_defaults(func=cmd_queue)

    sh = sub.add_parser("show"); sh.add_argument("id")
    sh.add_argument("--private", action="store_true",
                    help="also show the reporter and original (never posted)")
    sh.set_defaults(func=cmd_show)

    n = sub.add_parser("new")
    n.add_argument("--title", required=True); n.add_argument("--body", default="")
    n.add_argument("--reporter", default=""); n.add_argument("--channel", default="")
    n.add_argument("--original", default="")
    n.add_argument("--security", action="store_true")
    n.add_argument("--data-at-risk", action="store_true")
    n.add_argument("--usability", default="cosmetic",
                   choices=["stopped", "workaround", "cosmetic"])
    n.add_argument("--affected", type=int, default=1)
    n.add_argument("--pulse", action="store_true")
    n.set_defaults(func=cmd_new)

    tr = sub.add_parser("triage"); tr.add_argument("id")
    tr.add_argument("--security", action="store_true")
    tr.add_argument("--data-at-risk", action="store_true")
    tr.add_argument("--usability", default="",
                    choices=["", "stopped", "workaround", "cosmetic"])
    tr.add_argument("--affected", type=int, default=None)
    tr.add_argument("--pulse", action="store_true")
    tr.add_argument("--body", default="", help="the anonymised public description")
    tr.set_defaults(func=cmd_triage)

    po = sub.add_parser("post"); po.add_argument("id")
    po.add_argument("--board", default="Tickets")
    po.add_argument("--dry-run", action="store_true")
    po.set_defaults(func=cmd_post)

    rp = sub.add_parser("reply"); rp.add_argument("id")
    rp.add_argument("--text", default=""); rp.add_argument("--text-file", default="")
    rp.add_argument("--channel", default="email", choices=["email", "forum"])
    rp.set_defaults(func=cmd_reply)

    ak = sub.add_parser("ack"); ak.add_argument("id")
    ak.add_argument("--channel", default="email", choices=["email", "forum"])
    ak.set_defaults(func=cmd_ack)

    es = sub.add_parser("escalate"); es.add_argument("id")
    es.add_argument("--why", default=""); es.set_defaults(func=cmd_escalate)

    st = sub.add_parser("state"); st.add_argument("id")
    for s_ in ("triaged", "answered", "waiting", "blocked", "resolved", "closed"):
        st.add_argument(f"--{s_}", action="store_true")
    st.set_defaults(func=cmd_state)

    lk = sub.add_parser("link"); lk.add_argument("id")
    for k in ("build", "opaudit", "commit", "pr"):
        lk.add_argument(f"--{k}", default="")
    lk.set_defaults(func=cmd_link)

    mg = sub.add_parser("merge"); mg.add_argument("id")
    mg.add_argument("--into", required=True); mg.set_defaults(func=cmd_merge)

    sub.add_parser("stale").set_defaults(func=cmd_stale)

    ob = sub.add_parser("outbox")
    ob.add_argument("--state", default="queued", choices=["queued", "sent"])
    ob.set_defaults(func=cmd_outbox)

    sub.add_parser("mod-check").set_defaults(func=cmd_mod_check)
    return p


def main(argv=None) -> int:
    args = build_parser().parse_args(argv)
    return args.func(args)


if __name__ == "__main__":
    raise SystemExit(main())

# ===== SNAPSMACK EOF =====
