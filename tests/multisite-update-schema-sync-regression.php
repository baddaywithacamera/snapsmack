<?php
declare(strict_types=1);
/**
 * SNAPSMACK_EOF_HEADER
 * Last non-empty line must be the canonical PHP EOF marker.
 */

$source = (string)file_get_contents(__DIR__ . '/../core/multisite-api.php');
$start = strpos($source, "if (\$resource === 'updates' && \$sub_action === 'trigger'");
$end = strpos($source, "// 8. Release lock", $start === false ? 0 : $start);
if ($start === false || $end === false || $end <= $start) {
    throw new RuntimeException('Could not locate the fleet update migration stage.');
}
$stage = substr($source, $start, $end - $start);

if (!str_contains($stage, 'updater_run_migrations(')) {
    throw new RuntimeException('Fleet updates no longer run canonical reconciliation.');
}
if (preg_match('/if\s*\(\s*!empty\(\$migration_files\)\s*\)\s*\{[^}]*updater_run_migrations/s', $stage)) {
    throw new RuntimeException('Fleet canonical reconciliation is still conditional on loose migration files.');
}
foreach (["canonical_schema_url", "canonical_schema_sig"] as $manifestField) {
    if (!str_contains($stage, "release_info['{$manifestField}']")) {
        throw new RuntimeException("Fleet schema sync does not use signed manifest field: {$manifestField}");
    }
}

echo "PASS: fleet updates always reconcile the signed canonical schema.\n";

// ===== SNAPSMACK EOF =====
