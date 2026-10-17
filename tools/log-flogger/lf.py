"""LOG FLOGGER — command line.

Built so Claude and Codex can work the logs without Sean present, and so Sean
can read the same output without learning a tool. Every command prints plain
text by default and JSON with --json.

    lf gather                     what logs exist, by app and kind
    lf analyze                    what went wrong AND what stopped happening
    lf probes <access.log ...>    is anyone sharpening for SnapSmack?
    lf summarize                  write the summary that replaces the logs
    lf clean                      dry run by default; --apply to really delete
    lf report                     an anonymous report for Sean; --identify to sign it

Nothing here deletes without --apply, and nothing deletes at all without a
summary already written. See logflogger/summarize.py for why.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import argparse
import json
import os
import sys
from datetime import datetime, timedelta

BASE = os.path.dirname(os.path.abspath(__file__))
if BASE not in sys.path:
    sys.path.insert(0, BASE)

from logflogger import analyze, gather, probes, report as reportmod, summarize


def _out(args, payload, text):
    if getattr(args, "json", False):
        print(json.dumps(payload, indent=2, default=str))
    else:
        print(text)


def _harvest(args):
    since = None
    if getattr(args, "days", 0):
        since = datetime.now() - timedelta(days=args.days)
    apps = args.app.split(",") if getattr(args, "app", "") else None
    roots = [args.root] if getattr(args, "root", "") else None
    return gather.gather(roots=roots, since=since, apps=apps)


def cmd_gather(args):
    h = _harvest(args)
    lines = [
        f"{len(h.files)} logs, {analyze._human_bytes(h.total_bytes)}, "
        f"across {len(h.apps)} apps",
        "",
    ]
    for app in sorted(h.apps):
        files = h.apps[app]
        kinds = sorted({f.kind for f in files})
        newest = max((f.when for f in files if f.when), default=None)
        lines.append(
            f"  {app:<26} {len(files):>4} logs  "
            f"{analyze._human_bytes(sum(f.size for f in files)):>9}  "
            f"[{','.join(kinds)}]  newest {newest:%Y-%m-%d}" if newest else
            f"  {app:<26} {len(files):>4} logs"
        )
    if not h.files:
        lines.append("  Nothing found. Looked in: " + ", ".join(h.roots))
    _out(args, {"apps": {a: len(f) for a, f in h.apps.items()},
                "total_bytes": h.total_bytes, "roots": h.roots}, "\n".join(lines))
    return 0


def cmd_analyze(args):
    h = _harvest(args)
    findings = analyze.analyse(h, quiet_days=args.quiet_days)
    if args.band:
        findings = [f for f in findings if f.band == args.band.upper()]

    lines = [f"{len(findings)} findings from {len(h.files)} logs", ""]
    silences = [f for f in findings if f.kind == "silence"]
    if silences:
        lines.append("WHAT STOPPED HAPPENING  (the half nothing else looks for)")
        for f in silences:
            lines.append(f"  [{f.band}] {f.headline}")
            if f.detail:
                lines.append(f"      {f.detail}")
        lines.append("")
    rest = [f for f in findings if f.kind != "silence"]
    if rest:
        lines.append("WHAT WENT WRONG")
        for f in rest[: args.limit]:
            lines.append(f"  [{f.band}] {f.app}: {f.headline}  (x{f.count})")
        if len(rest) > args.limit:
            lines.append(f"  ... {len(rest) - args.limit} more, --limit to see them")
    if not findings:
        lines.append("Nothing worth reporting. That is a real answer, not an empty one.")
    _out(args, [f.as_dict() for f in findings], "\n".join(lines))
    return 0


def cmd_probes(args):
    rep = probes.scan_paths(args.paths)
    lines = [rep.verdict, ""]
    if rep.paths:
        lines.append("Paths probed, most hit first:")
        for path, n in rep.paths.most_common(args.limit):
            flag = "  <-- sensitive" if probes.SENSITIVE.search(path) else ""
            lines.append(f"  {n:>6}  {path}{flag}")
    payload = {
        "sharpening": rep.sharpening,
        "lines_read": rep.lines_read,
        "generic_hits": rep.generic_hits,
        "snapsmack_hits": rep.snapsmack_hits,
        "sensitive_hits": rep.sensitive_hits,
        "distinct_sources": rep.distinct_sources,
        "paths": dict(rep.paths.most_common(50)),
        "first_seen": rep.first_seen,
        "last_seen": rep.last_seen,
        "verdict": rep.verdict,
    }
    _out(args, payload, "\n".join(lines))
    return 0


def cmd_summarize(args):
    h = _harvest(args)
    findings = analyze.analyse(h, quiet_days=args.quiet_days)
    probe = {}
    if args.access_log:
        rep = probes.scan_paths(args.access_log)
        probe = {"sharpening": rep.sharpening, "snapsmack_hits": rep.snapsmack_hits,
                 "sensitive_hits": rep.sensitive_hits,
                 "distinct_sources": rep.distinct_sources,
                 "paths": dict(rep.paths.most_common(50)), "verdict": rep.verdict}
    s = summarize.summarise(h, findings, probe=probe)
    path = summarize.write_summary(s, args.out)
    _out(args, {"summary": path, "files_covered": len(s.files),
                "findings": len(s.findings)},
         f"Summary written: {path}\n"
         f"  covers {len(s.files)} logs, {len(s.apps)} apps, {len(s.findings)} findings\n"
         f"  this is what replaces those logs — cleanup will not run without it")
    return 0


def cmd_clean(args):
    h = _harvest(args)
    if not args.summary:
        print("Need --summary. Run 'lf summarize' first: the summary is what "
              "replaces the log, and nothing is deleted without one.", file=sys.stderr)
        return 2
    s = summarize.load_summary(args.summary)
    pinned = set(args.pin or [])
    plan = summarize.plan_clean(h, s, keep_days=args.keep_days, pinned=pinned)

    lines = [
        f"{len(plan.remove)} logs would be removed, freeing "
        f"{analyze._human_bytes(plan.bytes_freed)}",
        f"{len(plan.kept)} kept",
        "",
    ]
    for f in plan.remove[: args.limit]:
        lines.append(f"  remove  {f.name}  ({analyze._human_bytes(f.size)})")
    if len(plan.remove) > args.limit:
        lines.append(f"  ... {len(plan.remove) - args.limit} more")
    reasons = {}
    for why in plan.kept.values():
        reasons[why] = reasons.get(why, 0) + 1
    for why, n in sorted(reasons.items(), key=lambda kv: -kv[1]):
        lines.append(f"  keep    {n:>4}  {why}")

    if not args.apply:
        lines.append("")
        lines.append("DRY RUN. Nothing deleted. Pass --apply to do it.")
        _out(args, {"would_remove": len(plan.remove),
                    "bytes": plan.bytes_freed, "applied": False}, "\n".join(lines))
        return 0

    result = summarize.apply_clean(plan, args.summary)
    lines.append("")
    lines.append(f"Removed {len(result['removed'])} logs, freed "
                 f"{analyze._human_bytes(result['bytes_freed'])}.")
    if result["failed"]:
        lines.append(f"{len(result['failed'])} could not be removed:")
        lines.extend("  " + x for x in result["failed"][:10])
    _out(args, result | {"applied": True}, "\n".join(lines))
    return 0


def cmd_report(args):
    if not args.summary:
        print("Need --summary. Run 'lf summarize' first.", file=sys.stderr)
        return 2
    s = summarize.load_summary(args.summary)
    anonymous = not args.identify
    rep = reportmod.build(
        s, note=args.note, anonymous=anonymous,
        site=args.site, contact=args.contact,
        cms_version=args.cms_version, skin=args.skin, php=args.php,
    )
    body = reportmod.preview(rep)
    problems = reportmod.leaks(rep)

    if args.out:
        with open(args.out, "w", encoding="utf-8") as fh:
            fh.write(body)

    text = body
    if problems:
        text += ("\n\nWITHHELD — this anonymous report still contains:\n  "
                 + "\n  ".join(problems)
                 + "\n\nNot sending. Fix the scrubber before this travels.")
    elif anonymous:
        text += "\n\nChecked: nothing identifying found in the finished report."
    _out(args, {"anonymous": anonymous, "leaks": problems,
                "report": rep.as_dict(), "written": args.out or ""}, text)
    return 1 if problems else 0


def build_parser():
    p = argparse.ArgumentParser(
        prog="lf", description="LOG FLOGGER — read the logs nobody reads.")
    p.add_argument("--json", action="store_true", help="machine-readable output")
    sub = p.add_subparsers(dest="cmd", required=True)

    def common(sp):
        sp.add_argument("--root", default="", help="log directory (default C:/snapsmack/logs)")
        sp.add_argument("--app", default="", help="only these apps, comma separated")
        sp.add_argument("--days", type=int, default=0, help="only the last N days")
        return sp

    g = common(sub.add_parser("gather", help="what logs exist, by app and kind"))
    g.set_defaults(func=cmd_gather)

    a = common(sub.add_parser("analyze", help="errors AND silences"))
    a.add_argument("--quiet-days", type=int, default=14)
    a.add_argument("--band", default="", help="only this band, e.g. 'DOOR OPEN'")
    a.add_argument("--limit", type=int, default=25)
    a.set_defaults(func=cmd_analyze)

    pr = sub.add_parser("probes", help="is anyone sharpening for SnapSmack?")
    pr.add_argument("paths", nargs="+", help="web server access logs")
    pr.add_argument("--limit", type=int, default=25)
    pr.set_defaults(func=cmd_probes)

    s = common(sub.add_parser("summarize", help="write the summary that replaces the logs"))
    s.add_argument("--quiet-days", type=int, default=14)
    s.add_argument("--access-log", nargs="*", default=[])
    s.add_argument("--out", default="")
    s.set_defaults(func=cmd_summarize)

    c = common(sub.add_parser("clean", help="remove summarised logs (dry run by default)"))
    c.add_argument("--summary", default="", help="the summary covering them (required)")
    c.add_argument("--keep-days", type=int, default=30)
    c.add_argument("--pin", nargs="*", default=[], help="never delete these")
    c.add_argument("--limit", type=int, default=20)
    c.add_argument("--apply", action="store_true", help="actually delete")
    c.set_defaults(func=cmd_clean)

    r = sub.add_parser("report", help="a report for Sean — anonymous by default")
    r.add_argument("--summary", default="")
    r.add_argument("--note", default="")
    r.add_argument("--identify", action="store_true",
                   help="sign it with your site and contact (default is anonymous)")
    r.add_argument("--site", default="")
    r.add_argument("--contact", default="")
    r.add_argument("--cms-version", default="")
    r.add_argument("--skin", default="")
    r.add_argument("--php", default="")
    r.add_argument("--out", default="")
    r.set_defaults(func=cmd_report)
    return p


def main(argv=None) -> int:
    args = build_parser().parse_args(argv)
    return args.func(args)


if __name__ == "__main__":
    raise SystemExit(main())

# ===== SNAPSMACK EOF =====
