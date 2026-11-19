"""Tests for GHOSTED (Ghost -> SMACKTALK importer), including CHAPLIN round-trip."""

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.

from __future__ import annotations

import importlib.util
import json
import sys
from pathlib import Path

GHOSTED_ROOT = Path(__file__).resolve().parents[1]
REPO_ROOT = GHOSTED_ROOT.parents[1]
if str(GHOSTED_ROOT) not in sys.path:
    sys.path.insert(0, str(GHOSTED_ROOT))

from ghosted.archive_reader import discover_archives, read_candidate  # noqa: E402
from ghosted.ghost_parser import parse_ghost_json  # noqa: E402
from ghosted.import_engine import ImportEngine  # noqa: E402
from ghosted.job_store import JobStore  # noqa: E402


def _load_ghost_adapter():
    adapter_path = REPO_ROOT / "tools" / "take-your-shit-with-you" / "ghost_adapter.py"
    spec = importlib.util.spec_from_file_location("ghost_adapter", adapter_path)
    mod = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(mod)
    return mod


class FakeDestinationClient:
    """In-memory SMACKTALK destination mock supporting import-map and slug lookup."""

    def __init__(self):
        self.media_seq = 100
        self.post_seq = 200
        self.page_seq = 300
        self.media = {}
        self.posts = {}
        self.pages = {}
        self.import_map = {}

    def preflight(self):
        return {"compatible": True, "site_mode": "smacktalk", "content_count": 0, "import_authorized": True}

    def lookup(self, source_site_id: str, source_type: str, source_id: str):
        hit = self.import_map.get((source_site_id, source_type, source_id))
        if hit:
            return {"found": True, "mapping": hit}
        return {"found": False, "mapping": None}

    def find_by_slug(self, kind: str, slug: str):
        bucket = self.posts if kind == "post" else self.pages
        for dest_id, rec in bucket.items():
            if rec["slug"].lower() == slug.lower():
                return dest_id
        return None

    def upload_media(self, path, source_site_id: str, source_id: str, *, alt="", source_checksum=""):
        self.media_seq += 1
        mid = self.media_seq
        self.media[mid] = {
            "id": mid,
            "filename": Path(path).name,
            "alt": alt,
            "checksum": source_checksum,
        }
        self.import_map[(source_site_id, "media", source_id)] = {
            "destination_type": "media",
            "destination_id": mid,
        }
        return {"status": "ok", "image_id": mid, "asset_id": mid}

    def create_post(self, body: dict):
        self.post_seq += 1
        pid = self.post_seq
        self.posts[pid] = dict(body)
        self.import_map[(body["source_site_id"], "post", body["source_id"])] = {
            "destination_type": "post",
            "destination_id": pid,
        }
        return {"status": "ok", "post_id": pid, "slug": body["slug"]}

    def create_page(self, body: dict):
        self.page_seq += 1
        pgid = self.page_seq
        self.pages[pgid] = dict(body)
        self.import_map[(body["source_site_id"], "page", body["source_id"])] = {
            "destination_type": "page",
            "destination_id": pgid,
        }
        return {"status": "ok", "page_id": pgid, "slug": body["slug"]}

    def verify(self, destination_type: str, destination_id: int):
        return {"status": "ok", "type": destination_type, "id": destination_id}


def _sample_ghost_doc():
    return {
        "db": [
            {
                "meta": {"exported_on": 1795040000000, "version": "5.82.0"},
                "data": {
                    "posts": [
                        {
                            "id": "660000000000000000000001",
                            "title": "Fog on the Bow",
                            "slug": "fog-on-the-bow",
                            "html": (
                                '<p>Early morning along the river.</p>'
                                '<!--kg-card-begin: image-->'
                                '<figure class="kg-card kg-image-card">'
                                '<img src="__GHOST_URL__/content/images/2026/fog-inline.jpg" alt="River fog">'
                                '</figure>'
                                '<!--kg-card-end: image-->'
                                '<!--kg-card-begin: bookmark-->'
                                '<figure class="kg-card kg-bookmark-card"><a href="https://example.com">Link</a></figure>'
                                '<!--kg-card-end: bookmark-->'
                                '<p>[custom_map lat="51.0" lon="-114.0"]</p>'
                            ),
                            "feature_image": "__GHOST_URL__/content/images/2026/fog-hero.jpg",
                            "feature_image_alt": "Bow River hero",
                            "custom_excerpt": "Morning mist before sunrise.",
                            "type": "post",
                            "status": "published",
                            "published_at": "2026-10-15T13:30:00.000Z",
                            "created_at": "2026-10-15T12:00:00.000Z",
                            "updated_at": "2026-10-15T14:00:00.000Z",
                        },
                        {
                            "id": "660000000000000000000002",
                            "title": "About This Archive",
                            "slug": "about-this-archive",
                            "html": "<p>Built for long-term photo essays.</p>",
                            "feature_image": None,
                            "type": "page",
                            "status": "published",
                            "published_at": "2026-10-01 08:00:00",
                            "created_at": "2026-10-01 08:00:00",
                            "updated_at": "2026-10-01 08:00:00",
                        },
                    ],
                    "tags": [
                        {"id": "t1", "name": "Street", "slug": "street"},
                        {"id": "t2", "name": "River", "slug": "river"},
                    ],
                    "posts_tags": [
                        {"post_id": "660000000000000000000001", "tag_id": "t1", "sort_order": 0},
                        {"post_id": "660000000000000000000001", "tag_id": "t2", "sort_order": 1},
                    ],
                    "users": [
                        {"id": "u1", "name": "Sean McCormick", "email": "sean@example.invalid"},
                    ],
                    "posts_authors": [
                        {"post_id": "660000000000000000000001", "author_id": "u1"},
                        {"post_id": "660000000000000000000002", "author_id": "u1"},
                    ],
                },
            }
        ]
    }


def test_dry_run_counts_and_leftover_card_reporting(tmp_path):
    export_dir = tmp_path / "ghost_export"
    img_dir = export_dir / "content" / "images" / "2026"
    img_dir.mkdir(parents=True)
    (img_dir / "fog-hero.jpg").write_bytes(b"JPEGHERO")
    (img_dir / "fog-inline.jpg").write_bytes(b"JPEGINLINE")
    json_file = export_dir / "ghost.json"
    json_file.write_text(json.dumps(_sample_ghost_doc()), encoding="utf-8")

    candidates = discover_archives(json_file)
    assert len(candidates) == 1
    raw, media_root, zip_path = read_candidate(candidates[0])
    blog = parse_ghost_json(raw, source_name="ghost", media_root=media_root, zip_path=zip_path)

    counts = blog.counts()
    assert counts["posts"] == 1
    assert counts["pages"] == 1
    assert counts["images"] == 2
    assert counts["tags"] == 2
    assert counts["leftover_cards"] == 2  # bookmark kg-card + [custom_map] shortcode

    client = FakeDestinationClient()
    store = JobStore(tmp_path / "job.sqlite")
    engine = ImportEngine(blog, client, store, preserve_published=True)
    dry = engine.dry_run()
    assert dry["posts"] == 1
    assert dry["pages"] == 1
    assert dry["images"] == 2
    assert dry["tags"] == 2
    assert dry["to_import"] == 2
    assert dry["leftover_cards"] == 2
    assert any("kg-card:bookmark" in d for d in dry["leftover_details"])
    assert any("shortcode:custom_map" in d for d in dry["leftover_details"])
    assert client.posts == {} and client.pages == {}
    store.close()


def test_import_and_idempotent_rerun(tmp_path):
    export_dir = tmp_path / "ghost_export"
    img_dir = export_dir / "content" / "images" / "2026"
    img_dir.mkdir(parents=True)
    (img_dir / "fog-hero.jpg").write_bytes(b"JPEGHERO")
    (img_dir / "fog-inline.jpg").write_bytes(b"JPEGINLINE")
    json_file = export_dir / "ghost.json"
    json_file.write_text(json.dumps(_sample_ghost_doc()), encoding="utf-8")

    raw, media_root, zip_path = read_candidate(discover_archives(json_file)[0])
    blog = parse_ghost_json(raw, source_name="ghost", media_root=media_root, zip_path=zip_path)

    client = FakeDestinationClient()
    store = JobStore(tmp_path / "job.sqlite")
    logs = []
    engine = ImportEngine(blog, client, store, preserve_published=True, on_log=logs.append)
    first = engine.run()

    assert first["summary"]["created"] == 2
    assert first["summary"]["already_imported"] == 0
    assert len(client.posts) == 1
    assert len(client.pages) == 1
    assert len(client.media) == 2

    imported_post = next(iter(client.posts.values()))
    assert imported_post["slug"] == "fog-on-the-bow"
    assert imported_post["created_at"] == "2026-10-15 13:30:00"
    assert imported_post["status"] == "published"
    assert imported_post["tags"] == "Street River"
    assert imported_post["excerpt"] == "Morning mist before sunrise."
    assert imported_post["featured_image_id"] == 101
    assert "[img:g102]" in imported_post["content"]
    assert any("kg-card:bookmark" in line for line in logs)

    # Second run with same store -> zero duplicates
    second = engine.run()
    assert second["summary"]["created"] == 0
    assert second["summary"]["already_imported"] == 2
    assert len(client.posts) == 1
    assert len(client.pages) == 1

    # Third run with a brand-new empty JobStore -> remote import-map / slug check still prevents duplicates
    fresh_store = JobStore(tmp_path / "job_fresh.sqlite")
    third = ImportEngine(blog, client, fresh_store, preserve_published=True).run()
    assert third["summary"]["created"] == 0
    assert third["summary"]["already_imported"] == 2
    assert len(client.posts) == 1
    assert len(client.pages) == 1
    store.close()
    fresh_store.close()


def test_round_trip_chaplin_archive_via_ghost_adapter(tmp_path):
    ghost_adapter = _load_ghost_adapter()

    # Build a CHAPLIN-style canonical archive fixture
    arch = tmp_path / "chaplin_archive"
    for sub in ("content/posts", "content/images", "content/pages", "indexes", "media"):
        (arch / sub).mkdir(parents=True)

    (arch / "media" / "prairie.jpg").write_bytes(b"CHAPLIN-IMG-1")
    (arch / "media" / "elevator.jpg").write_bytes(b"CHAPLIN-IMG-2")

    (arch / "site.json").write_text(json.dumps({
        "canonical_schema": 1,
        "name": "CHAPLIN TEST",
        "mode": "smacktalk",
        "url": "https://chaplin.example.invalid",
        "owner": "Sean D. McCormick",
        "owner_email": "sean@example.invalid",
    }), encoding="utf-8")

    (arch / "indexes" / "content-map.json").write_text(json.dumps({
        "site_mode": "smacktalk",
    }), encoding="utf-8")
    (arch / "indexes" / "categories.json").write_text(json.dumps({
        "categories": [{"id": 10, "name": "Prairie", "description": "Prairie essays"}],
    }), encoding="utf-8")
    (arch / "indexes" / "tags.json").write_text(json.dumps({
        "tags": [{"id": 20, "tag": "grain"}],
    }), encoding="utf-8")
    (arch / "indexes" / "albums.json").write_text(json.dumps({"albums": []}), encoding="utf-8")
    (arch / "indexes" / "collections.json").write_text(json.dumps({"collections": []}), encoding="utf-8")

    (arch / "content" / "images" / "image-1.json").write_text(json.dumps({
        "source": {"id": 1},
        "title": "Grain Elevator",
        "description": "Steel and timber.",
        "media": [{"variant": "original", "path": "media/elevator.jpg"}],
        "dates": {"created": "2026-09-10 09:00:00", "published": "2026-09-10 09:30:00"},
    }), encoding="utf-8")
    (arch / "content" / "images" / "image-2.json").write_text(json.dumps({
        "source": {"id": 2},
        "title": "Prairie Horizon",
        "description": "Storm light.",
        "media": [{"variant": "original", "path": "media/prairie.jpg"}],
        "dates": {"created": "2026-09-11 10:00:00", "published": "2026-09-11 10:15:00"},
    }), encoding="utf-8")

    (arch / "content" / "posts" / "post-7.json").write_text(json.dumps({
        "source": {"id": 7},
        "title": "Last Elevator in Town",
        "slug": "last-elevator-in-town",
        "body": "Two paragraphs about the prairies.\n\n[img:1]",
        "excerpt": "A short prairie essay.",
        "status": "published",
        "images": [{"image_id": 2}],
        "categories": [{"id": 10}],
        "tags": [{"id": 20}],
        "dates": {
            "created": "2026-09-12 14:00:00",
            "published": "2026-09-12 15:00:00",
            "updated": "2026-09-12 16:00:00",
        },
        "comments": [],
    }), encoding="utf-8")

    (arch / "content" / "pages" / "page-3.json").write_text(json.dumps({
        "source": {"id": 3},
        "title": "Colophon",
        "slug": "colophon",
        "body": "Shot on medium format.",
        "status": "published",
        "dates": {"created": "2026-09-01 07:00:00", "published": "2026-09-01 07:00:00"},
    }), encoding="utf-8")

    out = ghost_adapter.generate(str(arch))
    assert out["items"]["posts"] == 2  # post 7 + standalone image 1
    assert out["items"]["pages"] == 1
    assert out["items"]["tags"] == 2
    assert out["items"]["images"] == 2

    zip_file = arch / "courtesy" / "ghost" / "ghost-import.zip"
    candidates = discover_archives(zip_file)
    assert len(candidates) == 1
    raw, media_root, zip_path = read_candidate(candidates[0])
    blog = parse_ghost_json(raw, source_name="chaplin-roundtrip", media_root=media_root, zip_path=zip_path)

    counts = blog.counts()
    assert counts["posts"] == 2
    assert counts["pages"] == 1
    assert counts["tags"] == 2
    assert counts["images"] == 2

    client = FakeDestinationClient()
    store = JobStore(tmp_path / "roundtrip.sqlite")
    res = ImportEngine(blog, client, store, preserve_published=True).run()
    assert res["summary"]["created"] == 3
    assert len(client.posts) == 2
    assert len(client.pages) == 1

    # Field-by-field comparison against original CHAPLIN fixture
    by_slug = {p["slug"]: p for p in client.posts.values()}
    assert "last-elevator-in-town" in by_slug
    restored_post = by_slug["last-elevator-in-town"]
    assert restored_post["title"] == "Last Elevator in Town"
    assert restored_post["created_at"] == "2026-09-12 15:00:00"
    assert restored_post["excerpt"] == "A short prairie essay."
    assert restored_post["status"] == "published"
    assert set(restored_post["tags"].split()) == {"Prairie", "grain"}
    assert restored_post["featured_image_id"] is not None

    restored_page = next(iter(client.pages.values()))
    assert restored_page["slug"] == "colophon"
    assert restored_page["title"] == "Colophon"
    assert restored_page["created_at"] == "2026-09-01 07:00:00"
    assert restored_page["status"] == "published"
    store.close()

# ===== SNAPSMACK EOF =====
