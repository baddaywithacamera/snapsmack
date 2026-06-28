"""
fix-your-batch-up — matcher.py
Two-stage image matching engine for locating Drive-upload originals.

Stage 1 — pHash pre-filter:
    Compute perceptual hashes for all candidates and pick the top N closest
    to the server image. Fast — handles 650 images in a few seconds.

Stage 2 — SIFT feature matching:
    Run SIFT keypoint matching on the pre-filtered candidates.
    A server image is a resized copy of its original, so they share strong,
    geometrically-consistent SIFT features. Two visually similar but
    different images will have weak, sparse correspondence.

Designed to run in worker processes via concurrent.futures.ProcessPoolExecutor.
match_one() must stay a module-level function to be picklable on Windows.
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.


import os

import cv2
import imagehash
from PIL import Image

# ── Tuning constants ──────────────────────────────────────────────────────────
PHASH_CANDIDATES = 10     # how many candidates pHash passes to SIFT
SIFT_RATIO       = 0.75   # Lowe's ratio test threshold
MIN_MATCHES      = 8      # fewer good matches → treat as no match
CONF_HIGH        = 0.82   # ≥ this → green (high confidence)
CONF_MED         = 0.60   # ≥ this → amber (review recommended)
                          # < CONF_MED → red (low confidence / no match)


# ── Hash helpers ─────────────────────────────────────────────────────────────

def phash_file(path: str):
    """Return pHash hex string for an image file, or None on failure."""
    try:
        return str(imagehash.phash(Image.open(path).convert('RGB')))
    except Exception:
        return None


# ── SIFT matching ─────────────────────────────────────────────────────────────

def _sift_score(path_server: str, path_orig: str) -> int:
    """
    Return the number of good SIFT matches between the server image and an
    original. Scales the original down to ≤1.5× the server image width so
    SIFT feature scales overlap well.
    """
    try:
        img_s = cv2.imread(path_server, cv2.IMREAD_GRAYSCALE)
        img_o = cv2.imread(path_orig,   cv2.IMREAD_GRAYSCALE)
        if img_s is None or img_o is None:
            return 0

        # Scale original down if it's much larger than the server copy
        h_s, w_s = img_s.shape
        h_o, w_o = img_o.shape
        if w_o > w_s * 1.5:
            scale = w_s / w_o
            img_o = cv2.resize(img_o,
                               (int(w_o * scale), int(h_o * scale)),
                               interpolation=cv2.INTER_AREA)

        sift    = cv2.SIFT_create()
        kp1, d1 = sift.detectAndCompute(img_s, None)
        kp2, d2 = sift.detectAndCompute(img_o, None)

        if d1 is None or d2 is None or len(d1) < 2 or len(d2) < 2:
            return 0

        flann   = cv2.FlannBasedMatcher(
            dict(algorithm=1, trees=5),   # FLANN_INDEX_KDTREE
            dict(checks=50),
        )
        matches = flann.knnMatch(d1, d2, k=2)
        good    = [m for m, n in matches if m.distance < SIFT_RATIO * n.distance]
        return len(good)
    except Exception:
        return 0


# ── Worker entry point ────────────────────────────────────────────────────────

def match_one(args: tuple) -> dict:
    """
    Match one server-side image against the pool of originals.

    args = (server_path, orig_pairs)
      server_path : str  — absolute path to the local FTP copy
      orig_pairs  : list of (orig_path: str, phash_hex: str | None)

    Returns a result dict:
      server_path : str
      match_path  : str | None
      confidence  : float   0.0–1.0
      match_count : int     SIFT good-match count for the winner
      candidates  : list[str]  runner-up paths (up to 4)
      label       : str     'high' | 'medium' | 'low' | 'none'
    """
    server_path, orig_pairs = args

    srv_hex = phash_file(server_path)
    if srv_hex is None:
        return _empty(server_path)

    srv_hash = imagehash.hex_to_hash(srv_hex)

    # ── Stage 1: pHash pre-filter ─────────────────────────────────────────
    ranked = sorted(
        [(srv_hash - imagehash.hex_to_hash(h), p)
         for p, h in orig_pairs if h is not None],
        key=lambda x: x[0],
    )
    candidates = [p for _, p in ranked[:PHASH_CANDIDATES]]

    if not candidates:
        return _empty(server_path)

    # ── Stage 2: SIFT scoring ─────────────────────────────────────────────
    scores = sorted(
        [(_sift_score(server_path, c), c) for c in candidates],
        key=lambda x: x[0],
        reverse=True,
    )

    best_n, best_path = scores[0]
    second_n          = scores[1][0] if len(scores) > 1 else 0

    if best_n < MIN_MATCHES:
        return {
            'server_path': server_path,
            'match_path':  None,
            'confidence':  0.0,
            'match_count': best_n,
            'candidates':  [p for _, p in scores[:5]],
            'label':       'none',
        }

    confidence = (best_n / (best_n + second_n)) if second_n > 0 else 1.0

    if confidence >= CONF_HIGH:
        label = 'high'
    elif confidence >= CONF_MED:
        label = 'medium'
    else:
        label = 'low'

    return {
        'server_path': server_path,
        'match_path':  best_path,
        'confidence':  confidence,
        'match_count': best_n,
        'candidates':  [p for _, p in scores[1:5]],
        'label':       label,
    }


def _empty(server_path: str) -> dict:
    return {
        'server_path': server_path,
        'match_path':  None,
        'confidence':  0.0,
        'match_count': 0,
        'candidates':  [],
        'label':       'none',
    }
# ===== SNAPSMACK EOF =====
