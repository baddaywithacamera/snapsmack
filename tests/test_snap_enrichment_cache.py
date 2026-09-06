"""Shared enrichment cache contract tests.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
"""

import importlib
import os
import sys


SHARED = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "tools", "_shared"))
if SHARED not in sys.path:
    sys.path.insert(0, SHARED)


def _modules(tmp_path, monkeypatch):
    monkeypatch.setenv("SNAPSMACK_HOME", str(tmp_path))
    import snap_home
    import snap_enrichment_cache
    importlib.reload(snap_home)
    return importlib.reload(snap_enrichment_cache)


def test_cache_is_content_and_domain_scoped(tmp_path, monkeypatch):
    cache = _modules(tmp_path, monkeypatch)
    image = tmp_path / "photo.jpg"
    image.write_bytes(b"same pixels for test")
    digest = cache.image_sha256(str(image))
    prompt = cache.prompt_sha256("full bundle v1")
    cache.put("https://one.example", digest, "one.example", "model", prompt,
              {"title": "One"}, ttl_days=90)
    assert cache.get("https://one.example", digest, "one.example", "model", prompt)["bundle"]["title"] == "One"
    assert cache.get("https://two.example", digest, "two.example", "model", prompt) is None


def test_remote_change_reports_collision_when_local_is_dirty(tmp_path, monkeypatch):
    cache = _modules(tmp_path, monkeypatch)
    digest = "a" * 64
    prompt = "b" * 64
    local = cache.put("https://one.example", digest, "one.example", "model", prompt,
                      {"title": "Mine"})
    remote = dict(local)
    remote.update({"revision": 2, "bundle": {"title": "Theirs"}})
    merged = cache.merge_remote("https://one.example", remote)
    assert merged["status"] == "collision"


def test_sensitive_result_gets_nsfw_tag():
    import snap_enrich
    result = snap_enrich.parse_response("SENSITIVE: yes\nTAGS: #portrait")
    assert result["sensitive"] == "yes"
    assert "#nsfw" in result["tags"].split()

# ===== SNAPSMACK EOF =====
