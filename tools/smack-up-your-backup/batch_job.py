"""Smack Up Your Backup — batch_job.py

Atomic persistent fleet batch-job receipts for multi-site backup resume (SS-1404).

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""

from datetime import datetime, timezone
import json
import os
import tempfile
import uuid
from typing import Any, Dict, List, Optional, Tuple


_SECRET_KEYS = frozenset({
    "api_key", "password", "pass", "secret", "client_secret",
    "refresh_token", "access_token", "b2_app_key", "app_key", "token",
})

TERMINAL_JOB_STATUSES = frozenset({"completed", "completed_with_failures", "abandoned"})


def _utc_now() -> str:
    return datetime.now(timezone.utc).isoformat()


def default_jobs_dir() -> str:
    base = os.environ.get("LOCALAPPDATA") or os.path.expanduser("~")
    return os.path.join(base, "SnapSmack", "suyb_batch_jobs")


def _atomic_write_json(path: str, payload: Dict[str, Any]) -> None:
    os.makedirs(os.path.dirname(path), exist_ok=True)
    fd, tmp_path = tempfile.mkstemp(dir=os.path.dirname(path), prefix=".batch_", suffix=".tmp")
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as fh:
            json.dump(payload, fh, indent=2)
            fh.flush()
            os.fsync(fh.fileno())
        os.replace(tmp_path, path)
    finally:
        if os.path.exists(tmp_path):
            try:
                os.remove(tmp_path)
            except OSError:
                pass


def sanitize_options(options: Optional[Dict[str, Any]]) -> Dict[str, Any]:
    """Strip any credential-like fields before persisting batch options."""
    clean: Dict[str, Any] = {}
    for k, v in (options or {}).items():
        kl = str(k).lower()
        if kl in _SECRET_KEYS or any(s in kl for s in ("password", "secret", "token", "api_key")):
            continue
        if isinstance(v, (bool, int, float, str)) or v is None:
            clean[str(k)] = v
    return clean


class BatchJob:
    """Atomic multi-site backup batch receipt."""

    def __init__(self, path: str, data: Dict[str, Any]):
        self.path = path
        self.data = data

    @property
    def job_id(self) -> str:
        return str(self.data.get("job_id", ""))

    @property
    def status(self) -> str:
        return str(self.data.get("status", ""))

    @property
    def is_nonterminal(self) -> bool:
        return self.status not in TERMINAL_JOB_STATUSES

    @classmethod
    def create(
        cls,
        sites: List[str],
        options: Optional[Dict[str, Any]] = None,
        jobs_dir: Optional[str] = None,
    ) -> "BatchJob":
        jdir = jobs_dir or default_jobs_dir()
        now = _utc_now()
        job_id = f"batch-{datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%SZ')}-{uuid.uuid4().hex[:6]}"
        path = os.path.join(jdir, f"{job_id}.json")
        ordered_sites: Dict[str, Dict[str, Any]] = {}
        for idx, name in enumerate(sites):
            ordered_sites[name] = {
                "order": idx,
                "status": "waiting",
                "started_at": None,
                "finished_at": None,
                "zip_verified": False,
                "cloud_verified": False,
                "cloud_required": bool((options or {}).get("cloud_required", False)),
                "evidence": {},
                "error": "",
            }
        data: Dict[str, Any] = {
            "job_id": job_id,
            "created_at": now,
            "updated_at": now,
            "status": "running",
            "current_site": sites[0] if sites else "",
            "options": sanitize_options(options),
            "sites": ordered_sites,
        }
        job = cls(path, data)
        job.save()
        return job

    @classmethod
    def load(cls, path: str) -> Optional["BatchJob"]:
        try:
            with open(path, encoding="utf-8") as fh:
                data = json.load(fh)
            if not isinstance(data, dict) or "job_id" not in data or "sites" not in data:
                return None
            return cls(path, data)
        except Exception:
            return None

    def save(self) -> None:
        self.data["updated_at"] = _utc_now()
        _atomic_write_json(self.path, self.data)

    def mark_site_running(self, site: str) -> None:
        entry = self.data["sites"].setdefault(site, {"order": len(self.data["sites"])})
        entry["status"] = "running"
        entry["started_at"] = entry.get("started_at") or _utc_now()
        self.data["current_site"] = site
        self.data["status"] = "running"
        self.save()

    def mark_site_result(
        self,
        site: str,
        *,
        zip_verified: bool,
        cloud_required: bool,
        cloud_verified: bool,
        cancelled: bool = False,
        evidence: Optional[Dict[str, Any]] = None,
        error: str = "",
    ) -> str:
        """Record site outcome atomically.

        A site is 'completed' ONLY when zip_verified is True AND (not cloud_required or cloud_verified).
        A ZIP that merely exists on disk without verify+cloud completion is NOT complete.
        """
        entry = self.data["sites"].setdefault(site, {"order": len(self.data["sites"])})
        entry["zip_verified"] = bool(zip_verified)
        entry["cloud_required"] = bool(cloud_required)
        entry["cloud_verified"] = bool(cloud_verified)
        entry["evidence"] = sanitize_options(evidence)
        entry["error"] = str(error or "")

        fully_complete = bool(zip_verified) and (not cloud_required or bool(cloud_verified))
        if cancelled and not fully_complete:
            entry["status"] = "interrupted"
        elif fully_complete:
            entry["status"] = "completed"
            entry["finished_at"] = _utc_now()
        else:
            entry["status"] = "failed"
            entry["finished_at"] = _utc_now()

        self.save()
        return entry["status"]

    def finalize_if_done(self) -> str:
        sites = self.data.get("sites", {})
        statuses = [str(meta.get("status", "")) for meta in sites.values()]
        if not statuses or any(st in ("waiting", "running", "interrupted") for st in statuses):
            return self.status
        self.data["status"] = "completed" if all(st == "completed" for st in statuses) else "completed_with_failures"
        self.data["current_site"] = ""
        self.save()
        return self.data["status"]

    def abandon(self) -> None:
        self.data["status"] = "abandoned"
        self.save()

    def resume_plan(self) -> Tuple[List[str], List[str]]:
        """Return (completed_sites, remaining_ordered_sites)."""
        ordered = sorted(self.data.get("sites", {}).items(), key=lambda kv: int(kv[1].get("order", 0)))
        completed = [name for name, meta in ordered if meta.get("status") == "completed"]
        remaining = [name for name, meta in ordered if meta.get("status") != "completed"]
        return completed, remaining


def find_nonterminal_jobs(jobs_dir: Optional[str] = None) -> List[BatchJob]:
    jdir = jobs_dir or default_jobs_dir()
    if not os.path.isdir(jdir):
        return []
    out: List[BatchJob] = []
    for fname in sorted(os.listdir(jdir), reverse=True):
        if not fname.endswith(".json"):
            continue
        job = BatchJob.load(os.path.join(jdir, fname))
        if job is not None and job.is_nonterminal:
            out.append(job)
    return out


# ===== SNAPSMACK EOF =====
