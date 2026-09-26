<?php
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$source = (string)file_get_contents(dirname(__DIR__) . '/assets/js/ss-engine-image-fade-load.js');
$assert = static function (bool $ok, string $message): void {
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$assert(str_contains($source, "document.addEventListener('visibilitychange'"), 'tab restore is observed');
$assert(str_contains($source, "window.addEventListener('pageshow'"), 'history/page restore is observed');
$assert(str_contains($source, "if (!img.complete) return;"), 'only completed images are reconciled');
$assert(str_contains($source, "img.style.opacity = '1';"), 'completed images are revealed');

echo "PASS: image fade resumes after tab restore\n";

// ===== SNAPSMACK EOF =====
