"""
SmackPress — links.py
Internal links in a migrated post: rewrite them, or say so.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.

WHAT WAS HAPPENING. wp_source._discard_source_links kept a link's TEXT and
threw its URL away whenever the href pointed at the retired WordPress host.
That is right for a link to a page that no longer exists anywhere. It is wrong
for the common case: a link from one of the owner's posts to ANOTHER of the
owner's posts, which has also been migrated and does exist, at a new address
this tool already knows. Those links were silently deleted, and nothing was
recorded, so there was no list of what had been lost.

Relative links were worse off. `/2015/03/some-post/` has no hostname, so the
host comparison never matched it and it was left exactly as written -- a link
that now resolves against the NEW site, where that path does not exist. It
404s, silently, for a visitor.

WHAT THIS DOES. Given the local posts table's mapping it resolves each link to
its new address and rewrites the href. What it cannot resolve it reports, with
a reason, so the unmapped ones can be written to the post's notes instead of
disappearing. Nothing here deletes a link; the caller decides that, and now it
decides it with a list in hand.

THE FORMS A WORDPRESS LINK TAKES. All of these appear in a real export:

    https://old.example.com/2015/03/04/a-post/     absolute, dated permalink
    https://old.example.com/a-post/                absolute, plain permalink
    //old.example.com/2015/03/a-post/              protocol-relative
    /2015/03/04/a-post/                            root-relative
    ?p=123  /?p=123                                the WP post id
    ?page_id=45                                    the WP page id
    /2015/03/                                      an ARCHIVE, not a post
    /wp-content/uploads/2015/03/pic.jpg            media, not a post
    #section   mailto:  tel:  javascript:  data:   not site links at all

The slug is the LAST non-empty path segment. That one rule covers dated
permalinks, plain permalinks and category-prefixed permalinks without having
to guess which scheme the site used. The archive case is why it is not enough
on its own: `/2015/03/` would otherwise resolve "03" as a slug and could match
a real post called "03".
"""

from __future__ import annotations

import html
import re
import urllib.parse
from typing import Dict, List, Optional, Tuple

# Schemes that are not links into the site and must never be touched.
_OPAQUE_SCHEMES = ("mailto:", "tel:", "sms:", "javascript:", "data:", "callto:",
                   "ftp:", "file:", "webcal:")

# WordPress's own id-bearing query keys.
_ID_KEYS = ("p", "page_id", "attachment_id")

_ANCHOR = re.compile(
    r'<a\b(?P<before>[^>]*?)\bhref\s*=\s*(?P<q>["\'])(?P<href>.*?)\2'
    r'(?P<after>[^>]*)>(?P<text>.*?)</a>', re.I | re.S)

# Actions reported per link.
REWRITTEN = "rewritten"      # href replaced with the post's new address
UNMAPPED = "unmapped"        # a link into the old site we cannot place
EXTERNAL = "external"        # not this site; left alone
SKIPPED = "skipped"          # anchor-only, mailto:, and friends; left alone
MEDIA = "media"              # /wp-content/uploads/... ; not a post


class LinkReport:
    """What happened to every link in one post body."""

    def __init__(self) -> None:
        self.entries: List[dict] = []

    def add(self, href: str, action: str, target: str = "", reason: str = "",
            text: str = "") -> None:
        self.entries.append({"href": href, "action": action, "target": target,
                             "reason": reason, "text": text})

    def of(self, action: str) -> List[dict]:
        return [e for e in self.entries if e["action"] == action]

    @property
    def rewritten(self) -> List[dict]:
        return self.of(REWRITTEN)

    @property
    def unmapped(self) -> List[dict]:
        return self.of(UNMAPPED)

    @property
    def media(self) -> List[dict]:
        return self.of(MEDIA)

    def __len__(self) -> int:
        return len(self.entries)

    def note_lines(self) -> List[str]:
        """One plain line per link that needs a human. This is what goes in the
        post's notes column, because a link nobody is told about is a link
        nobody will ever fix."""
        lines = []
        for e in self.unmapped:
            label = (e["text"] or "").strip()
            label = re.sub(r"<[^>]+>", "", label)
            label = re.sub(r"\s+", " ", label)[:60]
            lines.append("unmapped link %s%s — %s"
                         % (e["href"], (' ("%s")' % label) if label else "",
                            e["reason"]))
        for e in self.media:
            lines.append("old media link %s — %s" % (e["href"], e["reason"]))
        return lines

    def summary(self) -> str:
        if not self.entries:
            return "no links"
        parts = []
        for action, word in ((REWRITTEN, "rewritten"), (UNMAPPED, "unmapped"),
                             (MEDIA, "old media"), (EXTERNAL, "external"),
                             (SKIPPED, "skipped")):
                n = len(self.of(action))
                if n:
                    parts.append("%d %s" % (n, word))
        return ", ".join(parts)


def slug_from_path(path: str) -> str:
    """The post slug in a WordPress permalink path, or '' when there is none.

    The last non-empty segment, except where every segment is numeric -- that
    is a date archive (/2015/, /2015/03/), not a post, and resolving its last
    segment as a slug is how "03" gets mistaken for a post called "03".
    """
    segments = [s for s in (path or "").split("/") if s]
    if not segments:
        return ""
    if all(re.fullmatch(r"\d{1,4}", s) for s in segments):
        return ""
    last = segments[-1]
    if re.fullmatch(r"\d{1,4}", last) and len(segments) > 1:
        # /2015/03/04/ with a trailing date part: still an archive.
        if all(re.fullmatch(r"\d{1,4}", s) for s in segments):
            return ""
    if last.lower().endswith((".jpg", ".jpeg", ".png", ".gif", ".webp", ".pdf",
                              ".mp4", ".mov", ".zip", ".avif", ".heic")):
        return ""
    return last.lower()


def slug_candidates(path: str) -> List[str]:
    """The slug as it may appear in wp_slug, both ways round.

    WordPress percent-encodes a non-ASCII slug in the permalink, and what the
    posts table holds depends on which API field it was read from: the raw
    segment (caf%c3%a9) or the decoded one (cafe/cafe with an accent). Guessing
    one breaks the other, so both are tried and the first that maps wins. A
    post called "cafe" is not matched by accident: unquote only changes the
    string when there was an escape in it.
    """
    raw = slug_from_path(path)
    if not raw:
        return []
    out = [raw]
    decoded = urllib.parse.unquote(raw).lower()
    if decoded != raw:
        out.append(decoded)
    return out


def wp_id_from_query(query: str) -> int:
    """The post id in ?p=123 / ?page_id=45, or 0."""
    try:
        params = urllib.parse.parse_qs(query or "")
    except (ValueError, AttributeError):
        return 0
    for key in _ID_KEYS:
        values = params.get(key) or []
        for value in values:
            if re.fullmatch(r"\d+", str(value).strip() or ""):
                return int(value)
    return 0


def _bare(host: str) -> str:
    """A hostname without a leading www., for comparing one site to another."""
    host = (host or "").lower()
    return host[4:] if host.startswith("www.") else host


def classify(href: str, source_host: str) -> Tuple[str, str]:
    """(kind, detail) for one href, before any mapping is attempted.

    kind is one of 'skip', 'external', 'media', 'site'. 'site' means a link
    into the migrated site, by hostname or by being relative.
    """
    raw = html.unescape((href or "").strip())
    if not raw:
        return "skip", "empty href"
    lowered = raw.lower()
    if lowered.startswith("#"):
        return "skip", "anchor within the page"
    for scheme in _OPAQUE_SCHEMES:
        if lowered.startswith(scheme):
            return "skip", scheme.rstrip(":") + " link"
    parsed = urllib.parse.urlparse(raw)
    host = (parsed.hostname or "").lower()
    if host and source_host and host != source_host:
        # www. and the bare domain are the same site. NOT lstrip("www."):
        # str.lstrip takes a SET OF CHARACTERS, so it would turn
        # "wonderful.com" into "onderful.com" and quietly call two different
        # sites the same one.
        if _bare(host) != _bare(source_host):
            return "external", "another site (%s)" % host
    if host and not source_host:
        return "external", "another site (%s)" % host
    path = parsed.path or ""
    if re.search(r"/wp-content/|/wp-includes/", path, re.I):
        return "media", "points into the old WordPress uploads"
    return "site", "link into the migrated site"


def resolve(href: str, source_host: str, slug_map: Dict[str, str],
            id_map: Dict[int, str]) -> Tuple[str, str, str]:
    """(action, new_href, reason) for one href.

    slug_map is {wp_slug: snap_url} and id_map is {wp_id: snap_url}, both for
    posts that have actually been migrated -- a row with no snap_url is not a
    destination and must not be offered as one.
    """
    kind, detail = classify(href, source_host)
    if kind != "site":
        return {"skip": SKIPPED, "external": EXTERNAL, "media": MEDIA}[kind], "", detail

    raw = html.unescape((href or "").strip())
    parsed = urllib.parse.urlparse(raw)

    wp_id = wp_id_from_query(parsed.query)
    if wp_id:
        target = id_map.get(wp_id)
        if target:
            return REWRITTEN, _with_fragment(target, parsed.fragment), \
                "WordPress id %d" % wp_id
        return UNMAPPED, "", "WordPress id %d was never migrated" % wp_id

    candidates = slug_candidates(parsed.path)
    if not candidates:
        if not (parsed.path or "").strip("/"):
            return SKIPPED, "", "the site's own front page"
        return UNMAPPED, "", "no post slug in the path (archive or index page)"

    for slug in candidates:
        target = slug_map.get(slug)
        if target:
            return REWRITTEN, _with_fragment(target, parsed.fragment), "slug %s" % slug
    return UNMAPPED, "", "no migrated post has the slug %s" % candidates[0]


def _with_fragment(url: str, fragment: str) -> str:
    if not fragment:
        return url
    return "%s#%s" % (url.rstrip("#"), fragment)


def build_maps(rows) -> Tuple[Dict[str, str], Dict[int, str]]:
    """Turn posts-table rows into the two lookups.

    Each row needs wp_slug, wp_id and snap_url; rows without a snap_url are
    skipped, because a post that has not landed yet is not somewhere to send a
    reader. Accepts sqlite3.Row, dicts, or anything subscriptable by name.
    """
    slug_map: Dict[str, str] = {}
    id_map: Dict[int, str] = {}
    for row in rows or []:
        try:
            snap_url = (row["snap_url"] or "").strip()
            slug = (row["wp_slug"] or "").strip().lower()
            wp_id = int(row["wp_id"] or 0)
        except (KeyError, IndexError, TypeError, ValueError):
            continue
        if not snap_url:
            continue
        if slug:
            slug_map.setdefault(slug, snap_url)
        if wp_id:
            id_map.setdefault(wp_id, snap_url)
    return slug_map, id_map


def rewrite_internal_links(body: str, source_url: str,
                           slug_map: Optional[Dict[str, str]] = None,
                           id_map: Optional[Dict[int, str]] = None,
                           *, discard_unmapped: bool = True
                           ) -> Tuple[str, LinkReport]:
    """Rewrite what can be rewritten; report everything.

    ``discard_unmapped`` keeps the old behaviour for links that cannot be
    placed: the text is kept and the dead href dropped. The difference from
    before is that they are now in the report either way, so the caller can
    write them to the post's notes instead of losing them.
    """
    report = LinkReport()
    source_host = (urllib.parse.urlparse(source_url or "").hostname or "").lower()
    slug_map = slug_map or {}
    id_map = id_map or {}

    def replace(match):
        href = match.group("href")
        text = match.group("text")
        action, new_href, reason = resolve(href, source_host, slug_map, id_map)
        report.add(href, action, new_href, reason, text)
        if action == REWRITTEN:
            return '<a%shref="%s"%s>%s</a>' % (
                match.group("before") or " ",
                html.escape(new_href, quote=True),
                match.group("after") or "", text)
        if action == UNMAPPED and discard_unmapped:
            return text
        if action == MEDIA and discard_unmapped:
            # A link into the retired host's uploads folder is dead the moment
            # that host goes away. Keeping the words and dropping the href is
            # the same call the old code made for posts, and it is recorded.
            return text
        return match.group(0)

    return _ANCHOR.sub(replace, body), report

# ===== SNAPSMACK EOF =====
