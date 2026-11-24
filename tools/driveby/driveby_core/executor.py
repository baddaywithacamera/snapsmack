"""Fail-closed prune executor with dry-run, provider auth checks, fresh TOTP, and durable audit."""

from dataclasses import dataclass, field
from typing import Callable, Dict, List, Optional
import uuid

from .audit_log import append_audit_event, preflight_audit_log
from .auth_state import TotpStepUpGuard, get_drive_auth_state
from .models import BackupRecord
from .providers import RECOVERY_WINDOWS_DAYS
from .retention import PrunePlan


@dataclass
class PruneExecutionResult:
    ok: bool
    dry_run: bool
    batch_id: str
    attempted: int
    succeeded: int
    failed: int
    bytes_recovered: int
    errors: List[str] = field(default_factory=list)
    message: str = ""


def execute_prune_plan(
    plan: PrunePlan,
    provider_map: Dict[str, object],
    *,
    cred_store=None,
    vault_error: str = "",
    totp_code: str = "",
    totp_guard: Optional[TotpStepUpGuard] = None,
    stepup_verifier: Optional[Callable[[str], object]] = None,
    log_path: Optional[str] = None,
    dry_run: bool = False,
) -> PruneExecutionResult:
    """Execute a PrunePlan safely.

    Invariants:
    - dry_run=True performs ZERO provider mutations and ZERO audit writes.
    - Live execution fails closed unless vault is available, every affected provider is
      authenticated, fresh TOTP verifies, and the audit log is preflight-writable.
    - Durable audit 'intent' is written BEFORE each provider mutation, and 'result' AFTER.
    - Partial failures are tracked and reported honestly.
    """
    batch_id = uuid.uuid4().hex[:12]
    items: List[BackupRecord] = list(plan.prune)

    if not items:
        return PruneExecutionResult(
            ok=True,
            dry_run=dry_run,
            batch_id=batch_id,
            attempted=0,
            succeeded=0,
            failed=0,
            bytes_recovered=0,
            message="Nothing to prune.",
        )

    if dry_run:
        return PruneExecutionResult(
            ok=True,
            dry_run=True,
            batch_id=batch_id,
            attempted=len(items),
            succeeded=0,
            failed=0,
            bytes_recovered=plan.bytes_recovered,
            message=f"Dry-run: {len(items)} backup(s) would be moved to Trash/hidden; 0 mutated.",
        )

    # 1. Fail closed on vault error
    if vault_error or cred_store is None:
        msg = vault_error or "Protected credential vault (snap_creds) is unavailable."
        return PruneExecutionResult(
            ok=False,
            dry_run=False,
            batch_id=batch_id,
            attempted=0,
            succeeded=0,
            failed=len(items),
            bytes_recovered=0,
            errors=[msg],
            message=msg,
        )

    # 2. Fail closed unless EVERY affected provider is authenticated
    affected_providers = sorted({r.provider for r in items})
    drive_state = get_drive_auth_state(cred_store, vault_error)
    for pid in affected_providers:
        prov = provider_map.get(pid)
        if prov is None:
            msg = f"Provider {pid!r} is not configured; refusing prune."
            return PruneExecutionResult(
                ok=False, dry_run=False, batch_id=batch_id,
                attempted=0, succeeded=0, failed=len(items), bytes_recovered=0,
                errors=[msg], message=msg,
            )
        if pid == "google_drive" and not drive_state.deletes_allowed:
            return PruneExecutionResult(
                ok=False, dry_run=False, batch_id=batch_id,
                attempted=0, succeeded=0, failed=len(items), bytes_recovered=0,
                errors=[drive_state.message], message=drive_state.message,
            )
        is_auth_fn = getattr(prov, "is_authenticated", None)
        if callable(is_auth_fn) and not is_auth_fn():
            msg = f"Provider {pid!r} is not authenticated; refusing prune."
            return PruneExecutionResult(
                ok=False, dry_run=False, batch_id=batch_id,
                attempted=0, succeeded=0, failed=len(items), bytes_recovered=0,
                errors=[msg], message=msg,
            )

    # 3. Fresh TOTP required on EVERY delete
    guard = totp_guard or TotpStepUpGuard()
    totp_ok, totp_msg = guard.verify(totp_code, stepup_verifier=stepup_verifier)
    if not totp_ok:
        return PruneExecutionResult(
            ok=False, dry_run=False, batch_id=batch_id,
            attempted=0, succeeded=0, failed=len(items), bytes_recovered=0,
            errors=[totp_msg], message=totp_msg,
        )

    # 4. Preflight audit log writability BEFORE any provider mutation
    try:
        preflight_audit_log(log_path)
    except Exception as exc:  # noqa: BLE001
        msg = f"Audit log is not writable ({exc}); aborting before any provider action."
        return PruneExecutionResult(
            ok=False, dry_run=False, batch_id=batch_id,
            attempted=0, succeeded=0, failed=len(items), bytes_recovered=0,
            errors=[msg], message=msg,
        )

    # 5. Execute each item: intent BEFORE action, result AFTER
    succeeded = 0
    failed = 0
    recovered = 0
    errors: List[str] = []

    for rec in items:
        prov = provider_map[rec.provider]
        action = "drive_trash" if rec.provider == "google_drive" else "b2_hide_file"
        recovery_days = int(getattr(prov, "recovery_days", RECOVERY_WINDOWS_DAYS.get(rec.provider, 30)))

        try:
            append_audit_event(
                rec, phase="intent", action=action, recovery_days=recovery_days,
                status="pending", batch_id=batch_id, log_path=log_path,
            )
        except Exception as exc:  # noqa: BLE001
            err = f"Failed writing audit intent for {rec.filename}: {exc}; halted remaining items."
            errors.append(err)
            failed += len(items) - (succeeded + failed)
            break

        try:
            res = prov.trash_backup(rec)
            actual_action = (res or {}).get("action", action)
            actual_days = int((res or {}).get("recovery_days", recovery_days))
            try:
                append_audit_event(
                    rec, phase="result", action=actual_action, recovery_days=actual_days,
                    status="ok", batch_id=batch_id, log_path=log_path,
                )
            except Exception as log_exc:  # noqa: BLE001
                errors.append(f"{rec.filename} trashed, but result audit write failed: {log_exc}")
            succeeded += 1
            recovered += max(0, int(rec.size_bytes or 0))
        except Exception as exc:  # noqa: BLE001
            failed += 1
            err = f"{rec.filename}: {exc}"
            errors.append(err)
            try:
                append_audit_event(
                    rec, phase="result", action=action, recovery_days=recovery_days,
                    status="error", detail=str(exc), batch_id=batch_id, log_path=log_path,
                )
            except Exception:  # noqa: BLE001
                pass

    all_ok = (failed == 0 and not errors)
    if all_ok:
        summary = f"Moved {succeeded} backup(s) to Trash/hidden."
    elif succeeded > 0:
        summary = f"Partial completion: {succeeded} succeeded, {failed} failed."
    else:
        summary = f"Prune failed ({failed} failed): {errors[0] if errors else 'unknown error'}"

    return PruneExecutionResult(
        ok=all_ok,
        dry_run=False,
        batch_id=batch_id,
        attempted=len(items),
        succeeded=succeeded,
        failed=failed,
        bytes_recovered=recovered,
        errors=errors,
        message=summary,
    )


# ===== SNAPSMACK EOF =====
