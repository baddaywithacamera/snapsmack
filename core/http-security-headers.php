<?php
/**
 * SNAPSMACK - HTTP Security Headers
 *
 * Single source of truth for the security response headers, emitted on every
 * request. This is a SHIPPING file (delivered by the updater) and is pulled in
 * from core/skin-manifest.php — which is itself unprotected and which
 * core/constants.php require_once's on every load ("available everywhere
 * constants are loaded"). Routing the headers through skin-manifest.php means
 * changes reach UPGRADED installs even where an install's own core/constants.php
 * is frozen.
 *
 * SECAUDIT 048 correction (2026-08-15): HSTS and CSP were first added directly in
 * core/constants.php. That file was a protected updater path AND protected_paths.json
 * (which lists it) is itself protected, so the change could never reach upgraded
 * sites. constants.php has since been unprotected for new installs, but skin-manifest
 * is the path that actually delivers to existing ones — hence this file.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

if (!defined('SNAPSMACK_SECURITY_HEADERS_EMITTED')) {
    define('SNAPSMACK_SECURITY_HEADERS_EMITTED', true);

    // Skipped on CLI (e.g. migrations) and once output has begun.
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        // Keep browsers on HTTPS once seen over TLS. Harmless over plain HTTP
        // (browsers ignore HSTS on non-secure responses).
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        // Conservative CSP: blocks plugin/object embeds, <base> hijacking, and
        // third-party framing — none of which SnapSmack uses — WITHOUT restricting
        // img/script/form sources, so federated remote images, inline config
        // scripts, and remote-follow forms keep working. A stricter script/img
        // policy needs live testing against federated content before enforcement.
        header("Content-Security-Policy: object-src 'none'; base-uri 'self'; frame-ancestors 'self'");
    }
}
// ===== SNAPSMACK EOF =====
