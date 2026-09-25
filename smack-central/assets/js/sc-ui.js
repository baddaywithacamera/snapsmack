/**
 * SMACK CENTRAL - shared UI behaviours
 *
 * SMACK CENTRAL had no JavaScript file at all, so every behaviour was typed
 * into an onclick= attribute. This is where they go instead. Loaded by
 * sc-layout-bottom.php on every SMACK CENTRAL page.
 *
 * MARKUP CONTRACT
 *
 *   Accordion row toggle (a caret that shows or hides one table row):
 *     <button class="sc-accordion-toggle" data-accordion="row-id">&#9654;</button>
 *     <tr id="row-id" class="d-none">...</tr>
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

(function () {
    'use strict';

    document.addEventListener('click', function (e) {
        var btn = e.target && e.target.closest ? e.target.closest('[data-accordion]') : null;
        if (!btn) return;
        var row = document.getElementById(btn.getAttribute('data-accordion'));
        if (!row) return;
        var open = row.classList.toggle('sc-accordion-open');
        btn.textContent = open ? '▼' : '▶';
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
})();

// ===== SNAPSMACK EOF =====
