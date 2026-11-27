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
            if (rf_demo_parity_is_placeholder((string)$feature[$field])) {
                return "demo-parity feature {$index} {$field} is still a placeholder: "
                    . trim((string)$feature[$field]);
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
        foreach ($feature['captures'] as $position => $capture) {
            $problem = rf_demo_parity_capture_problem($capture, "feature {$index} capture {$position}");
            if ($problem !== '') {
                return 'demo-parity ' . $problem;
            }
        }
    }
    return '';
}

/**
 * Text with every run of punctuation and whitespace flattened to one space.
 *
 * One rule then covers every spelling a template arrives in: "FILL IN",
 * "FILL  IN", "FILL\tIN", "FILL-IN", "FILL_IN".
 */
function rf_demo_parity_normalise(string $text): string
{
    return trim((string)preg_replace('/[^A-Za-z0-9]+/', ' ', strtoupper($text)));
}

/**
 * Does this field still read as a fact nobody supplied?
 *
 * WHY THIS IS IN THE GATE. Until now every field here was checked only for
 * being a non-empty string, so a record whose site read "FILL IN: the local
 * site" and whose captures read "FILL IN: the control reading OFF" PASSED, and
 * "real-site parity" was satisfied by typing TODO six times. That was measured
 * against this very function's caller, not guessed. A gate that cannot tell a
 * demonstration from a to-do list is not holding the line it was added for.
 *
 * A bare FILL counts only as a WHOLE field: SnapSmack has a feature called
 * Generative Fill, so "the Generative Fill panel" is legitimate capture text.
 *
 * This cannot catch plausible text somebody invented, and does not pretend to.
 * It catches a template nobody filled in. The capture-file rule below is what
 * makes invention expensive, because a claimed capture must exist on disk and
 * hash to what the record says.
 */
function rf_demo_parity_is_placeholder(string $text): bool
{
    $flat = rf_demo_parity_normalise($text);
    if (in_array($flat, ['', 'FILL', 'N A', 'NA', 'NONE', 'PENDING'], true)) {
        return true;
    }
    foreach (['FILL IN', 'FILLIN', 'TODO', 'TBD', 'TBC', 'XXX', 'PLACEHOLDER', 'FIXME'] as $marker) {
        if (str_contains($flat, rf_demo_parity_normalise($marker))) {
            return true;
        }
    }
    return false;
}

/** The directory a capture path is resolved against. */
function rf_demo_parity_root(): string
{
    return dirname(__DIR__, 2);
}

/**
 * One capture must be a FILE THAT EXISTS, with the hash the record claims.
 *
 * A capture used to be any non-empty string, so "capture-1920.png" satisfied
 * the gate whether or not such a file had ever been made. The evidence a
 * release rests on cannot be a filename somebody typed. So a capture is now
 * {"path": "...", "sha256": "..."} where the path is inside the repository, the
 * file is there, it is not empty, and its contents hash to the stated value.
 *
 * The path is resolved against the repository root and must stay inside it:
 * evidence that lives outside the tree cannot be reviewed by anyone else.
 */
function rf_demo_parity_capture_problem($capture, string $label): string
{
    if (is_string($capture)) {
        return "{$label} is a bare string; a capture must be "
            . '{"path": "...", "sha256": "..."} naming a file in the repository';
    }
    if (!is_array($capture)) {
        return "{$label} must be an object with a path and a sha256";
    }
    $path = is_string($capture['path'] ?? null) ? trim($capture['path']) : '';
    if ($path === '') {
        return "{$label} must declare path";
    }
    if (rf_demo_parity_is_placeholder($path)) {
        return "{$label} path is still a placeholder: {$path}";
    }
    $hash = is_string($capture['sha256'] ?? null) ? strtolower(trim($capture['sha256'])) : '';
    if (!preg_match('/^[0-9a-f]{64}$/', $hash)) {
        return "{$label} must declare the capture's sha256";
    }
    $root = rf_demo_parity_root();
    if (preg_match('~^(?:[a-zA-Z]:|[\\\\/])~', $path) === 1 || str_contains($path, '..')) {
        return "{$label} path must be relative to the repository and must not leave it: {$path}";
    }
    $full = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    $real = realpath($full);
    if ($real === false || !is_file($real)) {
        return "{$label} names a file that is not in the repository: {$path}";
    }
    if (strncmp($real, realpath($root) ?: $root, strlen(realpath($root) ?: $root)) !== 0) {
        return "{$label} resolves outside the repository: {$path}";
    }
    if (filesize($real) === 0) {
        return "{$label} names an empty file: {$path}";
    }
    $actual = hash_file('sha256', $real);
    if ($actual !== $hash) {
        return "{$label} does not match its file: the record says {$hash}, {$path} hashes to {$actual}";
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
        // The note is what survives in git after the record file is gone, so a
        // placeholder here outlives everything. Same rule as the record.
        foreach (['site', 'room_message_id'] as $field) {
            if (rf_demo_parity_is_placeholder((string)$feature[$field])) {
                return "release-gate demo_parity_features entry {$index} {$field} "
                    . 'is still a placeholder: ' . trim((string)$feature[$field]);
            }
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
