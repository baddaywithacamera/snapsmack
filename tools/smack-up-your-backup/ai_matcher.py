"""
Smack Up Your Backup — ai_matcher.py

Optional AI-assisted file matching using sentence-transformers.
Uses all-MiniLM-L6-v2 (≈90 MB, CPU-friendly) to compute semantic
similarity between manifest paths and candidate local paths when
the standard algorithmic strategies in file_matcher.py are uncertain.

INSTALL:
    pip install sentence-transformers

If sentence-transformers is not installed the module degrades
gracefully — all functions return None and callers fall back to
the algorithmic matcher.
"""

from __future__ import annotations

import os
from typing import Optional

# ── Availability check ────────────────────────────────────────────────────────

_MODEL_NAME  = "sentence-transformers/all-MiniLM-L6-v2"
_model       = None          # loaded lazily on first use
_available   = None          # None = not yet checked


def is_available() -> bool:
    """Return True if sentence-transformers is installed."""
    global _available
    if _available is None:
        try:
            import sentence_transformers  # noqa: F401
            _available = True
        except ImportError:
            _available = False
    return _available


def model_name() -> str:
    return _MODEL_NAME


# ── Model loading ─────────────────────────────────────────────────────────────

def _get_model():
    """Load and cache the model. Returns None if unavailable."""
    global _model
    if _model is not None:
        return _model
    if not is_available():
        return None
    try:
        from sentence_transformers import SentenceTransformer
        _model = SentenceTransformer(_MODEL_NAME)
    except Exception:
        _model = None
    return _model


# ── Core scoring ──────────────────────────────────────────────────────────────

def _path_to_text(path: str) -> str:
    """
    Convert a file path to a sentence-friendly string.
    Strips separators and extension so the model focuses on
    the meaningful words in the path.
    e.g. "img_uploads/2019/paris_cafe_001.jpg" → "img uploads 2019 paris cafe 001"
    """
    parts = path.replace("\\", "/").replace("_", " ").replace("-", " ")
    parts = os.path.splitext(parts)[0]          # drop extension
    parts = parts.replace("/", " ").strip()
    return parts


def score_path_pair(manifest_path: str, candidate_path: str) -> Optional[float]:
    """
    Return a cosine similarity score [0.0–1.0] between two file paths,
    or None if the model is unavailable.

    A score ≥ 0.80 is considered a confident match.
    A score ≥ 0.65 is considered a plausible match worth surfacing.
    Below 0.65 the paths are semantically dissimilar.
    """
    model = _get_model()
    if model is None:
        return None
    try:
        import numpy as np
        a = _path_to_text(manifest_path)
        b = _path_to_text(candidate_path)
        embs = model.encode([a, b], convert_to_numpy=True, normalize_embeddings=True)
        return float(np.dot(embs[0], embs[1]))
    except Exception:
        return None


def score_paths_batch(
    manifest_path: str,
    candidate_paths: list[str],
) -> Optional[list[tuple[str, float]]]:
    """
    Score multiple candidates against one manifest path in a single
    model call (faster than calling score_path_pair in a loop).
    Returns [(candidate_path, score), ...] sorted descending by score,
    or None if the model is unavailable.
    """
    model = _get_model()
    if model is None:
        return None
    if not candidate_paths:
        return []
    try:
        import numpy as np
        query  = _path_to_text(manifest_path)
        corpus = [_path_to_text(p) for p in candidate_paths]
        all_texts = [query] + corpus
        embs   = model.encode(all_texts, convert_to_numpy=True, normalize_embeddings=True)
        q_emb  = embs[0]
        c_embs = embs[1:]
        scores = [float(np.dot(q_emb, c)) for c in c_embs]
        pairs  = sorted(zip(candidate_paths, scores), key=lambda x: x[1], reverse=True)
        return pairs
    except Exception:
        return None


# ── High-level match helper ───────────────────────────────────────────────────

CONFIDENT_THRESHOLD  = 0.80
PLAUSIBLE_THRESHOLD  = 0.65


def best_ai_match(
    manifest_path: str,
    candidate_paths: list[str],
) -> Optional[tuple[str, float, str]]:
    """
    Given a manifest path and a list of local candidates that the
    algorithmic matcher couldn't resolve confidently, return the
    best AI-scored match as (path, score, confidence_label) or None.

    confidence_label is one of: "ai-confident" | "ai-plausible" | "ai-weak"
    Returns None if the model is unavailable or no candidates supplied.
    """
    if not candidate_paths:
        return None
    results = score_paths_batch(manifest_path, candidate_paths)
    if results is None:
        return None
    best_path, best_score = results[0]
    if best_score >= CONFIDENT_THRESHOLD:
        label = "ai-confident"
    elif best_score >= PLAUSIBLE_THRESHOLD:
        label = "ai-plausible"
    else:
        label = "ai-weak"
    return best_path, best_score, label


# ── Status string for UI ──────────────────────────────────────────────────────

def status_string() -> str:
    """Return a human-readable status line for the Settings tab."""
    if not is_available():
        return "Not installed  —  pip install sentence-transformers"
    model = _get_model()
    if model is None:
        return f"Installed but model failed to load ({_MODEL_NAME})"
    return f"Ready  —  {_MODEL_NAME}"
