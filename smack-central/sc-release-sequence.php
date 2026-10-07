<?php
/**
 * SMACK CENTRAL - fail-closed development release sequence gate.
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
 * Evaluate the three independent facts required before packaging N+1.
 * There is intentionally no override parameter.
 */
function sc_dev_sequence_refusal(
    string $requested,
    bool $predecessor_packaged,
    bool $predecessor_recorded,
    array $active_dev_versions
): string {
    $predecessor = sc_dev_predecessor($requested);
    if ($predecessor === null) {
        return "SEQUENTIAL RELEASE GATE REFUSED {$requested}: invalid development version.";
    }

    $missing = [];
    if (!$predecessor_packaged) $missing[] = "{$predecessor} is not in dev build history (not packaged)";
    if (!$predecessor_recorded) $missing[] = "{$predecessor} is not in the immutable release ledger (not recorded)";
    if (!$active_dev_versions) {
        $missing[] = 'no active development-track install has reported a deployed version';
    } else {
        $not_deployed = array_values(array_filter(
            $active_dev_versions,
            static fn (string $reported): bool => strcasecmp($reported, $predecessor) !== 0
        ));
        if ($not_deployed) {
            $counts = array_count_values($not_deployed);
            $summary = [];
            foreach ($counts as $reported => $count) $summary[] = "{$reported} x{$count}";
            $missing[] = "{$predecessor} is not deployed across the active dev fleet (reported: "
                . implode(', ', $summary) . ')';
        }
    }

    return $missing
        ? "SEQUENTIAL RELEASE GATE REFUSED {$requested}: immediate predecessor {$predecessor} must be packaged, deployed, and recorded first; "
            . implode('; ', $missing) . '. No override exists.'
        : '';
}

// ===== SNAPSMACK EOF =====
