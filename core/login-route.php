<?php
/** Resolve the path the browser actually requested before an internal rewrite. */

/**
 * Apache may rewrite /snap-in to snap-in.php before PHP runs. On some hosts
 * REQUEST_URI then contains the rewritten filename, while THE_REQUEST retains
 * the original HTTP request line sent by the browser.
 */
function snapsmack_login_requested_path(array $server): string {
    $request_line = trim((string)($server['THE_REQUEST'] ?? ''));
    if ($request_line !== ''
        && preg_match('#^[A-Z]+\s+([^\s]+)\s+HTTP/\d(?:\.\d)?$#i', $request_line, $m)) {
        $path = parse_url($m[1], PHP_URL_PATH);
        if (is_string($path) && $path !== '') return $path;
    }

    return (string)(parse_url((string)($server['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');
}

// ===== SNAPSMACK EOF =====
