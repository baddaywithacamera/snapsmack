/**
 * SNAPSMACK - Instant Camera scan-upload UX
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * Thumbnail previews + upload progress for the AI ASPECT-RATIO DETECTION scan
 * picker on smack-skin.php (Instant Camera). Reuses the solo-poster pattern
 * (FileReader thumbnails on select + an XHR upload progress bar) for what was a
 * bare <input type=file> with no feedback. The picker only takes 3 scans, so it
 * caps the preview at three and posts via XHR so the upload + AI step show
 * progress, then reloads to pick up the detected ratio + flash message.
 */
(function () {
    const input = document.getElementById('ic-scan-input');
    const form  = document.getElementById('ic-aspect-form');
    if (!input || !form) return;

    const preview = document.getElementById('ic-scan-preview');
    const pCont   = document.getElementById('ic-scan-progress');
    const pBar    = document.getElementById('ic-scan-bar');
    const btn     = form.querySelector('button[type="submit"]');

    // --- Thumbnails on select (client-side, no upload yet) ---
    input.addEventListener('change', function () {
        if (preview) preview.innerHTML = '';
        const files = Array.prototype.slice.call(this.files || [], 0, 3);
        files.forEach(function (f) {
            const img = document.createElement('img');
            img.alt = f.name;
            img.style.cssText = 'width:80px;height:80px;object-fit:cover;border-radius:4px;' +
                                'border:1px solid rgba(127,127,127,.45);background:#111;';
            const r = new FileReader();
            r.onload = function (e) { img.src = e.target.result; };
            r.readAsDataURL(f);
            if (preview) preview.appendChild(img);
        });
        if (preview && this.files && this.files.length > 3) {
            const note = document.createElement('div');
            note.className = 'dim field-hint';
            note.style.cssText = 'flex-basis:100%;margin-top:4px;';
            note.textContent = 'Only the first 3 scans are used.';
            preview.appendChild(note);
        }
    });

    // --- Upload with progress (reuses the solo XHR-progress pattern) ---
    form.addEventListener('submit', function (e) {
        if (!input.files || !input.files.length) return; // nothing chosen — let it be
        e.preventDefault();

        const fd = new FormData(form);
        // The submit button's name isn't auto-included on an XHR send, and the
        // server gate checks isset($_POST['ic_aspect_detect']) — so add it.
        fd.append('ic_aspect_detect', '1');

        const xhr = new XMLHttpRequest();
        if (pCont) pCont.style.display = 'block';
        if (btn) { btn.disabled = true; btn.dataset.label = btn.textContent; btn.textContent = 'DETECTING…'; }

        xhr.upload.addEventListener('progress', function (ev) {
            if (ev.lengthComputable && pBar) pBar.style.width = (ev.loaded / ev.total) * 100 + '%';
        });
        xhr.onload  = function () { window.location.reload(); };
        xhr.onerror = function () {
            if (pCont) pCont.style.display = 'none';
            if (btn) { btn.disabled = false; btn.textContent = btn.dataset.label || 'DETECT ASPECT RATIO'; }
            alert('Upload failed — please try again.');
        };
        xhr.open('POST', form.action || window.location.href, true);
        xhr.send(fd);
    });
})();
// ===== SNAPSMACK EOF =====
