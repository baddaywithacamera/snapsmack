<?php
/**
 * SNAPSMACK - API site-scope enforcement (mutual-auth A1, SECAUDIT 054)
 *
 * WHAT IT CLOSES. The fleet installs ONE key value on every spoke so the desktop
 * tools work everywhere with one credential. That means a request a tool meant
 * for site A is accepted by site B — the key is valid there too. Until now the
 * only thing stopping a wrong-site write was the TOOL's own check (GYSS's
 * resume guard, COLD SNAP's profile). That is the asking side promising to
 * behave. This is the server refusing to take its word for it.
 *
 * HOW. A tool states which site it is talking to in a request header:
 *
 *     X-Snap-Site: pixhellated.ca        (host, or a full URL — host is taken)
 *
 * The server compares that with its own host (snap_settings.site_url, falling
 * back to HTTP_HOST). Mismatch on a WRITE (anything but GET/HEAD/OPTIONS) with a
 * Bearer key → 403 `wrong_site`, with the two hosts named so the operator can
 * see exactly what happened. Reads are never refused: a read against the wrong
 * site leaks nothing the key could not read anyway, and refusing reads would
 * make discovery/heartbeat brittle for no gain.
 *
 * ROLLOUT (Sean's closeout spec, Tier 2 — fleet blast if wrong):
 *   snap_settings.api_site_scope =
 *     ''/'off'   — no check (DEFAULT on every existing site; the code ships dark)
 *     'enforce'  — header present + mismatch → 403; header absent → allowed
 *                  (old tools keep working; new tools get the real check)
 *     'require'  — header absent on a Bearer write → 403 too
 *                  (only after every tool in the field sends it)
 *   Set it on ONE spoke first. The 403 body names both hosts.
 *
 * Applies to the two API doors: core/api-auth.php (the classic tool endpoints)
 * and api.php (every routed API). Session/admin requests are untouched.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

if (!function_exists('snap_api_site_scope_host')) {
    /** Lower-case host with no port, from a host or a URL. '' if none. */
    function snap_api_site_scope_host(string $v): string {
        $v = trim($v);
        if ($v === '') return '';
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $v)) {
            $h = (string)parse_url($v, PHP_URL_HOST);
        } else {
            $h = preg_replace('#[/?\#].*$#', '', $v);
            $h = preg_replace('#:\d+$#', '', $h);
        }
        $h = strtolower(trim($h, '[]. '));
        return preg_match('/^[a-z0-9.-]+$/', $h) ? $h : '';
    }

    /** The site's own host: site_url setting first, HTTP_HOST as fallback. */
    function snap_api_site_scope_self(PDO $pdo): string {
        $own = '';
        try {
            $own = snap_api_site_scope_host((string)($pdo->query(
                "SELECT setting_val FROM snap_settings WHERE setting_key='site_url' LIMIT 1"
            )->fetchColumn() ?: ''));
        } catch (Throwable $e) {
            $own = '';
        }
        if ($own === '') $own = snap_api_site_scope_host((string)($_SERVER['HTTP_HOST'] ?? ''));
        return $own;
    }

    /** Emit the 403 and stop. Tests define SNAP_SCOPE_THROW to get an exception instead of an exit. */
    function snap_api_site_scope_refuse(array $body): void {
        if (defined('SNAP_SCOPE_THROW')) throw new RuntimeException(json_encode($body));
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode($body);
        exit;
    }

    /**
     * Refuse a Bearer WRITE aimed at another site. Returns silently when the
     * check is off, the request is a read, there is no Bearer header, or the
     * declared site matches. Exits with 403 JSON otherwise.
     */
    function snap_api_site_scope_check(PDO $pdo): void {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) return;

        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (!$auth && function_exists('getallheaders')) {
            $hh = getallheaders();
            $auth = $hh['Authorization'] ?? $hh['authorization'] ?? '';
        }
        if (stripos((string)$auth, 'Bearer ') !== 0) return;   // session/admin, not a tool key

        try {
            $mode = strtolower(trim((string)($pdo->query(
                "SELECT setting_val FROM snap_settings WHERE setting_key='api_site_scope' LIMIT 1"
            )->fetchColumn() ?: '')));
        } catch (Throwable $e) {
            $mode = '';
        }
        if ($mode !== 'enforce' && $mode !== 'require') return;

        $declared = (string)($_SERVER['HTTP_X_SNAP_SITE'] ?? '');
        if ($declared === '' && function_exists('getallheaders')) {
            $hh = $hh ?? getallheaders();
            $declared = (string)($hh['X-Snap-Site'] ?? $hh['x-snap-site'] ?? '');
        }
        $declared_host = snap_api_site_scope_host($declared);
        $own = snap_api_site_scope_self($pdo);

        if ($declared_host === '') {
            if ($mode !== 'require') return;               // enforce: old tool, let it through
            snap_api_site_scope_refuse([
                'ok'    => false,
                'error' => 'This site requires every tool write to name its target site '
                         . '(X-Snap-Site header). Update the tool.',
                'code'  => 'site_scope_missing',
                'site'  => $own,
            ]);
        }
        if ($own !== '' && $declared_host !== $own) {
            snap_api_site_scope_refuse([
                'ok'       => false,
                'error'    => "Wrong site: this request was meant for {$declared_host} but reached {$own}. Refused.",
                'code'     => 'wrong_site',
                'declared' => $declared_host,
                'site'     => $own,
            ]);
        }
    }
}

// ===== SNAPSMACK EOF =====
