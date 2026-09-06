<?php
/**
 * SNAPSMACK — sybu-images.php (COLD STORAGE endpoint) regression.
 *
 * Static contract checks: the endpoint stays sybu-key-gated, photo-modes-only,
 * field-scoped (metadata in, metadata out — never the file, never status,
 * never deletion), sanitized, and integer-bound on pagination.
 *
 * Run: php tests/sybu-images-regression.php
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

$src = file_get_contents(__DIR__ . '/../sybu-images.php');
if ($src === false) {
    fwrite(STDERR, "FAIL: sybu-images.php missing\n");
    exit(1);
}

$checks = [
    // Auth contract — same gate family as sybu-data.php.
    "sybu key gate declared"        => strpos($src, "\$GLOBALS['SNAP_API_KEY_TYPES']    = ['sybu']") !== false,
    "photo-modes gate declared"     => strpos($src, "['photoblog', 'carousel']") !== false,
    "api-auth required"             => strpos($src, "core/api-auth.php") !== false,
    "gate lines precede api-auth"   => strpos($src, "SNAP_API_KEY_TYPES") < strpos($src, "core/api-auth.php"),

    // Write scope — metadata only, sanitized.
    "alt sanitized"                 => strpos($src, 'snap_sanitize_alt((string)$_POST[\'alt\'])') !== false,
    "colour normalized"             => strpos($src, 'snap_normalize_color_mode((string)$_POST[\'color_mode\'])') !== false,
    "update is prepared"            => strpos($src, "UPDATE snap_images SET ' . implode") !== false,
    "never updates the file"        => !preg_match('/img_file\s*=\s*\?/', $src),
    "never updates status"          => !preg_match('/img_status\s*=\s*\?/', $src),
    "never deletes"                 => stripos($src, 'DELETE FROM') === false,

    // Listing — paged with INTEGER binds (the GYSS 650D lesson).
    "limit bound as int"            => strpos($src, 'PDO::PARAM_INT') !== false,
    "per-page capped"               => strpos($src, 'min(200, $per)') !== false,

    // Lazy columns for older installs.
    "img_alt lazy alter"            => strpos($src, 'ADD COLUMN IF NOT EXISTS img_alt') !== false,
    "img_color_mode lazy alter"     => strpos($src, 'ADD COLUMN IF NOT EXISTS img_color_mode') !== false,

    // File integrity.
    "EOF marker present"            => strpos($src, '// ===== SNAPSMACK EOF =====') !== false,
];

$fails = 0;
foreach ($checks as $name => $ok) {
    if (!$ok) {
        fwrite(STDERR, "FAIL: $name\n");
        $fails++;
    }
}
if ($fails) {
    exit(1);
}
echo "sybu-images regression: " . count($checks) . " checks passed\n";

// ===== SNAPSMACK EOF =====
