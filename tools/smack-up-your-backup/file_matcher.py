"""
Smack Up Your Backup — file_matcher.py
Match local files to manifest entries using four strategies:
  1. Exact relative path
  2. Basename + size match
  3. Basename only (single candidate)
  4. AI semantic path scoring (optional — requires sentence-transformers)
Used by the restore engine to locate files in a backup package or local dir.
"""

import os
from typing import Dict, List, Optional

from manifest_reader import FileRecord, Manifest
import ai_matcher


class MatchResult:
    __slots__ = ("record", "local_path", "strategy", "confident", "ai_score")

    def __init__(self, record: FileRecord, local_path: str, strategy: str,
                 ai_score: Optional[float] = None):
        self.record     = record
        self.local_path = local_path
        self.strategy   = strategy   # "exact" | "basename+size" | "basename" |
                                     # "ai-confident" | "ai-plausible" | "ai-weak" | "unmatched"
        self.ai_score   = ai_score   # float 0-1 when strategy starts with "ai-", else None
        self.confident  = strategy in ("exact", "basename+size", "ai-confident")


def build_local_index(root_dir: str) -> Dict[str, List[str]]:
    """
    Walk root_dir and return {basename_lower: [full_abs_path, ...]}.
    Used for basename and basename+size lookups.
    """
    index: Dict[str, List[str]] = {}
    for dirpath, _, filenames in os.walk(root_dir):
        for fname in filenames:
            full = os.path.join(dirpath, fname)
            key  = fname.lower()
            index.setdefault(key, []).append(full)
    return index


def match_manifest_to_local(
    manifest: Manifest,
    local_root: str,
    use_ai: bool = True,
) -> Dict[str, MatchResult]:
    """
    For every file in the manifest, find the best local match.
    Returns {manifest_key: MatchResult}.

    When use_ai=True and sentence-transformers is installed, Strategy 4
    (AI semantic scoring) is attempted for any file that remains
    unmatched or has multiple ambiguous basename candidates after the
    three algorithmic strategies.
    """
    local_index = build_local_index(local_root)
    results: Dict[str, MatchResult] = {}
    ai_pending: Dict[str, tuple] = {}   # key → (record, rel, candidates)

    for key, record in manifest.files.items():
        if record.bundled:
            continue

        rel      = record.restores_to.replace("\\", "/")
        basename = os.path.basename(rel).lower()

        # Strategy 1: exact relative path
        exact_path = os.path.join(local_root, rel.replace("/", os.sep))
        if os.path.exists(exact_path):
            results[key] = MatchResult(record, exact_path, "exact")
            continue

        candidates = local_index.get(basename, [])

        # Strategy 2: basename + size (confident)
        size_matches = [p for p in candidates if os.path.getsize(p) == record.size]
        if len(size_matches) == 1:
            results[key] = MatchResult(record, size_matches[0], "basename+size")
            continue

        # Strategy 3: basename only — single unambiguous candidate
        if len(candidates) == 1:
            results[key] = MatchResult(record, candidates[0], "basename")
            continue

        # Ambiguous or unmatched — queue for AI if enabled
        if use_ai and ai_matcher.is_available():
            # For ambiguous basename (multiple size candidates or multiple
            # basename hits), pass all candidates; for truly unmatched
            # (no basename match) walk the full index so AI can find
            # semantically similar paths across the whole tree.
            pool = size_matches or candidates
            if not pool:
                pool = [p for paths in local_index.values() for p in paths]
            ai_pending[key] = (record, rel, pool)
        else:
            results[key] = MatchResult(record, "", "unmatched")

    # ── Strategy 4: AI semantic scoring (batch per-key) ──────────────────────
    for key, (record, rel, pool) in ai_pending.items():
        match = ai_matcher.best_ai_match(rel, pool)
        if match is not None:
            best_path, score, label = match
            results[key] = MatchResult(record, best_path, label, ai_score=score)
        else:
            results[key] = MatchResult(record, "", "unmatched")

    return results
