<?php
/**
 * SNAPSMACK - Shared input containment for the desktop-tool APIs
 *
 * Two validators for values that arrive from a desktop client and are STORED,
 * then acted on later by unrelated code. Both close SECAUDIT 040 findings.
 *
 * This file is deliberately side-effect free — no DB, no session, no headers,
 * no other includes — so any API handler can require it and so it is directly
 * testable (tests/api-input-safety-regression.php). Same shape as
 * core/client-ip.php, the SECAUDIT 035 boundary helper.
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

/**
 * Is this a safe img_uploads-relative file path?
 *
 * SECAUDIT 040 finding C. img_file / img_thumb_square / img_thumb_aspect are
 * written verbatim into snap_images and later handed to unlink() by
 * snap_manage_delete_image() (smack-manage.php) when the owner deletes the
 * image — so an unvalidated value is a stored, delayed, owner-triggered
 * arbitrary file delete.
 *
 * Root containment alone is NOT sufficient: 'core/db.php' never leaves the
 * install and is still catastrophic. The real invariant is that these columns
 * may only name a file the upload endpoint itself wrote, and that endpoint only
 * ever writes under img_uploads/. That is what is enforced here.
 */
if (!function_exists('snap_api_safe_upload_path')) {
function snap_api_safe_upload_path(string $p): bool {
    if ($p === '' || strlen($p) > 500)      return false;
    if (strpos($p, "\0") !== false)         return false;   // NUL truncation
    if (strpos($p, '\\') !== false)         return false;   // backslash paths
    if ($p[0] === '/')                      return false;   // absolute
    if (preg_match('#^[a-zA-Z]:#', $p))     return false;   // drive letter
    if (strpos($p, '//') !== false)         return false;   // UNC / empty segment

    foreach (explode('/', $p) as $seg) {
        if ($seg === '' || $seg === '.' || $seg === '..') return false;
    }

    if (strncmp($p, 'img_uploads/', 12) !== 0) return false;

    // Conservative charset — no spaces, quotes, or anything needing escaping
    // downstream in a shell, an SQL LIKE, or an HTML attribute.
    return (bool)preg_match('#^[A-Za-z0-9._/-]+$#', $p);
}
}

/**
 * Normalise a user-supplied link, or NULL if it is not a plain http(s) URL.
 *
 * SECAUDIT 040 finding D. An imported comment's author URL is stored in
 * snap_community_comments.guest_url and rendered as a link href by
 * core/community-component.php. htmlspecialchars() there stops attribute
 * breakout but does NOT stop a javascript: scheme — escaping is not scheme
 * validation, and the two are often confused.
 *
 * Returns the URL unchanged when acceptable, NULL otherwise (callers store NULL
 * and the name renders unlinked).
 */
if (!function_exists('snap_api_safe_link')) {
function snap_api_safe_link(string $url): ?string {
    $url = trim($url);
    if ($url === '' || strlen($url) > 500)     return null;
    if (preg_match('#[\x00-\x1F\x7F]#', $url)) return null;   // control chars / newlines
    if (!filter_var($url, FILTER_VALIDATE_URL)) return null;

    $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
    if ($scheme !== 'http' && $scheme !== 'https') return null;

    return $url;
}
}
// ===== SNAPSMACK EOF =====
