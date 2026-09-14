/** SMACKTHEMUP public sharing helpers. No data leaves until a visitor acts. */
(function () {
    'use strict';
    document.addEventListener('click', function (event) {
        var button = event.target.closest('.stu-copy-link');
        if (!button) return;
        event.preventDefault();
        var url = button.getAttribute('data-share-url') || window.location.href;
        var original = button.textContent;
        function done() {
            button.textContent = 'COPIED';
            window.setTimeout(function () { button.textContent = original; }, 1800);
        }
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(url).then(done, function () {});
            return;
        }
        var field = document.createElement('textarea');
        field.value = url; field.setAttribute('readonly', '');
        field.style.position = 'fixed'; field.style.opacity = '0';
        document.body.appendChild(field); field.select();
        try { if (document.execCommand('copy')) done(); } catch (ignore) {}
        document.body.removeChild(field);
    });
}());
// ===== SNAPSMACK EOF =====
