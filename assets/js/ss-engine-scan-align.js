/**
 * SNAPSMACK — Scan Align
 * ss-engine-scan-align.js
 *
 * Posting-interface tool (spec: _spec/instant-camera-spec-v0.2.docx §4). Lets a
 * photographer dial a slightly-crooked instant-film scan straight before
 * publishing: a ±5° rotation slider (0.1° steps), a live preview that crops to
 * FILL the posting window (no white corners), and — on submit — it bakes the
 * rotated, centre-cropped result into the uploaded file. The stored image is
 * the processed result; this never affects public display.
 *
 * FAIL-SAFE BY DESIGN: it only enhances wrappers that carry [data-scan-align],
 * and on submit it only swaps files it actually processed. Absent the markup it
 * does nothing — normal posting is untouched.
 *
 * MARKUP CONTRACT — for each image to align:
 *   <div data-scan-align data-aspect="62/46">      (target window aspect = tile)
 *       <input type="file" data-scan-input accept="image/*">
 *   </div>
 * The engine injects a <canvas> preview + a range slider after the input.
 * Shared core engine (manifest handle smack-scan-align); no inline JS in skins.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


(function () {
    'use strict';

    function fault(w, e) { try { if (window.console && console.error) console.error('[scan-align] ' + w, e); } catch (x) {} }

    // ── Reusable bake helper (always available, even with no auto-wrappers) ──
    // ScanAlign.bakeFile(file, angleDeg, aspect) → Promise<File>: the rotated,
    // centre-cropped-to-FILL result (no white corners). Lets a bespoke poster
    // (e.g. the gram composer, which holds its own file array) rotate each
    // image into its upload. On any failure it resolves the ORIGINAL file, so a
    // post can never break.
    window.ScanAlign = window.ScanAlign || {};
    window.ScanAlign.bakeFile = function (file, angleDeg, aspect) {
        return new Promise(function (resolve) {
            if (!file || !/^image\//.test(file.type) || !(parseFloat(angleDeg) || 0)) { resolve(file); return; }
            var url = URL.createObjectURL(file);
            var im = new Image();
            im.onload = function () {
                try {
                    var asp = (typeof aspect === 'number' && aspect > 0) ? aspect : parseAspect(String(aspect));
                    var OUT_W = 1200, outW = OUT_W, outH = Math.max(1, Math.round(OUT_W / asp));
                    var cv = document.createElement('canvas'); cv.width = outW; cv.height = outH;
                    var cx = cv.getContext('2d');
                    var a = (parseFloat(angleDeg) || 0) * Math.PI / 180;
                    var cos = Math.cos(Math.abs(a)), sin = Math.sin(Math.abs(a));
                    var base = Math.max(outW / im.width, outH / im.height);
                    var grow = Math.max((outW * cos + outH * sin) / outW, (outW * sin + outH * cos) / outH);
                    var scale = base * grow, dw = im.width * scale, dh = im.height * scale;
                    cx.save(); cx.translate(outW / 2, outH / 2); cx.rotate(a);
                    cx.drawImage(im, -dw / 2, -dh / 2, dw, dh); cx.restore();
                    var type = file.type === 'image/png' ? 'image/png' : 'image/jpeg';
                    cv.toBlob(function (blob) { resolve(blob ? new File([blob], file.name, { type: type }) : file); }, type, 0.92);
                } catch (e) { fault('bakeFile', e); resolve(file); }
                URL.revokeObjectURL(url);
            };
            im.onerror = function () { URL.revokeObjectURL(url); resolve(file); };
            im.src = url;
        });
    };

    var wraps = document.querySelectorAll('[data-scan-align]');
    if (!wraps.length) return;

    var pending = [];   // { input, render } for each enhanced wrapper with an image

    Array.prototype.forEach.call(wraps, function (wrap) {
        try { setupWrap(wrap); } catch (e) { fault('setupWrap', e); }
    });

    function parseAspect(s) {
        var m = (s || '').match(/(\d+(?:\.\d+)?)\s*[\/:xX]\s*(\d+(?:\.\d+)?)/);
        if (m && +m[2] > 0) return +m[1] / +m[2];
        return 1;
    }

    function setupWrap(wrap) {
        // Resolve the file input three ways: a [data-scan-input] child, a
        // [data-input] CSS-selector reference (self-attach to an existing poster
        // input — no wrapper needed), or a plain child file input.
        var ref = wrap.getAttribute('data-input');
        var input = wrap.querySelector('[data-scan-input]')
                 || (ref ? document.querySelector(ref) : null)
                 || wrap.querySelector('input[type="file"]');
        if (!input) return;
        var aspect = parseAspect(wrap.getAttribute('data-aspect'));

        // UI: preview canvas + rotation slider (built once, shown after a pick).
        var ui = document.createElement('div');
        ui.className = 'scan-align-ui';
        ui.style.cssText = 'margin-top:10px;display:none;';
        var canvas = document.createElement('canvas');
        canvas.className = 'scan-align-canvas';
        canvas.style.cssText = 'display:block;max-width:100%;background:#111;border-radius:2px;';
        var row = document.createElement('div');
        row.style.cssText = 'display:flex;align-items:center;gap:10px;margin-top:8px;';
        var slider = document.createElement('input');
        slider.type = 'range'; slider.min = '-5'; slider.max = '5'; slider.step = '0.1'; slider.value = '0';
        slider.style.cssText = 'flex:1;';
        var label = document.createElement('span');
        label.style.cssText = 'font:12px/1 monospace;min-width:54px;text-align:right;opacity:.8;';
        label.textContent = '0.0°';
        var reset = document.createElement('button');
        reset.type = 'button'; reset.textContent = 'Reset';
        reset.style.cssText = 'font-size:12px;padding:2px 8px;cursor:pointer;';
        row.appendChild(slider); row.appendChild(label); row.appendChild(reset);
        ui.appendChild(canvas); ui.appendChild(row);
        input.insertAdjacentElement('afterend', ui);

        var ctx = canvas.getContext('2d');
        var img = null;          // loaded HTMLImageElement
        var angle = 0;           // degrees

        // Preview/output box: a fixed-aspect window. Cap preview width for perf.
        var OUT_W = 600;
        var outW = OUT_W, outH = Math.round(OUT_W / aspect);

        function draw() {
            if (!img) return;
            canvas.width = outW; canvas.height = outH;
            ctx.clearRect(0, 0, outW, outH);
            // Scale the source so that, after rotation, it still fully covers the
            // output box (crop-to-fill, centred) — no white corners.
            var rad = Math.abs(angle * Math.PI / 180);
            var cos = Math.cos(rad), sin = Math.sin(rad);
            // Cover scale for an unrotated fit:
            var base = Math.max(outW / img.width, outH / img.height);
            // Extra zoom so the rotated image still covers the box corners:
            var grow = (outW * cos + outH * sin) / outW; // >=1
            var grow2 = (outW * sin + outH * cos) / outH;
            var scale = base * Math.max(grow, grow2);
            var dw = img.width * scale, dh = img.height * scale;
            ctx.save();
            ctx.translate(outW / 2, outH / 2);
            ctx.rotate(angle * Math.PI / 180);
            ctx.drawImage(img, -dw / 2, -dh / 2, dw, dh);
            ctx.restore();
        }

        function loadFile(file) {
            if (!file || !/^image\//.test(file.type)) { ui.style.display = 'none'; return; }
            var url = URL.createObjectURL(file);
            var im = new Image();
            im.onload = function () {
                img = im; angle = 0; slider.value = '0'; label.textContent = '0.0°';
                ui.style.display = '';
                draw();
                URL.revokeObjectURL(url);
            };
            im.onerror = function () { fault('image load', file.name); URL.revokeObjectURL(url); };
            im.src = url;
        }

        input.addEventListener('change', function () {
            loadFile(input.files && input.files[0]);
        });
        slider.addEventListener('input', function () {
            angle = parseFloat(slider.value) || 0;
            label.textContent = angle.toFixed(1) + '°';
            draw();
        });
        reset.addEventListener('click', function () { slider.value = '0'; angle = 0; label.textContent = '0.0°'; draw(); });

        // Register for submit-time baking. render() resolves a processed File
        // (or null if nothing to do) for THIS input.
        pending.push({
            input: input,
            render: function () {
                return new Promise(function (resolve) {
                    if (!img) { resolve(null); return; }      // no image picked → leave as-is
                    try {
                        draw();
                        var name = (input.files && input.files[0] && input.files[0].name) || 'scan.jpg';
                        var type = (input.files && input.files[0] && input.files[0].type) || 'image/jpeg';
                        if (type === 'image/png') type = 'image/png'; else type = 'image/jpeg';
                        canvas.toBlob(function (blob) {
                            if (!blob) { resolve(null); return; }
                            resolve(new File([blob], name, { type: type }));
                        }, type, 0.92);
                    } catch (e) { fault('render', e); resolve(null); }
                });
            }
        });
    }

    // ── Submit hook: bake processed files into their inputs, then submit ──────
    // Bind to each form that contains an enhanced wrapper.
    var forms = [];
    pending.forEach(function (p) {
        var f = p.input.form;
        if (f && forms.indexOf(f) === -1) forms.push(f);
    });

    forms.forEach(function (form) {
        var done = false;
        form.addEventListener('submit', function (e) {
            if (done) return;                 // second pass — let it through
            // Only intercept if at least one input has an image to bake.
            var jobs = pending.filter(function (p) { return p.input.form === form; });
            if (!jobs.length) return;
            e.preventDefault();
            Promise.all(jobs.map(function (p) {
                return p.render().then(function (file) {
                    if (file) {
                        try {
                            var dt = new DataTransfer();
                            dt.items.add(file);
                            p.input.files = dt.files;
                        } catch (err) { fault('swap file', err); }   // unsupported → original file stands
                    }
                });
            })).then(function () {
                done = true;
                if (typeof form.requestSubmit === 'function') form.requestSubmit();
                else form.submit();
            }).catch(function (err) { fault('submit bake', err); done = true; form.submit(); });
        });
    });

})();
// ===== SNAPSMACK EOF =====
