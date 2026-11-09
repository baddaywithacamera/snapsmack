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
    tw mod-check                  does my forum key have the role it needs?

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

from ticketwicket import agent, anonymise, forum as forummod, model, store as storemod
import snap_forum                      # noqa: E402  (forum.py put it on the path)


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

    # ALREADY ON THE BOARD? THEN THIS WOULD BE A SECOND COPY.
    #
    # On 29 October 2026 this command created three duplicate threads in one
    # evening -- SS-1006 (48 and 89), SS-1033 (71 and 87) and SS-1064 (86 and
    # 93) -- because it posts unconditionally and then OVERWRITES forum_thread
    # with the new id. So the ticket stopped pointing at the thread that had
    # the reply history, and the board showed the same ticket twice. The owner
    # noticed the board before anyone noticed the duplicates.
    #
    # I wrote down that this needed a guard and then did it once more an hour
    # later, which is the argument for the guard rather than for being careful.
    # `reply` is what updates a ticket that is already published.
    # WILL THE FORUM RENDER IT THE WAY IT READS HERE?
    #
    # Thread 94 answered that. It went up hard-wrapped at ~72 columns, the
    # renderer honours every newline, and Sean's screenshot showed a stack of
    # short ragged lines with a gap under each. 21 of the 49 threads already on
    # the board are like that. Checked on the COMPOSED post, because the
    # template contributes to it as much as the ticket body does.
    shape = snap_forum.body_shape_complaints(body)
    if shape and args.unwrap:
        body = snap_forum.unwrap_body(body)
        shape = snap_forum.body_shape_complaints(body)
    if shape:
        print("REFUSING TO POST — the forum would not render this the way it "
              "reads here:\n\n  " + "\n\n  ".join(shape)
              + "\n\nNothing has been posted. The stored ticket is unchanged."
              + ("" if args.unwrap else
                 "\n\nPass --unwrap to join the wrapped lines for this post, "
                 "or rewrite the body properly:\n"
                 "    tw.py --as <you> triage " + str(t.id) + " --body-file draft.txt"),
              file=sys.stderr)
        return 1

    existing = getattr(t, "forum_thread", 0) or 0
    if existing and not args.again:
        print(f"REFUSING TO POST — {t.id} is already forum thread {existing}.\n"
              f"\nPosting again creates a SECOND thread for the same ticket and "
              f"repoints the ticket at the copy, leaving the original and its "
              f"replies orphaned.\n"
              f"\nTo add to the existing thread:\n"
              f"    tw.py --as <you> reply {t.id} --text \"...\"\n"
              f"\nIf a second thread is genuinely wanted, pass --again.",
              file=sys.stderr)
        return 1

    if args.dry_run or not args.forum_url:
        _out(args, {"would_post": body, "posted": False},
             "DRY RUN — would post:\n\n" + body
             + "\n\n(no --forum-url given, or --dry-run)")
        return 0

    f = forummod.Forum(args.forum_url, args.forum_key)
    cat = _board_id(f, args.board)
    if not cat:
        message = [
            "REFUSING TO POST - there is no board called "
            + repr(args.board) + ".",
            "",
            "This command LOOKS UP the board and never creates one. On",
            "17 October it defaulted to a board named 'Tickets', the forum",
            "did not have one, and ensure_board invented it: a board nobody",
            "asked for, sorted above every real board because it was created",
            "without a sort order. The owner found it on his own board list",
            "twelve days later.",
            "",
            "Creating a board is a deliberate act someone types:",
            "    fc.py board-new " + repr(args.board) + " --sort N",
            "",
            "Or pass --board with the name of a board that exists.",
        ]
        print("\n".join(message), file=sys.stderr)
        return 1
    thread_id = f.post_ticket(cat, f"{t.id} · {t.title}", body)
    s.update(t.id, forum_thread=thread_id)
    s.add_entry(t.id, "post", f"posted to forum thread {thread_id}", "forum")
    _out(args, {"thread": thread_id, "posted": True},
         f"Posted {t.id} as forum thread {thread_id}")
    return 0


def _board_id(forum, name: str) -> int:
    """The id of an EXISTING board, or 0.

    Deliberately not snap_forum.ensure_board, which creates on a miss. A
    publishing tool must not be able to invent a board: AGENTS.md names one
    board for tickets, and anything else is a typo or a drifted default, not an
    instruction to make somewhere new to put things.
    """
    return forummod.board_id(forum, name)


def _title_is_for_ticket(title, ticket_id):
    """True only when a thread title names THIS ticket as a WHOLE id.

    The board search is a prefix query: searching SS-1 returns SS-1, SS-12,
    SS-100... A bare startswith() backfilled SS-1's thread id from an SS-12
    thread and misrouted every later delivery for SS-1 (Muse, 989D pass). The id
    must be followed by a boundary -- end of string, or a non-word character --
    never another digit or letter.
    """
    title = (title or "").strip()
    if not title.startswith(ticket_id):
        return False
    rest = title[len(ticket_id):]
    return rest == "" or not (rest[0].isalnum() or rest[0] in "-_")


def _resolve_thread(s, f, ticket_id):
    """The ticket's forum thread id, backfilling the store when the DB lost it.

    A ticket published through FORUM CLI or by another seat never wrote its
    thread id back into TICKET WICKET's own forum_thread, so a queued reply had
    nowhere the tool knew to go. Here we trust the DB first, then find the thread
    on the board by the ticket id in its title and record it, so next time it is
    known.
    """
    try:
        thread = int(getattr(s.get(ticket_id), "forum_thread", 0) or 0)
    except Exception:
        return 0
    if thread > 0:
        return thread
    try:
        data = f.search(ticket_id)
        items = (data.get("threads") or data.get("items") or data.get("results")
                 or (data if isinstance(data, list) else []))
        for th in items:
            if _title_is_for_ticket(th.get("title"), ticket_id):
                tid = int(th.get("id") or 0)
                if tid > 0:
                    try:
                        s.update(ticket_id, forum_thread=tid)
                    except Exception:
                        pass
                    return tid
    except Exception:
        pass
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
    # A queued reply is not sent from here, but it is written to be published,
    # and a wrapped draft that sits in the outbox gets published wrapped. 3 of
    # the 7 rows in there are hard-wrapped right now.
    shape = snap_forum.body_shape_complaints(body)
    if shape and args.unwrap:
        body = snap_forum.unwrap_body(body)
        shape = snap_forum.body_shape_complaints(body)
    if shape:
        print("REFUSING TO QUEUE — the forum would not render this the way it "
              "reads here:\n\n  " + "\n\n  ".join(shape)
              + "\n\nNothing has been queued."
              + ("" if args.unwrap else "\n\nPass --unwrap to join the "
                 "wrapped lines for you."),
              file=sys.stderr)
        return 1
    try:
        row = s.enqueue(args.id, args.channel, body)
    except PermissionError as exc:
        print(str(exc), file=sys.stderr); return 1
    # SS-1099: a forum reply to a PUBLISHED ticket is delivered now, with the
    # forum key, instead of sitting unsent in the outbox forever. Delivery still
    # needs the credential (the armed part); without a key, or for email, or for
    # an unpublished ticket, it stays queued and `tw deliver-forum` drains it.
    if args.channel == "forum" and args.forum_url and args.forum_key:
        _f = forummod.Forum(args.forum_url, args.forum_key)
        thread = _resolve_thread(s, _f, args.id)
        if thread > 0:
            try:
                reply_id = _f.reply(thread, body)
                s.mark_sent(row, f"forum reply {reply_id} on thread {thread}")
                _out(args, {"outbox": row, "sent": True, "thread": thread, "reply": reply_id},
                     f"Delivered to forum thread {thread} (reply {reply_id}), signed {sig}.")
                return 0
            except Exception as exc:
                _out(args, {"outbox": row, "sent": False, "error": str(exc)},
                     f"Queued for forum, signed {sig}. Delivery FAILED ({exc}); "
                     f"it stays in the outbox for `tw deliver-forum`.")
                return 0
    _out(args, {"outbox": row, "sent": False},
         f"Queued for {args.channel}, signed {sig}. "
         + ("NOT SENT — email is the owner's to send."
            if args.channel == "email"
            else "NOT SENT — no forum key, or the ticket is not published yet. "
                 "Run `tw deliver-forum` with a key once it is."))
    return 0


def _already_on_thread(f, thread, body):
    """True when the thread already carries this update, so delivery is idempotent.

    An update can reach a thread by more than one path -- posted as a reply, or
    edited into the opening post as an inline UPDATE block -- and the outbox row
    is not always marked sent when that happens (SS-1043's 0.7.970D note is on
    thread 77 that way). Reposting it would double it on a PUBLIC board, so we
    read the thread first and match on a distinctive line of the body.
    """
    collapse = lambda t: " ".join((t or "").split())   # whitespace-insensitive
    norm = collapse(body)
    probe = ""
    for line in (body or "").splitlines():            # prefer a distinctive line
        line = collapse(line)
        if len(line) >= 24:
            probe = line[:80]
            break
    if not probe:                                     # short/one-line body: use the whole thing
        probe = norm[:80]
    if not probe:
        return False
    try:
        data = f.thread(thread)
        texts = [(data.get("thread") or {}).get("body", "")]
        texts += [x.get("body", "") for x in (data.get("replies") or [])]
        return probe in collapse("\n".join(texts))
    except Exception:
        return False   # cannot read the thread: do not block delivery on it


def cmd_deliver_forum(args):
    """The armed sender: deliver every queued FORUM reply to its ticket's thread.

    This is what SS-1099 was missing -- replies queued and nothing ever sent
    them. It needs the forum key (the credential is still held separately from
    authoring), posts each queued forum reply to the ticket's published thread,
    and marks the outbox row sent so it is never double-posted. It also RESCUES an
    update parked as email on a ticket that is published to the forum and has no
    email address on file -- there is no inbox and the ticket is public, so its
    thread is where it belongs. Genuine mail (a real address, or no thread) is
    left untouched: sending that is the owner's.
    """
    s = _store(args)
    if not args.forum_url or not args.forum_key:
        print("The armed sender needs a forum key: --forum-url and --forum-key "
              "(or SNAPSMACK_FORUM_URL / SNAPSMACK_FORUM_KEY).", file=sys.stderr)
        return 2
    f = forummod.Forum(args.forum_url, args.forum_key)
    delivered, skipped = [], []
    for r in s.outbox("queued"):
        ticket_id = r.get("ticket")
        channel = r.get("channel")
        reporter = (getattr(s.get(ticket_id), "reporter", "") or "")
        # Decide candidacy BEFORE touching the board. A genuine email row (a real
        # address) is never searched, so its ticket's forum_thread can never be
        # backfilled as a side effect (Muse, 989D pass). A forum row, or an email
        # row for a public ticket with no inbox, is a candidate; nothing else is.
        if channel == "forum":
            rescued = False
        elif channel == "email" and "@" not in reporter:
            rescued = True
        else:
            continue                        # genuine mail: the owner's to send
        thread = _resolve_thread(s, f, ticket_id)
        if thread <= 0:
            skipped.append({"ticket": ticket_id, "why": "ticket not published to the forum"})
            continue
        body = r.get("body", "")
        if _already_on_thread(f, thread, body):
            s.mark_sent(r["rowid_"], f"already on thread {thread}; reconciled, not reposted")
            skipped.append({"ticket": ticket_id, "why": f"already on thread {thread} (reconciled)"})
            continue
        # The email drafts were never vetted for forum rendering; make sure a
        # hard-wrapped one does not post as a ladder of broken lines.
        if snap_forum.body_shape_complaints(body):
            body = snap_forum.unwrap_body(body)
        try:
            reply_id = f.reply(thread, body)
            note = f"forum reply {reply_id} on thread {thread}"
            if rescued:
                note += " (rescued from email: public ticket, no inbox)"
            s.mark_sent(r["rowid_"], note)
            delivered.append({"ticket": ticket_id, "thread": thread, "reply": reply_id,
                              "rescued": rescued})
        except Exception as exc:
            skipped.append({"ticket": ticket_id, "why": f"delivery failed: {exc}"})
    _out(args, {"delivered": delivered, "skipped": skipped},
         f"Delivered {len(delivered)} forum repl"
         f"{'y' if len(delivered) == 1 else 'ies'}; {len(skipped)} skipped. "
         f"Genuine email replies -- no forum thread, or a real address -- are left "
         f"for the owner to send.")
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
    """Can my key do the job? Asked rather than assumed."""
    if not args.forum_url:
        print("--forum-url needed.", file=sys.stderr); return 2
    f = forummod.Forum(args.forum_url, args.forum_key)
    try:
        ok = f.am_i_admin()
        boards = [c.get("name") for c in f.categories()]
    except forummod.ForumError as exc:
        _out(args, {"admin": False, "error": str(exc)}, f"Could not ask: {exc}")
        return 1
    _out(args, {"admin": ok, "boards": boards},
         ("This key is ADMIN (role 3). It can create the Tickets board, post, "
          "lock and pin — everything this tool needs."
          if ok else
          "This key is NOT admin (role 3), so it cannot create the Tickets "
          "board. It may still be a moderator (role 2) and able to post, lock "
          "and pin: no endpoint reports your own role, so that cannot be "
          "checked from here. Sean grants role with "
          "PATCH installs/{id}/role in the forum admin.")
         + f"\nBoards: {', '.join(b for b in boards if b) or '(none)'}")
    return 0


def cmd_agent(args):
    """What Claude and Codex can do here. Refusals first: this tool cannot
    send and cannot delete, and knowing that saves looking for the button."""
    _out(args, agent.sheet(), agent.text())
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
    # AGENTS.md: "tickets are published to the ROACH MOTEL board". The
    # default was "Tickets" from the day this tool was written, which is
    # how a board nobody asked for came to exist.
    po.add_argument("--board", default=model.TICKET_BOARD)
    po.add_argument("--again", action="store_true",
                    help="post a SECOND thread for a ticket that already has one")
    po.add_argument("--dry-run", action="store_true")
    po.add_argument("--unwrap", action="store_true",
                    help="join hard-wrapped lines in this post; the stored "
                         "ticket is not changed")
    po.set_defaults(func=cmd_post)

    rp = sub.add_parser("reply"); rp.add_argument("id")
    rp.add_argument("--text", default=""); rp.add_argument("--text-file", default="")
    rp.add_argument("--channel", default="forum", choices=["email", "forum"],
                    help="where the reply goes; defaults to the ticket's forum "
                         "thread. Pass email only for a genuinely private note.")
    rp.add_argument("--unwrap", action="store_true",
                    help="join hard-wrapped lines before queueing")
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

    df = sub.add_parser("deliver-forum")
    df.set_defaults(func=cmd_deliver_forum)

    ob = sub.add_parser("outbox")
    ob.add_argument("--state", default="queued", choices=["queued", "sent"])
    ob.set_defaults(func=cmd_outbox)

    sub.add_parser("mod-check").set_defaults(func=cmd_mod_check)
    sub.add_parser("agent", help="what Claude and Codex can do here"
                   ).set_defaults(func=cmd_agent)

    # --json is documented as working on every command, and an agent will
    # naturally write it after the subcommand. argparse only accepts a
    # top-level flag before the subcommand, so mirror it onto each subparser.
    # SUPPRESS matters: without it the subparser's default would overwrite a
    # --json given before the subcommand.
    for _sp in sub.choices.values():
        _sp.add_argument("--json", action="store_true",
                         default=argparse.SUPPRESS,
                         help="machine-readable output")
    return p


def main(argv=None) -> int:
    args = build_parser().parse_args(argv)
    return args.func(args)


if __name__ == "__main__":
    raise SystemExit(main())

# ===== SNAPSMACK EOF =====
