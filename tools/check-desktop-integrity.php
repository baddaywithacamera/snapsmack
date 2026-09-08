<?php
/**
 * SNAPSMACK — Desktop app integrity guard (version + icons)
 *
 * Why this exists: desktop app versions have been silently REVERTED and icons
 * lost when divergent desktop branches get merged and the merge resolves the
 * config to the OLDER side. Concrete case: GYSS went 0.7.7 → 0.7.8 → 0.7.9 →
 * (merge) 0.1.5-alpha → 0.7.10. This guard turns that silent revert into a loud
 * failure BEFORE it ships.
 *
 * What it checks, per desktop tool with a Tauri config (src-tauri/tauri.conf.json):
 *   1. Every icon path declared in the config exists on disk.
 *   2. The current version is >= the baseline recorded in tools/desktop-versions.json.
 *      (A merge that drags a version backwards fails here.)
 *
 * When a version is INTENTIONALLY bumped, update the baseline in the same commit
 * (that's the deliberate, reviewed act — the guard only blocks accidental reverts).
 *
 * Usage:  php tools/check-desktop-integrity.php
 * Exit 0 = all good; exit 1 = a regression or a missing icon (details printed).
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$root = dirname(__DIR__);
$toolsDir = $root . '/tools';
$baselinePath = $toolsDir . '/desktop-versions.json';

/** Compare dotted versions numerically; trailing -alpha/-beta etc. ignored for ordering. */
function ver_tuple(string $v): array {
    $v = preg_replace('/[^0-9.].*$/', '', trim($v));   // "0.1.5-alpha" -> "0.1.5"
    $parts = array_map('intval', explode('.', $v === '' ? '0' : $v));
    return array_pad($parts, 3, 0);
}
function ver_cmp(string $a, string $b): int {
    return ver_tuple($a) <=> ver_tuple($b);
}

$baseline = is_file($baselinePath)
    ? (json_decode((string)file_get_contents($baselinePath), true) ?: [])
    : [];

$fail = 0;
$seen = [];

foreach (glob($toolsDir . '/*/src-tauri/tauri.conf.json') as $conf) {
    $tool = basename(dirname(dirname($conf)));
    $seen[$tool] = true;
    $cfg = json_decode((string)file_get_contents($conf), true);
    if (!is_array($cfg)) { echo "FAIL $tool: tauri.conf.json is not valid JSON\n"; $fail++; continue; }

    $version = (string)($cfg['version'] ?? ($cfg['package']['version'] ?? ''));
    if ($version === '') { echo "FAIL $tool: no version in tauri.conf.json\n"; $fail++; }

    // 1) icon files must exist
    $icons = $cfg['bundle']['icon'] ?? $cfg['tauri']['bundle']['icon'] ?? $cfg['icon'] ?? [];
    // fall back to a raw scan if the config nesting differs across Tauri versions
    if (!$icons) {
        preg_match_all('/"(icons\/[^"]+)"/', (string)file_get_contents($conf), $m);
        $icons = $m[1] ?? [];
    }
    foreach ($icons as $rel) {
        $abs = dirname($conf) . '/' . $rel;
        if (!is_file($abs)) { echo "FAIL $tool: declared icon missing on disk: $rel\n"; $fail++; }
    }
    if (!$icons) { echo "WARN $tool: no icons declared in config\n"; }

    // 2) version must not regress below the recorded baseline
    if (isset($baseline[$tool]['version'])) {
        $base = (string)$baseline[$tool]['version'];
        if (ver_cmp($version, $base) < 0) {
            echo "FAIL $tool: version REVERTED $base -> $version (a merge likely took the older side). "
               . "If this downgrade is intentional, update tools/desktop-versions.json in this commit.\n";
            $fail++;
        } else {
            echo "ok   $tool: version $version (>= baseline $base), icons present\n";
        }
    } else {
        echo "WARN $tool: no baseline recorded — add it to tools/desktop-versions.json to lock the floor\n";
    }
}

// A tool disappearing from the baseline set is worth a nudge, not a failure.
foreach ($baseline as $tool => $_) {
    if ($tool !== '' && $tool[0] === '_') continue;   // skip _comment and other notes
    if (!isset($seen[$tool])) echo "WARN baseline lists '$tool' but no tauri.conf.json was found for it\n";
}

echo $fail === 0 ? "\nDESKTOP INTEGRITY OK\n" : "\n$fail PROBLEM(S) — do not ship until resolved\n";
exit($fail === 0 ? 0 : 1);
// ===== SNAPSMACK EOF =====
