"""Tests for SS-1444: GYSS reads shared catalog counts/sync time and loads BROWSE LOCAL off UI thread."""

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.

import json
import os
import sys
import time

os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")

import pytest
from PIL import Image
from PySide6.QtWidgets import QApplication


@pytest.fixture(autouse=True)
def _sandbox_home(tmp_path, monkeypatch):
    sandbox = tmp_path / "snapsmack_home"
    sandbox.mkdir(parents=True, exist_ok=True)
    monkeypatch.setenv("SNAPSMACK_HOME", str(sandbox))

GYSS_DIR = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
SHARED_DIR = os.path.abspath(os.path.join(GYSS_DIR, "..", "_shared"))
for p in (GYSS_DIR, SHARED_DIR):
    if p not in sys.path:
        sys.path.insert(0, p)

import gyss_qt  # noqa: E402
import snap_home  # noqa: E402
import snap_library  # noqa: E402
import snap_profiles  # noqa: E402


def _app():
    return QApplication.instance() or QApplication([])


def test_library_status_reads_shared_catalog_when_gyss_cache_empty():
    app = _app()
    site_url = "https://catalog-only.example.invalid"
    snap_profiles.save({"site_url": site_url, "name": "Catalog Site", "api_key_gyss": "k"})

    snap_library.sync_from_sybu_data(site_url, {
        "site_mode": "smacktalk",
        "categories": [{"id": 1, "name": "Street"}],
        "albums": [],
        "tags": ["fog"],
        "titles": ["Test"],
    })
    src = os.path.join(snap_home.home(), "sample.jpg")
    Image.new("RGB", (200, 200), (20, 80, 40)).save(src)
    asset = snap_library.store_media(site_url, src)
    snap_library.record_post(site_url, {"post_id": 42, "post_type": "long", "title": "Hello", "body": ""}, assets=[asset])

    win = gyss_qt.Window()
    if win.site.count() > 1:
        win.site.setCurrentIndex(1)
    app.processEvents()
    text = win.lib_status.text()
    assert "1 photographs in shared catalog" in text
    assert "1 posts" in text
    assert "Last synced: never" not in text
    win.close()


def test_browse_local_loads_off_ui_thread_with_progress():
    app = _app()
    site_url = "https://async-browse.example.invalid"
    snap_profiles.save({"site_url": site_url, "name": "Async Site", "api_key_gyss": "k"})

    tdir = snap_home.site_thumbs_dir(site_url)
    thumb_file = os.path.join(tdir, "1.jpg")
    Image.new("RGB", (120, 120), (115, 240, 75)).save(thumb_file)
    sdir = snap_home.site_dir(site_url)
    with open(os.path.join(sdir, "index.json"), "w", encoding="utf-8") as f:
        json.dump({"images": {"1": {"id": 1, "title": "Green Frame", "thumb_file": "thumbs/1.jpg"}}}, f)
    with open(os.path.join(sdir, "meta.json"), "w", encoding="utf-8") as f:
        json.dump({"categories": [], "albums": [], "synced_at": "2026-11-18T22:00:00Z"}, f)

    win = gyss_qt.Window()
    if win.site.count() > 1:
        win.site.setCurrentIndex(1)
    progress_events = []
    win.worker.progress.connect(lambda t, n, m: progress_events.append((t, n, m)))

    win.browse_local()
    # Wait up to 3s for background thread + queued signal
    deadline = time.monotonic() + 3.0
    while win.busy and time.monotonic() < deadline:
        app.processEvents()
        time.sleep(0.02)
    app.processEvents()

    assert not win.busy
    assert win.photo_list.count() == 1
    assert win.photo_list.item(0).text() == "Green Frame"
    assert not win.photo_list.item(0).icon().isNull()
    assert any(evt[0] == "Loading local library" for evt in progress_events)
    win.close()

# ===== SNAPSMACK EOF =====
