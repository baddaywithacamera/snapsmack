"""TICKET WICKET — tickets carry nobody's name.

Sean, 2026-10-17: "tickets never contain info that identifies the user who
submitted it."

This is the rule that makes public-by-default safe, and it is absolute — not a
default, not a preference. Every ticket goes in the tracking forum whatever
channel it arrived on, so getting this wrong publishes a person. It is the
highest-stakes routine action in the tool.

Two things worth being explicit about:

  * The ticket is written ABOUT THE PROBLEM, never as a reprint of what
    arrived. The reporter's words may inform it; they are not quoted.
  * The store keeps the original verbatim and private. The forum gets the
    anonymised ticket. These are two different artefacts and confusing them
    publishes somebody.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import re

_IPV4 = re.compile(r"\b\d{1,3}(?:\.\d{1,3}){3}\b")
_IPV6 = re.compile(r"\b(?:[0-9a-f]{0,4}:){2,7}[0-9a-f]{0,4}\b", re.IGNORECASE)
_EMAIL = re.compile(r"\b[\w.+-]+@[\w-]+\.[\w.-]+\b")
_URL = re.compile(r"https?://[^\s'\"<>)]+", re.IGNORECASE)
_DOMAIN = re.compile(
    r"\b(?:[a-z0-9][a-z0-9-]*\.)+(?:ca|com|net|org|fyi|day|ing|io|dev|photography)\b",
    re.IGNORECASE)
_WINPATH = re.compile(r"[A-Za-z]:\\[^\s'\"]+")
_NIXPATH = re.compile(r"(?:/home|/Users|/var/www)/[\w./-]+")
_HANDLE = re.compile(r"@[A-Za-z0-9_][A-Za-z0-9_.-]{2,}")
_KEYISH = re.compile(r"\b[0-9a-f]{24,}\b", re.IGNORECASE)
_QUERY = re.compile(r"\?[^\s'\"]+")

# Phrases that name a person even with the address stripped.
_SELF = re.compile(
    r"\b(my name is|i am|i'm|this is)\s+[A-Z][a-z]+(?:\s+[A-Z][a-z]+)?", re.IGNORECASE)


def scrub(text: str, extra=None) -> str:
    """Strip anything that identifies the reporter, their site or their visitors.

    Order matters. URLs and paths first because they carry the domain and album
    slugs; addresses next; secrets last.
    """
    out = text or ""
    for term in sorted({t for t in (extra or []) if t and len(t) > 3},
                       key=len, reverse=True):
        out = re.sub(re.escape(term), "[removed]", out, flags=re.IGNORECASE)
    out = _URL.sub("[a site]", out)
    out = _WINPATH.sub("[a path]", out)
    out = _NIXPATH.sub("[a path]", out)
    out = _QUERY.sub("", out)
    out = _EMAIL.sub("[an address]", out)
    out = _IPV4.sub("[an address]", out)
    out = _IPV6.sub("[an address]", out)
    out = _DOMAIN.sub("[a site]", out)
    out = _HANDLE.sub("[a handle]", out)
    out = _KEYISH.sub("[a key]", out)
    out = _SELF.sub("someone", out)
    return out


def check(text: str, extra=None) -> list:
    """What is still identifying in a finished ticket body.

    Belt and braces: a scrubber nobody checks is a scrubber trusted for no
    reason. The CLI refuses to post when this returns anything.
    """
    problems = []
    body = text or ""
    for term in (extra or []):
        if term and len(term) > 3 and re.search(re.escape(term), body, re.IGNORECASE):
            problems.append(f"the reporter's own identifier: {term}")
    for label, pattern in (
        ("an email address", _EMAIL),
        ("an IP address", _IPV4),
        ("a URL", _URL),
        ("a domain", _DOMAIN),
        ("a filesystem path", _WINPATH),
        ("a filesystem path", _NIXPATH),
        ("a fediverse handle", _HANDLE),
        ("something key-shaped", _KEYISH),
    ):
        hit = pattern.search(body)
        if hit:
            problems.append(f"{label}: {hit.group(0)[:60]}")
    return problems


def forum_post(ticket) -> str:
    """The public ticket, as it appears in the tracking forum.

    Written about the problem. The reporter, their channel and the original
    message are in the store and are not here.
    """
    lines = [
        f"**{ticket.id}** · {ticket.band} · {ticket.state}",
        "",
        ticket.body or ticket.title,
        "",
    ]
    if ticket.affected > 1:
        lines.append(f"Affected installs: {ticket.affected}")
    if ticket.links:
        for k, v in sorted(ticket.links.items()):
            lines.append(f"{k}: {v}")
        lines.append("")
    lines.append(
        "Press **affected** if this is happening to you as well, and follow the "
        "thread for updates. Nothing on this ticket identifies who reported it.")
    return "\n".join(lines)

# ===== SNAPSMACK EOF =====
