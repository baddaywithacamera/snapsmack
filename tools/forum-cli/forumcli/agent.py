"""What Claude and Codex can do here, and what this tool will refuse.

Refusals first. Knowing a thing is impossible saves an agent a wasted turn,
and every tool in this repo that an agent drives prints its refusals before
its commands for that reason.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

ENTRY = "fc"

REFUSALS = [
    "THERE IS NO DELETE. Threads and replies soft-delete through the API and "
    "this tool does not expose it. Ask a human.",
    "It is inert without a key. A stolen copy of this tool is a dead thing.",
    "It will not invent a board. `board-new` needs role 3, and says so when "
    "the key has less.",
    "Breadcrumbs when a thread moves do not exist yet. `move` moves the "
    "thread and leaves nothing behind in the old board.",
]

COMMANDS = [
    {"name": "whoami", "does": "Which key is this, and what may it do?",
     "returns": "install, role (0-4), role_name, can{post,moderate,administer}",
     "args": [], "example": ENTRY + " whoami --json",
     "notes": "Ask this FIRST. Everything else is gated on the answer."},

    {"name": "boards", "does": "List the active boards.",
     "returns": "id, slug, name, thread_count, reply_count",
     "args": [], "example": ENTRY + " boards --json"},

    {"name": "board-new", "does": "Create a board.", "returns": "board id",
     "args": ["NAME", "--description D"], "example": ENTRY + " board-new Tickets",
     "notes": "Role 3. Idempotent: an existing board of that name is returned."},

    {"name": "threads", "does": "List threads, pinned first then by activity.",
     "returns": "id, title, excerpt, flag, reply_count, last reply, unread",
     "args": ["--board N", "--tag SLUG", "--page N"],
     "example": ENTRY + " threads --board 3 --json"},

    {"name": "thread", "does": "One thread with its replies and reactions.",
     "returns": "thread, replies, reactions, tags", "args": ["ID"],
     "example": ENTRY + " thread 42 --json",
     "notes": "Increments the view count, same as a browser would."},

    {"name": "post", "does": "Start a thread.", "returns": "thread id",
     "args": ["--board N", "--title T", "--body B | --body-file F",
              "--flag none|chat|support|question|brag"],
     "example": ENTRY + " post --board 3 --title 'Upload fails' --body-file bug.txt",
     "notes": "Rate limited: 3/hour ordinary, 12/hour role>=1, mods exempt."},

    {"name": "reply", "does": "Reply to a thread.", "returns": "reply id",
     "args": ["ID", "--body B | --body-file F"],
     "example": ENTRY + " reply 42 --body 'Fixed in 0.7.903D.'",
     "notes": "Notifies the author and everyone following. 10/hour ordinary."},

    {"name": "react", "does": "Add or remove an emoji reaction.",
     "returns": "status", "args": ["ID", "--emoji X", "--reply", "--remove"],
     "example": ENTRY + " react 42 --emoji ??",
     "notes": "Same emoji toggles off; a different one replaces it."},

    {"name": "follow", "does": "Follow or unfollow a thread for its replies.",
     "returns": "status", "args": ["ID", "--off"],
     "example": ENTRY + " follow 42"},

    {"name": "lock", "does": "Lock or unlock a thread.", "returns": "status",
     "args": ["ID", "--off"], "example": ENTRY + " lock 42",
     "notes": "Role 2. The field is is_locked; sending `locked` is a 400."},

    {"name": "pin", "does": "Pin or unpin a thread.", "returns": "status",
     "args": ["ID", "--off"], "example": ENTRY + " pin 42", "notes": "Role 2."},

    {"name": "move", "does": "Move a thread to another board.",
     "returns": "status, category_id, moved_from", "args": ["ID", "--board N"],
     "example": ENTRY + " move 42 --board 5",
     "notes": "Role 2. The thread keeps its id, so followers keep it."},

    {"name": "search", "does": "Full-text search across titles, bodies, replies.",
     "returns": "results", "args": ["QUERY", "--board N", "--page N"],
     "example": ENTRY + " search 'upload fails' --json"},

    {"name": "notifications", "does": "Replies to your threads and ones you follow.",
     "returns": "notifications, unread count", "args": ["--mark-read", "--page N"],
     "example": ENTRY + " notifications --json",
     "notes": "Polled. There is no push."},

    {"name": "agent", "does": "This sheet.", "returns": "commands and refusals",
     "args": [], "example": ENTRY + " agent --json"},
]


def sheet() -> dict:
    return {"entry": ENTRY, "refusals": REFUSALS, "commands": COMMANDS}


def text() -> str:
    lines = ["FORUM CLI — what this tool will NOT do:", ""]
    lines += ["  - " + r for r in REFUSALS]
    lines += ["", "Commands:", ""]
    for c in COMMANDS:
        args = (" " + " ".join(c["args"])) if c["args"] else ""
        lines.append(f"  {ENTRY} {c['name']}{args}")
        lines.append(f"      {c['does']}")
        lines.append(f"      -> {c['returns']}")
        if c.get("notes"):
            lines.append(f"      note: {c['notes']}")
        lines.append(f"      e.g. {c['example']}")
        lines.append("")
    lines.append("Credentials: --forum-url/--forum-key, or SNAPSMACK_FORUM_URL")
    lines.append("and SNAPSMACK_FORUM_KEY in the environment. Nothing is stored.")
    return "\n".join(lines)
# ===== SNAPSMACK EOF =====
