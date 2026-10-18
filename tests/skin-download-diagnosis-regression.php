<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * A failed skin download must say which thing failed.
 *
 * 18 October 2026: three spokes refused RATIONAL GEO and SLICKR minutes after
 * those two packages were published, each reporting "Download failed. Check
 * that this server can reach the skin registry." Every other skin on the same
 * spokes installed in the same run, so the registry was plainly reachable and
 * the message sent the owner to look at a firewall that was fine.
 *
 * The registry states download_size for every entry and nothing read it. A
 * publish still in progress answers 200 with a short body, which is the one
 * failure that looks like success until the signature check rejects it.
 */

$root = dirname(__DIR__);
$fail = [];
$check = static function (bool $ok, string $what) use (&$fail): void {
    if (!$ok) $fail[] = $what;
};

$registry  = (string)file_get_contents($root . '/core/skin-registry.php');
$multisite = (string)file_get_contents($root . '/core/multisite-api.php');
$hub       = (string)file_get_contents($root . '/smack-multisite.php');

// ── the installer takes an expected size and uses it ─────────────────────────
$check(str_contains($registry, 'int $expected_size = 0'),
    'skin_registry_install() no longer accepts an expected package size');
$check(str_contains($registry, '$expected_size > 0 && $got !== $expected_size'),
    'the expected size is accepted and then ignored, which is worse than not taking it');

// ── each failure names itself ────────────────────────────────────────────────
foreach ([
    'the package is not there yet (404)' => 'a 404 is reported as a missing package',
    'answered HTTP '                     => 'a non-200 reports its status code',
    'bytes, which is not a skin package' => 'a short body is reported as a short body',
    'The package is incomplete'          => 'a size mismatch is reported as incomplete',
    'before any data arrived'            => 'a transport error reports the cURL message',
] as $needle => $what) {
    $check(str_contains($registry, $needle), 'missing diagnosis: ' . $what);
}

// The catch-all may remain, but only as the last resort when nothing else is
// known -- never as the answer to a 404 or a truncated body.
$check(substr_count($registry, 'Check that this server can reach the skin registry.') === 1,
    'the blame-the-network message is back in more than one place');

// ── the size reaches the spoke that needs it ─────────────────────────────────
$check(substr_count($multisite, "skin_registry_install(\$slug, \$download_url, \$signature, \$public_key, \$download_size)") === 2,
    'multisite-api calls the installer without passing the size through');
$check(str_contains($multisite, "(int)(\$body['download_size'] ?? 0)"),
    'the single-spoke endpoint does not read download_size from the request');
$check(str_contains($multisite, "(int)(\$package['download_size'] ?? 0)"),
    'the batch endpoint does not read download_size per package');
$check(str_contains($hub, "'download_size' => (int)(\$rskin['download_size'] ?? 0)"),
    'the hub does not carry download_size out of the registry row');
$check(str_contains($hub, "'download_size' => \$download_size"),
    'the hub does not send download_size to the spoke');

if ($fail) {
    fwrite(STDERR, "FAIL: skin download diagnosis\n  - " . implode("\n  - ", $fail) . "\n");
    exit(1);
}
echo "PASS: a failed skin download names the thing that failed, and the size travels.\n";
// ===== SNAPSMACK EOF =====
