"""Post-restore verification and PASS/FAIL report generator (SS-1363)."""

from .models import BackupPackage, VerificationCheck, VerificationResult


def verify_restored_blog(backup: BackupPackage, snapshot: dict) -> VerificationResult:
    """Compare a restored target blog snapshot against the backup manifest."""
    checks = [
        VerificationCheck(
            name="Posts count",
            passed=int(snapshot.get("posts_count", -1)) == backup.posts_count,
            expected=str(backup.posts_count),
            actual=str(snapshot.get("posts_count", 0)),
        ),
        VerificationCheck(
            name="Photos count",
            passed=int(snapshot.get("photos_count", -1)) == backup.photos_count,
            expected=str(backup.photos_count),
            actual=str(snapshot.get("photos_count", 0)),
        ),
        VerificationCheck(
            name="Pages count",
            passed=int(snapshot.get("pages_count", -1)) == backup.pages_count,
            expected=str(backup.pages_count),
            actual=str(snapshot.get("pages_count", 0)),
        ),
        VerificationCheck(
            name="Settings present",
            passed=int(snapshot.get("settings_count", 0)) >= backup.settings_count,
            expected=f">={backup.settings_count}",
            actual=str(snapshot.get("settings_count", 0)),
        ),
        VerificationCheck(
            name="Active skin",
            passed=str(snapshot.get("skin_name", "")).strip() == backup.skin_name,
            expected=backup.skin_name,
            actual=str(snapshot.get("skin_name", "")),
        ),
    ]

    loaded_samples = set(snapshot.get("loaded_sample_images", []) or [])
    expected_samples = list(backup.sample_image_urls or [])
    samples_ok = all(u in loaded_samples for u in expected_samples) if expected_samples else True
    checks.append(
        VerificationCheck(
            name="Sample images load",
            passed=samples_ok,
            expected=f"{len(expected_samples)}/{len(expected_samples)}",
            actual=f"{sum(1 for u in expected_samples if u in loaded_samples)}/{len(expected_samples)}",
        )
    )

    all_ok = all(c.passed for c in checks)
    verdict = "PASS" if all_ok else "FAIL"
    failed_names = [c.name for c in checks if not c.passed]
    summary = (
        f"PASS — Restored {backup.backup_id} ({backup.date_iso}); all {len(checks)} manifest & sample-image checks matched."
        if all_ok
        else f"FAIL — Restored {backup.backup_id}; mismatched: {', '.join(failed_names)}."
    )
    return VerificationResult(verdict=verdict, checks=checks, summary=summary)


# ===== SNAPSMACK EOF =====
