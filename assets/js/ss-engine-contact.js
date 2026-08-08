/**
 * SNAPSMACK - Contact Form Engine
 *
 * Progressive enhancement for the [snapsmack_contact] shortcode form. Submits
 * via fetch() to process-contact.php so the page carries no per-session CSRF
 * token and stays eligible for the opt-in page cache (see core/page-cache.php).
 *
 * Self-contained shared library asset (no inline JS, no per-skin copy). Loaded
 * through manifest-inventory.php key `smack-contact`. No-ops when the page has
 * no .snapsmack-contact-form, so it is safe to load anywhere.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


if (!window._ssContactLoaded) {
window._ssContactLoaded = true;

(function () {
    'use strict';

    function initForm(wrap) {
        if (wrap._ssContactBound) return;
        wrap._ssContactBound = true;

        var form = wrap.querySelector('form');
        if (!form) return;

        var endpoint = wrap.getAttribute('data-contact-endpoint') || 'process-contact.php';
        var result   = wrap.querySelector('.contact-result');
        var button   = form.querySelector('button[type="submit"], .contact-submit');

        function say(msg, ok) {
            if (!result) return;
            result.textContent = msg;
            result.className = 'contact-result ' + (ok ? 'contact-success' : 'contact-error');
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();

            var name    = (form.querySelector('[name="contact_name"]')    || {}).value || '';
            var email   = (form.querySelector('[name="contact_email"]')   || {}).value || '';
            var message = (form.querySelector('[name="contact_message"]') || {}).value || '';

            if (!name.trim() || !email.trim() || !message.trim()) {
                say('All fields are required.', false);
                return;
            }

            var fd = new FormData(form);
            if (button) { button.disabled = true; }
            say('Sending…', true);

            fetch(endpoint, {
                method: 'POST',
                body: fd,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            })
            .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
            .then(function (data) {
                if (data && data.ok) {
                    say('Thank you. Your message has been sent.', true);
                    form.reset();
                } else {
                    var err = (data && data.error) || '';
                    var friendly =
                        err === 'rate_limited'  ? 'Too many messages just now. Please try again later.' :
                        err === 'invalid_email' ? 'Please enter a valid email address.' :
                        err === 'not_configured'? 'The contact form is not configured yet.' :
                        'Message could not be sent. Please try emailing directly.';
                    say(friendly, false);
                    if (button) { button.disabled = false; }
                }
            })
            .catch(function () {
                say('Message could not be sent. Please try emailing directly.', false);
                if (button) { button.disabled = false; }
            });
        });
    }

    function initAll() {
        var forms = document.querySelectorAll('.snapsmack-contact-form');
        for (var i = 0; i < forms.length; i++) { initForm(forms[i]); }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }
})();

}
// ===== SNAPSMACK EOF =====
