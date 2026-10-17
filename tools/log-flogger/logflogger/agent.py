"""LOG FLOGGER — the capability sheet for Claude and Codex.

Sean, 2026-10-17: "It needs to have a help system specific to you and Codex
where you can check what functions are available to you. you both need to be
able to use it directly when troubleshooting."

An agent arriving cold needs to know the surface without reading the source,
and needs to know the REFUSALS more than the commands — what this tool will not
let it do is the part that stops it wasting a turn or doing harm.

    python lf.py agent              the sheet, for a human or an agent to read
    python lf.py agent --json       the same thing, parseable

Deliberately duplicated rather than shared with TICKET WICKET. A shared module
would have to live in tools/_shared, and the runtime path to _shared is the
exact thing OPAUDIT 024 is about. A few lines twice is cheaper than a shared
rule that breaks both tools.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

TOOL = "LOG FLOGGER"
ENTRY = "python lf.py"
PURPOSE = (
    "Reads the logs nobody reads. Built around SILENCES rather than errors: "
    "four of the five expensive incidents in this project never errored."
)

# Every command, what it is for, and what comes back. Keep in step with
# build_parser() in lf.py — tests/test_agent.py fails if they drift.
COMMANDS = [
    {
        "name": "gather",
        "does": "Find every log and sort it by app and kind.",
        "returns": "apps -> count, total_bytes, roots searched",
        "args": ["--root DIR", "--app a,b", "--days N"],
        "example": f"{ENTRY} gather --json",
        "notes": "Reads C:/snapsmack/logs, where every desktop tool already "
                 "writes as <tool>_<kind>_<stamp>.log. SNAPSMACK_HOME overrides.",
    },
    {
        "name": "analyze",
        "does": "Findings: what went wrong AND what stopped happening.",
        "returns": "list of {band, app, headline, detail, count, kind, evidence}",
        "args": ["--quiet-days N", "--band 'DOOR OPEN'", "--limit N", "--app a,b"],
        "example": f"{ENTRY} analyze --quiet-days 14 --json",
        "notes": "kind='silence' is the valuable half and is listed first. "
                 "Identical faults collapse to one finding with a count.",
    },
    {
        "name": "probes",
        "does": "Is anyone probing SnapSmack specifically, rather than spraying?",
        "returns": "sharpening (bool), counts, paths, distinct_sources, verdict",
        "args": ["PATH [PATH ...]", "--limit N"],
        "example": f"{ENTRY} probes /var/log/nginx/access.log --json",
        "notes": "wp-login.php means nothing; smack-api-keys.php means somebody "
                 "knows what this software is. Sources are hashed, never recorded.",
    },
    {
        "name": "summarize",
        "does": "Write the summary that REPLACES the logs.",
        "returns": "summary path, files_covered, findings",
        "args": ["--out FILE", "--access-log F...", "--quiet-days N"],
        "example": f"{ENTRY} summarize --out summary.json",
        "notes": "Must run before clean. The summary is the knowledge that "
                 "outlives the data.",
    },
    {
        "name": "clean",
        "does": "Remove logs the summary already covers. DRY RUN by default.",
        "returns": "would_remove / removed, bytes, applied (bool)",
        "args": ["--summary FILE (required)", "--keep-days N", "--pin NAME...",
                 "--apply"],
        "example": f"{ENTRY} clean --summary summary.json",
        "notes": "Refuses without a summary. Refuses anything the summary does "
                 "not cover. Pinned and recent logs always survive.",
    },
    {
        "name": "report",
        "does": "Build a report for Sean. ANONYMOUS unless told otherwise.",
        "returns": "report body, anonymous (bool), leaks (list)",
        "args": ["--summary FILE", "--note TEXT", "--identify", "--out FILE"],
        "example": f"{ENTRY} report --summary summary.json",
        "notes": "Exits non-zero if the finished report still contains anything "
                 "identifying. Raw log lines never travel anonymously.",
    },
]

# The part an agent most needs. A refusal understood up front is a turn saved
# and, in two of these cases, harm avoided.
REFUSALS = [
    "clean will NOT delete without a summary on disk. The summary replaces the "
    "log; without it this is destroying evidence.",
    "clean will NOT delete a log the summary does not cover, is newer than "
    "--keep-days, is pinned, or has no usable date.",
    "report is ANONYMOUS by default and checks itself for leaks. If leaks are "
    "found it exits 1 and tells you what slipped through. Do not work around "
    "this — fix the scrubber.",
    "Nothing here sends anything anywhere. There is no network call except "
    "reading files you name.",
    "It reads DESKTOP logs only. Servers, boxes and web access logs are not "
    "gathered — pass an access log to `probes` explicitly.",
]

BANDS = [
    "LOSING WORK       photographs or data at risk. everything stops.",
    "DOOR OPEN         a security hole. secure ASAP.",
    "STOPPED           someone cannot do the thing, no way round it.",
    "WORKING ROUND IT  broken, but there is a path through.",
    "WANTED            an idea. no clock.",
]

RECIPES = [
    ("What is wrong right now?",
     f"{ENTRY} analyze --json"),
    ("Has anything quietly stopped running?",
     f"{ENTRY} analyze --json   # then filter kind == 'silence'"),
    ("Only the security-shaped findings",
     f"{ENTRY} analyze --band 'DOOR OPEN' --json"),
    ("One app you are troubleshooting",
     f"{ENTRY} analyze --app snap_slapper --days 30 --json"),
    ("Is anyone sharpening for SnapSmack?",
     f"{ENTRY} probes ACCESS.log --json   # read .sharpening and .verdict"),
    ("Free disk safely",
     f"{ENTRY} summarize --out s.json && {ENTRY} clean --summary s.json"),
]


def sheet() -> dict:
    return {
        "tool": TOOL,
        "entry": ENTRY,
        "purpose": PURPOSE,
        "audience": "Claude and Codex, working without Sean present",
        "commands": COMMANDS,
        "refusals": REFUSALS,
        "bands": BANDS,
        "recipes": [{"goal": g, "run": r} for g, r in RECIPES],
        "json_flag": "--json works on every command and is the parseable form",
        "exit_codes": {"0": "ok", "1": "refused or leaked", "2": "bad usage"},
    }


def text() -> str:
    out = [
        f"{TOOL} — capability sheet for Claude and Codex",
        "=" * 64,
        PURPOSE,
        "",
        "COMMANDS",
    ]
    for c in COMMANDS:
        out.append(f"  {c['name']:<11} {c['does']}")
        out.append(f"              args:    {' '.join(c['args'])}")
        out.append(f"              returns: {c['returns']}")
        out.append(f"              {c['notes']}")
        out.append(f"              e.g. {c['example']}")
        out.append("")
    out.append("WHAT IT WILL REFUSE TO DO  (read this before the commands)")
    for r in REFUSALS:
        out.append(f"  - {r}")
    out.append("")
    out.append("URGENCY BANDS")
    for b in BANDS:
        out.append(f"  {b}")
    out.append("")
    out.append("TROUBLESHOOTING RECIPES")
    for goal, run in RECIPES:
        out.append(f"  {goal}")
        out.append(f"      {run}")
    out.append("")
    out.append("--json on any command for the parseable form. "
               "Exit 0 ok, 1 refused/leaked, 2 bad usage.")
    return "\n".join(out)

# ===== SNAPSMACK EOF =====
