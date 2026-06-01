<?php
/**
 * SNAPSMACK.CA — OI MATE! - Contact Sean
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

$page_title       = 'OI MATE! - Contact Sean';
$page_description = 'Get in touch with Sean McCormick about SnapSmack.';
$page_og_url      = 'https://snapsmack.ca/oi.php';
$nav_active       = 'oi';
$page_css = <<<'CSS'
/* ─── MAIN CONTENT ──────────────────────────────────────────────────────── */
main { margin-top: 0; }
.page-header { padding: 80px 0 72px; border-bottom: 1px solid var(--border); }
.page-header h1 { margin-bottom: 8px; }
.content { padding: 60px 0; max-width: 820px; }
.content p { margin-bottom: 1.5em; }

/* ─── CONTACT CARD ──────────────────────────────────────────────────────── */
.contact-card {
    background: var(--light-grey);
    border: 2px solid var(--border);
    padding: 32px;
    margin-bottom: 40px;
    max-width: 480px;
}
.contact-card h3 { color: var(--black); margin-bottom: 4px; font-size: 1.3rem; }
.contact-card .title {
    font-size: 0.95rem; color: var(--mid-grey); margin-bottom: 20px;
    text-transform: uppercase; letter-spacing: 0.04em;
}
.contact-info { margin-bottom: 24px; }
.contact-info label {
    display: block;
    font-family: Arial Black, Arial, sans-serif;
    font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.04em;
    color: var(--dark-grey); margin-bottom: 6px; font-weight: 700;
}
.contact-info a, .contact-info p { font-size: 1rem; color: var(--red); margin: 0; }
.contact-info a:hover { text-decoration: underline; }
.contact-note {
    background: var(--white);
    border-left: 3px solid var(--red);
    padding: 16px; margin-top: 24px;
    font-style: italic; font-size: 0.95rem; color: var(--dark-grey);
}
CSS;
$page_footer_script = '<script>
(function(){
    var u=\'sean\',d=\'baddaywithacamera.ca\';
    var el = document.getElementById(\'contact-email\');
    if (el) { var a = document.createElement(\'a\'); a.href = \'mailto:\' + u + \'@\' + d; a.textContent = u + \'@\' + d; el.appendChild(a); }
})();
</script>';
include __DIR__ . '/includes/header.php';
?>

<!-- MAIN CONTENT -->
<main>
    <div class="page-header">
        <div class="wrap">
            <h1>OI MATE!</h1>
            <p class="lede">Get in touch with Sean about SnapSmack</p>
        </div>
    </div>

    <section>
        <div class="wrap">
            <div class="content">
                <h2>Let's Chat</h2>
                <img src="img/sean-paddleboard.jpg" alt="Sean on a paddleboard" style="width: 100%; display: block; margin-bottom: 1.5em;">
                <p>Got questions about SnapSmack? Just want to say hello? Reach out using any of the methods below.</p>

                <div class="contact-card">
                    <h3>Sean McCormick</h3>
                    <div class="title">SnapSmack Creator</div>

                    <div class="contact-info">
                        <label>Email</label>
                        <a href="mailto:sean@baddaywithacamera.ca">sean@baddaywithacamera.ca</a>
                    </div>

                    <div class="contact-info">
                        <label>Signal</label>
                        <p>Available on Signal</p>
                    </div>

                    <div class="contact-info">
                        <label>Links</label>
                        <a href="https://linktr.ee/mccormickphotography" target="_blank">linktr.ee/mccormickphotography</a>
                    </div>

                    <div class="contact-note">
                        <strong>Note on communication:</strong> I don't do voice or video calls due to hearing impairment. Email is best. Signal is erratic — I have a hard time keeping a normal schedule (Asperger's).
                    </div>
                </div>
            </div>
        </div>
    </section>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
