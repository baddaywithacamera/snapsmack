/**
 * SNAPSMACK - ss-page-community-settings
 *
 * Moved verbatim out of a <script> block in smack-community-settings.php during the inline-JS
 * sweep. No logic was changed. Loaded from the same place in the page, so
 * it still runs at the same point relative to everything else.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

(function () {
    // Live reaction counter — disable checkboxes beyond 10 selections
    var MAX = 10;
    var grid = document.querySelector('.reaction-picker-grid');
    var countDisplay = document.getElementById('rx-count-display');
    if (!grid || !countDisplay) return;

    function update() {
        var boxes = grid.querySelectorAll('input[type="checkbox"]');
        var checked = 0;
        boxes.forEach(function (cb) { if (cb.checked) checked++; });
        countDisplay.textContent = checked;
        boxes.forEach(function (cb) {
            var label = cb.closest('.reaction-option');
            if (!cb.checked && checked >= MAX) {
                cb.disabled = true;
                label.style.opacity = '0.4';
            } else {
                cb.disabled = false;
                label.style.opacity  = '';
            }
            label.classList.toggle('is-active', cb.checked);
        });
    }

    grid.addEventListener('change', update);
    update(); // run once on load

    // Radio option highlight sync for dock position
    document.querySelectorAll('.radio-option').forEach(function (label) {
        label.querySelector('input').addEventListener('change', function () {
            document.querySelectorAll('.radio-option').forEach(function (l) {
                l.classList.toggle('is-selected', l.querySelector('input').checked);
            });
        });
    });
})();

// ===== SNAPSMACK EOF =====
