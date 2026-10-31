"""COLD STORAGE: pick one site, or all of them.

Sean, 30 October 2026: Cold Storage "is unusable without" a site filter -- he
could not show the images of one site only.

WHAT THE OLD TESTS SAID, AND WHY HALF OF IT WAS WRONG.
test_cold_storage_search_sort.py records that site was deliberately left out
because "a site is a whole catalogue file, not a column on a row, so there is
nothing to sort within one view". The first half is still true; the second half
was the mistake. You cannot SORT by site within one view, but you can CHOOSE
which catalogue the view reads, and you can read several and say which one each
row came from. That is what these tests cover.

THE SITE IS STRUCTURAL. There is no site column on assets. One SQLite
catalogue per host at shared_library/<host>/db/catalog.sqlite, so the directory
name IS the site. No schema change, no migration.

TWO THINGS THE REAL MACHINE TAUGHT THIS FILE.
  1. shared_library/ also holds auth/ and assets/ directories which are NOT
     sites. A picker built from directory names would offer two fake sites, so
     a site is defined here as a directory that HAS a catalogue file.
  2. There were eleven catalogues on the owner's machine when this was written,
     not the four an older comment assumed, holding 4,629 rows -- of which one
     site had 3,642. The counts are what make the picker usable, so they are
     part of what is returned and are tested.

The functions under test are STATIC on StorageMode so they can be exercised
without constructing a Qt widget. The grid wiring around them is NOT CHECKED
here.

Run:  .python-build/python.exe -m pytest tools/coldsnap/tests -q

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import os
import sys

BASE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if BASE not in sys.path:
    sys.path.insert(0, BASE)
SHARED = os.path.join(os.path.dirname(BASE), "_shared")
if SHARED not in sys.path:
    sys.path.insert(0, SHARED)

os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")

import pytest  # noqa: E402


@pytest.fixture()
def library(tmp_path, monkeypatch):
    """A scratch SnapSmack home with three real catalogues and two decoys.

    SNAPSMACK_HOME is the seam the store already provides; nothing here
    reaches the owner's real library.
    """
    monkeypatch.setenv("SNAPSMACK_HOME", str(tmp_path))
    # Drop the cached modules so they re-read the scratch root.
    for mod in ("snap_home", "snap_library", "coldsnap_qt.cold_storage"):
        sys.modules.pop(mod, None)
    import snap_home
    import snap_library

    def add(site, *asset_ids):
        for aid in asset_ids:
            snap_library.upsert_asset(site, {
                "asset_id": aid,
                "orig_name": aid + ".jpg",
                "title": aid.upper(),
            })

    add("https://big.example.test/", "b1", "b2", "b3")
    add("small.example.test", "s1")
    # A site connected but never synced: opening the catalogue creates it and
    # it holds nothing. It must still be offered, or he cannot tell the
    # difference between "no images here" and "this site does not exist".
    snap_library._connect("empty.example.test").close()

    # THE DECOYS. Real directories in the library root that are not sites.
    for decoy in ("auth", "assets"):
        os.makedirs(os.path.join(snap_home.shared_library(), decoy), exist_ok=True)

    return tmp_path


@pytest.fixture()
def mode(library):
    from coldsnap_qt.cold_storage import StorageMode
    return StorageMode


# -- the picker's contents -------------------------------------------------

def test_lists_every_catalogue_with_its_row_count(mode):
    got = {c["site"]: c["rows"] for c in mode.site_catalogues()}
    assert got == {
        "big.example.test": 3,
        "small.example.test": 1,
        "empty.example.test": 0,
    }


def test_a_directory_without_a_catalogue_is_not_a_site(mode):
    """auth/ and assets/ are real directories and must never be offered.

    This is the check that stops the picker being built from a directory
    listing.
    """
    sites = [c["site"] for c in mode.site_catalogues()]
    assert "auth" not in sites
    assert "assets" not in sites


def test_biggest_shelf_first(mode):
    """He is choosing where the photographs are, so the order is by size.

    Alphabetical would bury a 3,642-image site below an empty one.
    """
    rows = [c["rows"] for c in mode.site_catalogues()]
    assert rows == sorted(rows, reverse=True)


def test_an_empty_library_offers_nothing_rather_than_raising(tmp_path, monkeypatch):
    monkeypatch.setenv("SNAPSMACK_HOME", str(tmp_path / "nothing-here"))
    for m in ("snap_home", "snap_library", "coldsnap_qt.cold_storage"):
        sys.modules.pop(m, None)
    from coldsnap_qt.cold_storage import StorageMode
    assert StorageMode.site_catalogues() == []


# -- reading one site, or all of them --------------------------------------

def test_one_site_returns_only_its_own_rows(mode):
    got = mode.assets_for("big.example.test")
    assert sorted(a["asset_id"] for a in got) == ["b1", "b2", "b3"]


def test_every_row_says_which_site_it_came_from(mode):
    """Without this an ALL view cannot resolve a thumbnail or a media file,
    because both are stored per site."""
    for a in mode.assets_for("small.example.test"):
        assert a["site"] == "small.example.test"


def test_all_sites_returns_the_union_each_tagged(mode):
    got = mode.assets_for(mode.ALL_SITES)
    assert sorted(a["asset_id"] for a in got) == ["b1", "b2", "b3", "s1"]
    by_site = {}
    for a in got:
        by_site.setdefault(a["site"], []).append(a["asset_id"])
    assert sorted(by_site["big.example.test"]) == ["b1", "b2", "b3"]
    assert by_site["small.example.test"] == ["s1"]


def test_all_sites_is_not_the_same_as_one_site(mode):
    """A filter that quietly returns everything whatever it is asked is the
    defect being fixed, so prove the two answers differ."""
    assert len(mode.assets_for(mode.ALL_SITES)) > len(mode.assets_for("small.example.test"))


def test_a_site_with_no_catalogue_returns_nothing(mode):
    assert mode.assets_for("never-heard-of-it.example.test") == []


def test_a_url_is_accepted_as_well_as_a_host(mode):
    """The connected site arrives as a URL from the config; the picker hands
    back a host. Both must read the same catalogue, or choosing the site you
    are already on would show an empty shelf."""
    by_url = mode.assets_for("https://big.example.test/some/path")
    by_host = mode.assets_for("big.example.test")
    assert sorted(a["asset_id"] for a in by_url) == sorted(a["asset_id"] for a in by_host)
    assert len(by_url) == 3


# -- the existing search and sort still work on the tagged rows ------------

def test_search_and_sort_still_apply_across_an_all_view(mode):
    """The site filter composes with what is already there rather than
    replacing it. Searching an ALL view must narrow it, not reset it."""
    everything = mode.assets_for(mode.ALL_SITES)
    just_s = mode.filter_assets(everything, "s1")
    assert [a["asset_id"] for a in just_s] == ["s1"]
    ordered = mode.sort_assets(everything, "name")
    assert [a["asset_id"] for a in ordered] == ["b1", "b2", "b3", "s1"]

# ===== SNAPSMACK EOF =====
