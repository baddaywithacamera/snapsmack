"""TICKET WICKET — the ticket, its states, and how urgent it is.

Sean, 2026-10-17:

    "when someone brings up a real issue, we can issue a ticket and track it...
     we'll drop it in a ticket tracking forum and close them as we solve the
     problems. If someone brings up something we're working on we can direct
     them to the open ticket and say we're aware, please follow this for
     updates. if someone emails something that becomes a ticket, we still post
     the ticket in that forum.
     TICKETS NEVER CONTAIN INFO THAT IDENTIFIES THE USER WHO SUBMITTED IT."

and on urgency:

    "combination of security, usability, and number of users impacted."

That last line is why urgency is COMPUTED from three inputs rather than picked
off a flat list. A ladder cannot tell one broken site from the whole fleet
broken, and that difference is most of the judgement.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

from dataclasses import dataclass, field
from datetime import datetime, timedelta

# ── States. Deliberately few: a queue with eleven states is one nobody keeps
#    honest. None of them deletes anything (GL-6).
NEW = "new"
TRIAGED = "triaged"
ANSWERED = "answered"
WAITING = "waiting"      # on the reporter
BLOCKED = "blocked"      # on us: a real bug, a build, a decision
RESOLVED = "resolved"
CLOSED = "closed"
WITHDRAWN = "withdrawn"  # duplicate or mistake. still fully readable.

STATES = [NEW, TRIAGED, ANSWERED, WAITING, BLOCKED, RESOLVED, CLOSED, WITHDRAWN]
OPEN_STATES = {NEW, TRIAGED, ANSWERED, WAITING, BLOCKED}

# ── Urgency bands. Names are Sean's to change; the meanings are the argued part.
LOSING_WORK = "LOSING WORK"
DOOR_OPEN = "DOOR OPEN"
STOPPED = "STOPPED"
WORKING_ROUND_IT = "WORKING ROUND IT"
WANTED = "WANTED"

BANDS = [LOSING_WORK, DOOR_OPEN, STOPPED, WORKING_ROUND_IT, WANTED]

# How long a band may sit untouched before `tw stale` says so. This is the only
# reason urgency exists: without it every old ticket looks equally like neglect,
# the warnings become noise, and Sean learns to ignore them.
STALE_AFTER = {
    LOSING_WORK: timedelta(days=1),
    DOOR_OPEN: timedelta(days=2),
    STOPPED: timedelta(days=7),
    WORKING_ROUND_IT: timedelta(days=30),
    WANTED: None,            # may sit for ever without being neglect
}

# ── Authors. Everyone signs as themselves; nobody ever signs as another.
#    Sean, 2026-10-17: "I would expect you and ChatGPT to sign as yourselves."
SEAN = "sean"
CLAUDE = "claude"
CHATGPT = "chatgpt"
ASSISTANT = "assistant"     # the local model. NOT Claude, NOT ChatGPT.

SIGNATURES = {
    SEAN: "— Sean",
    CLAUDE: "— Claude",
    CHATGPT: "— ChatGPT",
    ASSISTANT: "— SnapSmack AI assistant",
}

# The one fixed text the always-awake tier may send unarmed. Not composed, not
# parameterised, not influenced by the message it answers — a model that
# generates an acknowledgement can be steered into generating something else.
ACKNOWLEDGEMENT = (
    "Thank you — this is in the ticketing system and a response is coming.\n"
    "— SnapSmack AI assistant (automated; a human reads every ticket)"
)


def usability_rank(value: str) -> int:
    return {"stopped": 3, "workaround": 2, "cosmetic": 1}.get((value or "").lower(), 1)


def urgency(security: bool = False, data_at_risk: bool = False,
            usability: str = "cosmetic", affected: int = 1) -> str:
    """Sean's three inputs, combined.

    Security is not a slider among three — ETHICS.md puts it above function, so
    a hole outranks everything below it. The other two multiply: cosmetic across
    the whole fleet and stopped-dead on one site can land in the same band, and
    that is correct.
    """
    if data_at_risk:
        return LOSING_WORK
    if security:
        return DOOR_OPEN

    rank = usability_rank(usability)
    reach = 3 if affected >= 5 else (2 if affected >= 2 else 1)
    score = rank * reach

    if score >= 6:
        return STOPPED
    if score >= 2:
        return WORKING_ROUND_IT
    return WANTED


@dataclass
class Entry:
    """One thing that happened to a ticket. Append-only; nothing is ever edited
    away, because the record is the point."""

    at: str
    author: str          # sean | claude | chatgpt | assistant | reporter
    kind: str            # note | reply | state | triage | post | escalate | audit
    text: str = ""
    channel: str = ""    # email | forum | internal

    def as_dict(self) -> dict:
        return {"at": self.at, "author": self.author, "kind": self.kind,
                "text": self.text, "channel": self.channel}


@dataclass
class Ticket:
    """The record. Note what is PRIVATE: the original message and the reporter
    live here and never reach the forum post. Publishing the store's copy
    publishes a person."""

    id: str = ""                     # SS-1042
    title: str = ""
    body: str = ""                   # the anonymised, public description
    state: str = NEW
    band: str = WANTED
    created: str = ""
    updated: str = ""

    # inputs to urgency
    security: bool = False
    data_at_risk: bool = False
    usability: str = "cosmetic"
    affected: int = 1

    pulse: bool = False              # GL-4: once set, nothing auto-sends. ever.
    forum_thread: int = 0            # where the public ticket lives
    merged_into: str = ""

    # PRIVATE — never published, never in a forum post
    reporter: str = ""
    reporter_channel: str = ""
    original: str = ""

    links: dict = field(default_factory=dict)      # build / opaudit / commit
    entries: list = field(default_factory=list)

    @property
    def is_open(self) -> bool:
        return self.state in OPEN_STATES

    def stale_after(self):
        return STALE_AFTER.get(self.band)

    def is_stale(self, now: datetime | None = None) -> bool:
        """Past the clock for its band. The mechanism behind 'keep me honest' —
        and it reports a fact, it does not nag."""
        window = self.stale_after()
        if window is None or not self.is_open:
            return False
        try:
            last = datetime.fromisoformat(self.updated or self.created)
        except (TypeError, ValueError):
            return False
        return (now or datetime.now()) - last > window

    def recompute_band(self) -> str:
        self.band = urgency(self.security, self.data_at_risk,
                            self.usability, self.affected)
        return self.band

    def public_dict(self) -> dict:
        """Exactly what may be posted. If a field is not here it does not travel —
        and the three private fields are not here."""
        return {
            "id": self.id,
            "title": self.title,
            "body": self.body,
            "state": self.state,
            "band": self.band,
            "affected": self.affected,
            "links": self.links,
        }

# ===== SNAPSMACK EOF =====
