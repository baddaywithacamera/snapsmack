"""LOG FLOGGER — the owner's report to Sean, anonymous by default.

Sean, 2026-10-17: "also needs a submit report for them to send to me with an
anonymize option." Then, unprompted: "anonymous is always the default".

So it is not an option you reach for. It is the shape of the thing, and
identifying yourself is the deliberate extra step.

WHY ANONYMOUS IS NOT A CONCESSION HERE. The sharpening question — has anyone
started probing SnapSmack itself — can only be answered across many installs.
One site's logs cannot tell you whether a scanner kit has learned this software.
But reading owners' server logs is the sharpest privacy edge in the whole suite:
their visitors' addresses, their traffic, their content paths.

Anonymised submission squares it. Sean learns "three installs saw probes for
smack-api-keys.php this week" without ever learning whose, or seeing one
visitor's address. Threat intelligence without surveillance.

A report Sean cannot reply to is still worth having. Fully anonymous means no
follow-up question — that is accepted. The aggregate is the point.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import hashlib
import json
import re
from dataclasses import dataclass, field
from datetime import datetime

# What has to be scrubbed. More than it sounds: a log identifies the SITE as
# much as the person.
_IPV4 = re.compile(r"\b\d{1,3}(?:\.\d{1,3}){3}\b")
_IPV6 = re.compile(r"\b(?:[0-9a-f]{0,4}:){2,7}[0-9a-f]{0,4}\b", re.IGNORECASE)
_EMAIL = re.compile(r"\b[\w.+-]+@[\w-]+\.[\w.-]+\b")
_URL = re.compile(r"https?://[^\s'\"<>]+", re.IGNORECASE)
_WINPATH = re.compile(r"[A-Za-z]:\\\\[^\s'\"]+")
_NIXPATH = re.compile(r"(?:/home|/Users|/var/www)/[\w./-]+")
# query strings are where tokens leak
_QUERY = re.compile(r"\?[^\s'\"]+")
_HEXKEY = re.compile(r"\b[0-9a-f]{24,}\b", re.IGNORECASE)


def scrub(text: str, extra_terms=None) -> str:
    """Strip everything that could identify the site, the owner or a visitor.

    Order matters: URLs and paths first (they contain the domain and the album
    slugs), then addresses, then anything that looks like a secret.
    """
    out = text
    for term in sorted({t for t in (extra_terms or []) if t and len(t) > 3},
                       key=len, reverse=True):
        out = re.sub(re.escape(term), "<redacted>", out, flags=re.IGNORECASE)
    out = _URL.sub("<url>", out)
    out = _WINPATH.sub("<path>", out)
    out = _NIXPATH.sub("<path>", out)
    out = _QUERY.sub("?<query>", out)
    out = _EMAIL.sub("<email>", out)
    out = _IPV4.sub("<ip>", out)
    out = _IPV6.sub("<ip>", out)
    out = _HEXKEY.sub("<key>", out)
    return out


@dataclass
class Report:
    """A report bound for Sean. Anonymous unless the owner chose otherwise."""

    anonymous: bool = True
    created: str = ""
    note: str = ""                       # what the owner wants to say
    cms_version: str = ""
    skin: str = ""
    php: str = ""
    findings: list = field(default_factory=list)
    probe: dict = field(default_factory=dict)
    # Only present when the owner deliberately identified themselves.
    site: str = ""
    contact: str = ""
    # Never serialised, never sent. Set from the site even on an anonymous
    # report so Sean can tell "three installs" from "one install three times" --
    # which is the whole difference between a person looking and a scanner kit.
    _install_seed: str = ""

    def as_dict(self) -> dict:
        body = {
            "report_version": 1,
            "anonymous": self.anonymous,
            "created": self.created,
            "note": self.note,
            "install": {
                "cms_version": self.cms_version,
                "skin": self.skin,
                "php": self.php,
            },
            "findings": self.findings,
            "probe": self.probe,
        }
        if not self.anonymous:
            body["identified"] = {"site": self.site, "contact": self.contact}
        return body

    def fingerprint(self) -> str:
        """A stable id for THIS install without naming it. One-way: the seed is
        never serialised, so the report carries a number of installs and never a
        way back to one."""
        seed = (self._install_seed or self.site or "anonymous") + "|" + (self.cms_version or "")
        return hashlib.sha256(("snapsmack-report:" + seed).encode()).hexdigest()[:16]


def build(summary, note: str = "", anonymous: bool = True, site: str = "",
          contact: str = "", cms_version: str = "", skin: str = "", php: str = "",
          now: datetime | None = None) -> Report:
    """Make a report from a summary. Anonymous unless explicitly told otherwise."""
    secrets = [] if anonymous else []
    if anonymous:
        # Scrub using the owner's own identifiers as extra terms, so their domain
        # and username go even where the generic patterns would miss them.
        secrets = [s for s in (site, contact) if s]

    findings = []
    for f in summary.findings:
        item = dict(f)
        if anonymous:
            item["headline"] = scrub(item.get("headline", ""), secrets)
            item["detail"] = scrub(item.get("detail", ""), secrets)
            # Evidence is raw log lines. It is the most useful and most dangerous
            # field, so an anonymous report carries the SHAPE and not the lines.
            item["evidence"] = []
            item["evidence_withheld"] = len(f.get("evidence", []))
        findings.append(item)

    probe = dict(summary.probe or {})
    probe.pop("sources", None)   # hashed already, but it never needs to travel

    return Report(
        anonymous=anonymous,
        created=(now or datetime.now()).isoformat(timespec="seconds"),
        note=scrub(note, secrets) if anonymous else note,
        cms_version=cms_version,
        skin=skin,
        php=php,
        findings=findings,
        probe=probe,
        site="" if anonymous else site,
        contact="" if anonymous else contact,
        _install_seed=site,
    )


def preview(report: Report) -> str:
    """EXACTLY what will travel, shown to the owner before it goes.

    Same rule as the diagnostic payload: never a silent collection. The owner
    sees the whole thing and presses send, or does not.
    """
    body = json.dumps(report.as_dict(), indent=2, default=str)
    head = (
        "ANONYMOUS REPORT — nothing below identifies you, your site or your visitors.\n"
        if report.anonymous
        else "IDENTIFIED REPORT — this names your site and your contact details.\n"
    )
    return head + "\n" + body


def leaks(report: Report) -> list:
    """Belt and braces: scan the finished anonymous report for anything that
    looks like it slipped through. A scrubber that is never checked is a
    scrubber that is trusted for no reason."""
    if not report.anonymous:
        return []
    body = json.dumps(report.as_dict(), default=str)
    out = []
    for label, pattern in (
        ("an IP address", _IPV4),
        ("an email address", _EMAIL),
        ("a URL", _URL),
        ("a filesystem path", _WINPATH),
        ("a filesystem path", _NIXPATH),
        ("something key-shaped", _HEXKEY),
    ):
        hit = pattern.search(body)
        if hit and hit.group(0) not in ("<ip>", "<email>", "<url>", "<path>", "<key>"):
            out.append(f"{label}: {hit.group(0)[:60]}")
    return out

# ===== SNAPSMACK EOF =====
