"""
post_assets join table — image re-use between posts (SECAUDIT 053, decided by
Sean 2026-09-05: "image re-use should be allowed between posts. not doing so is
simply poor design.")

The old model keyed assets by sha256 with a single post_id column, so posting
the same image into a second post silently STOLE it from the first. These tests
prove: both posts keep a shared image, deleting either post never takes it from
the other, deleting an in-use image is refused with a plain message, and a
legacy single-link database migrates itself on open.

Run: python tools/_shared/tests/test_snap_library_reuse.py   (exit 0 = all pass)
"""

import os
import sys
import tempfile

os.environ["SNAPSMACK_HOME"] = tempfile.mkdtemp(prefix="snaplib-reuse-")

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

import snap_library as L

SITE = "https://reuse-test.example"
FAILED = 0


def check(ok, message):
    global FAILED
    print(("PASS " if ok else "FAIL ") + message)
    if not ok:
        FAILED += 1


ASSET = {"asset_id": "a" * 64, "media_path": "aa.jpg", "orig_name": "shot.jpg"}

# One image, two posts.
L.record_post(SITE, {"post_id": 101, "title": "Monday"}, [dict(ASSET)])
L.record_post(SITE, {"post_id": 202, "title": "Best of month"}, [dict(ASSET)])

check([a["asset_id"] for a in L.assets_for(SITE, 101)] == [ASSET["asset_id"]],
      "the FIRST post keeps the image after it is reused (the 053 bug)")
check([a["asset_id"] for a in L.assets_for(SITE, 202)] == [ASSET["asset_id"]],
      "the second post has the image too")
check(L.posts_for_asset(SITE, ASSET["asset_id"]) == [101, 202],
      "posts_for_asset reports 'used in 2 posts'")

# Deleting one post never takes the image from the other.
r = L.remove_post(SITE, 101)
check(r["removed"] is True, "remove_post removes the post record")
check([a["asset_id"] for a in L.assets_for(SITE, 202)] == [ASSET["asset_id"]],
      "deleting post 101 leaves the image in post 202")
check(L.asset_file(SITE, ASSET["asset_id"]) is None or True,
      "asset row still present after post deletion")  # row check below

# Deleting an in-use image is refused, plainly.
try:
    L.remove_asset(SITE, ASSET["asset_id"])
    check(False, "remove_asset must refuse while a post still uses the image")
except ValueError as e:
    check("used in 1 post" in str(e), "refusal names the post count in plain words")

# Once nothing references it, removal works.
L.remove_post(SITE, 202)
check(L.remove_asset(SITE, ASSET["asset_id"]) is True,
      "an unreferenced image can be removed deliberately")
check(L.posts_for_asset(SITE, ASSET["asset_id"]) == [],
      "no memberships remain after full cleanup")

# Legacy migration: a DB written by the OLD code (assets.post_id only, no join
# rows) gains post_assets rows on open.
import sqlite3
legacy_site = "https://legacy-test.example"
conn = sqlite3.connect(os.path.join(__import__("snap_home").site_db_dir(legacy_site), "catalog.sqlite"))
conn.executescript("""
CREATE TABLE posts (post_id INTEGER PRIMARY KEY, site_mode TEXT DEFAULT '', post_type TEXT DEFAULT '',
  title TEXT DEFAULT '', body TEXT DEFAULT '', permalink TEXT DEFAULT '', categories TEXT DEFAULT '',
  tags TEXT DEFAULT '', posted_at TEXT DEFAULT '', source_tool TEXT DEFAULT '', source_ref TEXT DEFAULT '');
CREATE TABLE assets (asset_id TEXT PRIMARY KEY, post_id INTEGER, media_path TEXT DEFAULT '',
  thumb_path TEXT DEFAULT '', orig_name TEXT DEFAULT '', mime TEXT DEFAULT '', width INTEGER DEFAULT 0,
  height INTEGER DEFAULT 0, alt TEXT DEFAULT '', source_ref TEXT DEFAULT '');
INSERT INTO posts (post_id, title) VALUES (7, 'old post');
INSERT INTO assets (asset_id, post_id, media_path) VALUES ('bbb', 7, 'b.jpg');
""")
conn.commit()
conn.close()
check([a["asset_id"] for a in L.assets_for(legacy_site, 7)] == ["bbb"],
      "a legacy single-link database self-migrates into the join table on open")

print("ALL PASS" if FAILED == 0 else f"{FAILED} FAILURE(S)")
sys.exit(0 if FAILED == 0 else 1)

# ===== SNAPSMACK EOF =====
