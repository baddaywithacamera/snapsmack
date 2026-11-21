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


# ─── SS-1457: SMACK CENTRAL desktop stylometric analysis & client ─────────────

import json
import urllib.error
import urllib.parse
import urllib.request


class _RefuseRedirect(urllib.request.HTTPRedirectHandler):
    """Refuse HTTP redirects on requests carrying Bearer credentials (SECAUDIT 053/054)."""
    def redirect_request(self, r, fp, code, msg, headers, newurl):
        raise urllib.error.HTTPError(
            r.full_url, code, "redirect refused: request carried a credential", headers, fp)


def _normalize_pair(a: int, b: int) -> tuple[int, int]:
    ia, ib = int(a), int(b)
    return (ia, ib) if ia <= ib else (ib, ia)


def _parse_vector(raw) -> Optional[list[float]]:
    if isinstance(raw, str):
        try:
            raw = json.loads(raw)
        except Exception:
            return None
    if not isinstance(raw, (list, tuple)) or len(raw) != scanner_module.DIMENSIONS:
        return None
    try:
        vals = [float(v) for v in raw]
    except (TypeError, ValueError):
        return None
    if any(v < 0.0 or v > 1.0 for v in vals):
        return None
    return vals


def analyze_central_vectors(
    vector_rows: list[dict],
    dismissed_pairs: Optional[list] = None,
    *,
    edge_threshold: float = scanner_module.CENTRAL_CLUSTER_THRESHOLD,
    include_green: bool = False,
    progress: Optional[Callable] = None,
) -> dict:
    """
    Deterministic desktop port of ste_style_run_analysis() (sc-enemy-admin.php:115-245).
    Zero server compute, zero AI model calls, zero raw comment text.

    Inputs:
      vector_rows: list of dicts from GET ?route=admin/style/vectors, each with
        fingerprint_id (int), vector (25-float list or JSON str), ban_type,
        ban_value, score, colour_level, word_count, comment_count, reporting_sites.
      dismissed_pairs: list of [fp_a, fp_b] false-positive pairs from sc_settings.

    Returns:
      {
        "vector_count": int,
        "pairs_compared": int,
        "clusters": [
          {
            "cluster_id": int,        # deterministic min fingerprint_id in cluster
            "members": [dict, ...],   # sorted by fingerprint_id
            "member_ids": "1,2,...",
            "edges": [{"a": int, "b": int, "sim": float}, ...],
            "max_sim": float,
            "confidence": "STRONG MATCH" | "LIKELY MATCH" | "POSSIBLE MATCH",
            "target_colour": "yellow" | "orange" | "red" | "black",
          }, ...
        ],
        "outcomes": [
          {
            "fingerprint_id": int,
            "cluster_id": int,
            "linked_count": int,
            "max_sim": float,
            "style_confidence": str,
            "target_colour": str,
          }, ...
        ],
      }
    """
    progress = progress or _noop
    dismissed_set: set[tuple[int, int]] = set()
    for pair in (dismissed_pairs or []):
        if isinstance(pair, (list, tuple)) and len(pair) == 2:
            try:
                dismissed_set.add(_normalize_pair(int(pair[0]), int(pair[1])))
            except (TypeError, ValueError):
                continue

    fps: dict[int, dict] = {}
    for row in (vector_rows or []):
        try:
            fp_id = int(row["fingerprint_id"])
        except (KeyError, TypeError, ValueError):
            continue
        colour = str(row.get("colour_level") or "green").lower()
        if not include_green and colour == "green":
            continue
        vec = _parse_vector(row.get("vector"))
        if vec is None:
            continue
        entry = dict(row)
        entry["fingerprint_id"] = fp_id
        entry["colour_level"] = colour
        entry["score"] = float(row.get("score") or 0.0)
        entry["vec"] = vec
        fps[fp_id] = entry

    ids = sorted(fps.keys())
    n = len(ids)
    progress("vectors", count=n)
    if n < 2:
        progress("percent", value=100.0)
        return {
            "vector_count": n,
            "pairs_compared": 0,
            "clusters": [],
            "outcomes": [],
        }

    parent: dict[int, int] = {i: i for i in ids}

    def find(x: int) -> int:
        root = x
        while parent[root] != root:
            root = parent[root]
        while parent[x] != root:
            nxt = parent[x]
            parent[x] = root
            x = nxt
        return root

    def union(a: int, b: int) -> None:
        ra, rb = find(a), find(b)
        if ra != rb:
            if ra < rb:
                parent[rb] = ra
            else:
                parent[ra] = rb

    total_pairs = (n * (n - 1)) // 2
    progress("pairs", count=total_pairs)
    edges: dict[tuple[int, int], float] = {}
    done = 0

    for i in range(n - 1):
        id_a = ids[i]
        v_a = fps[id_a]["vec"]
        for j in range(i + 1, n):
            id_b = ids[j]
            done += 1
            pair = _normalize_pair(id_a, id_b)
            if pair in dismissed_set:
                continue
            sim = scanner_module.cosine_similarity(v_a, fps[id_b]["vec"])
            if sim >= edge_threshold:
                union(id_a, id_b)
                edges[pair] = sim
            if total_pairs and done % 1000 == 0:
                progress("percent", value=(done / total_pairs) * 100.0)

    groups: dict[int, list[int]] = {}
    for fp_id in ids:
        root = find(fp_id)
        groups.setdefault(root, []).append(fp_id)

    clusters: list[dict] = []
    outcomes: list[dict] = []
    colour_order = scanner_module.STE_COLOUR_ORDER

    for root, members in groups.items():
        if len(members) < 2:
            continue
        members.sort()
        cluster_id = members[0]
        max_sim = 0.0
        cluster_edges: list[dict] = []
        per_member_max: dict[int, float] = {m: 0.0 for m in members}

        for i in range(len(members) - 1):
            for j in range(i + 1, len(members)):
                a, b = members[i], members[j]
                sim = edges.get((a, b), 0.0)
                if sim > 0.0:
                    cluster_edges.append({"a": a, "b": b, "sim": sim})
                    if sim > max_sim:
                        max_sim = sim
                    if sim > per_member_max[a]:
                        per_member_max[a] = sim
                    if sim > per_member_max[b]:
                        per_member_max[b] = sim

        confidence = scanner_module.central_confidence(max_sim)
        target_colour = "yellow"
        for mid in members:
            col = fps[mid]["colour_level"]
            if colour_order.get(col, 0) > colour_order.get(target_colour, 0):
                target_colour = col

        member_rows = []
        for mid in members:
            m_copy = {k: v for k, v in fps[mid].items() if k != "vec"}
            member_rows.append(m_copy)
            m_sim = per_member_max[mid] or max_sim
            outcomes.append({
                "fingerprint_id":   mid,
                "cluster_id":       cluster_id,
                "linked_count":     len(members) - 1,
                "max_sim":          m_sim,
                "style_confidence": scanner_module.central_confidence(m_sim),
                "target_colour":    target_colour,
            })

        clusters.append({
            "cluster_id":    cluster_id,
            "members":       member_rows,
            "member_ids":    ",".join(str(m) for m in members),
            "edges":         cluster_edges,
            "max_sim":       max_sim,
            "confidence":    confidence,
            "target_colour": target_colour,
        })

    clusters.sort(key=lambda c: (-c["max_sim"], c["cluster_id"]))
    outcomes.sort(key=lambda o: (-o["max_sim"], o["fingerprint_id"]))
    progress("percent", value=100.0)
    progress("clusters", count=len(clusters))

    return {
        "vector_count":   n,
        "pairs_compared": total_pairs,
        "clusters":       clusters,
        "outcomes":       outcomes,
    }


def _build_route_url(base_url: str, route: str, params: Optional[dict] = None) -> str:
    base = (base_url or "").strip()
    if not base:
        raise ValueError("SMACK CENTRAL API URL is required.")
    parsed = urllib.parse.urlsplit(base)
    if parsed.scheme not in ("https", "http") or not parsed.netloc:
        raise ValueError(f"Invalid SMACK CENTRAL API URL: {base!r}")
    q = dict(urllib.parse.parse_qsl(parsed.query, keep_blank_values=True))
    q["route"] = route
    if params:
        for k, v in params.items():
            if v is not None and v != "":
                q[k] = str(v)
    new_query = urllib.parse.urlencode(q)
    return urllib.parse.urlunsplit((parsed.scheme, parsed.netloc, parsed.path, new_query, ""))


def pull_central_vectors(
    api_url: str,
    admin_token: str,
    *,
    page_limit: int = 500,
    timeout: int = 20,
    opener=None,
) -> dict:
    """
    Pull all non-expired style vectors and dismissed pairs from SMACK CENTRAL via
    GET ?route=admin/style/vectors with deterministic cursor pagination (after_id).
    """
    token = (admin_token or "").strip()
    if not token:
        raise ValueError("Admin token is required (stored in OS credential store).")
    opener = opener or urllib.request.build_opener(_RefuseRedirect)

    all_vectors: list[dict] = []
    dismissed: list = []
    after_id = 0
    seen_cursors: set[int] = set()

    while True:
        params = {"limit": page_limit}
        if after_id > 0:
            params["after_id"] = after_id
        url = _build_route_url(api_url, "admin/style/vectors", params)
        req = urllib.request.Request(
            url,
            method="GET",
            headers={
                "Accept": "application/json",
                "Authorization": f"Bearer {token}",
            },
        )
        with opener.open(req, timeout=timeout) as resp:
            body = json.loads(resp.read().decode("utf-8"))
        if not isinstance(body, dict) or not body.get("ok"):
            err = body.get("error", "unknown error") if isinstance(body, dict) else "invalid JSON response"
            raise RuntimeError(f"SMACK CENTRAL pull failed: {err}")

        batch = body.get("vectors") or []
        if not isinstance(batch, list):
            raise RuntimeError("SMACK CENTRAL pull returned malformed vectors list.")
        all_vectors.extend(batch)
        if "dismissed_pairs" in body and isinstance(body["dismissed_pairs"], list):
            dismissed = body["dismissed_pairs"]

        has_more = bool(body.get("has_more"))
        next_after = body.get("next_after_id")
        if not has_more or not batch:
            break
        if next_after is None:
            try:
                next_after = max(int(r.get("fingerprint_id", 0)) for r in batch)
            except (TypeError, ValueError):
                break
        next_after = int(next_after)
        if next_after <= after_id or next_after in seen_cursors:
            break
        seen_cursors.add(next_after)
        after_id = next_after

    return {
        "vectors":         all_vectors,
        "dismissed_pairs": dismissed,
    }


def upload_central_results(
    api_url: str,
    admin_token: str,
    *,
    clusters: Optional[list[dict]] = None,
    outcomes: Optional[list[dict]] = None,
    dismiss_pairs: Optional[list] = None,
    escalations: Optional[list[dict]] = None,
    timeout: int = 20,
    opener=None,
) -> dict:
    """
    Upload computed stylometric results and operator decisions (dismissals /
    escalations) to SMACK CENTRAL via POST ?route=admin/style/results.
    Never transmits keys or raw text.
    """
    token = (admin_token or "").strip()
    if not token:
        raise ValueError("Admin token is required (stored in OS credential store).")
    opener = opener or urllib.request.build_opener(_RefuseRedirect)

    clean_dismiss: list[list[int]] = []
    for pair in (dismiss_pairs or []):
        if isinstance(pair, (list, tuple)) and len(pair) == 2:
            a, b = _normalize_pair(int(pair[0]), int(pair[1]))
            clean_dismiss.append([a, b])

    payload = {
        "clusters":      list(clusters or []),
        "outcomes":      list(outcomes or []),
        "dismiss_pairs": clean_dismiss,
        "escalations":   list(escalations or []),
    }
    data = json.dumps(payload).encode("utf-8")
    url = _build_route_url(api_url, "admin/style/results")
    req = urllib.request.Request(
        url,
        data=data,
        method="POST",
        headers={
            "Content-Type":  "application/json",
            "Accept":        "application/json",
            "Authorization": f"Bearer {token}",
        },
    )
    with opener.open(req, timeout=timeout) as resp:
        body = json.loads(resp.read().decode("utf-8"))
    if not isinstance(body, dict) or not body.get("ok"):
        err = body.get("error", "unknown error") if isinstance(body, dict) else "invalid JSON response"
        raise RuntimeError(f"SMACK CENTRAL upload failed: {err}")
    return body
# ===== SNAPSMACK EOF =====
