/**
 * SNAPSMACK - ss-page-update
 *
 * Moved verbatim out of a <script> block in smack-update.php during the inline-JS
 * sweep. No logic was changed. Loaded from the same place in the page, so
 * it still runs at the same point relative to everything else.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

(function () {
    var log = document.querySelector('.step-log');
    if (log) { log.scrollIntoView({ behavior: 'smooth', block: 'start' }); }

    // File picker label feedback
    var inp   = document.getElementById('upload-zip-input');
    var lbl   = document.getElementById('file-pick-label');
    var fname = document.getElementById('file-pick-name');
    if (inp && lbl && fname) {
        inp.addEventListener('change', function () {
            if (inp.files && inp.files.length > 0) {
                fname.textContent = inp.files[0].name;
                lbl.classList.add('has-file');
            } else {
                fname.textContent = 'No file chosen';
                lbl.classList.remove('has-file');
            }
        });
    }

    // Auto-advance the staged update pipeline.
    // Once the user clicks APPLY UPDATE on the status card, every subsequent
    // stage (download → verify → backup → premigrate → extract → migrate)
    // runs automatically. The only manual step is the initial Apply click.
    // Does not fire when there is an error (alert-danger is on the page).
    var stageBox = document.getElementById('stage-box');
    if (stageBox && !document.querySelector('.alert-danger')) {
        var nextBtn = stageBox.querySelector('.stage-next-btn button[type="submit"]');
        if (nextBtn && !nextBtn.disabled) {
            nextBtn.style.opacity = '0.35';
            var note = document.createElement('p');
            note.style.cssText  = 'font-size:0.72rem;opacity:0.4;margin-top:8px;font-family:monospace;letter-spacing:0.05em;';
            note.textContent    = 'AUTO-CONTINUING...';
            nextBtn.parentNode.appendChild(note);
            // Use click() not form.submit() — form.submit() omits the button
            // name/value so PHP receives no action and the pipeline stalls.
            setTimeout(function () { nextBtn.click(); }, 800);
        }
    }
})();

// ===== SNAPSMACK EOF =====
