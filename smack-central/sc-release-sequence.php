<?php
/**
 * SMACK CENTRAL - development release sequence advisory.
 *
 * This file is deliberately dependency-free so the refusal logic can be
 * regression-tested without loading the authenticated packager page.
 */

function sc_dev_release_number(string $version): ?array {
    $version = ltrim(trim($version), 'vV');
    if (!preg_match('/^(\d+)\.(\d+)\.(\d+)D$/i', $version, $m)) return null;
    return [(int)$m[1], (int)$m[2], (int)$m[3]];
}

function sc_dev_predecessor(string $version): ?string {
    $parts = sc_dev_release_number($version);
    if ($parts === null || $parts[2] < 1) return null;
    return $parts[0] . '.' . $parts[1] . '.' . ($parts[2] - 1) . 'D';
}

/**
 * Describe missing predecessor evidence for the operator. This is advisory:
 * release packaging must remain available for the newest intended version.
 */
function sc_dev_sequence_refusal(
    string $requested,
    bool $predecessor_packaged,
    bool $predecessor_recorded,
    array $active_dev_versions
): string {
    $predecessor = sc_dev_predecessor($requested);
    if ($predecessor === null) {
        return "RELEASE SEQUENCE WARNING {$requested}: invalid development version.";
    }

    $missing = [];
    if (!$predecessor_packaged) $missing[] = "{$predecessor} is not in dev build history (not packaged)";
    if (!$predecessor_recorded) $missing[] = "{$predecessor} is not in the immutable release ledger (not recorded)";
    if (!$active_dev_versions) {
        $missing[] = 'no active development-track install has reported a deployed version';
    } else {
        $deployed = array_values(array_filter(
            $active_dev_versions,
            static fn (string $reported): bool => strcasecmp($reported, $predecessor) === 0
        ));
        if (!$deployed) {
            $counts = array_count_values($active_dev_versions);
            $summary = [];
            foreach ($counts as $reported => $count) $summary[] = "{$reported} x{$count}";
            $missing[] = "no active development install reports {$predecessor} deployed (reported: "
                . implode(', ', $summary) . ')';
        }
    }

    return $missing
        ? "RELEASE SEQUENCE WARNING {$requested}: immediate predecessor {$predecessor} was not fully packaged, recorded, and observed in deployment; "
            . implode('; ', $missing) . '. Packaging remains available.'
        : '';
}

// ===== SNAPSMACK EOF =====
