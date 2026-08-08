<?php
/**
 * SNAPSMACK - Contact form submission handler
 *
 * AJAX endpoint for the [snapsmack_contact] shortcode form. Public, no session,
 * no cookie — so it never drops a visitor out of the opt-in page cache and no
 * per-session CSRF token is baked into cached HTML (the same model the guest
 * comment form uses; see core/page-cache.php).
 *
 * Abuse controls in place of a session nonce: a hidden honeypot field, the
 * shared IP rate limiter (rate_limit_contact), the ban list, and the keyword
 * filter. Mail leaves via core/mailer.php — Brevo's HTTP API when configured,
 * PHP mail() as fallback. From is the site's own verified sender; the visitor's
 * address goes to Reply-To so replies reach them.
 *
 * Returns JSON: { ok: true } or { ok: false, error: string }.
 *
 * POST params:
 *   contact_name     (string, required)
 *   contact_email    (string, required, must be a valid address)
 *   contact_message  (string, required)
 *   contact_website  (honeypot — must be empty)
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


header('Content-Type: application/json');

require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/client-ip.php';

// --- METHOD ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

// --- SETTINGS ---
$settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")
                ->fetchAll(PDO::FETCH_KEY_PAIR);

$admin_email = trim($settings['admin_email'] ?? $settings['site_email'] ?? '');
$site_name   = $settings['site_name'] ?? 'SnapSmack';

if ($admin_email === '' || !filter_var($admin_email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'not_configured']);
    exit;
}

// --- HONEYPOT ---
// A filled hidden field means a bot. Return success so it doesn't retry, but
// send nothing.
if (!empty($_POST['contact_website'])) {
    echo json_encode(['ok' => true]);
    exit;
}

// --- RATE LIMIT ---
// Reuse the shared per-IP hourly limiter when the community layer is present.
// Absent (community-off installs) → the ban list + honeypot still apply.
if (file_exists(__DIR__ . '/core/community-session.php')) {
    require_once __DIR__ . '/core/community-session.php';
}
if (function_exists('community_rate_limit') && !community_rate_limit('contact')) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'rate_limited']);
    exit;
}

// --- INPUT ---
// Strip CRLF from name/email before any header use — prevents header injection.
$name    = preg_replace('/[\r\n]+/', ' ', trim($_POST['contact_name']    ?? ''));
$email   = preg_replace('/[\r\n]+/', '',  trim($_POST['contact_email']   ?? ''));
$message = trim($_POST['contact_message'] ?? '');

if ($name === '' || $email === '' || $message === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_fields']);
    exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_email']);
    exit;
}

// --- ABUSE CHECKS ---
$ip      = snap_trusted_client_ip($pdo);
$fp_hash = preg_match('/^[0-9a-f]{64}$/', $_POST['fp_hash'] ?? '') ? $_POST['fp_hash'] : '';

if (file_exists(__DIR__ . '/core/ban-check.php')) {
    require_once __DIR__ . '/core/ban-check.php';
    if (function_exists('is_banned') && is_banned($pdo, $fp_hash, $ip, $email)) {
        // Silent: look like success to a banned sender, deliver nothing.
        echo json_encode(['ok' => true]);
        exit;
    }
}
if (file_exists(__DIR__ . '/core/keyword-check.php')) {
    require_once __DIR__ . '/core/keyword-check.php';
    if (function_exists('check_keywords')) {
        $kw = check_keywords($pdo, $name . "\n" . $email . "\n" . $message);
        if (($kw['severity'] ?? '') === 'reject') {
            echo json_encode(['ok' => true]);
            exit;
        }
    }
}

// --- SEND ---
$subject = "[$site_name] Contact form message from $name";
$body    = "Name: $name\nEmail: $email\nIP: $ip\n\nMessage:\n$message";

require_once __DIR__ . '/core/mailer.php';
$sent = snapsmack_send_mail($admin_email, $subject, $body, [
    'pdo'      => $pdo,
    'settings' => $settings,
    'reply_to' => $email,
]);

if ($sent) {
    echo json_encode(['ok' => true]);
} else {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'send_failed']);
}
// ===== SNAPSMACK EOF =====
