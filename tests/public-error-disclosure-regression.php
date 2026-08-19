<?php
/**
 * SECAUDIT 049 - public failures must be logged, never rendered verbatim.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$root = dirname(__DIR__);
$files = ['index.php', 'archive.php', 'albums.php', 'blogroll.php', 'page.php', 'privacy-policy.php', 'process-comment.php'];

foreach ($files as $file) {
    $source = (string)file_get_contents($root . '/' . $file);
    if (preg_match("/ini_set\(\s*['\"]display_errors['\"]\s*,\s*(?:1|['\"]1['\"])\s*\)/", $source)) {
        fwrite(STDERR, "FAIL: {$file} enables public error display\n");
        exit(1);
    }
    if (preg_match('/die\s*\([^;]*getMessage\s*\(\s*\)/s', $source)) {
        fwrite(STDERR, "FAIL: {$file} sends an exception message to the visitor\n");
        exit(1);
    }
}

echo "PASS: public error-disclosure regression suite\n";
// ===== SNAPSMACK EOF =====
