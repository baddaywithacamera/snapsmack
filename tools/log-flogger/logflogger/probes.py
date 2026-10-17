"""LOG FLOGGER — is SnapSmack being *sharpened for*?

Sean, 2026-10-17: "are bot hits sharpening for snapsmack once released. what is
being probed?"

Every web server is scanned constantly for GENERIC targets — wp-login.php,
xmlrpc.php, .env, shell uploads. SnapSmack already handles those: .htaccess
routes them to probe-ban.php, which bans the source for thirty days and returns
a bodyless 403. That is noise and it is dealt with.

The signal is the moment the noise CHANGES SHAPE. SnapSmack has an unmistakable
fingerprint: ninety smack-*.php files at the web root, plus snap-in.php,
pixelfed-api.php, fedup.php, break-glass.php. Nothing else on the internet looks
like that.

    a bot asking for wp-login.php          knows nothing. it is spraying.
    a bot asking for smack-api-keys.php    knows what it is looking at.

The second one is the day SnapSmack became worth attacking, and it is detectable
the first time it happens.

This needs a baseline. You need the quiet period on record to recognise the loud
one — which is an argument for running this from day one of release, not after
something happens.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import re
from collections import Counter
from dataclasses import dataclass, field

# Generic scanner traffic. Present on every server on the internet, means nothing.
GENERIC = re.compile(
    r"/(wp-(login|admin|content|includes)|xmlrpc\.php|\.env|\.git/|phpmyadmin"
    r"|administrator/|vendor/phpunit|shell\.php|cgi-bin/|autodiscover|owa/|\.aws/)",
    re.IGNORECASE,
)

# SnapSmack's own fingerprint. A request for one of these from something that is
# not a signed-in admin is somebody who knows what this software is.
SNAPSMACK = re.compile(
    r"/(smack-[a-z0-9-]+\.php|snap-in\.php|fedup\.php|break-glass\.php"
    r"|pixelfed-api\.php|smackback\.php|sybu-data\.php|suyb-data\.php"
    r"|directory-api\.php|multisite-api\.php)",
    re.IGNORECASE,
)

# The ones that would actually matter if someone got in. Probing these is not
# curiosity.
SENSITIVE = re.compile(
    r"/(smack-api-keys|smack-break-glass|break-glass|smack-2fa|smack-users"
    r"|smack-settings|smack-disaster|smack-backup|smack-multisite-sso|snap-in)\.php",
    re.IGNORECASE,
)

# Common access-log shapes: combined/common, and a bare "IP ... "GET /path"" form.
_LINE = re.compile(
    r'^(?P<ip>\S+).*?"(?P<method>[A-Z]+)\s+(?P<path>\S+)[^"]*"\s+(?P<status>\d{3})'
)


@dataclass
class ProbeReport:
    """What the access logs say about whether anyone has noticed SnapSmack."""

    lines_read: int = 0
    generic_hits: int = 0
    snapsmack_hits: int = 0
    sensitive_hits: int = 0
    paths: Counter = field(default_factory=Counter)
    sources: Counter = field(default_factory=Counter)   # hashed, never raw IPs
    first_seen: str = ""
    last_seen: str = ""

    @property
    def sharpening(self) -> bool:
        """The binary question: has anyone started probing SnapSmack ITSELF?"""
        return self.snapsmack_hits > 0

    @property
    def distinct_sources(self) -> int:
        return len(self.sources)

    @property
    def verdict(self) -> str:
        if not self.lines_read:
            return "No access log read. Nothing can be said."
        if not self.sharpening:
            return (
                f"No SnapSmack-specific probing seen in {self.lines_read:,} requests. "
                f"{self.generic_hits:,} generic scanner hits, which is the internet's "
                "background noise and is already handled by probe-ban.php. "
                "This is the quiet baseline — keep it, it is what makes a later "
                "change legible."
            )
        who = (
            "one source" if self.distinct_sources == 1
            else f"{self.distinct_sources} distinct sources"
        )
        edge = (
            f" {self.sensitive_hits:,} of them went at admin, key or break-glass "
            "endpoints, which is not curiosity."
            if self.sensitive_hits else ""
        )
        return (
            f"SHARPENING. {self.snapsmack_hits:,} requests for SnapSmack-specific "
            f"paths, from {who}.{edge} Somebody knows what this software is. "
            "One source is a person looking; many is a scanner kit, which means "
            "every install gets this from now on."
        )


def _hash_source(ip: str) -> str:
    """Sources are counted, never recorded. We need 'how many', never 'who' — and
    an IP in a report is a visitor's identity, not the attacker's alone."""
    import hashlib

    return hashlib.sha256(("snapsmack-probe:" + ip).encode("utf-8")).hexdigest()[:12]


def scan_access_log(text: str, report: ProbeReport | None = None) -> ProbeReport:
    """Read an access log and answer the sharpening question."""
    rep = report or ProbeReport()
    for raw in text.splitlines():
        m = _LINE.match(raw)
        if not m:
            continue
        rep.lines_read += 1
        path = m.group("path")

        if GENERIC.search(path):
            rep.generic_hits += 1
            continue

        if SNAPSMACK.search(path):
            rep.snapsmack_hits += 1
            rep.paths[path.split("?")[0]] += 1
            rep.sources[_hash_source(m.group("ip"))] += 1
            if SENSITIVE.search(path):
                rep.sensitive_hits += 1
            stamp = _stamp(raw)
            if stamp:
                rep.first_seen = min(rep.first_seen or stamp, stamp)
                rep.last_seen = max(rep.last_seen, stamp)
    return rep


def _stamp(line: str) -> str:
    m = re.search(r"\[([^\]]+)\]", line)
    return m.group(1) if m else ""


def scan_paths(paths) -> ProbeReport:
    """Scan one or more access-log files on disk."""
    rep = ProbeReport()
    for p in paths:
        try:
            with open(p, "r", encoding="utf-8", errors="replace") as fh:
                scan_access_log(fh.read(), rep)
        except OSError:
            continue
    return rep

# ===== SNAPSMACK EOF =====
