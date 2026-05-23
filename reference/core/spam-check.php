<?php
/**
 * SNAPSMACK - Akismet Spam Filter
 *
 * Checks incoming comments against the Akismet spam detection service.
 * Requires an Akismet API key to be stored in the database settings.
 * Returns false (no spam) if no key is configured.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


function is_spam($author, $email, $body, $pdo) {
    // --- API KEY LOOKUP ---
    $stmt        = $pdo->query("SELECT setting_val FROM snap_settings WHERE setting_key = 'akismet_key'");
    $akismet_key = $stmt->fetchColumn();

    // If no key is set, fail open and allow the comment through.
    if (!$akismet_key) return false;

    // --- SITE URL ---
    // Pull from settings; fall back to current host. Never hardcode.
    $stmt2    = $pdo->query("SELECT setting_val FROM snap_settings WHERE setting_key = 'site_url'");
    $blog_url = rtrim($stmt2->fetchColumn() ?: ('https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')), '/');

    // --- REQUEST PAYLOAD ---
    $payload = http_build_query([
        'blog'                 => $blog_url,
        'user_ip'              => $_SERVER['REMOTE_ADDR']     ?? '',
        'user_agent'           => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'referrer'             => $_SERVER['HTTP_REFERER']    ?? '',
        'comment_type'         => 'comment',
        'comment_author'       => $author,
        'comment_author_email' => $email,
        'comment_content'      => $body,
    ]);

    // --- HTTPS REQUEST VIA CURL ---
    // fsockopen on port 80 is deprecated and blocked by many hosts.
    $url = "https://{$akismet_key}.rest.akismet.com/1.1/comment-check";
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_USERAGENT      => 'SnapSmack/' . (defined('SNAPSMACK_VERSION') ? SNAPSMACK_VERSION : '0'),
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    // Akismet returns "true" in the body if the comment is spam.
    return (trim($response ?: '') === 'true');
}
// ===== SNAPSMACK EOF =====
