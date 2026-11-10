<?php
/**
 * Release-key rotation form regression.
 *
 * SNAPSMACK_EOF_HEADER
 * Last non-empty line must be: // ===== SNAPSMACK EOF =====
 *
 * The normal POST must reach the rotation handler, and the server must verify
 * the exact timestamped blob displayed and signed offline. The rotation target
 * must also match the active release-signing private key.
 */

$source = file_get_contents(__DIR__ . '/../smack-central/sc-release.php');
if ($source === false) {
    fwrite(STDERR, "FAIL: could not read smack-central/sc-release.php\n");
    exit(1);
}

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$rotation_handler = strpos($source, "isset(\$_POST['publish_rotation'])");
$ajax_handler     = strpos($source, "(\$_GET['action'] ?? '') === 'fetch_changelog'");
$check($rotation_handler !== false, 'rotation POST handler is missing');
$check($ajax_handler !== false, 'changelog AJAX handler is missing');
$check(
    $rotation_handler !== false && $ajax_handler !== false && $rotation_handler < $ajax_handler,
    'rotation POST handler must be outside and before the changelog AJAX route'
);

$check(
    str_contains($source, 'name="rotation_issued_at" id="rot-issued-at"'),
    'form must submit the timestamp from the displayed blob'
);
$check(
    str_contains($source, "'issued_at'  => \$issued_at"),
    'server must verify the submitted blob timestamp instead of regenerating it'
);
$check(
    str_contains($source, "!hash_equals(\$sc_derived_pubkey, \$new_pub)"),
    'rotation target must match the active release-signing private key'
);
$check(
    str_contains($source, "hash_equals(\$sc_release_csrf, \$submitted_csrf)"),
    'rotation POST must require the release-form CSRF token'
);
$check(
    str_contains($source, "\$root_pub_hex = '3287b9b29257da6a307fc85b949c9dc52bc99c08a66db21e6fcbaab0fb324652'"),
    'hub rotation verifier must use the fleet root trust anchor'
);

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

echo "PASS: release-key rotation form reaches its handler and verifies the exact signed blob.\n";
// ===== SNAPSMACK EOF =====
