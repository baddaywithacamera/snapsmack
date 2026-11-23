<?php
/**
 * Validate the real-site feature-parity evidence required before a release.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

function rf_demo_parity_record_problem(array $record, string $head): string
{
    if (($record['commit'] ?? null) !== $head) {
        return 'demo-parity record must name the exact release commit';
    }
    if (($record['status'] ?? null) !== 'pass') {
        return 'demo-parity record status must be pass';
    }
    foreach (['checked_by', 'checked_at'] as $field) {
        if (!is_string($record[$field] ?? null) || trim($record[$field]) === '') {
            return "demo-parity record must declare {$field}";
        }
    }
    if (strtotime($record['checked_at']) === false) {
        return 'demo-parity record checked_at must be a date and time';
    }
    if (!isset($record['features']) || !is_array($record['features']) || $record['features'] === []) {
        return 'demo-parity record must contain at least one changed feature';
    }
    foreach ($record['features'] as $index => $feature) {
        if (!is_array($feature)) {
            return "demo-parity feature {$index} must be an object";
        }
        foreach (['ticket', 'acceptance', 'site', 'room_message_id'] as $field) {
            if (!is_string($feature[$field] ?? null) || trim($feature[$field]) === '') {
                return "demo-parity feature {$index} must declare {$field}";
            }
        }
        if (!preg_match('/^SS-\d+$/', $feature['ticket'])) {
            return "demo-parity feature {$index} ticket must look like SS-1508";
        }
        if (($feature['result'] ?? null) !== 'pass') {
            return "demo-parity feature {$index} result must be pass";
        }
        if (!isset($feature['captures']) || !is_array($feature['captures']) || $feature['captures'] === []) {
            return "demo-parity feature {$index} must include at least one capture";
        }
        foreach ($feature['captures'] as $capture) {
            if (!is_string($capture) || trim($capture) === '') {
                return "demo-parity feature {$index} contains an empty capture reference";
            }
        }
    }
    return '';
}

function rf_demo_parity_note_problem(array $gate): string
{
    if (($gate['demo_parity'] ?? null) !== 'pass') {
        return 'release-gate note field demo_parity must be "pass"';
    }
    if (!is_string($gate['demo_parity_record_sha256'] ?? null)
        || !preg_match('/^[0-9a-f]{64}$/', $gate['demo_parity_record_sha256'])) {
        return 'release-gate note must contain the demo-parity record SHA-256';
    }
    if (!isset($gate['demo_parity_features'])
        || !is_array($gate['demo_parity_features'])
        || $gate['demo_parity_features'] === []) {
        return 'release-gate note must summarize at least one demo-parity feature';
    }
    foreach ($gate['demo_parity_features'] as $index => $feature) {
        if (!is_array($feature)
            || !preg_match('/^SS-\d+$/', (string)($feature['ticket'] ?? ''))
            || !is_string($feature['site'] ?? null)
            || trim($feature['site']) === ''
            || !is_string($feature['room_message_id'] ?? null)
            || trim($feature['room_message_id']) === ''
            || !is_int($feature['capture_count'] ?? null)
            || $feature['capture_count'] < 1) {
            return "release-gate demo_parity_features entry {$index} is incomplete";
        }
    }
    return '';
}

function rf_demo_parity_note_fields(array $record, string $raw): array
{
    return [
        'demo_parity' => 'pass',
        'demo_parity_checked_by' => trim($record['checked_by']),
        'demo_parity_checked_at' => trim($record['checked_at']),
        'demo_parity_record_sha256' => hash('sha256', $raw),
        'demo_parity_features' => array_map(
            static fn (array $feature): array => [
                'ticket' => trim($feature['ticket']),
                'site' => trim($feature['site']),
                'room_message_id' => trim($feature['room_message_id']),
                'capture_count' => count($feature['captures']),
            ],
            $record['features']
        ),
    ];
}

// ===== SNAPSMACK EOF =====
