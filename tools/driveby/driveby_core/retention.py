"""Retention keep-rules and non-negotiable backup safety invariants for DRIVE BY."""

from dataclasses import dataclass
from typing import Dict, List, Set

from .models import BackupRecord


@dataclass
class KeepRule:
    keep_last_n: int = 2
    keep_one_per_month: bool = True


@dataclass
class PrunePlan:
    keep: List[BackupRecord]
    prune: List[BackupRecord]
    protected_ids: Set[str]
    bytes_recovered: int
    by_provider: Dict[str, int]


def build_prune_plan(records: List[BackupRecord], rule: KeepRule = None) -> PrunePlan:
    """Partition active backups into keep vs prune sets.

    Non-negotiable safety invariants:
    1. Never mark the newest backup per blog for deletion.
    2. Never mark the newest verified (or INSTANT REPLAY tested) backup per blog.
    3. Apply optional keep_last_n + keep_one_per_month per blog.
    """
    rule = rule or KeepRule()
    keep_n = max(1, int(rule.keep_last_n or 1))
    active = [r for r in records if not r.trashed]

    by_blog: Dict[str, List[BackupRecord]] = {}
    for rec in active:
        by_blog.setdefault(rec.blog, []).append(rec)

    keep_ids: Set[str] = set()
    protected_ids: Set[str] = set()

    for _blog, items in by_blog.items():
        # Sort newest first
        ordered = sorted(items, key=lambda r: (r.date_iso, r.filename, r.remote_id), reverse=True)
        if not ordered:
            continue

        # Invariant 1: newest backup per blog is always protected
        newest = ordered[0]
        keep_ids.add(newest.remote_id)
        protected_ids.add(newest.remote_id)

        # Invariant 2: newest verified or replayed backup per blog is always protected
        for rec in ordered:
            if rec.verified or rec.replayed:
                keep_ids.add(rec.remote_id)
                protected_ids.add(rec.remote_id)
                break

        # Keep last N
        for rec in ordered[:keep_n]:
            keep_ids.add(rec.remote_id)

        # Keep one (newest) per YYYY-MM month
        if rule.keep_one_per_month:
            seen_months: Set[str] = set()
            for rec in ordered:
                ym = (rec.date_iso or "")[:7]
                if len(ym) == 7 and ym not in seen_months:
                    seen_months.add(ym)
                    keep_ids.add(rec.remote_id)

    keep: List[BackupRecord] = []
    prune: List[BackupRecord] = []
    bytes_recovered = 0
    by_provider: Dict[str, int] = {}

    for rec in active:
        if rec.remote_id in keep_ids or rec.remote_id in protected_ids:
            keep.append(rec)
        else:
            prune.append(rec)
            sz = max(0, int(rec.size_bytes or 0))
            bytes_recovered += sz
            by_provider[rec.provider] = by_provider.get(rec.provider, 0) + sz

    return PrunePlan(
        keep=keep,
        prune=prune,
        protected_ids=protected_ids,
        bytes_recovered=bytes_recovered,
        by_provider=by_provider,
    )


# ===== SNAPSMACK EOF =====
