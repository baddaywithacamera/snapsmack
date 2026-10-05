# SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
"""Repair already-imported WordPress content without changing destination identity.

This is deliberately conservative: only source items whose authored slug already
exists at the SnapSmack destination are eligible.  Posts reuse their existing
Gallery bucket when its size matches, so a repair does not duplicate photographs.
Static pages have no read-back endpoint, so their referenced images are uploaded
again while the existing page id and URL are retained.
"""

from __future__ import annotations

import argparse
from pathlib import Path

import snap_connections
from smackpress import config, wp_client, wp_source
from sumna_post import SmacktalkPoster


def _all_source(kind: str) -> list[dict]:
    getter = wp_client.get_pages if kind == "page" else wp_client.get_posts
    first = getter(page=1, per_page=100)
    rows = list(first.get("posts") or [])
    for page in range(2, int(first.get("total_pages") or 1) + 1):
        rows.extend(getter(page=page, per_page=100).get("posts") or [])
    return rows


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--state-dir", type=Path, required=True)
    parser.add_argument("--destination", required=True)
    parser.add_argument("--slug", action="append", default=[],
                        help="repair only this existing authored slug (repeatable)")
    parser.add_argument("--link-page-images", action="store_true",
                        help="make imported static-page images open their Gallery originals")
    parser.add_argument("--apply", action="store_true")
    args = parser.parse_args()

    config.DB_DIR = args.state_dir.resolve()
    config.DB_PATH = config.DB_DIR / "smackpress.db"

    connection = snap_connections.resolve(args.destination, "smackpress")
    if not connection or not connection.get("api_key"):
        raise SystemExit("No SMACKPRESS destination credential is available.")
    poster = SmacktalkPoster(connection["site_url"], connection["api_key"])

    destinations = {
        "post": {row["slug"].casefold(): row for row in poster.list_posts(500)},
        "page": {row["slug"].casefold(): row for row in poster.list_pages(500)},
    }
    matches: list[tuple[str, dict, dict]] = []
    for kind in ("post", "page"):
        for source in _all_source(kind):
            destination = destinations[kind].get(str(source.get("slug") or "").casefold())
            if destination:
                if args.slug and source.get("slug") not in set(args.slug):
                    continue
                matches.append((kind, source, destination))

    print(f"Matched {len(matches)} existing destination records.")
    if not args.apply:
        for kind, source, destination in matches:
            print(f"DRY RUN  {kind:4} WP {source['id']} -> Snap {destination['id']}  {source['slug']}")
        return 0

    profile = config.get_migration_profile(config.get("wp_url"))
    failures = 0
    for kind, source, destination in matches:
        slug = source["slug"]
        print(f"REPAIR   {kind:4} WP {source['id']} -> Snap {destination['id']}  {slug}")
        try:
            full = wp_client.get_post(int(source["id"]))
            workdir = args.state_dir / "repair" / str(source["id"])
            draft = wp_source.draft_from_wp(full, str(workdir), profile=profile)
            draft.remote_post_id = int(destination["id"])
            draft.destination_url = connection["site_url"]
            draft.img_status = "published" if kind == "post" else draft.img_status

            # Compatibility with destination builds whose longform auto-paragraph
            # helper only recognises authored HTML when '<p>' is the first token.
            # A harmless empty paragraph keeps block HTML intact when a static
            # page begins with its hero image shortcode.
            if kind == "page" and draft.caption.lstrip().startswith("[img:"):
                draft.caption = "<p></p>\n" + draft.caption
            if kind == "page" and args.link_page_images:
                draft.caption = draft.caption.replace("[img:bucket:", "[img:gbucket:")

            if kind == "post":
                current = poster.get_post(int(destination["id"]))
                bucket = list(current.get("bucket") or [])
                if len(bucket) != len(draft.images):
                    raise RuntimeError(
                        f"image count changed ({len(draft.images)} source, {len(bucket)} destination); "
                        "refusing an ambiguous positional repair"
                    )
                for image, existing in zip(draft.images, bucket):
                    image.remote_image_id = int(existing["id"])
                draft.category_ids = list(current.get("cat_ids") or [])
                draft.album_ids = list(current.get("album_ids") or [])
                draft.tags = " ".join(current.get("tags") or [])

            result = poster.sync_smacktalk(draft)
            if not result.ok:
                raise RuntimeError(result.message)
            print(f"OK       kept destination id {result.remote_post_id}")
        except Exception as exc:  # continue so the operator gets the full repair report
            failures += 1
            print(f"FAILED   {slug}: {exc}")

    print(f"Repair complete: {len(matches) - failures} succeeded, {failures} failed.")
    return 1 if failures else 0


if __name__ == "__main__":
    raise SystemExit(main())

# ===== SNAPSMACK EOF =====
