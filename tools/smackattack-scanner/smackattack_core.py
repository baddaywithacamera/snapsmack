"""
SMACKATTACK SCANNER — smackattack_core.py
Pure (no-UI) scan orchestration, factored out of main.py's tkinter thread so both
the Windows tkinter app and the Linux Chrome/Blink port run the SAME scanning work.

This module does NOT import tkinter. It talks only to db.py / scanner.py and returns
plain data. A caller passes an optional `progress` callback; the tkinter app used to
poke UI widgets from inside the scan loop, and the Blink port collects log lines to
show in the page — both go through the same callback here.
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.


from datetime import datetime
from typing import Callable, Optional

import db as db_module
import scanner as scanner_module


def _noop(*_a, **_k):
    pass


def run_scan(conn, threshold: float, min_words: int,
             progress: Optional[Callable] = None) -> dict:
    """
    Run one full stylometric scan against `conn` and return a result dict.

    progress(event, **fields) is called as the scan proceeds so a UI can update.
    Events emitted (all optional to consume):
        log(message=str, kind=str)          — a line for the log pane
        authors(count=int)                  — authors-with-enough-text stat
        pairs(count=int)                    — total pairs to compare stat
        flags(count=int)                    — flags-issued stat
        percent(value=float)                — progress 0..100
        status(message=str, ok=bool|None)   — status-bar text

    Returns:
        {
          "authors": int, "pairs": int, "flags": int,
          "results": [ {author_key, matched_key, match_type, similarity,
                        display_name, email}, ... ],
          "log": [ {message, kind}, ... ],   # every line emitted, for replay
          "finished_at": "HH:MM:SS",
        }
    Raises on hard failure (e.g. DB error) so the caller can surface it.
    """
    progress = progress or _noop
    log_lines: list[dict] = []

    def log(message: str, kind: str = ""):
        log_lines.append({"message": message, "kind": kind})
        progress("log", message=message, kind=kind)

    log(f"Scan started at {datetime.now():%H:%M:%S}", "dim")

    # 1. Fetch authors
    progress("status", message="Fetching comments from database…")
    log("Fetching approved comments…", "dim")
    authors = db_module.fetch_comment_authors(conn, min_words)
    n = len(authors)
    progress("authors", count=n)
    log(f"Found {n} authors with enough text.", "ok")

    if n < 2:
        log("Not enough authors to compare. Add more comments and retry.", "warn")
        progress("percent", value=100.0)
        progress("status", message="Scan complete — not enough data.", ok=None)
        return {
            "authors": n, "pairs": 0, "flags": 0, "results": [],
            "log": log_lines, "finished_at": f"{datetime.now():%H:%M:%S}",
        }

    # 2. Compute vectors
    log("Computing stylometric vectors…", "dim")
    vectors: dict = {}
    meta: dict = {}
    for key, info in authors.items():
        vec = scanner_module.extract_vector(info["texts"])
        if vec:
            vectors[key] = vec
            meta[key] = info
    log(f"Vectors computed for {len(vectors)} authors.", "ok")

    # 3. Fetch banned vectors
    banned = db_module.fetch_banned_vectors(conn)
    log(f"Loaded {len(banned)} banned style profiles.", "dim")

    # 4. Compare all pairs
    keys = list(vectors.keys())
    n_keys = len(keys)
    total_pairs = (n_keys * (n_keys - 1)) // 2 + n_keys * len(banned)
    progress("pairs", count=total_pairs)
    log(f"Comparing {total_pairs} pairs…", "dim")

    flags: list[dict] = []
    done = 0

    # Peer comparisons
    for i in range(n_keys):
        for j in range(i + 1, n_keys):
            sim = scanner_module.cosine_similarity(vectors[keys[i]], vectors[keys[j]])
            if sim >= threshold:
                flags.append({
                    "author_key":   keys[i],
                    "matched_key":  keys[j],
                    "match_type":   "peer",
                    "similarity":   sim,
                    "display_name": meta[keys[i]].get("display_name", ""),
                    "email":        meta[keys[i]].get("email", ""),
                })
            done += 1
            if total_pairs and done % 500 == 0:
                progress("percent", value=(done / total_pairs) * 100)

    # Banned comparisons
    for key in keys:
        for bv in banned:
            sim = scanner_module.cosine_similarity(vectors[key], bv["vector"])
            if sim >= threshold:
                flags.append({
                    "author_key":   key,
                    "matched_key":  bv["fp_hash"],
                    "match_type":   "banned",
                    "similarity":   sim,
                    "display_name": meta[key].get("display_name", ""),
                    "email":        meta[key].get("email", ""),
                })
            done += 1

    progress("percent", value=100.0)
    progress("flags", count=len(flags))

    # 5. Store results
    if flags:
        log(f"Storing {len(flags)} flagged pair(s)…", "dim")
        db_module.store_scan_results(conn, flags)
        log(f"Done. {len(flags)} match(es) above {threshold:.0%} threshold. "
            f"View in Results tab.", "warn")
        progress("status",
                 message=f"Scan complete — {len(flags)} flag(s) | {total_pairs} pairs compared",
                 ok=False)
    else:
        log(f"Scan complete. No matches above {threshold:.0%} threshold. Clean.", "ok")
        progress("status",
                 message=f"Scan complete — 0 flag(s) | {total_pairs} pairs compared",
                 ok=True)

    finished = f"{datetime.now():%H:%M:%S}"
    progress("status_final", message=f"Scan finished at {finished}")

    return {
        "authors":     len(vectors),
        "pairs":       total_pairs,
        "flags":       len(flags),
        "results":     flags,
        "log":         log_lines,
        "finished_at": finished,
    }
# ===== SNAPSMACK EOF =====
