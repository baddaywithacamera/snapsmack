"""TICKET WICKET — the ticket tracking forum.

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
import urllib.error
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


class Forum:
    """Thin client. Read methods work with any key; posting and moderating need
    a mod key, which Sean grants via PATCH installs/{id}/role."""

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

    # ── writing: needs a mod key ─────────────────────────────────────────────
    def ensure_board(self, name: str, description: str = "") -> int:
        for cat in self.categories():
            if (cat.get("name") or "").strip().lower() == name.strip().lower():
                return int(cat.get("id") or 0)
        made = self._call("POST", "categories",
                          {"name": name, "description": description})
        return int((made.get("category") or made).get("id") or 0)

    def post_ticket(self, category_id: int, title: str, body: str) -> int:
        made = self._call("POST", "threads",
                          {"category_id": category_id, "title": title, "body": body})
        return int((made.get("thread") or made).get("id") or 0)

    def reply(self, thread_id: int, body: str) -> int:
        made = self._call("POST", f"threads/{thread_id}/replies", {"body": body})
        return int((made.get("reply") or made).get("id") or 0)

    def set_flag(self, thread_id: int, flag: str) -> None:
        self._call("PATCH", f"threads/{thread_id}", {"flag": flag})

    def lock(self, thread_id: int, locked: bool = True) -> None:
        self._call("PATCH", f"threads/{thread_id}", {"locked": bool(locked)})

    def follow(self, thread_id: int) -> None:
        self._call("POST", f"threads/{thread_id}/follow", {})

    def am_i_mod(self) -> bool:
        """Can this key moderate? Asked by trying to list installs, which the
        API gates to admin/god. Cheap, read-only, and a clear answer."""
        try:
            self._call("GET", "installs")
            return True
        except ForumError:
            return False

    # ── the two gaps, named rather than faked ────────────────────────────────
    def move_thread(self, thread_id: int, category_id: int) -> None:
        raise NotImplementedError(
            "The forum API has no move endpoint yet. Threads already carry "
            "category_id and listing filters on it, so this is exposing a field "
            "rather than building a feature — add PATCH threads/{id} "
            "{category_id} in projects/forum-server/api/forum/index.php. "
            "Faking it by re-posting would orphan everyone following the thread.")

    def leave_breadcrumb(self, old_category: int, title: str, thread_id: int) -> None:
        raise NotImplementedError(
            "Breadcrumbs do not exist yet. When built: leave a LOCKED stub in the "
            "old board pointing at the new thread — never move silently. The "
            "forum already soft-deletes and un-hides, so a stub is the same shape "
            "as everything else there (GL-6).")

# ===== SNAPSMACK EOF =====
