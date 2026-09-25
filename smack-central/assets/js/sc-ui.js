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
 *   Set a form field, then let the button submit normally:
 *     <button name="go" data-set-field="level" data-set-value="yellow_fast">
 *
 *   Tick or untick a set of checkboxes:
 *     <button data-check-all="input[name='skins[]']" data-check-state="on">
 *              data-check-state: on | off | data-<attr> (tick where that
 *              attribute is set and is not '', '0' or 'false')
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

    // ---- set a field, then let the button submit -----------------------------
    document.addEventListener('click', function (e) {
        var b = e.target && e.target.closest ? e.target.closest('[data-set-field]') : null;
        if (!b || !b.form) return;
        var field = b.form.querySelector('[name="' + b.getAttribute('data-set-field') + '"]');
        if (field) field.value = b.getAttribute('data-set-value') || '';
        // no preventDefault: the button is a real submit
    });

    // ---- tick / untick a set of checkboxes ----------------------------------
    document.addEventListener('click', function (e) {
        var b = e.target && e.target.closest ? e.target.closest('[data-check-all]') : null;
        if (!b) return;
        e.preventDefault();
        var boxes = document.querySelectorAll(b.getAttribute('data-check-all'));
        var how = b.getAttribute('data-check-state') || 'on';
        for (var i = 0; i < boxes.length; i++) {
            if (how === 'on')       boxes[i].checked = true;
            else if (how === 'off') boxes[i].checked = false;
            else {
                var flag = boxes[i].getAttribute(how);
                boxes[i].checked = !(flag === null || flag === '' || flag === '0' || flag === 'false');
            }
        }
    });
})();

// ===== SNAPSMACK EOF =====
