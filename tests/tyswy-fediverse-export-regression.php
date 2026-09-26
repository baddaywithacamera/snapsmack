<?php
/*
 * SNAPSMACK_EOF_HEADER
 * Last non-empty line must be: // ===== SNAPSMACK EOF =====
 */
/**
 * OPAUDIT 019 — TAKE YOUR SHIT WITH YOU exports the people attached to a site,
 * not just the blog. Static contract on core/tyswy-api.php:
 *   - an action=fediverse exists and is reached under the same read-only auth
 *   - it reads followers / following / blocks from the real tables
 *   - it emits the four CSVs with Mastodon's exact header on following.csv
 *   - it ships the PUBLIC key only; the private key setting is never read here
 *
 *   php tests/tyswy-fediverse-export-regression.php   (exit 0 = pass)
 */
$root = dirname(__DIR__);
$src  = file_get_contents($root . '/core/tyswy-api.php');
$fail = 0;
function t(bool $ok, string $name): void { global $fail; echo ($ok ? "PASS " : "FAIL ") . $name . "\n"; if (!$ok) $fail++; }

t(str_contains($src, "if (\$action === 'fediverse')"), 'action=fediverse exists');
t(strpos($src, "if (\$action === 'fediverse')") > strpos($src, 'tyswy_rate_limit($pdo'),
  'fediverse action sits after auth + rate limit (same door as every other action)');
t(str_contains($src, "FROM snap_ap_followers WHERE is_active = 1"), 'reads active followers');
t(str_contains($src, "FROM snap_ap_following WHERE state IN ('accepted','pending')"), 'reads following');
t(str_contains($src, "FROM snap_ap_blocks"), 'reads blocks');
t(str_contains($src, "['Account address', 'Show boosts', 'Notify on new posts', 'Languages']"),
  "following.csv carries Mastodon's exact import header");
foreach (['following.csv', 'blocked_accounts.csv', 'muted_accounts.csv', 'blocked_domains.csv'] as $f) {
    t(str_contains($src, "'$f'"), "emits $f");
}
t(str_contains($src, "\$settings['fediverse_public_key']"), 'ships the PUBLIC key');
// The private key must not be read anywhere in the fediverse action's body.
$start = strpos($src, "if (\$action === 'fediverse')");
$end   = strpos($src, "tyswy_error(400, 'unknown_action'");
$body  = substr($src, $start, $end - $start);
t(!str_contains($body, 'fediverse_private_key') && !str_contains($body, 'curator_private_key'),
  'the fediverse action never touches a private key');
t(str_contains($body, 'FED UP -> MOVING TO'), 'README/notes say followers only move via Move');
t(str_contains($src, "'ActivityPub private signing keys'"), 'preflight still promises private keys are excluded');

echo $fail === 0 ? "ALL PASS\n" : "{$fail} FAILURE(S)\n";
exit($fail === 0 ? 0 : 1);

// ===== SNAPSMACK EOF =====
