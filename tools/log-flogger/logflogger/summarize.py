"""LOG FLOGGER — step 4: summarize, and step 5: clean up unneeded.

These are ONE pipeline, not two features, and the order is the safety rule:

    THE SUMMARY IS WHAT REPLACES THE LOG.

A million access lines can go once what they MEANT has been extracted and kept.
Delete first and the knowledge dies with the data. So cleanup refuses to touch
anything that has not been summarised, and the summary is written and verified
before a single byte is removed.

Three more rules, from standing project law:

  * Never delete what an open investigation needs. OPAUDIT 017 was solved FROM
    the logs. A log pinned to an open ticket is not deletable, at any age.
  * Prefer the habit already here. The CMS has snap_diaglog_rotate; this keeps
    a summary rather than pretending deletion is free.
  * It never touches another owner's logs. Sean's own debug logs are
    housekeeping. An owner's are their install, and GL-5 says no — for the fleet
    this recommends, and the owner acts.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import json
import os
from dataclasses import dataclass, field
from datetime import datetime

SUMMARY_VERSION = 1


@dataclass
class Summary:
    """What survives after the logs are gone."""

    created: str = ""
    covers_from: str = ""
    covers_to: str = ""
    apps: dict = field(default_factory=dict)       # app -> {runs, bytes, kinds}
    findings: list = field(default_factory=list)   # Finding.as_dict()
    files: list = field(default_factory=list)      # names + sizes, so a gap is visible
    probe: dict = field(default_factory=dict)

    def as_dict(self) -> dict:
        return {
            "summary_version": SUMMARY_VERSION,
            "created": self.created,
            "covers_from": self.covers_from,
            "covers_to": self.covers_to,
            "apps": self.apps,
            "findings": self.findings,
            "files": self.files,
            "probe": self.probe,
        }

    @property
    def covered_names(self) -> set:
        return {f["name"] for f in self.files}


def summarise(harvest, findings, probe=None, now: datetime | None = None) -> Summary:
    """Step 4. Everything worth keeping about these logs, in a form that is
    useful after they are deleted."""
    now = now or datetime.now()
    stamps = [f.when for f in harvest.files if f.when]

    apps: dict = {}
    for app, files in harvest.apps.items():
        kinds: dict = {}
        for f in files:
            kinds[f.kind] = kinds.get(f.kind, 0) + 1
        apps[app] = {
            "runs": len(files),
            "bytes": sum(f.size for f in files),
            "kinds": kinds,
            "first": min((f.when for f in files if f.when), default=None),
            "last": max((f.when for f in files if f.when), default=None),
        }
        for key in ("first", "last"):
            if apps[app][key]:
                apps[app][key] = apps[app][key].isoformat(timespec="minutes")

    return Summary(
        created=now.isoformat(timespec="seconds"),
        covers_from=min(stamps).isoformat(timespec="minutes") if stamps else "",
        covers_to=max(stamps).isoformat(timespec="minutes") if stamps else "",
        apps=apps,
        findings=[f.as_dict() for f in findings],
        files=[
            {
                "name": f.name,
                "app": f.app,
                "kind": f.kind,
                "bytes": f.size,
                "when": f.when.isoformat(timespec="minutes") if f.when else "",
            }
            for f in harvest.files
        ],
        probe=probe or {},
    )


def summary_dir() -> str:
    root = (os.environ.get("SNAPSMACK_HOME") or "").strip() or r"C:\snapsmack"
    path = os.path.join(os.path.abspath(root), "logs", "_summaries")
    os.makedirs(path, exist_ok=True)
    return path


def write_summary(summary: Summary, path: str = "") -> str:
    """Write it where it will be found later, and return the path. This file is
    the thing that makes deletion safe, so it is written before anything is
    removed and its presence is checked afterwards."""
    if not path:
        stamp = (summary.created or datetime.now().isoformat())[:16].replace(":", "-")
        path = os.path.join(summary_dir(), f"summary_{stamp}.json")
    with open(path, "w", encoding="utf-8") as fh:
        json.dump(summary.as_dict(), fh, indent=2, default=str)
    return path


def load_summary(path: str) -> Summary:
    with open(path, "r", encoding="utf-8") as fh:
        raw = json.load(fh)
    return Summary(
        created=raw.get("created", ""),
        covers_from=raw.get("covers_from", ""),
        covers_to=raw.get("covers_to", ""),
        apps=raw.get("apps", {}),
        findings=raw.get("findings", []),
        files=raw.get("files", []),
        probe=raw.get("probe", {}),
    )


# ── Step 5: clean up, and only what is genuinely unneeded ────────────────────

@dataclass
class CleanPlan:
    """What WOULD be removed. Produced first, always; a dry run is the default."""

    remove: list = field(default_factory=list)   # LogFile
    kept: dict = field(default_factory=dict)     # path -> why it is being kept

    @property
    def bytes_freed(self) -> int:
        return sum(f.size for f in self.remove)


def plan_clean(harvest, summary: Summary, keep_days: int = 30,
               pinned: set | None = None, now: datetime | None = None) -> CleanPlan:
    """Decide what may go. Refuses anything the summary has not covered."""
    now = now or datetime.now()
    pinned = {p.lower() for p in (pinned or set())}
    covered = summary.covered_names
    plan = CleanPlan()

    for f in harvest.files:
        if f.name in pinned or f.path.lower() in pinned:
            plan.kept[f.path] = "pinned to an open ticket or audit"
            continue
        if f.name not in covered:
            plan.kept[f.path] = "not in the summary — nothing would remember it"
            continue
        if f.when and (now - f.when).days < keep_days:
            plan.kept[f.path] = f"newer than {keep_days} days"
            continue
        if not f.when:
            plan.kept[f.path] = "undateable, so it cannot be aged out safely"
            continue
        plan.remove.append(f)

    return plan


def apply_clean(plan: CleanPlan, summary_path: str) -> dict:
    """Step 5 for real. Will not run without a written summary on disk — the
    knowledge has to outlive the data or this is just deletion."""
    if not summary_path or not os.path.isfile(summary_path):
        raise RuntimeError(
            "Refusing to delete logs with no summary on disk. The summary is what "
            "replaces the log; without it this is destroying evidence."
        )

    removed, failed, freed = [], [], 0
    for f in plan.remove:
        try:
            size = f.size
            os.remove(f.path)
            removed.append(f.name)
            freed += size
        except OSError as exc:
            failed.append(f"{f.name}: {exc}")

    return {
        "removed": removed,
        "failed": failed,
        "bytes_freed": freed,
        "summary": summary_path,
    }

# ===== SNAPSMACK EOF =====
