"""FED UP function 1 + 2 against a fake fediverse: outbox crawl, REST route,
archive layout, incremental run, verify, mirror, restore parser."""
# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
import json
import os
import shutil
import sys
import tempfile
import unittest

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
for p in (ROOT, os.path.join(ROOT, "..", "_shared"), os.path.join(ROOT, "..", "unzucker"), os.path.dirname(os.path.abspath(__file__))):
    if p not in sys.path:
        sys.path.insert(0, p)

from _fakes import FakeSession, mastodon_like, pixelfed_like  # noqa: E402
from fed_up import archive as archive_mod  # noqa: E402
from fed_up import restore  # noqa: E402
from fed_up.fetch import Fetcher, FetchError  # noqa: E402


def fetcher(routes):
    return Fetcher(pause=0, session=FakeSession(routes))


class FetchTest(unittest.TestCase):
    def test_webfinger_and_profile(self):
        routes, actor = mastodon_like()
        f = fetcher(routes)
        self.assertEqual(f.webfinger("@leo@masto.test"), actor)
        p = f.profile("leo@masto.test")
        self.assertEqual(p.display_name, "Leo & co")
        self.assertEqual(p.software, "mastodon")
        self.assertEqual(p.fields[0]["name"], "Site")

    def test_bad_handle(self):
        with self.assertRaises(FetchError):
            fetcher({}).webfinger("nonsense")

    def test_outbox_crawl_pages_and_maps(self):
        routes, _ = mastodon_like(n_posts=25)
        f = fetcher(routes)
        posts = f.posts(f.profile("leo@masto.test"), fetch_replies=False)
        self.assertEqual(len(posts), 25)
        self.assertEqual(posts[0].likes, 25)
        self.assertEqual(posts[0].tags, ["film"])
        self.assertEqual(posts[0].attachments[0].width, 100)
        self.assertEqual(posts[0].visibility, "public")

    def test_incremental_stops_at_known(self):
        routes, _ = mastodon_like(n_posts=25)
        f = fetcher(routes)
        known = {f"https://masto.test/users/leo/statuses/{1000 + i}" for i in range(1, 21)}  # 20 oldest known
        posts = f.posts(f.profile("leo@masto.test"), known_ids=known, fetch_replies=False)
        self.assertEqual(len(posts), 5)
        # stopped early: page 3 (posts 5..1) never requested
        self.assertNotIn("https://masto.test/users/leo/outbox?page=3", f.s.calls)

    def test_graph_and_hidden_graph(self):
        routes, _ = mastodon_like()
        f = fetcher(routes); p = f.profile("leo@masto.test")
        g = f.graph(p.followers)
        self.assertEqual([x["acct"] for x in g.items], ["ann@a.test", "bob@b.test"])
        routes, _ = mastodon_like(hidden_graph=True)
        f = fetcher(routes); p = f.profile("leo@masto.test")
        self.assertIn("publishes no list", f.graph(p.followers).unavailable)
        self.assertIn("403", f.graph(p.following).unavailable)

    def test_pixelfed_rest_route_first(self):
        routes, _ = pixelfed_like()
        f = fetcher(routes)
        posts = f.posts(f.profile("sean@pix.test"))
        self.assertEqual([p.id.rsplit("/", 1)[-1] for p in posts], ["503", "502", "501"])   # boost skipped
        self.assertEqual(posts[0].likes, 4)
        self.assertEqual(posts[0].replies[0]["author"], "ann@a.test")
        self.assertEqual(posts[0].attachments[0].height, 20)


class ArchiveTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.mkdtemp(prefix="fedup-test-")

    def tearDown(self):
        shutil.rmtree(self.tmp, ignore_errors=True)

    def test_full_then_incremental(self):
        routes, _ = mastodon_like(n_posts=12)
        arc = archive_mod.Archive(self.tmp, "leo@masto.test")
        s = arc.run(fetcher(routes), log=lambda m: None)
        self.assertFalse(s["incremental"]); self.assertEqual(s["new_posts"], 12)
        d = arc.dir
        self.assertTrue(os.path.isfile(os.path.join(d, "manifest.json")))
        self.assertTrue(os.path.isfile(os.path.join(d, "profile.json")))
        self.assertTrue(os.path.isfile(os.path.join(d, "graph", "followers.json")))
        self.assertTrue(os.path.isfile(os.path.join(d, "media", "profile-avatar.png")))
        m = arc.manifest()
        self.assertEqual(m["counts"]["posts"], 12); self.assertEqual(m["counts"]["followers"], 2)
        self.assertEqual(len(m["media"]), 13)   # 12 photos + avatar
        self.assertIn("Followers-only", s["could_not_fetch"][0])
        one = next(iter(m["posts"].values()))
        with open(os.path.join(d, one), encoding="utf-8") as fh:
            doc = json.load(fh)
        self.assertTrue(doc["media_files"][0].startswith("media/"))
        self.assertTrue(os.path.isfile(os.path.join(d, doc["media_files"][0])))
        # second run: nothing new, one more snapshot, media not re-fetched
        f2 = fetcher(routes)
        s2 = arc.run(f2, log=lambda m: None)
        self.assertTrue(s2["incremental"]); self.assertEqual(s2["new_posts"], 0)
        self.assertEqual(arc.manifest()["snapshot_count"], 2)
        self.assertFalse(any(u.endswith(".jpg") for u in f2.s.calls))
        self.assertEqual(len(arc.snapshots()), 2)
        # verify + tamper
        self.assertTrue(arc.verify()["ok"])
        with open(os.path.join(d, doc["media_files"][0]), "ab") as fh:
            fh.write(b"x")
        self.assertIn(doc["media_files"][0], arc.verify()["changed"])

    def test_media_failure_is_recorded_not_fatal(self):
        routes, _ = mastodon_like(n_posts=3)
        del routes["https://masto.test/media/2.jpg"]
        arc = archive_mod.Archive(self.tmp, "leo@masto.test")
        s = arc.run(fetcher(routes), log=lambda m: None)
        self.assertEqual(s["media_failures"], 1); self.assertEqual(s["new_posts"], 3)

    def test_mirror(self):
        routes, _ = mastodon_like(n_posts=2)
        arc = archive_mod.Archive(self.tmp, "leo@masto.test")
        arc.run(fetcher(routes), log=lambda m: None)
        dest = os.path.join(self.tmp, "mirror")
        n = arc.mirror_to_folder(dest)
        self.assertGreater(n, 4)
        self.assertEqual(arc.mirror_to_folder(dest), 0)
        self.assertTrue(os.path.isfile(os.path.join(dest, "leo@masto.test", "manifest.json")))

    def test_restore_parser(self):
        routes, _ = pixelfed_like()
        arc = archive_mod.Archive(self.tmp, "sean@pix.test")
        arc.run(fetcher(routes), log=lambda m: None)
        # make the fake JPEGs real enough? parse() only checks extension + existence.
        r = restore.parse(arc.dir)
        self.assertEqual(r.stats["posts"], 3)
        self.assertEqual(r.posts[0].hashtags, ["street"])
        self.assertIn("#street", r.posts[0].caption)
        self.assertLess(r.posts[0].ig_timestamp, r.posts[1].ig_timestamp)  # oldest first
        self.assertTrue(r.posts[0].images[0].endswith(".jpg"))
        self.assertEqual(restore.followers_to_tell(arc.dir), [])
        # followers list feeds the "tell them" list
        routes, _ = mastodon_like(n_posts=1)
        arc2 = archive_mod.Archive(self.tmp, "leo@masto.test")
        arc2.run(fetcher(routes), log=lambda m: None)
        self.assertEqual(restore.followers_to_tell(arc2.dir), ["@ann@a.test", "@bob@b.test"])

    def test_restore_skips_replies_and_no_image(self):
        routes, _ = mastodon_like(n_posts=2)
        page = routes["https://masto.test/users/leo/outbox?page=1"]
        page["orderedItems"][0]["object"]["inReplyTo"] = "https://x.test/note/1"
        page["orderedItems"][1]["object"]["attachment"] = []
        arc = archive_mod.Archive(self.tmp, "leo@masto.test")
        arc.run(fetcher(routes), log=lambda m: None)
        r = restore.parse(arc.dir)
        self.assertEqual(r.stats, {"posts": 0, "skipped_no_image": 1, "skipped_replies": 1, "skipped_private": 0})

    def test_post_key_stable_and_distinct(self):
        a = archive_mod.post_key("https://h/users/x/statuses/1")
        b = archive_mod.post_key("https://other/users/y/statuses/1")
        self.assertNotEqual(a, b)
        self.assertEqual(a, archive_mod.post_key("https://h/users/x/statuses/1"))
        self.assertTrue(a.startswith("1-"))


if __name__ == "__main__":
    unittest.main()
# ===== SNAPSMACK EOF =====
