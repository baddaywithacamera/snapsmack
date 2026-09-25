/**
 * SNAPSMACK - Admin Input Engine
 *
 * The small behaviours that used to be typed into oninput=/onchange=/onerror=
 * attributes on admin form controls:
 *
 *   1. Live readout beside a range or colour input.
 *   2. Two inputs that mirror each other (a slider and its number/hex box).
 *   3. Hiding a preview image that fails to load, and optionally revealing a
 *      stand-in beside it.
 *
 * Everything is delegated from the document, so markup added later by other
 * engines is picked up without re-initialising anything.
 *
 * MARKUP CONTRACT
 *
 *   Readout:
 *     <input type="range" data-readout data-unit="px">
 *     <span class="active-val">18px</span>
 *
 *     data-readout          Marks the input. Its value is the id of the element
 *                           to write into; leave it empty to use the next
 *                           element sibling (what the old handlers did).
 *     data-unit="px"        Appended to the number. Optional.
 *     data-readout-case     "upper" uppercases the value - used by the hex
 *                           displays next to colour pickers.
 *     data-readout-prop     "value" writes to .value instead of the text, for a
 *                           readout that is itself an input.
 *
 *   Mirror pair:
 *     <input type="color" data-mirror>
 *     <input type="text"  data-mirror>
 *
 *     data-mirror           Empty: mirror the adjacent sibling in both
 *                           directions. With an id: mirror that element.
 *
 *   Failed image:
 *     <img data-hide-on-error>
 *     <img data-hide-on-error data-on-error-show="fallback-id">
 *     <img data-hide-on-error data-on-error-show=".no-preview">
 *     <img data-fallback-src="/path/to/original.jpg">
 *
 *     data-hide-on-error    Hide the image if it 404s.
 *     data-on-error-show    What to reveal in its place: an element id, or a
 *                           selector starting with . or # looked up inside the
 *                           image's parent. A d-none class on it is removed.
 *     data-fallback-src     Swap to this source instead of hiding. Tried once,
 *                           so a broken fallback cannot loop.
 *
 *   Swatch button (writes a preset value into a field):
 *     <button data-swatch data-target="gp-accent" data-val="#888888">
 *     Marks itself .selected and clears its siblings inside data-swatch-group.
 *
 *   Reset button (empties a field and rewrites a preview):
 *     <button data-reset-field="input-id" data-reset-preview="preview-id"
 *             data-reset-html="Nothing chosen">
 *
 *   Font select with a live sample beside it:
 *     <select data-font-preview>  ... and a .font-preview span in its parent.
 *
 *   Select that copies its option's data-* into other fields:
 *     <select data-option-copy="data-url:push-skin-url,data-sig:push-skin-sig">
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

(function () {
    'use strict';

    function target(el, attr) {
        var id = el.getAttribute(attr);
        return id ? document.getElementById(id) : el.nextElementSibling;
    }

    function paint(input) {
        var out = target(input, 'data-readout');
        if (!out) return;
        var v = input.value;
        if (input.getAttribute('data-readout-case') === 'upper') v = v.toUpperCase();
        v += (input.getAttribute('data-unit') || '');
        if (input.getAttribute('data-readout-prop') === 'value') out.value = v;
        else out.textContent = v;
    }

    function mirror(input) {
        var id = input.getAttribute('data-mirror');
        var other = id
            ? document.getElementById(id)
            : (input.nextElementSibling && input.nextElementSibling.hasAttribute('data-mirror')
                ? input.nextElementSibling
                : input.previousElementSibling);
        if (!other || other === input) return;
        if (other.value !== input.value) other.value = input.value;
    }

    function handle(e) {
        var el = e.target;
        if (!el || !el.getAttribute) return;
        if (el.hasAttribute('data-readout')) paint(el);
        if (el.hasAttribute('data-mirror'))  mirror(el);
    }

    document.addEventListener('input', handle);
    document.addEventListener('change', handle);

    function reveal(img) {
        var want = img.getAttribute('data-on-error-show');
        if (!want) return;
        var alt = (want.charAt(0) === '.' || want.charAt(0) === '#')
            ? (img.parentElement && img.parentElement.querySelector(want))
            : document.getElementById(want);
        if (!alt) return;
        alt.classList.remove('d-none');
        alt.style.display = 'block';
    }

    // 'error' does not bubble, so listen in the capture phase instead.
    window.addEventListener('error', function (e) {
        var el = e.target;
        if (!el || el.tagName !== 'IMG') return;

        var fallback = el.getAttribute('data-fallback-src');
        if (fallback && el.dataset.fallbackTried !== '1') {
            el.dataset.fallbackTried = '1';   // one attempt, never a loop
            el.src = fallback;
            return;
        }
        if (!el.hasAttribute('data-hide-on-error')) return;
        el.style.display = 'none';
        reveal(el);
    }, true);

    // ---- click behaviours that used to be inline IIFEs --------------------
    document.addEventListener('click', function (e) {
        var b = e.target && e.target.closest ? e.target.closest('[data-swatch],[data-reset-field]') : null;
        if (!b) return;

        if (b.hasAttribute('data-swatch')) {
            var inp = document.getElementById(b.getAttribute('data-target'));
            if (inp) {
                inp.value = b.getAttribute('data-val');
                inp.dispatchEvent(new Event('input', { bubbles: true }));
            }
            var group = b.closest(b.getAttribute('data-swatch-group') || '.grey-swatches');
            if (group) {
                var sibs = group.querySelectorAll('[data-swatch]');
                for (var i = 0; i < sibs.length; i++) sibs[i].classList.remove('selected');
            }
            b.classList.add('selected');
            return;
        }

        // data-reset-field
        var field = document.getElementById(b.getAttribute('data-reset-field'));
        if (field) {
            field.value = '';
            field.dispatchEvent(new Event('input', { bubbles: true }));
        }
        var prev = document.getElementById(b.getAttribute('data-reset-preview') || '');
        if (prev) {
            prev.textContent = '';
            var note = document.createElement('span');
            note.className = 'dim';
            note.textContent = b.getAttribute('data-reset-html') || '';
            prev.appendChild(note);
        }
    });

    // ---- a font select drives the sample text next to it ------------------
    document.addEventListener('change', function (e) {
        var sel = e.target;
        if (!sel || !sel.getAttribute || !sel.hasAttribute('data-font-preview')) return;
        var sample = sel.parentNode && sel.parentNode.querySelector('.font-preview span');
        if (!sample) return;
        sample.style.fontFamily = (sel.value ? ("'" + sel.value + "'") : 'inherit') + ',sans-serif';
    });

    // ---- a select that copies its chosen option's data-* into other fields -
    document.addEventListener('change', function (e) {
        var sel = e.target;
        if (!sel || !sel.getAttribute || !sel.hasAttribute('data-option-copy')) return;
        var opt = sel.options[sel.selectedIndex];
        var pairs = sel.getAttribute('data-option-copy').split(',');
        for (var i = 0; i < pairs.length; i++) {
            var bits = pairs[i].split(':');
            if (bits.length !== 2) continue;
            var dest = document.getElementById(bits[1].trim());
            if (dest) dest.value = (opt && opt.getAttribute(bits[0].trim())) || '';
        }
    });

    // A page can restore every readout after it rewrites a form.
    window.snapPaintReadouts = function (root) {
        var scope = root || document;
        var list = scope.querySelectorAll('[data-readout]');
        for (var i = 0; i < list.length; i++) paint(list[i]);
    };
})();

// ===== SNAPSMACK EOF =====
