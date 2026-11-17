<?php
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 *
 * ss_skin_stats_row() alone, for tests.
 *
 * skin-stats.php fetches 28 sites over the network at include time, so a test
 * cannot require it. This extracts just the pure function from that file and
 * evaluates it, which keeps ONE definition rather than a copy that drifts --
 * a second copy of an access-control or data-shape decision is how the muse
 * scope list went wrong earlier today.
 */
$_row_src = (string)@file_get_contents(__DIR__ . '/skin-stats.php');
$_row_start = strpos($_row_src, "if (!function_exists('ss_skin_stats_row')) {");
if ($_row_start === false) {
    throw new RuntimeException('ss_skin_stats_row() is gone from skin-stats.php');
}
$_row_end = strpos($_row_src, "\n}\n", $_row_start);
if ($_row_end === false) {
    throw new RuntimeException('could not isolate ss_skin_stats_row()');
}
eval(substr($_row_src, $_row_start, $_row_end - $_row_start + 3));
unset($_row_src, $_row_start, $_row_end);
// ===== SNAPSMACK EOF =====
