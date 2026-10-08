<?php
declare(strict_types=1);

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$root = dirname(__DIR__);
$page = (string)file_get_contents($root . '/projects/snapsmack-ca/coming-soon.php');
$parser = (string)file_get_contents($root . '/core/parser.php');

if (!str_contains($parser, "class=\"ss-pullquote\"")) {
    throw new RuntimeException('The parser no longer implements semantic pull quotes.');
}
if (str_contains($page, 'never actually built')) {
    throw new RuntimeException('Coming Soon still falsely describes pullquote as unbuilt.');
}
if (!str_contains($page, '<strong><code>[pullquote]</code></strong>')) {
    throw new RuntimeException('The shipped pullquote entry is missing from Recently landed.');
}

echo "SnapSmack.ca Coming Soon truth regression passed\n";
// ===== SNAPSMACK EOF =====
