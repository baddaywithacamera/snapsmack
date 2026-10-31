"""TICKET WICKET - the capability sheet for Claude and Codex.

Sean, 2026-10-17: "It needs to have a help system specific to you and Codex
where you can check what functions are available to you."

An agent arriving cold needs the surface without reading the source, and needs
the REFUSALS most of all: this tool deliberately cannot send and cannot delete,
and knowing that up front stops an agent burning a turn looking for the button.

    python tw.py agent              the sheet
    python tw.py agent --json       parseable

Duplicated from LOG FLOGGER's rather than shared. A shared copy would live in
tools/_shared, whose runtime path is the subject of OPAUDIT 024.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

TOOL = "TICKET WICKET"
ENTRY = "python tw.py"
PURPOSE = ("Turns a real issue into a numbered, tracked, PUBLIC ticket that "
           "carries nobody's name.")

COMMANDS = [
    {"name": "queue", "does": "What needs attention, worst band first.",
     "returns": "list of public ticket dicts", "args": ["--all", "--band BAND"],
     "example": ENTRY + " queue --json",
     "notes": "Open tickets only unless --all. Sorted by band then age."},
    {"name": "show", "does": "One ticket and its whole history.",
     "returns": "public dict; the text view adds the entry log",
     "args": ["ID", "--private"],
     "example": ENTRY + " show SS-1042 --private",
     "notes": "--private reveals the reporter and their original message. Those "
              "are NEVER posted. Use it to reply to a human, not to publish."},
    {"name": "new", "does": "Open a ticket.",
     "returns": "the new ticket, with its computed band",
     "args": ["--title T", "--body T", "--reporter R", "--channel C",
              "--original T", "--security", "--data-at-risk",
              "--usability stopped|workaround|cosmetic", "--affected N", "--pulse"],
     "example": ENTRY + " new --title 'borders missing' --usability stopped",
     "notes": "--body is the PUBLIC description: write it about the problem, "
              "never as a reprint of what arrived."},
    {"name": "triage", "does": "Set the inputs to urgency; the band recomputes.",
     "returns": "the ticket",
     "args": ["ID", "--security", "--data-at-risk", "--usability X",
              "--affected N", "--pulse", "--body T"],
     "example": ENTRY + " triage SS-1042 --security --affected 4",
     "notes": "--pulse is one-way in practice: once set, no agent may queue a reply."},
    {"name": "post", "does": "Put the ticket in the tickets board, anonymised.",
     "returns": "thread id, or the refusal and what still identifies someone",
     "args": ["ID", "--board NAME", "--dry-run", "--unwrap"],
     "example": ENTRY + " post SS-1042 --dry-run",
     "notes": "REFUSES and exits 1 if the body still identifies anyone. Always "
              "--dry-run first; it prints exactly what would be published."},
    {"name": "reply", "does": "QUEUE a reply. Does not send.",
     "returns": "outbox row id",
     "args": ["ID", "--text T | --text-file F", "--channel email|forum",
              "--unwrap"],
     "example": ENTRY + " reply SS-1042 --text '...' --as claude",
     "notes": "Signed with YOUR signature automatically. Fails on a pulse ticket."},
    {"name": "ack", "does": "Queue the fixed acknowledgement.",
     "returns": "outbox row id", "args": ["ID", "--channel email|forum"],
     "example": ENTRY + " ack SS-1042",
     "notes": "A constant, not composed. Signed as the AI assistant, not as you."},
    {"name": "escalate", "does": "Hand to Sean by name; sets pulse.",
     "returns": "confirmation", "args": ["ID", "--why TEXT"],
     "example": ENTRY + " escalate SS-1042 --why 'security report'",
     "notes": "Escalating is a first-class outcome, not a failure. Use it."},
    {"name": "state", "does": "Move the ticket's state.",
     "returns": "the ticket",
     "args": ["ID", "--triaged|--answered|--waiting|--blocked|--resolved|--closed"],
     "example": ENTRY + " state SS-1042 --resolved",
     "notes": "No state deletes anything."},
    {"name": "link", "does": "Tie the ticket to what fixed it.",
     "returns": "the links",
     "args": ["ID", "--build V", "--opaudit N", "--commit SHA", "--pr N"],
     "example": ENTRY + " link SS-1042 --build 0.7.902D",
     "notes": "A ticket should not close as fixed with nothing to point at."},
    {"name": "merge", "does": "Point a duplicate at the real ticket.",
     "returns": "confirmation", "args": ["ID", "--into ID"],
     "example": ENTRY + " merge SS-1049 --into SS-1042",
     "notes": "Both stay readable. The duplicate's reporter still reported "
              "something real."},
    {"name": "stale", "does": "What is past the clock for its band.",
     "returns": "list of tickets", "args": [],
     "example": ENTRY + " stale --json",
     "notes": "The keep-me-honest mechanism. It states a fact; it does not nag, "
              "chase or editorialise."},
    {"name": "forum-gap", "does": "Published tickets the board has not heard about since.",
     "args": [],
     "returns": "id, thread, board_state, state, changes, security, note",
     "example": ENTRY + " forum-gap",
     "notes": "READ ONLY and offline: it reads the audit trail, never the "
              "forum, so it needs no key and cannot touch a live board. "
              "board_state is what the thread STILL SAYS, recovered from "
              "the 'state: old -> new' audit line. Security-flagged rows "
              "are marked and must never be swept up: disclosure timing is "
              "the owner's, every time."},

    {"name": "outbox", "does": "What is waiting to go out.",
     "returns": "queued rows", "args": ["--state queued|sent"],
     "example": ENTRY + " outbox --json",
     "notes": "Nothing here has been sent. A separate armed sender delivers."},
    {"name": "mod-check", "does": "Does my forum key have the role it needs?",
     "returns": "admin (bool), boards", "args": [],
     "example": ENTRY + " mod-check --json",
     "notes": "Ask this before assuming you can post. Tests role >= 3 (admin), "
              "which is what creating the Tickets board needs; role 2 cannot be "
              "detected read-only. Sean grants role through the forum admin."},
]

REFUSALS = [
    "THERE IS NO send. Replies queue; a separate armed sender holds the only "
    "credential. A bad draft can be written and cannot be delivered.",
    "THERE IS NO delete. close, withdraw, merge. The record survives all three.",
    "post and reply REFUSE a hard-wrapped body, and refuse markdown. The "
    "forum honours every newline and reads no markdown, so a body wrapped at "
    "72 columns arrives as a stack of short lines and **bold** arrives as "
    "asterisks. One paragraph per line, blank line between paragraphs, "
    "CAPITALS for emphasis. --unwrap joins the lines for this post only and "
    "leaves the stored ticket alone; nothing strips markdown for you.",
    "post REFUSES a ticket whose body still identifies anyone, and tells you "
    "what it found. Rewrite the body about the problem; do not work around it.",
    "reply REFUSES on a ticket flagged --pulse. Anything with a pulse reaches "
    "the human: frustration, anger, security, payment, a first-time poster. "
    "Escalate instead.",
    "You cannot sign as someone else. --as stamps who is driving, and every "
    "author signs as itself.",
    "The forum client is inert without a key. That is deliberate: a stolen copy "
    "of this tool is a dead thing.",
    "move_thread and leave_breadcrumb raise NotImplementedError. The forum API "
    "has no move endpoint yet. Do not fake it by re-posting - that orphans "
    "everyone following the thread.",
]

BANDS = [
    "LOSING WORK       data at risk. stale after 1 day.",
    "DOOR OPEN         a security hole. stale after 2 days.",
    "STOPPED           no way round it. stale after 7 days.",
    "WORKING ROUND IT  there is a path through. stale after 30 days.",
    "WANTED            an idea. never stale.",
]

RECIPES = [
    ("Start of a shift: what needs me?", ENTRY + " queue --json"),
    ("Am I letting something rot?", ENTRY + " stale --json"),
    ("Open a ticket from a bug you found",
     ENTRY + " new --title '...' --body '...' --usability stopped"),
    ("Check a ticket is safe to publish", ENTRY + " post SS-1042 --dry-run"),
    ("Someone is upset", ENTRY + " escalate SS-1042 --why 'frustrated reporter'"),
    ("Close it properly",
     ENTRY + " link SS-1042 --build 0.7.902D then state SS-1042 --resolved"),
]


def sheet() -> dict:
    return {
        "tool": TOOL,
        "entry": ENTRY,
        "purpose": PURPOSE,
        "audience": "Claude and Codex, working without Sean present",
        "signatures": {"claude": "- Claude", "chatgpt": "- ChatGPT",
                       "sean": "- Sean", "assistant": "- SnapSmack AI assistant"},
        "commands": COMMANDS,
        "refusals": REFUSALS,
        "bands": BANDS,
        "recipes": [{"goal": g, "run": r} for g, r in RECIPES],
        "global_args": ["--as claude|chatgpt|sean|assistant", "--json",
                        "--db PATH", "--forum-url URL", "--forum-key KEY"],
        "exit_codes": {"0": "ok", "1": "refused", "2": "bad usage"},
    }


def text() -> str:
    out = [TOOL + " - capability sheet for Claude and Codex", "=" * 64, PURPOSE, "",
           "ALWAYS PASS --as claude  (or --as chatgpt). Everyone signs as themselves.",
           "", "WHAT IT WILL REFUSE TO DO  (read this first)"]
    for r in REFUSALS:
        out.append("  - " + r)
    out += ["", "COMMANDS"]
    for c in COMMANDS:
        out.append("  %-11s %s" % (c["name"], c["does"]))
        out.append("              args:    " + " ".join(c["args"]))
        out.append("              returns: " + c["returns"])
        out.append("              " + c["notes"])
        out.append("              e.g. " + c["example"])
        out.append("")
    out += ["URGENCY BANDS  (computed from security + usability + affected)"]
    for b in BANDS:
        out.append("  " + b)
    out += ["", "RECIPES"]
    for g, r in RECIPES:
        out += ["  " + g, "      " + r]
    out += ["", "--json on every command. Exit 0 ok, 1 refused, 2 bad usage."]
    return "\n".join(out)

# ===== SNAPSMACK EOF =====
