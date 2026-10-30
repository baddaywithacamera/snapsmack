"""COLD STORAGE: search by name, and sort.

Sean, on a 3423-image shelf: "need to be able to sort by site and date range.
search by name." Name search and name/usage/insertion sort were authorised for
the next desktop build; date range and site grouping were NOT, and these tests
record why so nobody adds them later thinking they were forgotten.

DATE RANGE is absent because img_date is empty on every one of the 3480 rows
across all four catalogues on this machine. A date filter would match nothing.
SITE is absent because a site is a whole catalogue file, not a column on a row,
so there is nothing to sort within one view.

The two functions under test are deliberately STATIC on StorageMode, so the
filtering and ordering can be tested without constructing a Qt widget. The
grid wiring around them is not covered here and is NOT CHECKED.

Run:  .python-build/python.exe -m pytest tools/coldsnap/tests -q

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import os
import sys

BASE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if BASE not in sys.path:
    sys.path.insert(0, BASE)

os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")

from coldsnap_qt.cold_storage import StorageMode   # noqa: E402

FILTER = StorageMode.filter_assets
SORT = StorageMode.sort_assets
LABEL = StorageMode.asset_label


def a(asset_id, title="", orig_name="", used_in=0):
    return {"asset_id": asset_id, "title": title,
            "orig_name": orig_name, "used_in": used_in}


# ── the label, which is what he reads and what Name sorts on ────────────────

def test_the_label_prefers_a_title():
    assert LABEL(a("x", title="Rust on a door", orig_name="DSC_0001.jpg")) == "Rust on a door"


def test_the_label_falls_back_to_the_original_filename():
    """An image recorded by the upload path carries no title at all, and the
    filename is the only thing he could type."""
    assert LABEL(a("x", orig_name="DSC_0001.jpg")) == "DSC_0001.jpg"


def test_the_label_falls_back_to_a_short_asset_id_rather_than_nothing():
    assert LABEL(a("0123456789abcdef", title="", orig_name="")) == "0123456789ab"


# ── search ──────────────────────────────────────────────────────────────────

def test_an_empty_search_returns_everything_not_nothing():
    rows = [a("1", title="One"), a("2", title="Two")]
    assert len(FILTER(rows, "")) == 2
    assert len(FILTER(rows, "   ")) == 2
    assert len(FILTER(rows, None)) == 2


def test_search_matches_the_title_anywhere_ignoring_case():
    rows = [a("1", title="Rust on a Door"), a("2", title="Blue sky")]
    assert [r["asset_id"] for r in FILTER(rows, "ON A")] == ["1"]
    assert [r["asset_id"] for r in FILTER(rows, "rust")] == ["1"]
    assert [r["asset_id"] for r in FILTER(rows, "DOOR")] == ["1"]


def test_search_also_matches_the_original_filename():
    """The reason this is not title-only: 3423 of his images may have no
    title, and the filename is what he would actually type."""
    rows = [a("1", title="", orig_name="DSC_0042.jpg"),
            a("2", title="Blue sky", orig_name="IMG_9.jpg")]
    assert [r["asset_id"] for r in FILTER(rows, "dsc_0042")] == ["1"]
    assert [r["asset_id"] for r in FILTER(rows, "0042")] == ["1"]


def test_search_matches_either_field_not_both():
    rows = [a("1", title="Rust", orig_name="IMG_1.jpg")]
    assert len(FILTER(rows, "rust")) == 1
    assert len(FILTER(rows, "img_1")) == 1


def test_a_search_that_matches_nothing_returns_an_empty_list():
    rows = [a("1", title="Rust", orig_name="IMG_1.jpg")]
    assert FILTER(rows, "zebra") == []


def test_search_tolerates_missing_and_null_fields():
    """A row written before a column existed has None, not ''."""
    rows = [{"asset_id": "1"}, {"asset_id": "2", "title": None, "orig_name": None}]
    assert FILTER(rows, "anything") == []
    assert len(FILTER(rows, "")) == 2


def test_search_does_not_mutate_or_alias_the_input():
    rows = [a("1", title="One")]
    out = FILTER(rows, "")
    out.append(a("2", title="Two"))
    assert len(rows) == 1


# ── sort ────────────────────────────────────────────────────────────────────

def test_added_leaves_the_order_alone():
    """all_assets() already returns ORDER BY rowid DESC -- newest added first.
    Re-sorting it could only risk changing it."""
    rows = [a("3", title="C"), a("1", title="A"), a("2", title="B")]
    assert [r["asset_id"] for r in SORT(rows, "added")] == ["3", "1", "2"]


def test_an_unknown_sort_key_is_treated_as_added_rather_than_crashing():
    rows = [a("3", title="C"), a("1", title="A")]
    assert [r["asset_id"] for r in SORT(rows, "nonsense")] == ["3", "1"]


def test_name_sorts_by_label_ignoring_case():
    rows = [a("1", title="zebra"), a("2", title="Apple"), a("3", title="mango")]
    assert [r["title"] for r in SORT(rows, "name")] == ["Apple", "mango", "zebra"]


def test_name_sorts_untitled_images_by_their_filename():
    """Titled and untitled images sort together in one list, by whatever label
    they end up with.

    'A title' lands before 'a.jpg' because a space sorts before a dot, which is
    ordinary string ordering and not a special case. My first version of this
    test asserted the other order and was simply wrong about ASCII.
    """
    rows = [a("1", orig_name="b.jpg"), a("2", title="A title"), a("3", orig_name="a.jpg")]
    assert [LABEL(r) for r in SORT(rows, "name")] == ["A title", "a.jpg", "b.jpg"]


def test_most_used_puts_the_most_used_first():
    rows = [a("1", title="One", used_in=1), a("2", title="Two", used_in=9),
            a("3", title="Three", used_in=0)]
    assert [r["asset_id"] for r in SORT(rows, "used")] == ["2", "1", "3"]


def test_most_used_breaks_a_tie_by_name_so_the_order_is_stable():
    rows = [a("1", title="Zebra", used_in=2), a("2", title="Apple", used_in=2)]
    assert [r["title"] for r in SORT(rows, "used")] == ["Apple", "Zebra"]


def test_most_used_tolerates_a_missing_count():
    rows = [a("1", title="One"), {"asset_id": "2", "title": "Two", "used_in": None}]
    assert len(SORT(rows, "used")) == 2


def test_sort_does_not_mutate_the_input():
    rows = [a("2", title="B"), a("1", title="A")]
    SORT(rows, "name")
    assert [r["asset_id"] for r in rows] == ["2", "1"]


# ── the two that were deliberately NOT built ────────────────────────────────

def test_there_is_no_date_sort_key_and_that_is_on_purpose():
    """img_date is blank on every row in every catalogue on this machine, so a
    date control would filter against nothing. If someone adds one later, this
    test should fail and send them to read why first."""
    rows = [a("1", title="One"), a("2", title="Two")]
    for key in ("date", "img_date", "newest", "oldest"):
        assert [r["asset_id"] for r in SORT(rows, key)] == ["1", "2"], (
            "%r now sorts: if img_date is finally populated, say so in the "
            "changelog and in this test rather than quietly enabling it" % key)


def test_there_is_no_site_sort_because_a_site_is_a_whole_catalogue():
    """snap_library._db_path(site) is one SQLite file per site, so every row in
    view already belongs to the same site. 'Sort by site' needs a merged
    across-sites view, which is a different feature."""
    rows = [a("1", title="One"), a("2", title="Two")]
    assert [r["asset_id"] for r in SORT(rows, "site")] == ["1", "2"]


# ── runnable BOTH ways, because this directory is split down the middle ─────
#
# Of the twelve files in here, eleven are SCRIPTS: top-level code with a check()
# helper that prints "OK - N asserts" and must be run as
# `python tests/test_x.py`. pytest collects ZERO tests from them. So
# `pytest tools/coldsnap/tests` reports a tidy green number while most of the
# directory never ran -- the same shape as a test that reads a file the page
# never loads.
#
# This file uses test_ functions so pytest sees it, and carries the block below
# so running it as a script works too. Until the directory agrees on one
# convention, a file that only works under one runner is a file somebody will
# miss.
if __name__ == "__main__":
    _fns = [v for k, v in sorted(globals().items())
            if k.startswith("test_") and callable(v)]
    _failed = 0
    for _fn in _fns:
        try:
            _fn()
        except AssertionError as exc:
            _failed += 1
            print("FAIL %s: %s" % (_fn.__name__, str(exc).splitlines()[0][:100]))
    if _failed:
        print("FAILED %d of %d checks" % (_failed, len(_fns)))
        raise SystemExit(1)
    print("OK - %d checks passed" % len(_fns))

# ===== SNAPSMACK EOF =====
