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
 *   Proxy button for a hidden file input:
 *     <div data-click-target="file-input-id">Choose a file</div>
 *     <input type="file" id="file-input-id" data-file-name-target="name-span-id">
 *     <span id="name-span-id">SELECT FILE</span>
 *
 *     data-file-name-empty  what the span says when nothing is chosen
 *                           (default: SELECT FILE).
 *
 *   Native picker on a date/time/colour input:
 *     <input type="date" data-show-picker>
 *
 *   Copy to the clipboard:
 *     <button data-copy-text="#1e73be">           copy this literal text
 *     <button data-copy-from="element-id">        copy that element's value/text
 *     <span data-copy-self>[mosaic:12]</span>     copy the element's own text
 *     data-copied-label      shown on the button for two seconds after a copy
 *                            (default: COPIED).
 *
 *   Submit on change / reload / select-all-text:
 *     <select data-submit-on-change>                 form.submit()
 *     <select data-submit-on-change data-submit-mode="request">  form.requestSubmit()
 *     <button data-reload>
 *     <input data-select-on-click>
 *
 *   Set a field, then let the button submit normally:
 *     <button name="go" data-set-field="level" data-set-value="yellow_fast">
 *
 *   Tick or untick a set of checkboxes:
 *     <button data-check-all="input[name='skins[]']" data-check-state="on">
 *              data-check-state: on | off | data-<attr> (tick where that
 *              attribute on the checkbox is truthy)
 *
 *   Show or hide a block when a select changes:
 *     <select data-toggle-group="logo-upload-group" data-toggle-when="image">
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

    // ---- clipboard ---------------------------------------------------------
    function flash(btn) {
        var label = btn.getAttribute('data-copied-label') || 'COPIED';
        var was = btn.textContent;
        btn.textContent = label;
        setTimeout(function () { btn.textContent = was; }, 2000);
    }

    function copy(text, btn) {
        if (!navigator.clipboard) return;
        navigator.clipboard.writeText(text).then(function () {
            if (btn) flash(btn);
        });
    }

    // ---- the small click behaviours ----------------------------------------
    document.addEventListener('click', function (e) {
        var el = e.target;
        if (!el || !el.closest) return;

        var picker = el.closest('[data-show-picker]');
        if (picker && typeof picker.showPicker === 'function') { picker.showPicker(); return; }

        var proxy = el.closest('[data-click-target]');
        if (proxy) {
            var f = document.getElementById(proxy.getAttribute('data-click-target'));
            if (f) f.click();
            return;
        }

        var selfCopy = el.closest('[data-copy-self]');
        if (selfCopy) { copy(selfCopy.textContent, null); return; }

        var lit = el.closest('[data-copy-text]');
        if (lit) { copy(lit.getAttribute('data-copy-text'), lit); return; }

        var from = el.closest('[data-copy-from]');
        if (from) {
            var srcEl = document.getElementById(from.getAttribute('data-copy-from'));
            if (srcEl) copy('value' in srcEl ? srcEl.value : srcEl.textContent, from);
            return;
        }

        var reload = el.closest('[data-reload]');
        if (reload) { e.preventDefault(); location.reload(); return; }

        var setter = el.closest('[data-set-field]');
        if (setter && setter.form) {
            var field = setter.form.querySelector('[name="' + setter.getAttribute('data-set-field') + '"]');
            if (field) field.value = setter.getAttribute('data-set-value') || '';
            return;   // no preventDefault: the button still submits
        }

        var checker = el.closest('[data-check-all]');
        if (checker) {
            e.preventDefault();
            var boxes = document.querySelectorAll(checker.getAttribute('data-check-all'));
            var how = checker.getAttribute('data-check-state') || 'on';
            for (var i = 0; i < boxes.length; i++) {
                if (how === 'on')       boxes[i].checked = true;
                else if (how === 'off') boxes[i].checked = false;
                else {
                    var flag = boxes[i].getAttribute(how);
                    boxes[i].checked = !(flag === null || flag === '' || flag === '0' || flag === 'false');
                }
            }
            return;
        }

        var sel = el.closest('[data-select-on-click]');
        if (sel && typeof sel.select === 'function') sel.select();
    });

    // ---- change behaviours -------------------------------------------------
    document.addEventListener('change', function (e) {
        var el = e.target;
        if (!el || !el.getAttribute) return;

        if (el.hasAttribute('data-file-name-target')) {
            var out = document.getElementById(el.getAttribute('data-file-name-target'));
            if (out) {
                out.textContent = (el.files && el.files[0])
                    ? el.files[0].name
                    : (el.getAttribute('data-file-name-empty') || 'SELECT FILE');
            }
        }

        if (el.hasAttribute('data-toggle-group')) {
            var group = document.getElementById(el.getAttribute('data-toggle-group'));
            if (group) group.classList.toggle('d-none', el.value !== el.getAttribute('data-toggle-when'));
        }

        if (el.hasAttribute('data-submit-on-change') && el.form) {
            // submit() by default, matching the handlers this replaced.
            // data-submit-mode="request" opts into validation + the submit event.
            if (el.getAttribute('data-submit-mode') === 'request'
                    && typeof el.form.requestSubmit === 'function') {
                el.form.requestSubmit();
            } else {
                el.form.submit();
            }
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
