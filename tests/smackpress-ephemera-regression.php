<?php
/**
 * Longform ephemera belongs to the post contract, never to a skin heuristic.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */
$root = dirname(__DIR__);
$api = (string)file_get_contents($root . '/core/smackpress-api.php');
$schema = (string)file_get_contents($root . '/database/schema/snapsmack_canonical.sql');
$skin = (string)file_get_contents($root . '/skins/tilez/preload.php');

$assert = static function (bool $ok, string $message): void {
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$assert(str_contains($schema, '`colophon`') && str_contains($schema, '`signature_image_id`'),
    'canonical post schema owns colophon and signature fields');
$assert(str_contains($api, "array_key_exists('colophon', \$body)")
    && str_contains($api, "array_key_exists('signature_image_id', \$body)"),
    'API distinguishes omitted fields from explicit clearing');
$assert(str_contains($api, 'smackpress_sanitize_html(smack_autop_long($raw_colophon))'),
    'API sanitizes colophon HTML independently');
$assert(str_contains($api, 'SELECT id,colophon,signature_image_id FROM snap_posts'),
    'older clients preserve existing ephemera during updates');
$assert(str_contains($skin, "\$_alfred_post['colophon']")
    && str_contains($skin, "\$_alfred_post['signature_image_id']"),
    'TILEZ reads only canonical post semantics');
$assert(!str_contains($skin, 'legacy_is_colophon') && !str_contains($skin, 'LOWER(img_title)'),
    'TILEZ does not infer semantics from prose or filenames');

echo "PASS: SMACKPRESS canonical ephemera contract\n";

// ===== SNAPSMACK EOF =====
