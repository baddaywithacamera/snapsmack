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

# @-tokens that look like a handle but name no one. A real fediverse handle is
# "@user" or "@user@host"; these CSS at-rules and doc/decorator tags never are.
# Matched as the WHOLE token after '@' (at-rules/tags are followed by a space or
# brace, so the regex token is exactly the keyword), so "@media_person" — a real
# handle — is NOT excluded.
_AT_NONHANDLE = frozenset({
    # CSS at-rules (this is what blocked the bug sync: "@font-face" read as a handle)
    "charset", "import", "namespace", "media", "supports", "document",
    "page", "font-face", "font-feature-values", "keyframes", "viewport",
    "counter-style", "property", "layer", "container", "scope",
    "starting-style", "-webkit-keyframes", "-moz-keyframes",
    # PHPDoc / JSDoc / docblock tags
    "param", "return", "returns", "var", "type", "throws", "deprecated",
    "see", "link", "since", "author", "method", "example", "package",
    "todo", "api", "internal", "inheritdoc", "override", "template",
    "extends", "implements", "uses", "global", "const",
    # Python / JS decorators
    "staticmethod", "classmethod", "abstractmethod", "dataclass",
    "cached_property", "patch", "fixture", "mark", "pytest", "app",
})


# Hosts that belong to nobody whose privacy this guard exists to protect: the
# vendor's own public website, and the reserved names a ticket uses when it
# needs to SHOW a hostname rather than report one.
#
# Why this list exists. On 20 November 2026 the backlog went to the board and
# 24 tickets were refused for naming "someone" they did not name. Eleven said
# snapsmack.ca -- the vendor site, the one a prospective user reads, discussed
# as the subject of the ticket. One cited schema.org for JSON-LD. One used
# example.com the way every RFC does. Blocking those protects no reporter and
# costs a published ticket, and the refusal reads as though the body leaked a
# person, which sends whoever is posting looking for something that is not
# there.
#
# The list is EXACT hostnames, not suffixes, and that is the whole safety of
# it: a customer site at anything.snapsmack.ca is still caught, because a
# wildcard here would publish exactly the sites this guard is for.
_PUBLIC_HOSTS = frozenset({
    "snapsmack.ca", "www.snapsmack.ca",            # the vendor's own site
    "schema.org", "www.schema.org", "w3.org", "www.w3.org",   # specs
    "example.com", "example.org", "example.net",   # RFC 2606 documentation
})

# ── SPECIMENS, NOT PEOPLE (SS-1632) ──────────────────────────────────────────
#
# THE FAULT THIS FIXES. A ticket documenting what a Pixelfed profile link looks
# like has to show one. The example address it showed ended in a stand-in word,
# naming nobody -- and this guard read "a URL" and refused to publish the
# ticket. The advice on refusal is to rewrite the body about the problem, which
# there would have meant DELETING THE EXAMPLE THAT IS THE PROBLEM. Eight
# tickets were stuck behind this on 2026-11-27 and the only ways through were
# to delete the documentation or hold the ticket off the board for ever.
#
# THE OWNER NAMED THE HOUSE PLACEHOLDER: CORDWAINER BIRD. It is the pseudonym
# Harlan Ellison used to keep his real name off a project, so a reader who
# recognises it knows at once that nothing real is being named. That is the
# property a placeholder needs and "username" does not have.
#
# WHAT IS DELIBERATELY *NOT* DONE HERE, because it would be the easy wrong fix:
# pixelfed.social is NOT added to _PUBLIC_HOSTS. Doing that would let
# https://pixelfed.social/<a-real-person> through, which is the exact thing
# this guard exists to stop. A shared instance's hostname names nobody; the
# PATH on it names somebody. So a URL on a shared instance passes ONLY when its
# final segment is a known placeholder, and a real-looking segment is still
# refused. Both halves are proved in the regression by being fed the thing they
# must refuse.
_PLACEHOLDER_SEGMENTS = frozenset({
    # the house placeholder, in the spellings a path or handle can carry
    "cordwainerbird", "cordwainer-bird", "cordwainer.bird", "cordwainer_bird",
    # the vocabulary already in bodies written before the ruling
    "username", "example", "yourname", "youraccount", "yoursite", "sitename",
    "someone", "somebody", "placeholder",
})

# Hostnames of SHARED services, where the host names an instance and never a
# person. Being on this list is NOT enough on its own: it only means the host
# itself identifies nobody, so the decision falls to the path segment.
_SERVICE_HOSTS = frozenset({
    "pixelfed.social", "mastodon.social", "mastodon.online",
    "photoblogs.fyi",
})

# Reserved for documentation by RFC 2606 and RFC 6761. These can never belong
# to anyone, by definition, which is why they exist.
_RESERVED_EXAMPLE_SUFFIXES = (".example", ".invalid", ".test", ".localhost")


def _is_reserved_example_host(host: str) -> bool:
    """True for a hostname that is reserved for documentation and cannot be
    anybody's. example.com/org/net are in _PUBLIC_HOSTS already; this covers
    the reserved SUFFIXES, so anything.example and site.invalid are safe."""
    h = (host or "").lower().rstrip(".")
    return bool(h) and h.endswith(_RESERVED_EXAMPLE_SUFFIXES)


def _final_segment(url: str) -> str:
    """The last non-empty path segment of a URL, lowercased, with any query,
    fragment, extension and @ prefix removed. This is the part that names a
    person on a shared instance."""
    rest = re.sub(r"(?i)^https?://", "", url)
    path = re.split(r"[?#]", rest, 1)[0]
    parts = [p for p in path.split("/")[1:] if p]
    if not parts:
        return ""
    last = parts[-1].lstrip("@").lower()
    # THE WHOLE SEGMENT IS TRIED FIRST, and my own regression caught why.
    # "cordwainer.bird" is a dotted NAME, not a filename, and stripping what
    # looks like a 4-letter extension turned it into "cordwainer" and refused
    # the ticket. So only strip an extension when the segment as written is not
    # itself a known placeholder.
    if last in _PLACEHOLDER_SEGMENTS:
        return last
    if last.count(".") == 1 and len(last.rsplit(".", 1)[1]) <= 4:
        return last.rsplit(".", 1)[0]
    return last


def _url_names_a_specimen(url: str) -> bool:
    """True when a URL is plainly an EXAMPLE and names nobody.

    Either the host is reserved for documentation, or the host is a shared
    service AND the final path segment is a known placeholder. A real-looking
    segment on a shared service is NOT a specimen, and a placeholder on
    somebody's own site is NOT a specimen either -- the host still names them.
    """
    rest = re.sub(r"(?i)^https?://", "", url)
    host = re.split(r"[/?#]", rest, 1)[0].rsplit("@", 1)[-1].split(":", 1)[0].lower()
    if _is_reserved_example_host(host):
        return True
    return host in _SERVICE_HOSTS and _final_segment(url) in _PLACEHOLDER_SEGMENTS

# Reporter values that name nobody. The store records WHO reported a ticket so
# that the gate can refuse a body that still says it; a role word recorded in
# that field carries no identity, and treating it as one blocks the ordinary
# English the ticket needs. Twelve tickets had the reporter recorded as the
# literal word "owner", so every sentence containing "the owner" -- the phrase
# the product itself uses -- was read as naming the reporter.
#
# A real name stays a real name. Only these exact role words are ignored.
_GENERIC_REPORTERS = frozenset({
    "owner", "admin", "administrator", "agent", "agents", "anon", "anonymous",
    "customer", "guest", "internal", "member", "none", "reporter", "staff",
    "support", "team", "unknown", "user", "users", "visitor",
})


def _url_is_public(url: str) -> bool:
    """True if a URL points at a host on the public list.

    The host is taken as the text between the scheme and the first '/', '?' or
    '#', with any userinfo and port removed. A URL anywhere else is still
    replaced whole, because its path carries album slugs and its query carries
    search terms.
    """
    rest = re.sub(r"(?i)^https?://", "", url)
    host = re.split(r"[/?#]", rest, 1)[0]
    host = host.rsplit("@", 1)[-1].split(":", 1)[0]
    return bool(host) and host.lower() in _PUBLIC_HOSTS


def _is_identifying_domain(token: str) -> bool:
    """True if a hostname belongs to someone this guard protects.

    Matched EXACTLY against _PUBLIC_HOSTS, so a subdomain of the vendor's site
    -- which is how the fleet's own sites are addressed -- is still caught.

    SS-1632: a hostname reserved for documentation is excepted, because
    anything.example and site.invalid cannot belong to anyone by definition.
    A SHARED-SERVICE host is NOT excepted here: on its own, bare, it is still
    a real host somebody could be found on, and only a full URL carries the
    path that says whether a specimen or a person is being named.
    """
    return (token.lower() not in _PUBLIC_HOSTS
            and not _is_reserved_example_host(token))


def _identifying_terms(extra) -> list:
    """The reporter identifiers worth looking for: real ones, not role words."""
    return [t for t in (extra or [])
            if t and len(t) > 3 and t.strip().lower() not in _GENERIC_REPORTERS]


def _is_handle(token: str) -> bool:
    """True if an @token is a real fediverse handle, not a CSS at-rule or a
    docblock/decorator tag (@font-face, @media, @param, @staticmethod…)."""
    return token[1:].lower() not in _AT_NONHANDLE  # drop the leading '@'


def _is_keyish(token: str) -> bool:
    """True if a hex run is key-shaped, EXCLUDING a 40-char git SHA-1. Real
    secrets here are 64 hex (public key) or 128 hex (private key); commit SHAs
    are 40 hex and are safe to keep in a ticket body."""
    return len(token) != 40


def scrub(text: str, extra=None) -> str:
    """Strip anything that identifies the reporter, their site or their visitors.

    Order matters. URLs and paths first because they carry the domain and album
    slugs; addresses next; secrets last.
    """
    out = text or ""
    for term in sorted(set(_identifying_terms(extra)), key=len, reverse=True):
        out = re.sub(re.escape(term), "[removed]", out, flags=re.IGNORECASE)
    out = _URL.sub(lambda m: m.group(0) if _url_is_public(m.group(0)) else "[a site]", out)
    out = _WINPATH.sub("[a path]", out)
    out = _NIXPATH.sub("[a path]", out)
    out = _QUERY.sub("", out)
    out = _EMAIL.sub("[an address]", out)
    out = _IPV4.sub("[an address]", out)
    out = _IPV6.sub("[an address]", out)
    out = _DOMAIN.sub(
        lambda m: "[a site]" if _is_identifying_domain(m.group(0)) else m.group(0), out)
    out = _HANDLE.sub(lambda m: "[a handle]" if _is_handle(m.group(0)) else m.group(0), out)
    out = _KEYISH.sub(lambda m: "[a key]" if _is_keyish(m.group(0)) else m.group(0), out)
    out = _SELF.sub("someone", out)
    return out


def check(text: str, extra=None) -> list:
    """What is still identifying in a finished ticket body.

    Belt and braces: a scrubber nobody checks is a scrubber trusted for no
    reason. The CLI refuses to post when this returns anything.
    """
    problems = []
    body = text or ""
    for term in _identifying_terms(extra):
        if re.search(re.escape(term), body, re.IGNORECASE):
            problems.append(f"the reporter's own identifier: {term}")
    for label, pattern, keep in (
        ("an email address", _EMAIL, None),
        ("an IP address", _IPV4, None),
        # SS-1632: a documentation specimen is not a person. Still refused:
        # a real-looking segment on a shared instance, and any path at all on
        # somebody's own host.
        ("a URL", _URL, lambda u: not _url_is_public(u) and not _url_names_a_specimen(u)),
        ("a domain", _DOMAIN, _is_identifying_domain),
        ("a filesystem path", _WINPATH, None),
        ("a filesystem path", _NIXPATH, None),
        ("a fediverse handle", _HANDLE, _is_handle),
        ("something key-shaped", _KEYISH, _is_keyish),
    ):
        # Report the first REAL hit per pattern; a filtered-out match (a CSS
        # at-rule, a git SHA) must not be the thing that blocks a post.
        for hit in pattern.finditer(body):
            if keep is None or keep(hit.group(0)):
                problems.append(f"{label}: {hit.group(0)[:60]}")
                break
    return problems


def forum_post(ticket) -> str:
    """The public ticket, as it appears in the tracking forum.

    Written about the problem. The reporter, their channel and the original
    message are in the store and are not here.
    """
    # NO MARKDOWN. The forum renderer escapes the body and honours newlines
    # and [img:N]; it has never read markdown, so "**SS-1067**" put the
    # asterisks on the page. This template is why all 49 ticket threads on the
    # board show them, not just the two whose bodies happened to contain any.
    # The id is distinctive enough on its own; emphasis goes in CAPITALS.
    lines = [
        f"{ticket.id} · {ticket.band} · {ticket.state}",
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
        "Press AFFECTED if this is happening to you as well, and follow the "
        "thread for updates. Nothing on this ticket identifies who reported it.")
    return "\n".join(lines)

# ===== SNAPSMACK EOF =====
