"""Incremental, revision-safe render graph for SNAP SLAPPER.

The graph owns no editing policy.  It gives the existing float32 compositor
stable node identities, bounded deterministic caches, and stale-result guards.
Preview and export therefore differ only in requested resolution.
"""

from __future__ import annotations

from collections import OrderedDict
from dataclasses import dataclass
import hashlib
import json
import os
import threading
import time


def _json_default(value):
    if isinstance(value, bytes):
        return {"bytes_sha256": hashlib.sha256(value).hexdigest()}
    if isinstance(value, set):
        return sorted(value)
    raise TypeError(f"Cannot identify render input {type(value).__name__}")


def stable_identity(value) -> str:
    encoded = json.dumps(value, sort_keys=True, separators=(",", ":"),
                         ensure_ascii=True, default=_json_default).encode("utf-8")
    return hashlib.sha256(encoded).hexdigest()


def file_identity(path):
    absolute = os.path.normcase(os.path.abspath(os.fspath(path)))
    try:
        stat = os.stat(absolute)
        return absolute, stat.st_mtime_ns, stat.st_size
    except OSError:
        return absolute, 0, -1


@dataclass(frozen=True)
class RenderRequest:
    document_id: str
    revision: int
    maximum: tuple[int, int] | None
    priority: int = 0


@dataclass(frozen=True)
class RenderResult:
    request: RenderRequest
    image: object
    elapsed_ms: float
    cache_hits: int
    cache_misses: int


class StaleRender(RuntimeError):
    """Raised when work no longer belongs to the current document revision."""


class RenderCache:
    """Thread-safe byte-bounded LRU with deterministic oldest-use eviction."""

    def __init__(self, byte_limit=512 * 1024 * 1024):
        self.byte_limit = max(1, int(byte_limit))
        self._values = OrderedDict()
        self._bytes = 0
        self._lock = threading.RLock()
        self._inflight = {}
        self.hits = 0
        self.misses = 0

    @staticmethod
    def _size(value):
        pixels = getattr(value, "pixels", None)
        return int(getattr(pixels, "nbytes", 0))

    @property
    def bytes_used(self):
        with self._lock:
            return self._bytes

    def get(self, key):
        with self._lock:
            entry = self._values.get(key)
            if entry is None:
                self.misses += 1
                return None
            self.hits += 1
            self._values.move_to_end(key)
            return entry[0]

    def put(self, key, value):
        size = self._size(value)
        if size <= 0 or size > self.byte_limit:
            return value
        with self._lock:
            old = self._values.pop(key, None)
            if old:
                self._bytes -= old[1]
            self._values[key] = (value, size)
            self._bytes += size
            while self._bytes > self.byte_limit and self._values:
                _discarded_key, (_discarded, discarded_size) = self._values.popitem(last=False)
                self._bytes -= discarded_size
        return value

    def get_or_compute(self, key, compute):
        """Return one shared result when concurrent revisions request one node."""
        value = self.get(key)
        if value is not None:
            return value
        with self._lock:
            flight = self._inflight.get(key)
            if flight is None:
                flight = {"event": threading.Event(), "value": None, "error": None}
                self._inflight[key] = flight
                owner = True
            else:
                owner = False
        if owner:
            try:
                flight["value"] = compute()
                self.put(key, flight["value"])
            except BaseException as error:
                flight["error"] = error
            finally:
                with self._lock:
                    self._inflight.pop(key, None)
                    flight["event"].set()
        else:
            flight["event"].wait()
        if flight["error"] is not None:
            raise flight["error"]
        return flight["value"]

    def clear_document(self, document_id):
        with self._lock:
            doomed = [key for key in self._values if key[0] == document_id]
            for key in doomed:
                _value, size = self._values.pop(key)
                self._bytes -= size

    def clear(self):
        with self._lock:
            self._values.clear()
            self._bytes = 0


class RenderGraph:
    """Evaluate exact source/base/layer-prefix nodes for an EditorDocument."""

    def __init__(self, cache=None):
        self.cache = cache or RenderCache()

    @staticmethod
    def document_id(document):
        return stable_identity({
            "source": file_identity(document.source_path),
            "raw": file_identity(getattr(document, "raw_source_path", ""))
                   if getattr(document, "raw_source_path", "") else None,
        })

    @staticmethod
    def _resolution(maximum):
        return tuple(max(1, int(v)) for v in maximum) if maximum else None

    def render(self, document, maximum=None, *, request=None, is_current=None):
        started = time.perf_counter()
        maximum = self._resolution(maximum)
        request = request or RenderRequest(
            self.document_id(document), int(getattr(document, "revision", 0)), maximum)
        start_hits, start_misses = self.cache.hits, self.cache.misses

        def guard():
            if is_current is not None and not is_current(request):
                raise StaleRender(
                    f"Discarded revision {request.revision} for {request.document_id}")

        guard()
        source_key = (request.document_id, "source", maximum,
                      stable_identity(document._graph_source_identity()))
        image = self.cache.get_or_compute(
            source_key, lambda: document._graph_decode_source(maximum))

        base_identity = stable_identity(document._graph_base_identity())
        base_key = (request.document_id, "base", maximum, source_key[-1], base_identity)
        def render_base():
            guard()
            return document._graph_render_base(image)
        base = self.cache.get_or_compute(base_key, render_base)
        image = base
        dependency = base_key[-1]

        for position, layer in enumerate(document.layers):
            guard()
            layer_identity = stable_identity(document._graph_layer_identity(layer))
            node_key = (request.document_id, "layer", maximum, position,
                        dependency, layer_identity)
            prior = image
            cached = self.cache.get_or_compute(
                node_key,
                lambda prior=prior, layer=layer, position=position:
                document._graph_render_layer(prior, layer, position))
            image = cached
            dependency = stable_identity((dependency, layer_identity))

        guard()
        result = RenderResult(
            request, image, (time.perf_counter() - started) * 1000.0,
            self.cache.hits - start_hits, self.cache.misses - start_misses)
        _record_performance(result, self.cache)
        return result


_PERFORMANCE_LOCK = threading.Lock()


def _record_performance(result, cache):
    """Append opt-in timings from source or installed builds as JSON lines."""
    path = os.environ.get("SNAP_SLAPPER_PERF_LOG", "").strip()
    if not path:
        return
    record = {
        "document": result.request.document_id,
        "revision": result.request.revision,
        "maximum": result.request.maximum,
        "elapsed_ms": round(result.elapsed_ms, 3),
        "cache_hits": result.cache_hits,
        "cache_misses": result.cache_misses,
        "cache_bytes": cache.bytes_used,
        "timestamp": time.time(),
    }
    try:
        with _PERFORMANCE_LOCK:
            with open(path, "a", encoding="utf-8") as stream:
                stream.write(json.dumps(record, sort_keys=True) + "\n")
    except OSError:
        # Instrumentation must never risk a photograph or interrupt editing.
        pass


DEFAULT_GRAPH = RenderGraph()

# ===== SNAPSMACK EOF =====
