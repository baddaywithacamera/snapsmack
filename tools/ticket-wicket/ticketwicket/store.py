"""TICKET WICKET — the record of what people reported and what we did about it.

SQLite, because a ticket must outlive its channel. The forum sits behind
Cloudflare and may move; the mailbox is a Migadu plan; email threads fork and
get lost. Email and forum are TRANSPORTS. This is the record.

Two rules are enforced here rather than left to whoever is driving:

  * THERE IS NO DELETE. close, withdraw, merge. The record survives all three.
    (GL-6: moderation hides, never destroys.)
  * Every change writes an audit entry naming who did it. The caller does not
    get to pass an author for someone else.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import json
import os
import sqlite3
from datetime import datetime

from ticketwicket import model

SCHEMA = """
CREATE TABLE IF NOT EXISTS tickets (
    id              TEXT PRIMARY KEY,
    title           TEXT NOT NULL DEFAULT '',
    body            TEXT NOT NULL DEFAULT '',
    state           TEXT NOT NULL DEFAULT 'new',
    band            TEXT NOT NULL DEFAULT 'WANTED',
    created         TEXT NOT NULL,
    updated         TEXT NOT NULL,
    security        INTEGER NOT NULL DEFAULT 0,
    data_at_risk    INTEGER NOT NULL DEFAULT 0,
    usability       TEXT NOT NULL DEFAULT 'cosmetic',
    affected        INTEGER NOT NULL DEFAULT 1,
    pulse           INTEGER NOT NULL DEFAULT 0,
    forum_thread    INTEGER NOT NULL DEFAULT 0,
    merged_into     TEXT NOT NULL DEFAULT '',
    reporter        TEXT NOT NULL DEFAULT '',
    reporter_channel TEXT NOT NULL DEFAULT '',
    original        TEXT NOT NULL DEFAULT '',
    links           TEXT NOT NULL DEFAULT '{}'
);
CREATE TABLE IF NOT EXISTS entries (
    rowid_          INTEGER PRIMARY KEY AUTOINCREMENT,
    ticket          TEXT NOT NULL,
    at              TEXT NOT NULL,
    author          TEXT NOT NULL,
    kind            TEXT NOT NULL,
    text            TEXT NOT NULL DEFAULT '',
    channel         TEXT NOT NULL DEFAULT ''
);
CREATE TABLE IF NOT EXISTS outbox (
    rowid_          INTEGER PRIMARY KEY AUTOINCREMENT,
    ticket          TEXT NOT NULL,
    queued          TEXT NOT NULL,
    author          TEXT NOT NULL,
    channel         TEXT NOT NULL,
    body            TEXT NOT NULL,
    state           TEXT NOT NULL DEFAULT 'queued',
    sent            TEXT NOT NULL DEFAULT '',
    note            TEXT NOT NULL DEFAULT ''
);
CREATE TABLE IF NOT EXISTS meta (k TEXT PRIMARY KEY, v TEXT NOT NULL);
CREATE INDEX IF NOT EXISTS entries_ticket ON entries(ticket);
CREATE INDEX IF NOT EXISTS tickets_state ON tickets(state);
"""


def default_path() -> str:
    root = (os.environ.get("SNAPSMACK_HOME") or "").strip() or r"C:\snapsmack"
    path = os.path.join(os.path.abspath(root), "ticket-wicket")
    os.makedirs(path, exist_ok=True)
    return os.path.join(path, "tickets.db")


class Store:
    def __init__(self, path: str = "", actor: str = model.CLAUDE):
        """actor is who is driving — the store stamps it on every change and the
        caller never gets to claim to be somebody else."""
        self.path = path or default_path()
        self.actor = actor
        self.db = sqlite3.connect(self.path)
        self.db.row_factory = sqlite3.Row
        self.db.executescript(SCHEMA)
        self.db.commit()

    # ── ids ──────────────────────────────────────────────────────────────────
    def _next_id(self) -> str:
        """Monotonic, never reused. A reused number is a lie about history."""
        cur = self.db.execute("SELECT v FROM meta WHERE k='last_id'")
        row = cur.fetchone()
        n = int(row["v"]) if row else 1000
        n += 1
        self.db.execute("INSERT INTO meta(k,v) VALUES('last_id',?) "
                        "ON CONFLICT(k) DO UPDATE SET v=excluded.v", (str(n),))
        return f"SS-{n}"

    # ── writing ──────────────────────────────────────────────────────────────
    def _audit(self, ticket_id: str, kind: str, text: str, channel: str = "",
               author: str = "") -> None:
        self.db.execute(
            "INSERT INTO entries(ticket,at,author,kind,text,channel) VALUES(?,?,?,?,?,?)",
            (ticket_id, datetime.now().isoformat(timespec="seconds"),
             author or self.actor, kind, text, channel))

    def _touch(self, ticket_id: str) -> None:
        self.db.execute("UPDATE tickets SET updated=? WHERE id=?",
                        (datetime.now().isoformat(timespec="seconds"), ticket_id))

    def create(self, title: str, body: str = "", reporter: str = "",
               reporter_channel: str = "", original: str = "", **kw) -> model.Ticket:
        now = datetime.now().isoformat(timespec="seconds")
        t = model.Ticket(
            id=self._next_id(), title=title, body=body, created=now, updated=now,
            reporter=reporter, reporter_channel=reporter_channel, original=original,
            security=bool(kw.get("security")), data_at_risk=bool(kw.get("data_at_risk")),
            usability=kw.get("usability", "cosmetic"), affected=int(kw.get("affected", 1)),
            pulse=bool(kw.get("pulse")),
        )
        t.recompute_band()
        self.db.execute(
            "INSERT INTO tickets(id,title,body,state,band,created,updated,security,"
            "data_at_risk,usability,affected,pulse,forum_thread,merged_into,reporter,"
            "reporter_channel,original,links) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
            (t.id, t.title, t.body, t.state, t.band, t.created, t.updated,
             int(t.security), int(t.data_at_risk), t.usability, t.affected,
             int(t.pulse), 0, "", t.reporter, t.reporter_channel, t.original, "{}"))
        self._audit(t.id, "audit", f"opened as {t.band}")
        self.db.commit()
        return t

    def update(self, ticket_id: str, **fields) -> model.Ticket:
        t = self.get(ticket_id)
        if t is None:
            raise KeyError(ticket_id)
        allowed = {"title", "body", "state", "security", "data_at_risk",
                   "usability", "affected", "pulse", "forum_thread", "merged_into"}
        changed = []
        for key, value in fields.items():
            if key not in allowed:
                continue
            old = getattr(t, key)
            if old == value:
                continue
            setattr(t, key, value)
            changed.append(f"{key}: {old!r} -> {value!r}")

        before = t.band
        t.recompute_band()
        if t.band != before:
            changed.append(f"band: {before} -> {t.band}")

        self.db.execute(
            "UPDATE tickets SET title=?,body=?,state=?,band=?,security=?,data_at_risk=?,"
            "usability=?,affected=?,pulse=?,forum_thread=?,merged_into=? WHERE id=?",
            (t.title, t.body, t.state, t.band, int(t.security), int(t.data_at_risk),
             t.usability, t.affected, int(t.pulse), t.forum_thread, t.merged_into,
             t.id))
        if changed:
            self._audit(t.id, "audit", "; ".join(changed))
        self._touch(t.id)
        self.db.commit()
        return self.get(ticket_id)

    def add_entry(self, ticket_id: str, kind: str, text: str, channel: str = "",
                  author: str = "") -> None:
        self._audit(ticket_id, kind, text, channel, author)
        self._touch(ticket_id)
        self.db.commit()

    def set_link(self, ticket_id: str, key: str, value: str) -> None:
        t = self.get(ticket_id)
        links = dict(t.links)
        links[key] = value
        self.db.execute("UPDATE tickets SET links=? WHERE id=?",
                        (json.dumps(links), ticket_id))
        self._audit(ticket_id, "audit", f"link {key}={value}")
        self._touch(ticket_id)
        self.db.commit()

    def merge(self, ticket_id: str, into: str) -> None:
        """Point one ticket at another. BOTH stay readable — the reporter of the
        merged one still reported something real and is not told to go away."""
        self.update(ticket_id, state=model.WITHDRAWN, merged_into=into)
        self.add_entry(ticket_id, "audit", f"merged into {into}")
        self.add_entry(into, "audit", f"{ticket_id} merged in")

    # ── reading ──────────────────────────────────────────────────────────────
    def get(self, ticket_id: str) -> model.Ticket | None:
        row = self.db.execute("SELECT * FROM tickets WHERE id=?", (ticket_id,)).fetchone()
        if row is None:
            return None
        t = model.Ticket(
            id=row["id"], title=row["title"], body=row["body"], state=row["state"],
            band=row["band"], created=row["created"], updated=row["updated"],
            security=bool(row["security"]), data_at_risk=bool(row["data_at_risk"]),
            usability=row["usability"], affected=row["affected"],
            pulse=bool(row["pulse"]), forum_thread=row["forum_thread"],
            merged_into=row["merged_into"], reporter=row["reporter"],
            reporter_channel=row["reporter_channel"], original=row["original"],
            links=json.loads(row["links"] or "{}"),
        )
        t.entries = [
            model.Entry(at=e["at"], author=e["author"], kind=e["kind"],
                        text=e["text"], channel=e["channel"])
            for e in self.db.execute(
                "SELECT * FROM entries WHERE ticket=? ORDER BY rowid_", (ticket_id,))
        ]
        return t

    def queue(self, open_only: bool = True) -> list:
        """What needs attention, worst band first, oldest first within a band."""
        sql = "SELECT id FROM tickets"
        if open_only:
            marks = ",".join("?" * len(model.OPEN_STATES))
            sql += f" WHERE state IN ({marks})"
            rows = self.db.execute(sql, tuple(model.OPEN_STATES)).fetchall()
        else:
            rows = self.db.execute(sql).fetchall()
        tickets = [self.get(r["id"]) for r in rows]
        tickets.sort(key=lambda t: (model.BANDS.index(t.band), t.created))
        return tickets

    def stale(self, now: datetime | None = None) -> list:
        return [t for t in self.queue() if t.is_stale(now)]

    def search(self, term: str) -> list:
        like = f"%{term}%"
        rows = self.db.execute(
            "SELECT DISTINCT t.id FROM tickets t LEFT JOIN entries e ON e.ticket=t.id "
            "WHERE t.title LIKE ? OR t.body LIKE ? OR e.text LIKE ?",
            (like, like, like)).fetchall()
        return [self.get(r["id"]) for r in rows]

    # ── the outbox. nothing here sends. ──────────────────────────────────────
    def enqueue(self, ticket_id: str, channel: str, body: str,
                author: str = "") -> int:
        """Queue something to go out. THE STORE CANNOT SEND. A separate armed
        sender is the only thing that holds a credential, so a compromised CLI —
        or a Claude talked into something by a malicious support message — can
        write a bad draft and cannot deliver one."""
        author = author or self.actor
        t = self.get(ticket_id)
        if t is None:
            raise KeyError(ticket_id)
        if author not in model.SIGNATURES:
            raise ValueError(f"unknown author {author!r}; everyone signs as themselves")
        if t.pulse and author != model.SEAN:
            raise PermissionError(
                f"{ticket_id} is flagged as having a pulse. GL-4: anything with a "
                "pulse reaches the human. Escalate it; do not queue a reply.")
        cur = self.db.execute(
            "INSERT INTO outbox(ticket,queued,author,channel,body) VALUES(?,?,?,?,?)",
            (ticket_id, datetime.now().isoformat(timespec="seconds"),
             author, channel, body))
        self._audit(ticket_id, "reply", f"queued for {channel}", channel, author)
        self._touch(ticket_id)
        self.db.commit()
        return cur.lastrowid

    def outbox(self, state: str = "queued") -> list:
        return [dict(r) for r in self.db.execute(
            "SELECT * FROM outbox WHERE state=? ORDER BY rowid_", (state,))]

    def mark_sent(self, rowid: int, note: str = "") -> None:
        self.db.execute(
            "UPDATE outbox SET state='sent', sent=?, note=? WHERE rowid_=?",
            (datetime.now().isoformat(timespec="seconds"), note, rowid))
        self.db.commit()

    def close(self) -> None:
        self.db.close()


# There is deliberately no delete(). See GL-6.

# ===== SNAPSMACK EOF =====
