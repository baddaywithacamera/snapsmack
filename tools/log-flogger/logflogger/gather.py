"""LOG FLOGGER — step 1: gather, and step 2: sort by type and app.

Sean's five functions are gather, sort, analyze, summarize, clean up. The first
two are nearly free, because SnapSmack already writes every desktop tool's log
into one place with the app and the kind baked into the filename:

    C:\\snapsmack\\logs\\<tool>_<logname>_YYYY-MM-DD-HH-MM.log

(snap_home.log_path). So this module does not invent a scheme. It reads the one
that is already there, and falls back to sniffing for the handful of tools that
still write their own file somewhere else.

Three homes, and only one of them is anybody else's business:

    desktop   C:\\snapsmack\\logs plus each tool's own stray file  <- start here
    server    the CMS tables and logs/ on an install
    boxes     syslog and cron on CT102 and friends

Only the desktop half is implemented here. It is Sean's own machine, there is no
privacy question, nobody has ever read those logs, and it needs no 24/7 box.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import os
import re
from dataclasses import dataclass, field
from datetime import datetime

# The central convention: <tool>_<kind>_<YYYY-MM-DD-HH-MM>.log
_CENTRAL = re.compile(
    r"^(?P<app>[a-z0-9][a-z0-9._-]*?)_(?P<kind>[a-z0-9.-]+)_"
    r"(?P<stamp>\d{4}-\d{2}-\d{2}-\d{2}-\d{2})\.log$",
    re.IGNORECASE,
)

# Tools that predate the convention and write their own file. Sniffed by name so
# a stray log still gets an app rather than landing in "unknown".
_STRAY = {
    "unzucker.log": ("flkr-fckr", "run"),
    "export.log": ("take-your-shit-with-you", "export"),
    "sybu.log": ("sybu", "run"),
    "updater.log": ("cms", "updater"),
}

_KIND_ALIASES = {
    "debug": "debug",
    "run": "run",
    "error": "error",
    "errors": "error",
    "qt-debug": "debug",
}


@dataclass
class LogFile:
    """One log on disk, already sorted by app and kind (steps 1 and 2)."""

    path: str
    app: str
    kind: str
    when: datetime | None = None
    size: int = 0

    @property
    def name(self) -> str:
        return os.path.basename(self.path)

    def read_text(self, max_bytes: int = 0) -> str:
        """Read the log. max_bytes>0 keeps only the TAIL, because a log that has
        just exploded is exactly the one worth reading and exactly the one that
        would otherwise take the reader down with it."""
        try:
            size = os.path.getsize(self.path)
            with open(self.path, "r", encoding="utf-8", errors="replace") as fh:
                if max_bytes and size > max_bytes:
                    fh.seek(size - max_bytes)
                    fh.readline()  # drop the partial line we landed in
                return fh.read()
        except OSError:
            return ""


@dataclass
class Harvest:
    """Everything gathered, grouped. Deliberately plain so the CLI can print it
    and the Qt app can list it without either one re-deriving anything."""

    files: list = field(default_factory=list)
    roots: list = field(default_factory=list)

    @property
    def apps(self) -> dict:
        out: dict = {}
        for f in self.files:
            out.setdefault(f.app, []).append(f)
        return out

    @property
    def kinds(self) -> dict:
        out: dict = {}
        for f in self.files:
            out.setdefault(f.kind, []).append(f)
        return out

    @property
    def total_bytes(self) -> int:
        return sum(f.size for f in self.files)

    def for_app(self, app: str) -> list:
        return [f for f in self.files if f.app == app.lower()]


def _parse_stamp(raw: str):
    try:
        return datetime.strptime(raw, "%Y-%m-%d-%H-%M")
    except ValueError:
        return None


def classify(path: str) -> LogFile | None:
    """Sort one file by app and kind. Returns None for anything that is not a log."""
    name = os.path.basename(path)
    if not name.lower().endswith(".log"):
        return None
    try:
        size = os.path.getsize(path)
        mtime = datetime.fromtimestamp(os.path.getmtime(path))
    except OSError:
        return None

    m = _CENTRAL.match(name)
    if m:
        kind = _KIND_ALIASES.get(m.group("kind").lower(), m.group("kind").lower())
        return LogFile(
            path=path,
            app=m.group("app").lower(),
            kind=kind,
            when=_parse_stamp(m.group("stamp")) or mtime,
            size=size,
        )

    stray = _STRAY.get(name.lower())
    if stray:
        return LogFile(path=path, app=stray[0], kind=stray[1], when=mtime, size=size)

    # A log we do not recognise still gets gathered. Guessing the app from the
    # stem beats dropping it: an unreadable log is still evidence.
    stem = re.split(r"[_.-]", name)[0].lower() or "unknown"
    return LogFile(path=path, app=stem, kind="unknown", when=mtime, size=size)


def default_roots() -> list:
    """Where desktop logs live. SNAPSMACK_HOME is honoured so tests and non-Windows
    dev can point the whole thing at a scratch directory."""
    root = (os.environ.get("SNAPSMACK_HOME") or "").strip() or r"C:\snapsmack"
    return [os.path.join(os.path.abspath(root), "logs")]


def gather(roots=None, since: datetime | None = None, apps=None) -> Harvest:
    """Steps 1 and 2 in one pass.

    since   only logs at or after this time
    apps    only these apps (case-insensitive)
    """
    roots = [r for r in (roots or default_roots())]
    wanted = {a.lower() for a in apps} if apps else None
    found: list = []

    for root in roots:
        if not os.path.isdir(root):
            continue
        for dirpath, _dirnames, filenames in os.walk(root):
            for fn in filenames:
                entry = classify(os.path.join(dirpath, fn))
                if entry is None:
                    continue
                if since and entry.when and entry.when < since:
                    continue
                if wanted and entry.app not in wanted:
                    continue
                found.append(entry)

    found.sort(key=lambda f: (f.app, f.when or datetime.min))
    return Harvest(files=found, roots=roots)

# ===== SNAPSMACK EOF =====
