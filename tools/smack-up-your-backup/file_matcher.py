"""
Smack Up Your Backup — file_matcher.py
Match local files to manifest entries using three strategies:
  1. Exact relative path
  2. Basename match
  3. Basename + size match
Used by the restore engine to locate files in a backup package or local dir.
"""

import os
from typing import Dict, List, Optional, Tuple

from manifest_reader import FileRecord, Manifest


class MatchResult:
    __slots__ = ("record", "local_path", "strategy", "confident")

    def __init__(self, record: FileRecord, local_path: str, strategy: str):
        self.record     = record
        self.local_path = local_path
        self.strategy   = strategy   # "exact" | "basename" | "basename+size" | "unmatched"
        self.confident  = strategy in ("exact", "basename+size")


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
) -> Dict[str, MatchResult]:
    """
    For every file in the manifest, find the best local match.
    Returns {manifest_key: MatchResult}.
    """
    local_index = build_local_index(local_root)
    results: Dict[str, MatchResult] = {}

    for key, record in manifest.files.items():
        if record.bundled:
            # database.sql — bundled in the kit, not a media file to match
            continue

        rel = record.restores_to.replace("\\", "/")
        basename = os.path.basename(rel).lower()

        # Strategy 1: exact relative path
        exact_path = os.path.join(local_root, rel.replace("/", os.sep))
        if os.path.exists(exact_path):
            results[key] = MatchResult(record, exact_path, "exact")
            continue

        candidates = local_index.get(basename, [])

        # Strategy 3: basename + size (more confident than bare basename)
        size_matches = [p for p in candidates if os.path.getsize(p) == record.size]
        if len(size_matches) == 1:
            results[key] = MatchResult(record, size_matches[0], "basename+size")
            continue

        # Strategy 2: basename only (single candidate)
        if len(candidates) == 1:
            results[key] = MatchResult(record, candidates[0], "basename")
            continue

        # Unmatched
        results[key] = MatchResult(record, "", "unmatched")

    return results
