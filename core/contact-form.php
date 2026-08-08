<?php
/**
 * SNAPSMACK - Contact Form Shortcode
 *
 * Renders a simple contact form via [snapsmack_contact] shortcode.
 * Fields: name, email, message. No database storage. Honeypot field for spam
 * protection. Photographer email visible.
 *
 * Mail goes through core/mailer.php — Brevo's HTTP API when `brevo_api_key` is
 * set, PHP mail() only as the fallback. From is the site's own verified sender
 * (Brevo rejects unverified senders and From-spoofing fails SPF/DKIM); the
 * visitor's address goes to Reply-To.
 *
 * Usage: place [snapsmack_contact] in any static page's content. The shortcode
 * is expanded by core/parser.php (parseContactForm phase); the rendered <form>
 * is submitted by assets/js/ss-engine-contact.js (inventory key smack-contact)
 * to process-contact.php.
 *
 * Cache-safe by design: this renders static markup with NO per-session CSRF
 * token and never calls session_start(). Baking a session nonce into the HTML
 * would fight the opt-in page cache both ways — a session cookie drops the
 * visitor out of the cache site-wide (page_cache_eligible), and a nonce frozen
 * into cached HTML fails validation for everyone after the first hit. Abuse is
 * handled server-side in process-contact.php (honeypot + IP rate limit + ban
 * list + keyword filter), the same model the guest comment form uses.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


/**
 * Renders the contact form markup. Pure output — no POST handling, no session,
 * no per-request state, so the result is safe to freeze in the page cache.
 * Submission is handled entirely by ss-engine-contact.js → process-contact.php.
 *
 * @param PDO    $pdo       Database connection (unused today; kept for the
 *                          parser's uniform render signature)
 * @param array  $settings  Site settings array
 * @return string  HTML output
 */
function snapsmack_contact_form(PDO $pdo, array $settings): string {
    $admin_email = $settings['admin_email'] ?? $settings['site_email'] ?? '';

    $html  = '<div class="snapsmack-contact-form" data-contact-endpoint="process-contact.php">';
    $html .= '<form class="snapsmack-contact-form__form" novalidate>';

    // Honeypot — hidden field, bots fill it, humans don't.
    $html .= '<div style="position:absolute;left:-9999px;" aria-hidden="true">';
    $html .= '<input type="text" name="contact_website" tabindex="-1" autocomplete="off"></div>';

    $html .= '<div class="contact-field">';
    $html .= '  <label for="contact-name">NAME</label>';
    $html .= '  <input type="text" id="contact-name" name="contact_name" required>';
    $html .= '</div>';

    $html .= '<div class="contact-field">';
    $html .= '  <label for="contact-email">EMAIL</label>';
    $html .= '  <input type="email" id="contact-email" name="contact_email" required>';
    $html .= '</div>';

    $html .= '<div class="contact-field">';
    $html .= '  <label for="contact-message">MESSAGE</label>';
    $html .= '  <textarea id="contact-message" name="contact_message" rows="6" required></textarea>';
    $html .= '</div>';

    $html .= '<button type="submit" class="contact-submit">SEND MESSAGE</button>';
    $html .= '<div class="contact-result" role="status" aria-live="polite"></div>';
    $html .= '</form>';

    // Show photographer email below the form.
    if ($admin_email) {
        $safe = htmlspecialchars($admin_email);
        $html .= '<p style="margin-top:20px;font-size:13px;color:var(--text-secondary, #888);">';
        $html .= 'Or email directly: <a href="mailto:' . $safe . '">' . $safe . '</a>';
        $html .= '</p>';
    }

    $html .= '</div>';

    return $html;
}
// ===== SNAPSMACK EOF =====
