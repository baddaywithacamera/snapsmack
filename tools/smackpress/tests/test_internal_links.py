"""SMACKPRESS: an internal link is rewritten or reported, never silently lost.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====

THE FAULT. wp_source._discard_source_links kept a link's TEXT and threw its
URL away whenever the href pointed at the retired WordPress host. Right for a
page that no longer exists anywhere. Wrong for the common case on a photoblog
of fifteen years: a link from one of the owner's posts to ANOTHER of the
owner's posts, which has also been migrated and does exist, at an address this
tool already holds in its own posts table. Those links were deleted, and
nothing was written down, so there was no list of what had gone.

AND THE RELATIVE ONES WERE WORSE OFF. `/2015/03/a-post/` has no hostname, so
the host comparison never matched it and it was left exactly as written -- to
resolve against the NEW site, where that path does not exist. A silent 404 for
a visitor, on a link that could have been rewritten correctly.

WHAT IS TESTED HERE: every link shape a WordPress export actually contains,
the mapping both ways (slug and WordPress id), the archive and media cases
that must NOT be resolved as posts, the notes that get recorded instead of
silence, and the post-migration fix-up -- a link unplaceable at first import
because its target had not landed yet, placed on the repair run once it has.
"""

import os
import pathlib
import sys
import unittest

TOOL = pathlib.Path(__file__).resolve().parents[1]
if str(TOOL) not in sys.path:
    sys.path.insert(0, str(TOOL))

from smackpress import links                                       # noqa: E402

HOST = "old-blog.example"
SOURCE = "https://old-blog.example/2015/03/04/this-post/"

SLUGS = {"a-post": "https://new.example/p/a-post",
         "other-post": "https://new.example/p/other-post"}
IDS = {123: "https://new.example/p/a-post", 7: "https://new.example/p/other-post"}


def rewrite(body, slugs=None, ids=None, **kw):
    return links.rewrite_internal_links(
        body, SOURCE, SLUGS if slugs is None else slugs,
        IDS if ids is None else ids, **kw)


class ResolveTests(unittest.TestCase):

    def test_an_absolute_dated_permalink_is_rewritten(self):
        action, target, _why = links.resolve(
            "https://old-blog.example/2015/03/04/a-post/", HOST, SLUGS, IDS)
        self.assertEqual(action, links.REWRITTEN)
        self.assertEqual(target, "https://new.example/p/a-post")

    def test_a_plain_permalink_is_rewritten(self):
        action, target, _ = links.resolve(
            "https://old-blog.example/a-post/", HOST, SLUGS, IDS)
        self.assertEqual((action, target), (links.REWRITTEN, SLUGS["a-post"]))

    def test_a_category_prefixed_permalink_is_rewritten(self):
        action, target, _ = links.resolve(
            "https://old-blog.example/photography/black-and-white/a-post/",
            HOST, SLUGS, IDS)
        self.assertEqual((action, target), (links.REWRITTEN, SLUGS["a-post"]))

    def test_a_protocol_relative_link_is_rewritten(self):
        action, target, _ = links.resolve(
            "//old-blog.example/2015/03/other-post/", HOST, SLUGS, IDS)
        self.assertEqual((action, target), (links.REWRITTEN, SLUGS["other-post"]))

    def test_a_root_relative_link_is_rewritten_and_not_left_to_404(self):
        """The case that was not even being looked at before."""
        action, target, _ = links.resolve("/2015/03/04/a-post/", HOST, SLUGS, IDS)
        self.assertEqual((action, target), (links.REWRITTEN, SLUGS["a-post"]))

    def test_www_and_the_bare_domain_are_the_same_site(self):
        for href in ("https://www.old-blog.example/a-post/",
                     "https://old-blog.example/a-post/"):
            action, target, _ = links.resolve(href, HOST, SLUGS, IDS)
            self.assertEqual((action, target), (links.REWRITTEN, SLUGS["a-post"]),
                             href)

    def test_a_similar_hostname_is_not_mistaken_for_this_site(self):
        """str.lstrip('www.') would turn 'wonderful.com' into 'onderful.com'
        and call two different sites the same one."""
        action, _t, why = links.resolve("https://wonderful.example/a-post/",
                                        "onderful.example", SLUGS, IDS)
        self.assertEqual(action, links.EXTERNAL)
        self.assertIn("wonderful.example", why)

    def test_the_wordpress_post_id_form_is_rewritten(self):
        for href in ("?p=123", "/?p=123",
                     "https://old-blog.example/?p=123"):
            action, target, why = links.resolve(href, HOST, SLUGS, IDS)
            self.assertEqual((action, target),
                             (links.REWRITTEN, IDS[123]), href)
            self.assertIn("123", why)

    def test_the_page_id_form_is_rewritten_too(self):
        action, target, _ = links.resolve("/?page_id=7", HOST, SLUGS, IDS)
        self.assertEqual((action, target), (links.REWRITTEN, IDS[7]))

    def test_an_id_that_was_never_migrated_is_reported_not_guessed(self):
        action, target, why = links.resolve("/?p=999", HOST, SLUGS, IDS)
        self.assertEqual(action, links.UNMAPPED)
        self.assertEqual(target, "")
        self.assertIn("999", why)

    def test_a_slug_with_no_migrated_post_is_reported(self):
        action, _t, why = links.resolve("/2015/03/vanished/", HOST, SLUGS, IDS)
        self.assertEqual(action, links.UNMAPPED)
        self.assertIn("vanished", why)

    def test_a_fragment_survives_the_rewrite(self):
        _a, target, _ = links.resolve("/a-post/#the-print", HOST, SLUGS, IDS)
        self.assertEqual(target, "https://new.example/p/a-post#the-print")

    def test_a_percent_encoded_slug_matches_whichever_form_the_table_holds(self):
        """wp_slug may hold the raw segment or the decoded one, depending on
        which API field it was read from. Guessing one breaks the other."""
        encoded = {"caf%c3%a9": "https://new.example/p/cafe"}
        _a, target, _ = links.resolve("/2016/01/caf%C3%A9/", HOST, encoded, IDS)
        self.assertEqual(target, "https://new.example/p/cafe")
        decoded = {"café": "https://new.example/p/cafe"}
        _a, target, _ = links.resolve("/2016/01/caf%C3%A9/", HOST, decoded, IDS)
        self.assertEqual(target, "https://new.example/p/cafe")
        # And an ordinary ASCII slug is not changed on the way through.
        _a, target, _ = links.resolve("/a-post/", HOST, SLUGS, IDS)
        self.assertEqual(target, SLUGS["a-post"])

    # -- the things that are NOT posts --------------------------------------

    def test_a_date_archive_is_not_resolved_as_a_post(self):
        for href in ("/2015/", "/2015/03/", "/2015/03/04/"):
            action, target, why = links.resolve(href, HOST, {"03": "WRONG",
                                                             "04": "WRONG",
                                                             "2015": "WRONG"}, IDS)
            self.assertEqual(action, links.UNMAPPED, href)
            self.assertEqual(target, "", href)
            self.assertIn("archive", why)

    def test_the_front_page_is_left_alone(self):
        action, _t, why = links.resolve("/", HOST, SLUGS, IDS)
        self.assertEqual(action, links.SKIPPED)
        self.assertIn("front page", why)

    def test_old_uploads_are_media_not_a_post(self):
        action, _t, why = links.resolve(
            "/wp-content/uploads/2015/03/pic.jpg", HOST, SLUGS, IDS)
        self.assertEqual(action, links.MEDIA)
        self.assertIn("uploads", why)

    def test_a_media_filename_is_never_read_as_a_slug(self):
        self.assertEqual(links.slug_from_path("/files/a-post.jpg"), "")
        self.assertEqual(links.slug_from_path("/docs/a-post.pdf"), "")

    def test_links_that_are_not_site_links_are_untouched(self):
        for href in ("#top", "mailto:sean@example.com", "tel:+15555551212",
                     "javascript:void(0)", "data:text/plain,hi"):
            action, _t, _w = links.resolve(href, HOST, SLUGS, IDS)
            self.assertEqual(action, links.SKIPPED, href)

    def test_another_site_is_external(self):
        action, _t, _w = links.resolve("https://flickr.com/x", HOST, SLUGS, IDS)
        self.assertEqual(action, links.EXTERNAL)

    def test_a_row_with_no_destination_is_not_offered_as_one(self):
        rows = [{"wp_id": 5, "wp_slug": "not-yet", "snap_url": ""},
                {"wp_id": 6, "wp_slug": "landed", "snap_url": "https://new/p/landed"},
                {"wp_id": 7, "wp_slug": "null-url", "snap_url": None}]
        slug_map, id_map = links.build_maps(rows)
        self.assertEqual(slug_map, {"landed": "https://new/p/landed"})
        self.assertEqual(id_map, {6: "https://new/p/landed"})
        self.assertNotIn("not-yet", slug_map)


class BodyTests(unittest.TestCase):

    def test_a_mapped_link_keeps_its_words_and_gains_the_new_address(self):
        body = '<p>see <a href="/2015/03/a-post/" title="t">that one</a></p>'
        out, report = rewrite(body)
        self.assertIn('href="https://new.example/p/a-post"', out)
        self.assertIn("that one", out)
        self.assertIn('title="t"', out)
        self.assertEqual(len(report.rewritten), 1)
        self.assertEqual(report.unmapped, [])

    def test_an_unmapped_link_keeps_its_words_and_is_reported(self):
        body = '<p>see <a href="/2015/03/gone/">that one</a></p>'
        out, report = rewrite(body)
        self.assertIn("that one", out)
        self.assertNotIn("href", out)
        self.assertEqual(len(report.unmapped), 1)
        self.assertEqual(report.unmapped[0]["href"], "/2015/03/gone/")

    def test_an_external_link_is_left_exactly_as_written(self):
        body = '<p><a href="https://flickr.com/x" rel="me">flickr</a></p>'
        out, report = rewrite(body)
        self.assertEqual(out, body)
        self.assertEqual(len(report.of(links.EXTERNAL)), 1)

    def test_an_anchor_link_is_left_exactly_as_written(self):
        body = '<p><a href="#notes">notes</a></p>'
        out, _r = rewrite(body)
        self.assertEqual(out, body)

    def test_several_links_in_one_body_are_each_handled(self):
        body = ('<p><a href="/a-post/">one</a> '
                '<a href="/?p=7">two</a> '
                '<a href="/2015/03/gone/">three</a> '
                '<a href="https://example.net/r">four</a></p>')
        out, report = rewrite(body)
        self.assertEqual(len(report), 4)
        self.assertEqual(len(report.rewritten), 2)
        self.assertEqual(len(report.unmapped), 1)
        self.assertEqual(len(report.of(links.EXTERNAL)), 1)
        self.assertIn("https://new.example/p/a-post", out)
        self.assertIn("https://new.example/p/other-post", out)
        self.assertIn("three", out)
        self.assertIn("https://example.net/r", out)

    def test_nothing_is_reported_when_there_are_no_links(self):
        out, report = rewrite("<p>just words</p>")
        self.assertEqual(out, "<p>just words</p>")
        self.assertEqual(report.summary(), "no links")

    def test_with_no_map_at_all_the_old_behaviour_is_what_happens(self):
        """Every existing caller passes no map and must be unaffected."""
        body = ('<p><a href="https://old-blog.example/a-post/">kept words</a> '
                '<a href="https://example.net/r">external</a></p>')
        out, report = rewrite(body, slugs={}, ids={})
        self.assertIn("kept words", out)
        self.assertNotIn("old-blog.example", out)
        self.assertIn("https://example.net/r", out)
        self.assertEqual(len(report.unmapped), 1)

    def test_an_unmapped_link_can_be_kept_instead_of_stripped(self):
        body = '<p><a href="/2015/03/gone/">words</a></p>'
        out, report = rewrite(body, discard_unmapped=False)
        self.assertEqual(out, body)
        self.assertEqual(len(report.unmapped), 1)

    def test_a_single_quoted_href_is_handled(self):
        body = "<p><a href='/a-post/'>one</a></p>"
        out, _r = rewrite(body)
        self.assertIn("https://new.example/p/a-post", out)

    def test_an_html_escaped_href_is_handled(self):
        body = '<p><a href="/?p=123&amp;utm_source=x">one</a></p>'
        out, report = rewrite(body)
        self.assertEqual(len(report.rewritten), 1, report.entries)
        self.assertIn("https://new.example/p/a-post", out)


class NoteTests(unittest.TestCase):

    def test_the_notes_name_the_link_and_say_why(self):
        body = ('<p><a href="/2015/03/gone/">the darkroom piece</a> '
                '<a href="/?p=999">and this</a></p>')
        _out, report = rewrite(body)
        lines = report.note_lines()
        self.assertEqual(len(lines), 2)
        self.assertTrue(any("/2015/03/gone/" in line for line in lines))
        self.assertTrue(any("the darkroom piece" in line for line in lines))
        self.assertTrue(any("999" in line for line in lines))

    def test_a_rewritten_link_needs_no_note(self):
        _out, report = rewrite('<p><a href="/a-post/">x</a></p>')
        self.assertEqual(report.note_lines(), [])

    def test_old_media_links_are_noted_too(self):
        _out, report = rewrite(
            '<p><a href="/wp-content/uploads/2015/a.jpg">the original</a></p>')
        lines = report.note_lines()
        self.assertEqual(len(lines), 1)
        self.assertIn("uploads", lines[0])

    def test_markup_inside_the_link_text_does_not_reach_the_note(self):
        _out, report = rewrite(
            '<p><a href="/2015/03/gone/"><em>words</em> <strong>here</strong></a></p>')
        line = report.note_lines()[0]
        self.assertIn("words here", line)
        self.assertNotIn("<em>", line)

    def test_the_summary_counts_every_kind(self):
        body = ('<p><a href="/a-post/">a</a><a href="/2015/03/gone/">b</a>'
                '<a href="https://x.net/">c</a><a href="#d">d</a>'
                '<a href="/wp-content/uploads/e.jpg">e</a></p>')
        _out, report = rewrite(body)
        summary = report.summary()
        for word in ("1 rewritten", "1 unmapped", "1 old media",
                     "1 external", "1 skipped"):
            self.assertIn(word, summary)


class FixUpPassTests(unittest.TestCase):
    """The post-migration pass: a link unplaceable at first import, because its
    target had not been migrated yet, is placed on the repair run once it has."""

    def test_a_link_unplaceable_at_import_is_placed_on_the_repair(self):
        body = '<p><a href="/2015/03/later-post/">the other one</a></p>'

        # First import: later-post has not landed yet.
        first_rows = [{"wp_id": 1, "wp_slug": "this-post",
                       "snap_url": "https://new.example/p/this-post"},
                      {"wp_id": 2, "wp_slug": "later-post", "snap_url": ""}]
        slugs, ids = links.build_maps(first_rows)
        out, report = links.rewrite_internal_links(body, SOURCE, slugs, ids)
        self.assertEqual(len(report.unmapped), 1)
        self.assertNotIn("href", out)
        self.assertEqual(len(report.note_lines()), 1)

        # Repair run, after the whole archive is across.
        later_rows = [{"wp_id": 1, "wp_slug": "this-post",
                       "snap_url": "https://new.example/p/this-post"},
                      {"wp_id": 2, "wp_slug": "later-post",
                       "snap_url": "https://new.example/p/later-post"}]
        slugs, ids = links.build_maps(later_rows)
        out, report = links.rewrite_internal_links(body, SOURCE, slugs, ids)
        self.assertEqual(len(report.rewritten), 1)
        self.assertIn('href="https://new.example/p/later-post"', out)
        self.assertEqual(report.note_lines(), [])


class WiringTests(unittest.TestCase):
    """The module is actually reached from the migration path."""

    def test_wp_source_calls_the_rewriter(self):
        from smackpress import wp_source
        body = ('<p><a href="https://old-blog.example/a-post/">words</a></p>')
        out, report = wp_source._discard_source_links(
            body, SOURCE, (SLUGS, IDS))
        self.assertIn("https://new.example/p/a-post", out)
        self.assertEqual(len(report.rewritten), 1)

    def test_wp_source_without_a_map_still_strips_but_reports(self):
        from smackpress import wp_source
        body = ('<p><a href="https://old-blog.example/a-post/">words</a></p>')
        out, report = wp_source._discard_source_links(body, SOURCE)
        self.assertIn("words", out)
        self.assertNotIn("href", out)
        self.assertEqual(len(report.unmapped), 1)

    def test_draft_from_wp_accepts_a_link_map_and_hands_back_the_report(self):
        import inspect
        from smackpress import wp_source
        signature = inspect.signature(wp_source.draft_from_wp)
        self.assertIn("link_map", signature.parameters)
        self.assertIn("on_links", signature.parameters)

    def test_the_repair_pass_builds_the_map_and_records_the_notes(self):
        source = (TOOL / "repair_existing.py").read_text(encoding="utf-8")
        self.assertIn("links.build_maps(db.get_all_posts())", source)
        self.assertIn("link_map=link_map", source)
        self.assertIn("db.add_note_lines(", source)

class NotesTableTests(unittest.TestCase):
    """add_note_lines against a REAL sqlite file, not a source assertion.

    set_note() overwrites. The link report runs on every import and on every
    repair, so an overwriting write would mean the latest run's findings
    replaced the previous run's -- and notes that cannot accumulate lose the
    thing they were written down for.
    """

    def setUp(self):
        import tempfile
        from smackpress import config
        self._dir = tempfile.mkdtemp(prefix="smackpress-notes-")
        self._old_dir, self._old_path = config.DB_DIR, config.DB_PATH
        config.DB_DIR = pathlib.Path(self._dir)
        config.DB_PATH = pathlib.Path(self._dir) / "smackpress.db"

    def tearDown(self):
        from smackpress import config
        config.DB_DIR, config.DB_PATH = self._old_dir, self._old_path

    def test_a_second_run_adds_to_the_notes_instead_of_replacing_them(self):
        from smackpress import db
        db.upsert_post(42, wp_slug="a-post", wp_title="A Post")
        self.assertEqual(db.add_note_lines(42, ["first finding"]), 1)
        self.assertEqual(db.add_note_lines(42, ["second finding"]), 1)
        notes = db.get_post(42)["notes"]
        self.assertIn("first finding", notes)
        self.assertIn("second finding", notes)

    def test_the_same_finding_twice_is_recorded_once(self):
        from smackpress import db
        db.upsert_post(43, wp_slug="b-post")
        db.add_note_lines(43, ["unmapped link /x/ — gone"])
        added = db.add_note_lines(43, ["unmapped link /x/ — gone"])
        self.assertEqual(added, 0)
        self.assertEqual(db.get_post(43)["notes"].count("unmapped link /x/"), 1)

    def test_an_existing_hand_written_note_is_not_destroyed(self):
        from smackpress import db
        db.upsert_post(44, wp_slug="c-post")
        db.set_note(44, "Sean: check the print date on this one")
        db.add_note_lines(44, ["unmapped link /y/ — gone"])
        notes = db.get_post(44)["notes"]
        self.assertIn("check the print date", notes)
        self.assertIn("unmapped link /y/", notes)

    def test_nothing_to_record_writes_nothing(self):
        from smackpress import db
        db.upsert_post(45, wp_slug="d-post")
        self.assertEqual(db.add_note_lines(45, []), 0)
        self.assertEqual(db.add_note_lines(45, ["", "   "]), 0)
        self.assertEqual((db.get_post(45)["notes"] or ""), "")

    def test_a_report_with_no_problems_leaves_the_notes_alone(self):
        from smackpress import db
        db.upsert_post(46, wp_slug="e-post")
        _out, report = rewrite('<p><a href="/a-post/">x</a></p>')
        self.assertEqual(db.add_note_lines(46, report.note_lines()), 0)
        self.assertEqual((db.get_post(46)["notes"] or ""), "")


if __name__ == "__main__":
    unittest.main(verbosity=2)

# ===== SNAPSMACK EOF =====
