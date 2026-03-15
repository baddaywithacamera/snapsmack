<?php
/**
 * SNAPSMACK - Community Session Manager
 * Alpha v0.7.4
 *
 * Manages visitor (community user) authentication state for public-facing
 * pages. Parallel to core/auth.php but for snap_community_users, not admins.
 *
 * Usage — pages that require a logged-in community user:
 *   require_once 'core/community-session.php';
 *   $community_user = community_require_login('/the/current/page');
 *   // $community_user is now the authenticated user row.
 *
 * Usage — pages where login is optional (e.g. layout.php showing like button):
 *   require_once 'core/community-session.php';
 *   $community_user = community_current_user();
 *   // $community_user is user row or null if not logged in.
 */

if (!isset($pdo)) {
    require_once __DIR__ . '/db.php';
}

// --- CONSTANTS ---
define('COMMUNITY_COOKIE_NAME',    'ss_community_token');
define('COMMUNITY_COOKIE_PATH',    '/');
define('COMMUNITY_SESSION_DAYS',   30);   // fallback if DB setting missing


// ---------------------------------------------------------------------------
// community_current_user()
//
// Returns the authenticated community user row (associative array) if a valid
// session token is present, or null if the visitor is not logged in.
// Validates against snap_community_sessions and checks expiry.
// Also sweeps a small batch of expired sessions on each call (1-in-20 chance)
// to keep the table tidy without a dedicated cron.
// ---------------------------------------------------------------------------
function community_current_user(): ?array {
    global $pdo;

    $token = $_COOKIE[COMMUNITY_COOKIE_NAME] ?? null;
    if (!$token) {
        return null;
    }

    // Validate token and load user in one query
    $stmt = $pdo->prepare("
        SELECT u.id, u.username, u.display_name, u.email, u.avatar_url,
               u.bio, u.email_verified, u.status, s.expires_at
        FROM snap_community_sessions s
        JOIN snap_community_users u ON u.id = s.user_id
        WHERE s.token = ?
          AND s.expires_at > NOW()
          AND u.status = 'active'
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if (!$user) {
        // Invalid or expired token — clear the cookie
        community_clear_cookie();
        return null;
    }

    // Update last_seen_at (non-critical, ignore failure)
    try {
        $pdo->prepare("UPDATE snap_community_users SET last_seen_at = NOW() WHERE id = ?")
            ->execute([$user['id']]);
    } catch (Exception $e) {}

    // Probabilistic expired session sweep (5% of requests)
    if (rand(1, 20) === 1) {
        community_sweep_expired_sessions();
    }

    unset($user['expires_at']); // don't expose internals to callers
    return $user;
}


// ---------------------------------------------------------------------------
// community_require_login($redirect_back)
//
// Same as community_current_user() but redirects to the login page if the
// visitor is not authenticated. Pass the current URL so login can redirect
// back after success.
// ---------------------------------------------------------------------------
function community_require_login(string $redirect_back = ''): array {
    $user = community_current_user();
    if (!$user) {
        $dest = '/community-auth.php?action=login';
        if ($redirect_back) {
            $dest .= '&redirect=' . urlencode($redirect_back);
        }
        header('Location: ' . $dest);
        exit;
    }
    return $user;
}


// ---------------------------------------------------------------------------
// community_login($user_id)
//
// Creates a new session token, writes it to snap_community_sessions, and
// sets the cookie. Call after verifying credentials in community-auth.php.
// Returns the token string.
// ---------------------------------------------------------------------------
function community_login(int $user_id): string {
    global $pdo;

    $token    = community_generate_token();
    $days     = (int)community_setting('community_session_days', COMMUNITY_SESSION_DAYS);
    $expires  = date('Y-m-d H:i:s', strtotime("+{$days} days"));
    $ip       = $_SERVER['REMOTE_ADDR'] ?? null;
    $ua       = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);

    $pdo->prepare("
        INSERT INTO snap_community_sessions (user_id, token, expires_at, ip, user_agent)
        VALUES (?, ?, ?, ?, ?)
    ")->execute([$user_id, $token, $expires, $ip, $ua]);

    community_set_cookie($token, $days);

    return $token;
}


// ---------------------------------------------------------------------------
// community_logout()
//
// Invalidates the current session token and clears the cookie.
// ---------------------------------------------------------------------------
function community_logout(): void {
    global $pdo;

    $token = $_COOKIE[COMMUNITY_COOKIE_NAME] ?? null;
    if ($token) {
        $pdo->prepare("DELETE FROM snap_community_sessions WHERE token = ?")
            ->execute([$token]);
    }
    community_clear_cookie();
}


// ---------------------------------------------------------------------------
// community_logout_all($user_id)
//
// Logs the user out of every device. Used after password change or account
// suspension.
// ---------------------------------------------------------------------------
function community_logout_all(int $user_id): void {
    global $pdo;
    $pdo->prepare("DELETE FROM snap_community_sessions WHERE user_id = ?")
        ->execute([$user_id]);
    community_clear_cookie();
}


// ---------------------------------------------------------------------------
// community_generate_token()
//
// Generates a cryptographically secure 64-byte token, returned as a 128-char
// hex string.
// ---------------------------------------------------------------------------
function community_generate_token(): string {
    return bin2hex(random_bytes(64));
}


// ---------------------------------------------------------------------------
// community_set_cookie($token, $days)
//
// Writes the session cookie with secure defaults.
// ---------------------------------------------------------------------------
function community_set_cookie(string $token, int $days): void {
    $secure   = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
    $expires  = time() + ($days * 86400);
    setcookie(
        COMMUNITY_COOKIE_NAME,
        $token,
        [
            'expires'  => $expires,
            'path'     => COMMUNITY_COOKIE_PATH,
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]
    );
}


// ---------------------------------------------------------------------------
// community_clear_cookie()
//
// Deletes the session cookie from the browser.
// ---------------------------------------------------------------------------
function community_clear_cookie(): void {
    setcookie(COMMUNITY_COOKIE_NAME, '', [
        'expires'  => time() - 3600,
        'path'     => COMMUNITY_COOKIE_PATH,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    unset($_COOKIE[COMMUNITY_COOKIE_NAME]);
}


// ---------------------------------------------------------------------------
// community_sweep_expired_sessions()
//
// Deletes expired session rows. Called probabilistically on each request.
// Limit per sweep keeps runtime predictable.
// ---------------------------------------------------------------------------
function community_sweep_expired_sessions(): void {
    global $pdo;
    try {
        $pdo->exec("DELETE FROM snap_community_sessions WHERE expires_at < NOW() LIMIT 200");
    } catch (Exception $e) {}
}


// ---------------------------------------------------------------------------
// community_setting($key, $default)
//
// Reads a single value from snap_settings. Convenience wrapper so session
// functions don't need a pre-loaded $settings array.
// ---------------------------------------------------------------------------
function community_setting(string $key, mixed $default = null): mixed {
    global $pdo;
    static $cache = [];
    if (!array_key_exists($key, $cache)) {
        $stmt = $pdo->prepare("SELECT setting_val FROM snap_settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        $cache[$key] = ($val !== false) ? $val : $default;
    }
    return $cache[$key];
}


// ---------------------------------------------------------------------------
// snap_community_ready()
//
// Returns true if the community infrastructure tables have been created
// (i.e. the community migration has been run). Returns false silently if any
// required table is missing so callers can bail gracefully without crashing
// the page. Result is cached for the lifetime of the request.
// ---------------------------------------------------------------------------
function snap_community_ready(): bool {
    global $pdo;
    static $checked = null;
    if ($checked !== null) return $checked;
    try {
        $pdo->query("SELECT 1 FROM snap_community_sessions LIMIT 0");
        $checked = true;
    } catch (PDOException $e) {
        $checked = false;
    }
    return $checked;
}


// ---------------------------------------------------------------------------
// community_rate_limit($action, $limit_key)
//
// Returns true if the current IP is within the allowed rate for $action.
// Returns false if the limit has been exceeded.
// Window is 1 hour. Uses snap_rate_limits table.
// ---------------------------------------------------------------------------
function community_rate_limit(string $action): bool {
    global $pdo;

    $ip       = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $limit    = (int)community_setting('rate_limit_' . $action, 10);
    $window   = date('Y-m-d H:i:00', floor(time() / 3600) * 3600); // top of current hour

    // Upsert: insert new window row or increment existing
    $pdo->prepare("
        INSERT INTO snap_rate_limits (ip, action, count, window_start)
        VALUES (?, ?, 1, ?)
        ON DUPLICATE KEY UPDATE count = count + 1
    ")->execute([$ip, $action, $window]);

    $count = (int)$pdo->prepare("
        SELECT count FROM snap_rate_limits
        WHERE ip = ? AND action = ? AND window_start = ?
    ")->execute([$ip, $action, $window]) ? $pdo->query("SELECT FOUND_ROWS()")->fetchColumn() : 0;

    // Re-fetch the actual count after upsert
    $stmt = $pdo->prepare("
        SELECT count FROM snap_rate_limits
        WHERE ip = ? AND action = ? AND window_start = ?
        LIMIT 1
    ");
    $stmt->execute([$ip, $action, $window]);
    $count = (int)$stmt->fetchColumn();

    return $count <= $limit;
}
