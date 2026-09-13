<?php
/** Source-level regression checks for the PIPEDA privacy baseline. */

/**
 * SNAPSMACK_EOF_HEADER
 * Last non-empty line of this file MUST be the canonical PHP EOF marker.
 */

$root = dirname(__DIR__);
$banner = file_get_contents($root . '/core/consent-banner.php');
$stats = file_get_contents($root . '/core/stats-logger.php');
$privacy = file_get_contents($root . '/smack-privacy.php');
$install = file_get_contents($root . '/install.php');

$checks = [
    'banner no longer makes the false no-analytics claim' => strpos($banner, 'No tracking or analytics') === false,
    'banner explains unavoidable limited first-party statistics' => strpos($banner, 'recorded whether or not preferences are allowed') !== false,
    'preference choice labels are specific' => strpos($banner, 'Allow preferences') !== false && strpos($banner, 'Continue without') !== false,
    'built-in privacy policy is linked when published' => strpos($banner, "privacy-policy.php") !== false,
    'new visits do not retain full referrers' => strpos($stats, 'null, // never retain the full referring URL') !== false,
    'new visits do not retain full user agents' => strpos($stats, 'null, // parse broad browser/OS') !== false,
    'new visits do not retain search phrases' => strpos($stats, 'null, // search phrases are not necessary') !== false,
    'legacy detailed fields are scrubbed' => strpos($stats, 'SET referrer = NULL, user_agent = NULL, search_term = NULL') !== false,
    'visit records have a one-year product ceiling' => strpos($stats, 'min(365, (int)$days)') !== false,
    'automatic daily purge is wired in' => strpos($stats, "'stats_last_purge'") !== false && strpos($stats, 'snapsmack_purge_old_stats') !== false,
    'privacy manager offers a PIPEDA starter' => strpos($privacy, 'USE PIPEDA STARTER') !== false && strpos($privacy, 'PIPEDA') !== false,
    'privacy manager exposes bounded retention' => strpos($privacy, 'name="stats_retention_days"') !== false && strpos($privacy, 'max="365"') !== false,
    'fresh installs seed the retention window' => strpos($install, "'stats_retention_days'      => '365'") !== false,
];

$failed = [];
foreach ($checks as $name => $ok) {
    if (!$ok) $failed[] = $name;
}

if ($failed) {
    fwrite(STDERR, "PIPEDA privacy baseline regression failed:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "PIPEDA privacy baseline regression passed (" . count($checks) . " checks).\n";
// ===== SNAPSMACK EOF =====
