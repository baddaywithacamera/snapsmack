"""Headless GYSS operations. SNAPSMACK_EOF_HEADER."""
from pathlib import Path
import sys
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "_shared"))
import snap_cli as C


def configure(sub):
    C.add_library_commands(sub)
    p = sub.add_parser("metadata-update", help="Plan or apply reviewed metadata using existing CMS conflict checks")
    p.add_argument("--site", required=True); p.add_argument("--input", required=True)
    p.add_argument("--apply", action="store_true")
    for name in ("ping", "photos", "audit", "enrichment-sync"):
        p = sub.add_parser(name); p.add_argument("--site", required=True)
        if name == "photos":
            p.add_argument("--offset", type=int, default=0); p.add_argument("--limit", type=int, default=100)
        if name == "enrichment-sync":
            p.add_argument("--apply", action="store_true")


def execute(args):
    result = C.library_command(args)
    if result is not NotImplemented:
        return result
    if args.command == "metadata-update":
        payload = C.object_json(args.input)
        updates = payload.get("updates")
        allowed = {"id", "expected_modified_at", "title", "description", "alt", "hashtags", "colors", "color_mode", "orientation", "sort_order", "category_id"}
        if set(payload) != {"updates"} or not isinstance(updates, list) or not 1 <= len(updates) <= 500:
            raise ValueError("Expected 1–500 updates")
        seen = set()
        for update in updates:
            if not isinstance(update, dict) or set(update) - allowed:
                raise ValueError("Unknown metadata field; force overrides are not accepted")
            image_id = update.get("id")
            if type(image_id) is not int or image_id <= 0 or image_id in seen:
                raise ValueError("Each update needs a unique positive integer id")
            seen.add(image_id)
            if not isinstance(update.get("expected_modified_at"), str) or not update["expected_modified_at"].strip():
                raise ValueError("Every update requires expected_modified_at from a fresh photos response")
            if len(update) < 3:
                raise ValueError("Metadata update contains no changes")
            for field in ("title", "description", "alt", "hashtags", "color_mode"):
                if field in update and (not isinstance(update[field], str) or len(update[field]) > 100000):
                    raise ValueError(f"{field} must be bounded text")
            for field in ("sort_order", "orientation", "category_id"):
                if field in update and type(update[field]) is not int:
                    raise ValueError(f"{field} must be an integer")
            if "colors" in update:
                import re
                colors = update["colors"]
                if not isinstance(colors, list) or len(colors) > 5 or any(not isinstance(x, str) or not re.fullmatch(r"#[0-9a-fA-F]{6}", x) for x in colors):
                    raise ValueError("colors must contain up to five RGB hex strings")
        if not args.apply:
            return {"status": "planned", "site": args.site, "updates": updates}
        from gyss_api import API
        reply = API(C.profile(args.site, "gyss")).batch(updates)
        if reply.get("failed") or reply.get("conflicts"):
            raise C.OperationError("Some metadata updates failed or conflicted; inspect details before retrying", reply)
        return reply
    from gyss_api import API
    client = API(C.profile(args.site, "gyss"))
    if args.command == "ping":
        return client.ping()
    if args.command == "photos":
        if not 1 <= args.limit <= 500 or args.offset < 0:
            raise ValueError("Invalid photo page")
        # The GUI's legacy fallback does not honor offset. CLI pagination must
        # fail explicitly on an incompatible endpoint rather than repeat page 1.
        return client.call("GET", "photos", {"offset": args.offset, "limit": args.limit})
    if args.command == "audit":
        return client.audit()
    import snap_enrichment_cache as cache
    records = cache.pending(args.site)
    if not args.apply:
        return {"status": "planned", "pending": len(records), "cache_keys": [r["cache_key"] for r in records]}
    synced = []
    for record in records:
        reply = client.call("POST", "enrichment-cache", body={"record": record})
        # A newer local edit must stay dirty even if this request was accepted.
        with cache._connect(args.site) as conn:
            cursor = conn.execute("UPDATE enrichment_cache SET revision=?,base_revision=?,dirty=0 "
                                  "WHERE cache_key=? AND revision=? AND modified_at=? AND bundle_json=? AND accepted_json=?",
                                  (reply["revision"], reply["revision"], record["cache_key"], record["revision"], record["modified_at"],
                                   __import__('json').dumps(record["bundle"], ensure_ascii=False, sort_keys=True),
                                   __import__('json').dumps(record["accepted"], ensure_ascii=False, sort_keys=True)))
            synced.append({"cache_key": record["cache_key"], "acknowledged": bool(cursor.rowcount)})
    return {"synced": synced, "applied_to_site_metadata": False}


def main(argv=None):
    return C.run("gyss", configure, execute, argv)


if __name__ == "__main__":
    raise SystemExit(main())
# ===== SNAPSMACK EOF =====
