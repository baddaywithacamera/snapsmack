<?php
/**
 * SNAPSMACK - Central Mailer
 *
 * Single send path for all transactional email. Uses Brevo's HTTP API
 * (api.brevo.com/v3/smtp/email) when `brevo_api_key` is configured, so mail
 * leaves the box over outbound HTTPS only — no SMTP ports, no home-IP exposure
 * to the ISP, and delivery does NOT depend on the multisite hub being up.
 * Falls back to PHP mail() when Brevo isn't configured (or on a Brevo error).
 *
 *   snapsmack_send_mail($to, $subject, $body, $opts): bool
 *     $opts: ['pdo'=>PDO, 'html'=>bool, 'from_email'=>str, 'from_name'=>str,
 *             'reply_to'=>str, 'settings'=>array]
 *   Returns true if the message was accepted (by Brevo, or by mail()).
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

if (!function_exists('snapsmack_mail_settings')) {
function snapsmack_mail_settings(PDO $pdo): array {
    try {
        return $pdo->query(
            "SELECT setting_key, setting_val FROM snap_settings
             WHERE setting_key IN (
                 'brevo_api_key','email_from','email_from_name',
                 'site_name','site_url','admin_email'
             )"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Throwable $e) {
        return [];
    }
}
}

if (!function_exists('snapsmack_send_mail')) {
function snapsmack_send_mail(string $to, string $subject, string $body, array $opts = []): bool {
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $pdo      = $opts['pdo'] ?? ($GLOBALS['pdo'] ?? null);
    $settings = $opts['settings'] ?? ($pdo instanceof PDO ? snapsmack_mail_settings($pdo) : []);

    $is_html    = !empty($opts['html']);
    $host       = parse_url($settings['site_url'] ?? '', PHP_URL_HOST) ?: 'localhost';
    $from_email = $opts['from_email'] ?? ($settings['email_from'] ?? ('noreply@' . $host));
    $from_name  = $opts['from_name']  ?? ($settings['email_from_name'] ?? ($settings['site_name'] ?? 'SnapSmack'));
    $reply_to   = trim($opts['reply_to'] ?? '');
    $brevo_key  = trim($settings['brevo_api_key'] ?? '');

    // ── Brevo HTTP API path ────────────────────────────────────────────────
    if ($brevo_key !== '' && function_exists('curl_init')) {
        $payload = [
            'sender'  => ['email' => $from_email, 'name' => $from_name],
            'to'      => [['email' => $to]],
            'subject' => $subject,
        ];
        $payload[$is_html ? 'htmlContent' : 'textContent'] = $body;
        if ($reply_to !== '') {
            $payload['replyTo'] = ['email' => $reply_to];
        }

        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'api-key: ' . $brevo_key,
                'content-type: application/json',
                'accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($code >= 200 && $code < 300) {
            return true;
        }
        // Brevo failed — log and fall through to mail() so the message still tries.
        error_log("SNAPSMACK mailer: Brevo send failed (HTTP {$code} {$err}) — falling back to mail() for {$to}");
    }

    // ── PHP mail() fallback ────────────────────────────────────────────────
    $headers  = 'From: ' . $from_name . ' <' . $from_email . ">\r\n";
    if ($reply_to !== '') {
        $headers .= 'Reply-To: ' . $reply_to . "\r\n";
    }
    $headers .= 'Content-Type: text/' . ($is_html ? 'html' : 'plain') . "; charset=UTF-8\r\n";
    $headers .= 'X-Mailer: SnapSmack';

    return (bool) @mail($to, $subject, $body, $headers);
}
}
// ===== SNAPSMACK EOF =====
