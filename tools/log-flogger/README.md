# LOG FLOGGER

Reads the logs nobody reads.

Sean's five functions: **gather · sort by type and app · analyze · summarize ·
clean up unneeded** — plus the two that came out of the conversation: *is anyone
sharpening for SnapSmack*, and *an anonymous report to Sean*.

## Why it is built around silences

From SnapSmack's own audit record, the damage has almost always been something
that **stopped happening**, not something that errored:

- **OPAUDIT 014** — the fleet cron had never been installed. Nothing errored.
- **replay-access-log-stats.php** — page hits never recorded for a year. No error.
- **PARADE's borders** — dead eighteen days. Not one log line.
- **Thomas the Bear** — missing from 28 of 31 skins since he was written.
- **OPAUDIT 017** — the one that *did* scream: one comment, 480,000 deliveries.
  The logs had it. Nobody was reading them.

Four of those five are invisible to a tool that only greps for ERROR. So this
one asks *what stopped happening* first.

## Command line

Built so Claude and Codex can work the logs without Sean present.

    python lf.py gather
    python lf.py analyze --quiet-days 14
    python lf.py probes /var/log/nginx/access.log
    python lf.py summarize --out summary.json
    python lf.py clean --summary summary.json            # dry run
    python lf.py clean --summary summary.json --apply    # for real
    python lf.py report --summary summary.json           # anonymous
    python lf.py report --summary summary.json --identify

`--json` on any command for machine-readable output.

## The two safety rules

**Summarize before you delete.** The summary is what replaces the log. Cleanup
refuses to run on anything the summary has not covered, and refuses entirely
without a summary on disk.

**Anonymous is the default**, not an option you reach for. The report strips the
domain, every visitor IP, paths, query strings and anything key-shaped — then
checks the finished report for leaks before it will send.

## Tests

    .python-build/python.exe -m pytest tools/log-flogger/tests -q
