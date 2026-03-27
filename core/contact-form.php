<?php
/**
 * SNAPSMACK - Contact Form Shortcode
 * Alpha v0.7.6
 *
 * Renders a simple contact form via [snapsmack_contact] shortcode.
 * Fields: name, email, message. Sends email via PHP mail(). No database
 * storage. Honeypot field for spam protection. Photographer email visible.
 *
 * Usage: place [snapsmack_contact] in any static page content.
 * Processed by core/page-renderer.php when rendering static pages.
 */

/**
 * Processes the contact form if submitted. Returns HTML for the form.
 *
 * @param PDO    $pdo       Database connection
 * @param array  $settings  Site settings array
 * @return string  HTML output
 */
function snapsmack_contact_form(PDO $pdo, array $settings): string {
    $admin_email = $settings['admin_email'] ?? $settings['site_email'] ?? '';
    $site_name   = $settings['site_name'] ?? 'SnapSmack';
    $result_html = '';

    // Process form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['snapsmack_contact_nonce'])) {
        $result_html = _snapsmack_contact_process($admin_email, $site_name);
    }

    // Build form HTML
    $nonce = bin2hex(random_bytes(16));
    $_SESSION['snapsmack_contact_nonce'] = $nonce;

    $name_val  = htmlspecialchars($_POST['contact_name'] ?? '');
    $email_val = htmlspecialchars($_POST['contact_email'] ?? '');
    $msg_val   = htmlspecialchars($_POST['contact_message'] ?? '');

    $html = $result_html;
    $html .= '<div class="snapsmack-contact-form">';
    $html .= '<form method="POST">';
    $html .= '<input type="hidden" name="snapsmack_contact_nonce" value="' . $nonce . '">';

    // Honeypot — hidden field, bots fill it, humans don't
    $html .= '<div style="position:absolute;left:-9999px;"><input type="text" name="contact_website" tabindex="-1" autocomplete="off"></div>';

    $html .= '<div class="contact-field">';
    $html .= '  <label for="contact-name">NAME</label>';
    $html .= '  <input type="text" id="contact-name" name="contact_name" value="' . $name_val . '" required>';
    $html .= '</div>';

    $html .= '<div class="contact-field">';
    $html .= '  <label for="contact-email">EMAIL</label>';
    $html .= '  <input type="email" id="contact-email" name="contact_email" value="' . $email_val . '" required>';
    $html .= '</div>';

    $html .= '<div class="contact-field">';
    $html .= '  <label for="contact-message">MESSAGE</label>';
    $html .= '  <textarea id="contact-message" name="contact_message" rows="6" required>' . $msg_val . '</textarea>';
    $html .= '</div>';

    $html .= '<button type="submit" class="contact-submit">SEND MESSAGE</button>';
    $html .= '</form>';

    // Show photographer email below the form
    if ($admin_email) {
        $html .= '<p style="margin-top:20px;font-size:13px;color:var(--text-secondary, #888);">';
        $html .= 'Or email directly: <a href="mailto:' . htmlspecialchars($admin_email) . '">' . htmlspecialchars($admin_email) . '</a>';
        $html .= '</p>';
    }

    $html .= '</div>';

    return $html;
}

/**
 * Validates and sends the contact form email.
 * Returns HTML success/error message.
 */
function _snapsmack_contact_process(string $admin_email, string $site_name): string {
    // Verify nonce
    $nonce = $_POST['snapsmack_contact_nonce'] ?? '';
    if (!isset($_SESSION['snapsmack_contact_nonce']) || $nonce !== $_SESSION['snapsmack_contact_nonce']) {
        return '<div class="contact-error">Form validation failed. Please try again.</div>';
    }
    unset($_SESSION['snapsmack_contact_nonce']);

    // Honeypot check — if filled, it's a bot
    if (!empty($_POST['contact_website'])) {
        // Silently succeed to confuse the bot
        return '<div class="contact-success">Thank you. Your message has been sent.</div>';
    }

    $name    = trim($_POST['contact_name'] ?? '');
    $email   = trim($_POST['contact_email'] ?? '');
    $message = trim($_POST['contact_message'] ?? '');

    if (empty($name) || empty($email) || empty($message)) {
        return '<div class="contact-error">All fields are required.</div>';
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return '<div class="contact-error">Please enter a valid email address.</div>';
    }

    if (empty($admin_email)) {
        return '<div class="contact-error">Contact form is not configured. No admin email set.</div>';
    }

    // Send email
    $subject = "[$site_name] Contact form message from $name";
    $body    = "Name: $name\nEmail: $email\n\nMessage:\n$message";
    $headers = "From: $email\r\nReply-To: $email\r\nX-Mailer: SnapSmack";

    $sent = @mail($admin_email, $subject, $body, $headers);

    if ($sent) {
        // Clear form values on success
        $_POST['contact_name'] = '';
        $_POST['contact_email'] = '';
        $_POST['contact_message'] = '';
        return '<div class="contact-success">Thank you. Your message has been sent.</div>';
    } else {
        return '<div class="contact-error">Message could not be sent. Please try emailing directly.</div>';
    }
}
