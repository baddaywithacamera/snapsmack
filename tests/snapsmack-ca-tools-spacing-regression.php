<?php
declare(strict_types=1);

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$source = (string)file_get_contents(dirname(__DIR__) . '/projects/snapsmack-ca/tools.php');
if (!str_contains($source, '.why-desktop { padding: 48px 0 56px; }')) {
    throw new RuntimeException('BOX O\' TRICKS Why desktop section lost its desktop top spacing.');
}

echo "SnapSmack.ca BOX O' TRICKS spacing regression passed\n";
// ===== SNAPSMACK EOF =====
