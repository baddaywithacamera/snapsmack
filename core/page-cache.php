<?php
/**
 * SNAPSMACK - Opt-in anonymous full-page cache
 *
 * Conservative, opt-in (cache_enabled, default '0'). Caches the rendered HTML of
 * heavy public read views for ANONYMOUS visitors only, on plain GET requests
 * with no query string. Logged-in admins and any query-param request bypass
 * entirely, so per-user / dynamic content is never served from cache.
 *
 * What is intentionally NOT cached (would be wrong if frozen): admin pages, any
 * logged-in session, query-param views (?slug/?layout/?category/...), POSTs.
 * Like/comment counts may lag up to cache_ttl — accepted (Sean, spec #6) — and
 * are also flushed by page_cache_purge_all() on publish / comment / like.
 *
 * The public guest comment form submits via JS (no session CSRF token baked into
 * the HTML), so caching the page does not freeze a per-session token.
 *
 * Usage on a public page, immediately after $settings is loaded and AFTER the
 * maintenance gate:  page_cache_serve_or_start($settings);
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

/** Absolute path to the page-cache directory (created on demand). */
function page_cache_dir(): string {
    return dirname(__DIR__) . '/data/cache/pages';
}

/** Cache key → file path for the current request. */
function page_cache_file(): string {
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    // Strip any query string defensively (we only cache no-query requests).
    $uri = strtok($uri, '?');
    $host = $_SERVER['HTTP_HOST'] ?? '';
    return page_cache_dir() . '/' . sha1($host . '|' . $uri) . '.html';
}

/** Is this request eligible to be served from / written to cache? */
function page_cache_eligible(array $settings): bool {
    if (($settings['cache_enabled'] ?? '0') !== '1') return false;
    // Dev mode: caching is paused (bypassed entirely) until cache_dev_until, an
    // absolute unix timestamp set from the admin's chosen 5min–1week window.
    // Auto-resumes once the timestamp passes — no need to remember to switch back.
    if ((int)($settings['cache_dev_until'] ?? 0) > time()) return false;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return false;
    // Bypass on a REAL user query string. Note: pretty URLs are internally
    // rewritten to ?name=slug by .htaccess, so QUERY_STRING is non-empty even on
    // cacheable photo pages — we therefore test REQUEST_URI for a literal '?'
    // (absent on rewritten pretty URLs, present on ?layout=/?category=/?id= etc.).
    if (strpos($_SERVER['REQUEST_URI'] ?? '/', '?') !== false) return false;
    // Any session cookie present → treat as a possible logged-in admin; bypass.
    if (!empty($_COOKIE[session_name()])) return false;
    // Belt-and-suspenders: a live session with a login also bypasses.
    if (!empty($_SESSION['user_login'])) return false;
    return true;
}

/**
 * Serve a fresh cached copy if one exists, otherwise start output buffering so
 * the rendered page is written to cache on completion. No-op when ineligible.
 */
function page_cache_serve_or_start(array $settings): void {
    if (!page_cache_eligible($settings)) return;

    $ttl  = (int)($settings['cache_ttl'] ?? 300);
    if ($ttl < 1) $ttl = 300;
    $file = page_cache_file();

    // Serve a fresh hit.
    if (is_file($file) && (time() - filemtime($file)) < $ttl) {
        header('X-SnapSmack-Cache: HIT');
        readfile($file);
        exit;
    }

    // Miss: ensure the dir exists, then buffer and write on flush.
    $dir = page_cache_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
        @file_put_contents($dir . '/.htaccess', "Order deny,allow\nDeny from all\n");
    }
    if (!is_dir($dir) || !is_writable($dir)) return; // can't cache — render normally

    header('X-SnapSmack-Cache: MISS');
    ob_start(function (string $buffer) use ($file) {
        // Only persist successful, non-empty HTML responses.
        if (strlen($buffer) > 0 && http_response_code() === 200) {
            @file_put_contents($file, $buffer, LOCK_EX);
        }
        return $buffer;
    });
}

/** Flush the entire page cache. Call on publish / comment / like / settings save. */
function page_cache_purge_all(): void {
    $dir = page_cache_dir();
    if (!is_dir($dir)) return;
    foreach (glob($dir . '/*.html') ?: [] as $f) {
        @unlink($f);
    }
}
// ===== SNAPSMACK EOF =====
