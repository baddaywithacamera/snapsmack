import os
import sys

import numpy as np
from PIL import Image
import pytest
from concurrent.futures import ThreadPoolExecutor

sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))

import editor_engine
import render_graph


def _document(tmp_path):
    yy, xx = np.mgrid[:96, :128]
    pixels = np.dstack((xx * 2, yy * 2, (xx + yy) % 256)).astype(np.uint8)
    source = tmp_path / "source.png"
    Image.fromarray(pixels, "RGB").save(source)
    document = editor_engine.EditorDocument(source)
    first = document.add_adjustment_layer("First")
    first["adjustments"]["contrast"] = 18
    second = document.add_adjustment_layer("Second")
    second["adjustments"]["vignette"] = -24
    return document


def test_graph_matches_reference_compositor_at_viewport_resolution(tmp_path):
    document = _document(tmp_path)
    graph = render_graph.RenderGraph(render_graph.RenderCache(32 * 1024 * 1024))
    actual = graph.render(document, (80, 80)).image.pixels
    expected = document._render_float_reference((80, 80)).pixels
    np.testing.assert_allclose(actual, expected, rtol=0, atol=0)


def test_changing_last_layer_reuses_source_base_and_earlier_prefix(tmp_path):
    document = _document(tmp_path)
    graph = render_graph.RenderGraph(render_graph.RenderCache(32 * 1024 * 1024))
    cold = graph.render(document, (80, 80))
    document.layers[-1]["opacity"] = .45
    document.notify_change()
    warm = graph.render(document, (80, 80))
    assert cold.cache_misses == 4  # source, base, two layer nodes
    assert warm.cache_hits == 3   # source, base, unchanged first layer
    assert warm.cache_misses == 1


def test_changing_base_invalidates_every_downstream_prefix(tmp_path):
    document = _document(tmp_path)
    graph = render_graph.RenderGraph(render_graph.RenderCache(32 * 1024 * 1024))
    graph.render(document, (80, 80))
    document.adjustments["exposure"] = .4
    document.notify_change()
    result = graph.render(document, (80, 80))
    assert result.cache_hits == 1  # decoded source only
    assert result.cache_misses == 3


def test_cache_is_byte_bounded_and_evicts_oldest_deterministically(tmp_path):
    document = _document(tmp_path)
    one_image = 80 * 60 * 3 * 4
    cache = render_graph.RenderCache(one_image * 2 + 128)
    graph = render_graph.RenderGraph(cache)
    graph.render(document, (80, 60))
    assert cache.bytes_used <= cache.byte_limit
    # The complete pass has more than two nodes, so the oldest source node was evicted.
    again = graph.render(document, (80, 60))
    assert again.cache_misses > 0


def test_stale_revision_is_rejected_before_displayable_result(tmp_path):
    document = _document(tmp_path)
    graph = render_graph.RenderGraph(render_graph.RenderCache())
    request = render_graph.RenderRequest(
        graph.document_id(document), document.revision, (80, 80))
    document.notify_change()
    with pytest.raises(render_graph.StaleRender):
        graph.render(document, (80, 80), request=request,
                     is_current=lambda item: item.revision == document.revision)


def test_document_caches_are_isolated(tmp_path):
    # Use separate files with identical pixels; path identity must still isolate them.
    (tmp_path / "a").mkdir(); (tmp_path / "b").mkdir()
    first = _document(tmp_path / "a")
    second = _document(tmp_path / "b")
    graph = render_graph.RenderGraph(render_graph.RenderCache())
    graph.render(first, (80, 80))
    result = graph.render(second, (80, 80))
    assert result.cache_hits == 0


def test_concurrent_requests_share_inflight_nodes(tmp_path, monkeypatch):
    document = _document(tmp_path)
    graph = render_graph.RenderGraph(render_graph.RenderCache())
    calls = {"decode": 0}
    original = document._graph_decode_source

    def counted(maximum):
        calls["decode"] += 1
        return original(maximum)

    monkeypatch.setattr(document, "_graph_decode_source", counted)
    with ThreadPoolExecutor(max_workers=2) as workers:
        results = list(workers.map(lambda _item: graph.render(document, (80, 80)), range(2)))
    assert calls["decode"] == 1
    np.testing.assert_array_equal(results[0].image.pixels, results[1].image.pixels)


# ===== SNAPSMACK EOF =====
