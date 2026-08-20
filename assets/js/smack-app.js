/** SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment. */
/**
 * SMACK THAT APP UP composer client behaviour. Autosaves a draft of the app post
 * form to localStorage as you type, shows an upload-progress status bar, and
 * clears saved drafts on logout. Loaded by the CMS on the composer page; not a
 * skin asset.
 */
(function () {
    'use strict';
    var form = document.querySelector('body.smack-app-composer form[method="POST" i]');
    if (!form) return;

    var key = 'snapsmack-app-draft:' + location.pathname;
    var logout = document.querySelector('[data-app-logout]');
    if (logout) logout.addEventListener('click', function () {
        Object.keys(localStorage).forEach(function (storedKey) {
            if (storedKey.indexOf('snapsmack-app-draft:') === 0) localStorage.removeItem(storedKey);
        });
    });
    var status = document.createElement('div');
    status.className = 'smack-app-status';
    status.setAttribute('role', 'status');
    status.innerHTML = '<span>UPLOADING</span><progress max="100" value="0"></progress>';
    document.body.appendChild(status);

    function snapshot() {
        var values = {};
        form.querySelectorAll('input:not([type=file]):not([type=password]):not([type=hidden]),textarea,select').forEach(function (field) {
            if (!field.name) return;
            values[field.name] = (field.type === 'checkbox' || field.type === 'radio') ? field.checked : field.value;
        });
        localStorage.setItem(key, JSON.stringify({saved:Date.now(), values:values}));
    }

    var saved;
    try { saved = JSON.parse(localStorage.getItem(key) || 'null'); } catch (_) { saved = null; }
    if (saved && Date.now() - saved.saved < 7 * 86400000) {
        Object.keys(saved.values || {}).forEach(function (name) {
            var field = form.elements[name];
            if (!field || field instanceof RadioNodeList) return;
            if ((field.type === 'checkbox' || field.type === 'radio')) field.checked = !!saved.values[name];
            else if (!field.value) field.value = saved.values[name];
        });
        var note = document.createElement('div');
        note.className = 'smack-app-draft';
        note.textContent = 'Recovered your locally saved form text. Media must be selected again.';
        form.prepend(note);
    }

    var saveTimer;
    form.addEventListener('input', function () { clearTimeout(saveTimer); saveTimer = setTimeout(snapshot, 250); });
    form.addEventListener('change', snapshot);

    var submitting = false;
    form.addEventListener('submit', function () {
        if (submitting) return false;
        submitting = true;
        snapshot();
        form.querySelectorAll('button[type=submit],input[type=submit]').forEach(function (button) { button.disabled = true; });
        if (form.querySelector('input[type=file]') && Array.from(form.querySelectorAll('input[type=file]')).some(function (f) { return f.files && f.files.length; })) {
            status.classList.add('is-visible');
            var progress = status.querySelector('progress');
            progress.removeAttribute('value');
        }
    });

    addEventListener('beforeunload', function (event) {
        if (!submitting && localStorage.getItem(key)) {
            event.preventDefault(); event.returnValue = '';
        }
    });
}());
// ===== SNAPSMACK EOF =====
