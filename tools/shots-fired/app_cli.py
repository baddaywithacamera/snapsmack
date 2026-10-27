"""SHOTS FIRED scheduling commands using its existing transport. SNAPSMACK_EOF_HEADER."""
from pathlib import Path
from dataclasses import asdict
import sys
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "_shared"))
import snap_cli as C


def configure(sub):
    for name in ("scheduled", "reschedule"):
        p = sub.add_parser(name); p.add_argument("--site", required=True)
        p.add_argument("--days", type=int, default=60)
        if name == "reschedule":
            p.add_argument("--id", type=int, required=True); p.add_argument("--date", required=True)
            p.add_argument("--apply", action="store_true")


def execute(args):
    import schedule_client as client
    from fleet import Site
    if not 1 <= args.days <= 366:
        raise ValueError("--days must be between 1 and 366")
    selected = C.profile(args.site, "hub")
    site = Site(selected["name"], selected["site_url"], selected["api_key"])
    if args.command == "scheduled":
        reply = client.list_scheduled(site, args.days)
        if reply.status != client.ApiStatus.OK:
            raise ValueError(f"Scheduling API: {reply.status.value}")
        return {"items": [{**{k: v for k, v in asdict(post).items() if k not in {"raw", "img_date"}},
                           "img_date": post.img_date.isoformat()} for post in reply.posts]}
    date = client.parse_dt(args.date)
    if date is None or args.id <= 0:
        raise ValueError("A valid post ID and date are required")
    if not args.apply:
        return {"status": "planned", "site": site.url, "id": args.id, "date": date.isoformat()}
    reply = client.reschedule(site, args.id, date)
    if reply.status != client.ApiStatus.OK:
        raise ValueError(f"Scheduling API: {reply.status.value}")
    return {"status": "rescheduled", "id": args.id, "date": date.isoformat()}


def main(argv=None):
    return C.run("shots-fired", configure, execute, argv)


if __name__ == "__main__":
    raise SystemExit(main())
# ===== SNAPSMACK EOF =====
