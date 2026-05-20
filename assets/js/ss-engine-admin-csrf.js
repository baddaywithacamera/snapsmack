/**
 * SNAPSMACK - Admin CSRF AJAX Hook
 *
 * Reads the per-session CSRF token from the <meta name="csrf-token">
 * tag emitted by core/admin-header.php and auto-attaches it as an
 * X-CSRF-Token header to every POST request made via fetch() or
 * XMLHttpRequest from the admin pages.
 *
 * Forms get the same token as a hidden field via auto-injection in
 * core/admin-footer.php — that path doesn't go through this engine.
 *
 * If the token is missing (e.g. on a page that's mid-load and the meta
 * tag hasn't been parsed yet), AJAX calls just don't get the header
 * and will be rejected with 403 by core/csrf.php — the user reloads
 * and tries again. Fail-closed by design.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


(function () {
    'use strict';

    function readToken() {
        var el = document.querySelector('meta[name="csrf-token"]');
        return el ? el.getAttribute('content') : '';
    }

    var TOKEN = readToken();

    // Refresh the cached token when the page hits DOMContentLoaded —
    // the meta tag is in the head so it parses early, but be defensive.
    document.addEventListener('DOMContentLoaded', function () {
        var fresh = readToken();
        if (fresh) TOKEN = fresh;
    });

    // ── fetch() hook ───────────────────────────────────────────────────────

    if (typeof window.fetch === 'function') {
        var origFetch = window.fetch.bind(window);
        window.fetch = function (input, init) {
            init = init || {};
            var method = (init.method || (input && input.method) || 'GET').toUpperCase();
            if (method === 'POST' || method === 'PUT' || method === 'PATCH' || method === 'DELETE') {
                var headers = new Headers(init.headers || {});
                if (TOKEN && !headers.has('X-CSRF-Token')) {
                    headers.set('X-CSRF-Token', TOKEN);
                }
                init.headers = headers;
            }
            return origFetch(input, init);
        };
    }

    // ── XMLHttpRequest hook ────────────────────────────────────────────────

    var origOpen = XMLHttpRequest.prototype.open;
    var origSend = XMLHttpRequest.prototype.send;

    XMLHttpRequest.prototype.open = function (method, url) {
        this._csrfMethod = (method || 'GET').toUpperCase();
        return origOpen.apply(this, arguments);
    };

    XMLHttpRequest.prototype.send = function (body) {
        var m = this._csrfMethod || 'GET';
        if ((m === 'POST' || m === 'PUT' || m === 'PATCH' || m === 'DELETE') && TOKEN) {
            try {
                this.setRequestHeader('X-CSRF-Token', TOKEN);
            } catch (e) {
                // setRequestHeader throws if called after send() — harmless.
            }
        }
        return origSend.apply(this, arguments);
    };

}());
// ===== SNAPSMACK EOF =====
