#!/usr/bin/env python3
"""THE ARMED SENDER. It was designed, never built, and that is why the board is stale.

TICKET WICKET deliberately cannot send. tw.py's `reply` QUEUES, and both the
command line and the desktop window say so in as many words: "a separate armed
sender holds the only credential, so a bad draft can be written and never
delivered." That split is right and this file keeps it.

WHAT WAS ACTUALLY WRONG. The armed sender did not exist. store.mark_sent() was
written and had NO CALLER ANYWHERE IN THE REPOSITORY. Nine replies have sat in
the outbox since 28 October with an empty `sent` column, and the ROACH MOTEL
board shows 91 threads against 6 replies because nothing has ever carried a
ticket update to it. Not a lost credential, not an operator forgetting: the
thing that would do it was a sentence in a comment.

HOW IT BEHAVES, and every one of these is a refusal rather than a best effort:

  DRY RUN IS THE DEFAULT. With no --arm it sends nothing, touches nothing, and
  prints exactly what it would do, row by row, with the reason for anything it
  would skip. The outbox is read-only in this mode.

  IT SENDS ONLY TO THE FORUM. Rows queued on the email channel are LEFT ALONE.
  Sending mail is the owner's, not an agent's, and a tool that quietly learned
  to email people would be a worse fault than a stale board.

  IT WILL NOT INVENT A TARGET. A reply needs a thread. A row whose ticket has
  no forum_thread is skipped with the fix named -- `tw.py post <ID>` first --
  because guessing a thread id would put a ticket's words under somebody
  else's ticket.

  IT WILL NOT TOUCH A PULSE TICKET. tw.py's own rule: anything with a pulse
  reaches a human and is escalated, not answered by a script.

  IT NEVER EDITS A BODY. What was queued is what goes, byte for byte. If a
  draft is wrong the answer is to fix the draft, not to have the sender
  improve it on the way out.

  IT MARKS SENT ONLY ON A CONFIRMED POST. mark_sent() runs after the forum
  returns a reply id, and never before. A crash mid-run leaves a row queued,
  which is the safe direction: a duplicate reply is embarrassing and a lost one
  is invisible.

  ONE ROW AT A TIME, STOPPING ON THE FIRST FAILURE. Nine replies going out and
  the fourth failing should not leave five more attempted against an API that
  has started refusing.

USAGE

  python tw-send.py                          dry run, the default
  python tw-send.py --arm --forum-url U --forum-key K      actually send
  python tw-send.py --arm --only 4           send one row by its outbox number

SNAPSMACK_EOF_HEADER
Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import argparse
import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
if HERE not in sys.path:
    sys.path.insert(0, HERE)

from ticketwicket import forum as forummod, model, store as storemod  # noqa: E402


def classify(row: dict, ticket) -> tuple[bool, str]:
    """Can this row be delivered, and if not, what is the owner supposed to do?

    Returned as a reason rather than a bare False so the dry run is a work
    list instead of a count.
    """
    if row.get("channel") != "forum":
        return False, ("channel is '%s' -- NOT SENT. Sending mail is the owner's; "
                       "this tool only posts to the forum." % row.get("channel"))
    if ticket is None:
        return False, "ticket %s is not in the store any more" % row.get("ticket")
    # model.Ticket is a dataclass, not a row: attributes, not .get(). And the
    # pulse rule is the model's own -- "once set, nothing auto-sends. ever."
    if getattr(ticket, "pulse", False):
        return False, ("ticket has a PULSE -- escalate to a human, never answer "
                       "it with a script. The model says so itself: once set, "
                       "nothing auto-sends, ever.")
    thread = int(getattr(ticket, "forum_thread", 0) or 0)
    if thread <= 0:
        return False, ("ticket %s has no forum thread. Run: tw.py post %s   "
                       "(a reply needs a thread; guessing one would file these "
                       "words under somebody else's ticket)" % (row["ticket"], row["ticket"]))
    return True, "thread %d" % thread


def main(argv=None) -> int:
    p = argparse.ArgumentParser(description="Deliver queued TICKET WICKET replies to the forum.")
    p.add_argument("--db", default="", help="ticket store (default C:/snapsmack/ticket-wicket)")
    p.add_argument("--arm", action="store_true",
                   help="actually send. Without this nothing is sent and nothing is written.")
    p.add_argument("--only", type=int, default=0, help="one outbox row number")
    # NO ENVIRONMENT DEFAULTS, AND THIS IS THE WHOLE INTERLOCK.
    #
    # I first wrote these as default=os.environ.get(...) to match tw.py, then
    # ran `tw-send.py --arm` with no key EXPECTING A REFUSAL, as a test. The
    # shell already had SNAPSMACK_FORUM_KEY exported, argparse filled it in,
    # and two replies went to the live board: SS-1020 to thread 61 and SS-1029
    # to thread 68. The drafts were correct and the moment was not. I published
    # while testing that publishing was blocked.
    #
    # tw.py can afford environment defaults because TW.PY CANNOT SEND. This
    # file can, so an ambient credential must never be enough to arm it. The
    # key has to be typed, which makes sending something somebody did on
    # purpose rather than something their shell did for them.
    p.add_argument("--forum-url", default="")
    p.add_argument("--forum-key", default="")
    args = p.parse_args(argv)

    # The actor is stamped on every store change and the caller does not get to
    # claim to be somebody else, so the sender signs as itself.
    st = storemod.Store(path=args.db, actor=model.CLAUDE)
    rows = st.outbox("queued")
    if args.only:
        rows = [r for r in rows if int(r["rowid_"]) == args.only]

    print("ARMED" if args.arm else "DRY RUN — nothing will be sent and nothing written")
    print("%d queued row(s)%s\n" % (len(rows), " matching --only %d" % args.only if args.only else ""))
    if not rows:
        print("Nothing queued. The board is as current as the tickets are.")
        return 0

    sendable, blocked = [], []
    for r in rows:
        t = st.get(r["ticket"])
        ok, why = classify(r, t)
        (sendable if ok else blocked).append((r, t, why))
        mark = "SEND" if ok else "skip"
        print("  [%s] row %-3s %-9s %-6s %5dB  %s"
              % (mark, r["rowid_"], r["ticket"], r["channel"], len(r["body"] or ""), why))

    print("\n%d deliverable, %d blocked." % (len(sendable), len(blocked)))

    if not args.arm:
        print("\nThis was a dry run. Re-run with --arm plus --forum-url and --forum-key")
        print("to deliver the deliverable rows. Nothing above has been changed.")
        return 0

    if not args.forum_url or not args.forum_key:
        print("\nREFUSING TO SEND: --arm needs --forum-url and --forum-key ON THE\n"
              "COMMAND LINE. They are deliberately NOT read from the environment.\n"
              "An exported SNAPSMACK_FORUM_KEY once armed this by accident during a\n"
              "test and put two replies on the live board. Sending has to be typed.",
              file=sys.stderr)
        return 2

    f = forummod.Forum(args.forum_url, args.forum_key)
    done = 0
    for r, t, why in sendable:
        thread = int(getattr(t, "forum_thread", 0))
        try:
            reply_id = f.reply(thread, r["body"])
        except forummod.ForumError as exc:
            # STOP, do not carry on down the list. A failing API should not be
            # asked eight more times, and the rows behind this one stay queued.
            print("\nFAILED on row %s (%s, thread %d): %s"
                  % (r["rowid_"], r["ticket"], thread, exc), file=sys.stderr)
            print("Stopped. %d delivered before this; the rest are still queued."
                  % done, file=sys.stderr)
            return 1
        if not reply_id:
            print("\nFAILED on row %s: the forum returned no reply id, so this is NOT "
                  "marked sent." % r["rowid_"], file=sys.stderr)
            return 1
        st.mark_sent(int(r["rowid_"]), "forum reply %s on thread %d" % (reply_id, thread))
        done += 1
        print("  sent row %-3s %-9s -> thread %d, reply %s"
              % (r["rowid_"], r["ticket"], thread, reply_id))

    print("\n%d delivered. %d left queued and why is listed above." % (done, len(blocked)))
    return 0


if __name__ == "__main__":
    sys.exit(main())
# ===== SNAPSMACK EOF =====
