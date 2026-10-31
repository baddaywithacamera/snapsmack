"""The SnapSmack forum, as a client every tool shares.

The forum is SnapSmack's own: projects/forum-server/api/forum/ is a REST API on
snapsmack.ca and smack-forum.php in the CMS is only its client. So these are
"has it been built" questions, not vendor questions.

Read from the API's own endpoint list, 2026-10-17:

  WORKS ALREADY
    POST   categories            create the Tickets board (admin/mod key)
    POST   threads               post a ticket
    PATCH  threads/{id}          pin / lock / edit body / set flag
    POST   threads/{id}/replies  updates on a ticket
    POST   threads/{id}/react    <- the "I'm affected" button. Already there.
    POST   threads/{id}/follow   <- "we're aware, follow for updates". Already there.
    GET    threads/{id}          thread + replies + reaction COUNTS
    PATCH  installs/{id}/role    promote a blog — this is how mod is granted

  NOT BUILT YET (two small gaps, both in code we own)
    moving a thread between boards   — threads have category_id and listing
                                       filters on it; only the endpoint is missing
    a breadcrumb when a thread moves — doesn't exist. Should leave a locked stub
                                       pointing at the new thread, never move
                                       silently: nobody following a thread should
                                       lose it because a moderator tidied up.

WHERE THIS LIVES. It was TICKET WICKET's private client until FORUM CLI needed
the same calls. Two copies of an HTTP client drift, and the one that drifts is
always the one nobody is reading, so it moved here and TICKET WICKET imports it.
ticketwicket/forum.py is a re-export; nothing that used it had to change.

ON REACTIONS AND PRIVACY. fetch_reactions() returns counts and "did YOU react" —
it never returns who. That is currently luck rather than design, so this client
treats it as a contract and the disclosure floor below enforces the rest: with a
tiny fleet a count of 1 identifies somebody by arithmetic, whatever the API does.

Cloudflare 1010-blocks anything that does not look like a browser, so every
request carries a browser User-Agent. That is a documented requirement of this
API, not a trick.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import json
import re
import urllib.error
import urllib.parse
import urllib.request

# The emoji that means "this is happening to me too".
AFFECTED = "🙋"

# Below this, the public ticket says "a few" instead of a number. With a small
# fleet, "2 affected" plus knowing you are not one of them narrows it to two
# people. That is arithmetic, not a bug, and a threshold is the only fix.
DISCLOSURE_FLOOR = 5

_UA = ("Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
       "(KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36")


class ForumError(RuntimeError):
    pass


# ── What the renderer actually honours ───────────────────────────────────────
#
# core/forum-post-body.php escapes the body and places [img:N]; everything
# else arrives as text. The element around it carries white-space: pre-wrap,
# so a newline IS a line break and a run of spaces is kept. There is no
# markdown anywhere: **bold** shows its asterisks, a # heading shows its hash,
# a [label](url) shows both halves. The server strips markdown when it builds
# the LISTING excerpt, which is why it looked supported -- the list view is
# clean and the thread view is not.
#
# Hard wrapping is the one that bit. A body wrapped at 72 columns gets a line
# break every 72 characters inside a column several hundred pixels wide, so a
# paragraph arrives as a stack of short ragged lines. 21 of the 49 ticket
# threads on the board are like that, and Sean found it, not us.
#
# Nothing in these tools ever wrapped anything: the wrapping came from whoever
# typed the body. So this is a check at the publishing boundary rather than a
# formatter, because the thing to change is the draft.

WRAP_SUSPECT_LENGTH = 55

# A line that was meant to stand alone: a bullet, a numbered item, a quote, an
# image placeholder, or anything indented. Joining one of these into its
# neighbour would be the tool breaking the author's formatting rather than
# fixing it.
#
# The (\s|$) at the end is load-bearing: [img:2] alone on a line is the
# documented way to place an image (core/forum-post-body.php), and requiring
# whitespace after the token read it as the continuation of the paragraph
# above it instead -- so unwrapping a post would have pulled the placeholder
# up into the sentence. Same for a bare "-" bullet or a bare "1.".
_STANDS_ALONE = re.compile(r"^(\s+|\s*([-*\u2022>]|\d{1,3}[.)]|\[img:\d{1,2}\])(\s|$))")

# Markdown the renderer shows as characters. The label is what a person needs
# to hear, so it is carried next to the pattern rather than derived from it.
_DEAD_MARKDOWN = (
    ("**bold**", re.compile(r"\*\*[^*\n]+\*\*")),
    ("a # heading", re.compile(r"^#{1,6}\s+\S", re.M)),
    ("`code`", re.compile(r"`[^`\n]+`")),
    ("a ``` fence", re.compile(r"```")),
    ("a [label](link)", re.compile(r"\[[^\]\n]+\]\([^)\n]+\)")),
)


def hard_wrapped_lines(body: str) -> list:
    """The 1-based line numbers that were hard-wrapped into the next line.

    A line counts when it is long enough to look like a wrap, the line after
    it carries text, and neither of them is a list item, a quote, an image
    placeholder or indented.
    """
    lines = (body or "").split("\n")
    out = []
    for i in range(len(lines) - 1):
        here, nxt = lines[i], lines[i + 1]
        if len(here) < WRAP_SUSPECT_LENGTH:
            continue
        if not nxt.strip():
            continue
        if _STANDS_ALONE.match(here) or _STANDS_ALONE.match(nxt):
            continue
        out.append(i + 1)
    return out


def dead_markdown(body: str) -> list:
    """The markdown in this body that will arrive as literal characters."""
    return [label for label, rx in _DEAD_MARKDOWN if rx.search(body or "")]


def body_shape_complaints(body: str) -> list:
    """Everything about this body that the forum will render wrongly.

    Empty list means it is safe to publish. Each entry is a whole sentence
    meant to be shown to whoever is posting.
    """
    out = []
    wrapped = hard_wrapped_lines(body)
    if wrapped:
        shown = ", ".join(str(n) for n in wrapped[:8])
        more = "" if len(wrapped) <= 8 else " and " + str(len(wrapped) - 8) + " more"
        out.append(
            "The body is hard-wrapped: " + str(len(wrapped)) + " line(s) run on "
            "into the next one (line " + shown + more + "). The forum honours "
            "every newline, so each of those becomes a line break inside a "
            "wide column and the paragraph arrives as a stack of short lines. "
            "One paragraph per line, blank line between paragraphs.")
    dead = dead_markdown(body)
    if dead:
        out.append(
            "The body contains markdown the forum does not render: "
            + ", ".join(dead) + ". It will show the characters themselves. Use "
            "plain CAPITALS or plain words for emphasis.")
    return out


def unwrap_body(body: str) -> str:
    """Join the hard-wrapped lines, and change nothing else.

    Only the lines hard_wrapped_lines() names are joined, so blank lines,
    bullets, numbered items, quotes, image placeholders and indented blocks
    survive exactly as they were. It does not touch markdown: stripping ** out
    of someone's sentence can change what the sentence says, so that stays a
    refusal for a person to fix.
    """
    wrapped = set(hard_wrapped_lines(body))
    if not wrapped:
        return body
    lines = (body or "").split("\n")
    out = []
    for i, line in enumerate(lines):
        # hard_wrapped_lines() numbers lines from 1, and line number N runs on
        # into the line after it. The line after N is at 0-based index N. So
        # index i is a continuation exactly when i is in the set -- which looks
        # like an off-by-one and is not.
        if out and i in wrapped:
            out[-1] = out[-1].rstrip() + " " + line.lstrip()
        else:
            out.append(line)
    return "\n".join(out)


def _created_id(made: dict, kind: str) -> int:
    """The id of a thread or reply the API has just created.

    The API answers a create with its own key name -- {"thread_id": 42} for a
    thread, {"reply_id": 7} for a reply -- and never a bare "id". This read
    .get("id") or 0, so it returned 0 every single time, with exit code 0 and
    no error anywhere.

    Measured 23 Oct 2026: 32 threads posted to the skins board, 32 ids of 0,
    all 32 threads created correctly. The write worked and the receipt lied,
    which is the worse half. `fc post --flag` feeds that id straight into
    set_flag, so a flagged post PATCHes thread 0 instead of the thread it just
    made, and nothing complains.

    This raises on a missing id rather than defaulting to 0, because there is
    no honest zero: if the API created something then it has an id, and if it
    did not then the caller must not carry on as though it had.
    """
    payload = made.get(kind) or made
    for key in (kind + "_id", "id"):
        value = payload.get(key)
        if value not in (None, "", 0):
            return int(value)
    raise ForumError(
        "The forum said it created a " + kind + " but returned no id: "
        + repr(made) + ". Nothing has been retried; check the board before "
        "posting again, in case it was created after all.")


def board_id(forum, name: str) -> int:
    """The id of an EXISTING board of this name, or 0. NEVER creates one.

    The counterpart to Forum.ensure_board, and the one almost every caller
    actually wants. A publishing tool must not be able to invent a board: in
    October a default of "Tickets" met a forum that had none, ensure_board
    made it, and because it was created without a sort order it landed ABOVE
    every real board on the owner's list. He found it himself.

    That was repaired in the command-line tool and NOT in the desktop
    interface, which went on calling ensure_board afterwards. Two callers, one
    guard. So the lookup lives here, where both already import from.

    Takes any object with categories() rather than a Forum, so a test can hand
    it a stand-in. The first version of this was a method, which quietly
    demanded a real client and broke five tests that had been passing for
    weeks -- a helper that only works with the real thing cannot be proved.
    """
    wanted = (name or "").strip().lower()
    if not wanted:
        return 0
    for cat in forum.categories():
        if (cat.get("name") or "").strip().lower() == wanted:
            return int(cat.get("id") or 0)
    return 0


class Forum:
    """Thin client. Read methods work with any key. Posting, locking and
    pinning need role >= 2; creating the Tickets board needs role >= 3, which is
    what this tool must have. Sean grants both via PATCH installs/{id}/role."""

    def __init__(self, base_url: str, api_key: str, timeout: int = 20):
        self.base = (base_url or "").rstrip("/")
        self.key = api_key or ""
        self.timeout = timeout

    # ── transport ────────────────────────────────────────────────────────────
    def _call(self, method: str, path: str, payload=None):
        if not self.base:
            raise ForumError("No forum base URL configured.")
        if not self.key:
            raise ForumError(
                "No forum API key. TICKET WICKET is inert without one — that is "
                "deliberate: a stolen copy of this tool is a dead thing.")
        url = f"{self.base}/{path.lstrip('/')}"
        data = None
        if payload is not None:
            data = json.dumps(payload).encode("utf-8")
        req = urllib.request.Request(url, data=data, method=method)
        req.add_header("User-Agent", _UA)          # Cloudflare 1010s scripts
        req.add_header("Accept", "application/json")
        req.add_header("Authorization", f"Bearer {self.key}")
        if data:
            req.add_header("Content-Type", "application/json")
        try:
            with urllib.request.urlopen(req, timeout=self.timeout) as resp:
                body = resp.read().decode("utf-8", "replace")
        except urllib.error.HTTPError as exc:
            detail = exc.read().decode("utf-8", "replace")[:300]
            if exc.code in (401, 403):
                raise ForumError(
                    f"{exc.code} from the forum. If posting, this key is probably "
                    f"not a mod key. {detail}") from exc
            raise ForumError(f"{exc.code} {exc.reason}: {detail}") from exc
        except urllib.error.URLError as exc:
            raise ForumError(f"Could not reach the forum: {exc.reason}") from exc
        return json.loads(body) if body.strip() else {}

    # ── reading ──────────────────────────────────────────────────────────────
    def categories(self) -> list:
        return self._call("GET", "categories").get("categories", [])

    def thread(self, thread_id: int) -> dict:
        return self._call("GET", f"threads/{thread_id}")

    def affected_count(self, thread_id: int) -> int:
        """How many installs pressed 'affected'. The real number, for triage."""
        data = self.thread(thread_id)
        reactions = (data.get("thread") or data).get("reactions") or {}
        counts = reactions.get("counts") or {}
        return int(counts.get(AFFECTED, 0))

    @staticmethod
    def public_affected(count: int) -> str:
        """What the ticket may SAY. Below the floor it says 'a few'."""
        return str(count) if count >= DISCLOSURE_FLOOR else "a few"

    def threads(self, category_id: int = 0, page: int = 1, tag: str = "") -> dict:
        """Thread listing: pinned first, then by activity."""
        q = [f"page={int(page)}"]
        if category_id: q.append(f"cat={int(category_id)}")
        if tag: q.append("tag=" + urllib.parse.quote(tag))
        return self._call("GET", "threads?" + "&".join(q))

    def search(self, query: str, category_id: int = 0, page: int = 1) -> dict:
        q = ["q=" + urllib.parse.quote(query), f"page={int(page)}"]
        if category_id: q.append(f"cat={int(category_id)}")
        return self._call("GET", "search?" + "&".join(q))

    def notifications(self, page: int = 1) -> dict:
        return self._call("GET", f"notifications?page={int(page)}")

    def mark_notifications_read(self) -> dict:
        return self._call("POST", "notifications/read", {"all": True})

    # ── writing: needs a mod key ─────────────────────────────────────────────
    def react(self, target_id: int, emoji: str, target: str = "thread") -> dict:
        """Same emoji toggles off; a different one replaces. target: thread|reply."""
        path = "threads" if target == "thread" else "replies"
        return self._call("POST", f"{path}/{int(target_id)}/react", {"emoji": emoji})

    def unreact(self, target_id: int, target: str = "thread") -> dict:
        path = "threads" if target == "thread" else "replies"
        return self._call("DELETE", f"{path}/{int(target_id)}/react")

    def pin(self, thread_id: int, pinned: bool = True) -> dict:
        return self._call("PATCH", f"threads/{thread_id}", {"is_pinned": bool(pinned)})

    def unfollow(self, thread_id: int) -> None:
        self._call("DELETE", f"threads/{thread_id}/follow")

    def find_board(self, name: str) -> int:
        """The id of an EXISTING board of this name, or 0. Never creates one."""
        return board_id(self, name)

    def ensure_board(self, name: str, description: str = "",
                     sort_order: int = 0) -> int:
        """A board's id, creating it only if no board of that name exists.

        sort_order is where it sits in the list: GET /categories orders by it
        and the API defaults it to 0, so a board created without one lands
        ABOVE every board already there. The five seeded boards are 1..5, so a
        new board wants 6, not the default.

        The id read is the same bug _created_id was written for: this create
        answers {"category_id": N}, and reading .get("id") returned 0.
        """
        for cat in self.categories():
            if (cat.get("name") or "").strip().lower() == name.strip().lower():
                return int(cat.get("id") or 0)
        payload = {"name": name, "description": description}
        if sort_order:
            payload["sort_order"] = int(sort_order)
        made = self._call("POST", "categories", payload)
        return _created_id(made, "category")

    def update_board(self, category_id: int, **fields) -> dict:
        """Rename, reorder, or hide a board. Admin or mod key.

        Sends ONLY the fields given. PATCH /categories/{id} falls back to the
        stored value for every key that is absent, so a caller that helpfully
        sent all four would overwrite three of them with whatever it had
        guessed. The slug is immutable there and so is not accepted here: the
        URL of a board stays the URL of that board.

        is_active=False hides the board everywhere, including from
        categories() -- which lists active boards only. That is the one way
        back from a board nobody asked for, since there is no delete.
        """
        allowed = ("name", "description", "sort_order", "is_active")
        payload = {k: fields[k] for k in allowed if k in fields}
        unknown = sorted(set(fields) - set(allowed))
        if unknown:
            raise ForumError(
                "PATCH /categories reads name, description, sort_order and "
                "is_active, and nothing else: " + ", ".join(unknown)
                + " would fall through every branch. Not sent.")
        if not payload:
            raise ForumError(
                "Nothing to change. An empty PATCH would answer 'updated' "
                "having written each stored value back over itself.")
        return self._call("PATCH", f"categories/{int(category_id)}", payload)

    def post_ticket(self, category_id: int, title: str, body: str) -> int:
        made = self._call("POST", "threads",
                          {"category_id": category_id, "title": title, "body": body})
        return _created_id(made, "thread")

    def reply(self, thread_id: int, body: str) -> int:
        made = self._call("POST", f"threads/{thread_id}/replies", {"body": body})
        return _created_id(made, "reply")

    def edit_thread(self, thread_id: int, body: str) -> None:
        """Replace a thread's body. The API distinguishes the PATCH branches by
        which field is present, so this sends body and nothing else.

        There is no delete anywhere in this tool, deliberately. That makes an
        edit the only way to correct something already posted, which is why it
        is here rather than left to a human with database access."""
        self._call("PATCH", f"threads/{thread_id}", {"body": body})

    def set_flag(self, thread_id: int, flag: str) -> None:
        self._call("PATCH", f"threads/{thread_id}", {"flag": flag})

    def lock(self, thread_id: int, locked: bool = True) -> None:
        # The field is is_locked. PATCH threads/{id} reads body, flag, is_pinned
        # and is_locked, and anything else falls through every branch to a 400
        # MISSING_FIELDS — so "locked" here failed every single time.
        self._call("PATCH", f"threads/{thread_id}", {"is_locked": bool(locked)})

    def follow(self, thread_id: int) -> None:
        self._call("POST", f"threads/{thread_id}/follow", {})

    # ── who am I ─────────────────────────────────────────────────────────────
    def me(self) -> dict:
        """This key's own identity and rank. GET /me, added for exactly this.

        Before it existed the only way to ask was to try GET /installs and read
        the 403 — a probe gated at ADMIN, which told a working moderator key it
        was not one. Now the question is asked rather than inferred.
        """
        return self._call("GET", "me")

    def role(self) -> int:
        """0 member, 1 power, 2 moderator, 3 admin, 4 the God key."""
        return int(self.me().get("role") or 0)

    def can_moderate(self) -> bool:
        """Pin, lock, tag, move, and edit or delete somebody else's post."""
        return bool((self.me().get("can") or {}).get("moderate"))

    def am_i_admin(self) -> bool:
        """Create boards and set roles below your own: role >= 3.

        This is the question TICKET WICKET actually needs, because
        ensure_board() calls POST categories and the API gates that at admin.
        A role-2 key can post, lock and pin and still fail there.
        """
        try:
            return bool((self.me().get("can") or {}).get("administer"))
        except ForumError:
            return False

    # ── the roster, and granting power on it ─────────────────────────────────
    def installs(self) -> list:
        """Every blog with its role. Admin or God key.

        This is how an install id is found. set_role() takes a number and
        nobody knows a blog by its number, so without this the only way to
        promote a moderator was to read the database.
        """
        return self._call("GET", "installs").get("installs", [])

    def set_role(self, install_id: int, role: int) -> dict:
        """0 user, 1 power, 2 moderator, 3 admin. Admin or God key.

        The API caps this strictly below the caller: an admin blog may set
        roles below its own, on blogs below its own, and may not touch its
        own. Only the God key can make an admin. That cap is enforced there,
        not here -- this client does not get to decide who may be promoted.
        """
        return self._call("PATCH", f"installs/{int(install_id)}/role",
                          {"role": int(role)})

    # ── the gap that is still a gap, named rather than faked ─────────────────
    def move_thread(self, thread_id: int, category_id: int) -> dict:
        """Move a thread to another board. Mod key. The thread keeps its id, so
        everybody following it keeps following it; both boards' counters move
        with it. Was the first of the two gaps this file named; now built."""
        return self._call("PATCH", f"threads/{thread_id}", {"category_id": int(category_id)})

    def leave_breadcrumb(self, old_category: int, title: str, thread_id: int) -> None:
        raise NotImplementedError(
            "Breadcrumbs do not exist yet. When built: leave a LOCKED stub in the "
            "old board pointing at the new thread — never move silently. The "
            "forum already soft-deletes and un-hides, so a stub is the same shape "
            "as everything else there (GL-6).")

# ===== SNAPSMACK EOF =====
