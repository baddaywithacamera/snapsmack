"""LOG FLOGGER — step 3: analyze.

The insight this whole tool is built around, from SnapSmack's own audit record:

    the damage has almost always been a SILENCE, not an error.

  OPAUDIT 014  the fleet cron had never been installed. Nothing errored.
  replay-access-log-stats.php  page hits never recorded for a year. No error.
  PARADE's borders  dead eighteen days. Not one log line.
  Thomas the Bear  missing from 28 of 31 skins since he was written.
  OPAUDIT 017  the one that DID scream: one comment, 480,000 deliveries.
                The logs had it. Nobody was reading them.

So this module asks "what stopped happening" at least as loudly as "what went
wrong". Four of those five are invisible to a tool that only greps for ERROR.

Every finding carries a severity built the way Sean specified for tickets:
security, usability, and how many are affected — not a flat ladder.

The AI step is deliberately NOT here. This produces grounded, countable facts
first; a model then reads THOSE rather than raw logs. Findings a machine can
check do not need a model's opinion, and a model given raw logs invents things.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import re
from collections import Counter
from dataclasses import dataclass, field
from datetime import datetime, timedelta

# Bands, in Sean's words. Names are his to change.
LOSING_WORK = "LOSING WORK"
DOOR_OPEN = "DOOR OPEN"
STOPPED = "STOPPED"
WORKING_ROUND_IT = "WORKING ROUND IT"
WANTED = "WANTED"

_ORDER = [LOSING_WORK, DOOR_OPEN, STOPPED, WORKING_ROUND_IT, WANTED]

_ERROR = re.compile(
    r"\b(error|exception|traceback|fatal|failed|failure|refused|denied|timeout)\b",
    re.IGNORECASE,
)
# Things that mean someone's photographs are at risk, not just a bad request.
_DATA_AT_RISK = re.compile(
    r"\b(corrupt|truncat|checksum (mismatch|fail)|data loss|lost \d+ (file|image|photo)"
    r"|unable to write|disk full|no space left)\b",
    re.IGNORECASE,
)
_SECURITY = re.compile(
    r"\b(unauthor|forbidden|invalid (key|token|signature)|tamper|breach|intrusion"
    r"|lockout|brute[- ]?force|csrf|sql syntax)\b",
    re.IGNORECASE,
)


@dataclass
class Finding:
    band: str
    app: str
    headline: str
    detail: str = ""
    count: int = 0
    evidence: list = field(default_factory=list)   # a few real lines, never all
    kind: str = "error"                            # error | silence | spike | probe

    def as_dict(self) -> dict:
        return {
            "band": self.band,
            "app": self.app,
            "headline": self.headline,
            "detail": self.detail,
            "count": self.count,
            "kind": self.kind,
            "evidence": self.evidence[:3],
        }


def _band_for(line: str) -> str:
    if _DATA_AT_RISK.search(line):
        return LOSING_WORK
    if _SECURITY.search(line):
        return DOOR_OPEN
    return WORKING_ROUND_IT


def _normalise(line: str) -> str:
    """Collapse a line to its shape so 400 occurrences of the same fault count as
    one finding with count=400 rather than 400 findings."""
    out = re.sub(r"\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}", "<ts>", line)
    out = re.sub(r"\b[0-9a-f]{8,}\b", "<hex>", out, flags=re.IGNORECASE)
    out = re.sub(r"\b\d+\b", "<n>", out)
    out = re.sub(r"[A-Za-z]:\\\\[^\s'\"]+|/[\w./-]{8,}", "<path>", out)
    return " ".join(out.split())[:200]


def find_errors(harvest, max_bytes: int = 2_000_000) -> list:
    """What went wrong. The ordinary half, and the easy half."""
    shapes: dict = {}
    for lf in harvest.files:
        for raw in lf.read_text(max_bytes=max_bytes).splitlines():
            if not _ERROR.search(raw):
                continue
            key = (lf.app, _normalise(raw))
            slot = shapes.setdefault(
                key, {"band": _band_for(raw), "count": 0, "evidence": []}
            )
            slot["count"] += 1
            # A security or data-risk line anywhere promotes the whole shape.
            band = _band_for(raw)
            if _ORDER.index(band) < _ORDER.index(slot["band"]):
                slot["band"] = band
            if len(slot["evidence"]) < 3:
                slot["evidence"].append(raw.strip()[:300])

    out = []
    for (app, shape), slot in shapes.items():
        out.append(
            Finding(
                band=slot["band"],
                app=app,
                headline=shape,
                count=slot["count"],
                evidence=slot["evidence"],
                kind="error",
            )
        )
    return out


def find_silences(harvest, quiet_days: int = 14, now: datetime | None = None) -> list:
    """What STOPPED happening. The half that matters and that nothing else does.

    An app that used to write logs regularly and has gone quiet is the shape of
    every expensive incident in this project's history. We cannot know an app
    SHOULD have run — but we can know it used to, reliably, and then didn't.
    """
    now = now or datetime.now()
    out = []

    for app, files in harvest.apps.items():
        stamps = sorted(f.when for f in files if f.when)
        if len(stamps) < 3:
            continue  # too little history to call anything unusual

        last = stamps[-1]
        quiet_for = now - last
        if quiet_for < timedelta(days=quiet_days):
            continue

        # How often did it normally run? Median gap beats mean: one long holiday
        # should not make a daily job look monthly.
        gaps = sorted(
            (stamps[i + 1] - stamps[i]).total_seconds() for i in range(len(stamps) - 1)
        )
        median_gap = gaps[len(gaps) // 2]
        if median_gap <= 0:
            continue

        ratio = quiet_for.total_seconds() / median_gap
        if ratio < 4:
            continue  # quiet, but not out of character

        band = STOPPED if ratio >= 10 else WORKING_ROUND_IT
        out.append(
            Finding(
                band=band,
                app=app,
                headline=f"{app} has written nothing for {quiet_for.days} days",
                detail=(
                    f"It normally logs about every "
                    f"{_human_gap(median_gap)}, across {len(stamps)} runs. "
                    f"That is {ratio:.0f}x its usual gap. "
                    "Either it is not running, or it stopped being able to say so."
                ),
                count=len(stamps),
                kind="silence",
            )
        )
    return out


def find_spikes(harvest, factor: float = 10.0) -> list:
    """A log that suddenly got enormous. OPAUDIT 017's one comment became 480,000
    deliveries and took a database from 2.5 GB to 1.2 GB after cleanup. The
    explosion IS the alarm, so it must not be the thing that kills the reader."""
    out = []
    for app, files in harvest.apps.items():
        sizes = sorted(f.size for f in files)
        if len(sizes) < 4:
            continue
        median = sizes[len(sizes) // 2] or 1
        for f in files:
            if f.size > median * factor and f.size > 1_000_000:
                out.append(
                    Finding(
                        band=STOPPED,
                        app=app,
                        headline=f"{f.name} is {_human_bytes(f.size)}",
                        detail=(
                            f"{app}'s logs are usually about {_human_bytes(median)}. "
                            f"This one is {f.size / median:.0f}x larger. A log that "
                            "explodes is usually a loop, and the size is the alarm."
                        ),
                        count=f.size,
                        kind="spike",
                    )
                )
    return out


def analyse(harvest, quiet_days: int = 14, now: datetime | None = None) -> list:
    """Steps 3's countable half: every finding, worst first."""
    findings = (
        find_silences(harvest, quiet_days=quiet_days, now=now)
        + find_spikes(harvest)
        + find_errors(harvest)
    )
    findings.sort(key=lambda f: (_ORDER.index(f.band), -f.count))
    return findings


def _human_gap(seconds: float) -> str:
    if seconds < 3600:
        return f"{seconds / 60:.0f} minutes"
    if seconds < 86400:
        return f"{seconds / 3600:.0f} hours"
    return f"{seconds / 86400:.0f} days"


def _human_bytes(n: int) -> str:
    for unit in ("B", "KB", "MB", "GB"):
        if n < 1024 or unit == "GB":
            return f"{n:.0f} {unit}" if unit == "B" else f"{n:.1f} {unit}"
        n /= 1024.0
    return f"{n:.1f} GB"

# ===== SNAPSMACK EOF =====
