/**
 * SNAPSMACK — AI metadata enrichment (single-pull model)
 *
 * ONE AI call per image fills every field. VISION FILL analyses the photo and
 * populates title, caption, ALT, hashtags, and matching categories/albums in a
 * single request; the result is cached client-side. The per-field buttons
 * (AI TITLE / AI CAPTION / AI HASHTAGS) draw from that cache instead of each
 * firing their own request — so a photo is never billed 2–3× for different
 * fields. If no pull has happened yet, the first field button triggers the one
 * vision run, then fills from it. RE-RUN clears the cache and re-analyses.
 *
 * Sources for the pull: a file picker (#post-file-input, new-post page) or the
 * already-uploaded image URL (data-image-url on #btn-ai-vision, edit page).
 *
 * (Superseded the older per-field mode=enrich text-refine + saved-prompt library
 *  — deliberately removed 0.7.512: it was the source of the triple-bill.)
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

(function () {
    'use strict';

    var endpoint = 'smack-ai-assist.php';
    var rows = [];

    // The single cached vision result for the current image, and an in-flight
    // promise so concurrent clicks share ONE request (never double-bill).
    var visionCache = null;
    var visionInFlight = null;

    var fieldMap = {
        title: {
            selector: 'input[name="title"]',
            label: 'AI TITLE',
            key: 'title'
        },
        caption: {
            selector: 'textarea[name="desc"], textarea[name="content"]',
            label: 'AI CAPTION',
            key: 'caption'
        },
        hashtags: {
            selector: 'input[name="tags"]',
            label: 'AI HASHTAGS',
            key: 'tags'          // vision returns hashtags under 'tags'
        },
        alt: {
            selector: 'input[name="alt"], textarea[name="alt"]',
            label: 'AI ALT',
            key: 'alt'
        }
    };

    function request(data) {
        var body = new FormData();
        Object.keys(data).forEach(function (key) {
            body.append(key, typeof data[key] === 'string' ? data[key] : JSON.stringify(data[key]));
        });
        return fetch(endpoint, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: body
        }).then(function (response) {
            return response.json();
        });
    }

    function setStatus(item, message, error) {
        if (!item || !item.status) return;
        item.status.textContent = message || '';
        item.status.classList.toggle('is-error', Boolean(error));
    }

    // ── Image source → downscaled data URL ───────────────────────────────────
    // Vision is billed by image size; a 600px long-edge thumb carries all the
    // detail these fields need (mirrors SYBU's _load_image_part).
    function downscaleToDataUrl(file, maxEdge) {
        return new Promise(function (resolve, reject) {
            var url = URL.createObjectURL(file);
            var img = new Image();
            img.onload = function () {
                var w = img.naturalWidth || 1;
                var h = img.naturalHeight || 1;
                var scale = Math.min(1, maxEdge / Math.max(w, h));
                var canvas = document.createElement('canvas');
                canvas.width = Math.max(1, Math.round(w * scale));
                canvas.height = Math.max(1, Math.round(h * scale));
                canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
                URL.revokeObjectURL(url);
                try { resolve(canvas.toDataURL('image/jpeg', 0.85)); }
                catch (e) { reject(e); }
            };
            img.onerror = function () { URL.revokeObjectURL(url); reject(new Error('image load failed')); };
            img.src = url;
        });
    }

    // Same, from a same-origin image URL — the edit page has no file picker.
    function downscaleUrlToDataUrl(src, maxEdge) {
        return new Promise(function (resolve, reject) {
            var img = new Image();
            img.onload = function () {
                var w = img.naturalWidth || 1;
                var h = img.naturalHeight || 1;
                var scale = Math.min(1, maxEdge / Math.max(w, h));
                var canvas = document.createElement('canvas');
                canvas.width = Math.max(1, Math.round(w * scale));
                canvas.height = Math.max(1, Math.round(h * scale));
                canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
                try { resolve(canvas.toDataURL('image/jpeg', 0.85)); }
                catch (e) { reject(e); }
            };
            img.onerror = function () { reject(new Error('image load failed')); };
            img.src = src;
        });
    }

    // Resolve the current image to a data URL, or null if none is available.
    function currentImageDataUrl() {
        var fileInput = document.getElementById('post-file-input');
        var file = fileInput && fileInput.files && fileInput.files[0];
        if (file) return downscaleToDataUrl(file, 600);
        var vb = document.getElementById('btn-ai-vision');
        var url = vb && vb.getAttribute('data-image-url');
        if (url) return downscaleUrlToDataUrl(url, 600);
        return Promise.resolve(null);
    }

    // ── The single pull, cached ──────────────────────────────────────────────
    // force=true re-analyses even if a cache exists (RE-RUN). Concurrent callers
    // share the same in-flight promise so a burst of clicks makes ONE request.
    function ensureVisionCache(force) {
        if (!force && visionCache) return Promise.resolve(visionCache);
        if (visionInFlight) return visionInFlight;
        visionInFlight = currentImageDataUrl().then(function (dataUrl) {
            if (!dataUrl) {
                var noimg = new Error('no-image');
                noimg._friendly = 'Choose an image first — vision reads the photo itself.';
                throw noimg;
            }
            return request({ mode: 'vision', image: dataUrl });
        }).then(function (result) {
            visionInFlight = null;
            if (!result || !result.ok) {
                var e = new Error('vision-failed');
                e._friendly = (result && result.error) || 'Vision enrichment failed.';
                throw e;
            }
            visionCache = result;
            return result;
        }).catch(function (e) {
            visionInFlight = null;
            throw e;
        });
        return visionInFlight;
    }

    function clearVisionCache() {
        visionCache = null;
    }

    // ── Fill helpers ─────────────────────────────────────────────────────────
    function setFieldValue(selector, value) {
        var field = document.querySelector(selector);
        if (!field) return;
        field.value = value;
        field.dispatchEvent(new Event('input', { bubbles: true }));
    }

    // Fill EVERY empty field from a pull. Non-destructive: never overwrites text
    // the author already typed (safe to press on the edit page / a half-filled
    // post). To re-derive a filled field, use its per-field button (explicit).
    function applyVision(result) {
        var setEmpty = function (selector, value) {
            if (!value) return;
            var field = document.querySelector(selector);
            if (!field) return;
            if (field.value && field.value.trim() !== '') return;
            field.value = value;
            field.dispatchEvent(new Event('input', { bubbles: true }));
        };
        setEmpty(fieldMap.title.selector, result.title);
        setEmpty(fieldMap.caption.selector, result.caption);
        setEmpty('textarea[name="alt"], input[name="alt"]', result.alt);
        setEmpty(fieldMap.hashtags.selector, result.tags);

        var check = function (name, ids) {
            if (!ids || !ids.length) return;
            var want = {};
            ids.forEach(function (id) { want[String(id)] = true; });
            document.querySelectorAll('input[name="' + name + '"]').forEach(function (box) {
                if (want[String(box.value)] && !box.checked) {
                    box.checked = true;
                    box.dispatchEvent(new Event('change', { bubbles: true }));
                }
            });
        };
        check('cat_ids[]', result.category_ids);
        check('album_ids[]', result.album_ids);
    }

    // A per-field button click: reuse the cached pull (or trigger the one run),
    // then set THIS field from it. Overwrites the single field — an explicit,
    // user-initiated single-field action.
    function fillField(item) {
        item.button.disabled = true;
        item.button.classList.add('is-working');
        setStatus(item, visionCache ? 'Filling from the photo…' : 'Reading the photo…', false);
        ensureVisionCache(false).then(function (result) {
            item.button.disabled = false;
            item.button.classList.remove('is-working');
            var value = result[item.key] || '';
            if (!value) {
                setStatus(item, 'The AI returned nothing for this field.', true);
                return;
            }
            setFieldValue(fieldMap[item.type].selector, value);
            item.field.focus();
            setStatus(item, 'Filled from the photo (one AI call, reused).', false);
        }).catch(function (e) {
            item.button.disabled = false;
            item.button.classList.remove('is-working');
            setStatus(item, (e && e._friendly) || 'The AI request failed.', true);
        });
    }

    function buildRow(type, field) {
        var row = document.createElement('div');
        row.className = 'ss-ai-enrich-row';

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'sc-btn sc-btn-ai ss-ai-enrich-button';
        button.textContent = fieldMap[type].label;
        button.title = 'Fill this field from the photo — reuses the single AI vision pull, no extra charge';

        var status = document.createElement('span');
        status.className = 'ss-ai-enrich-status';
        status.setAttribute('aria-live', 'polite');

        row.appendChild(button);
        row.appendChild(status);
        field.insertAdjacentElement('afterend', row);

        var item = {
            type: type,
            key: fieldMap[type].key,
            field: field,
            row: row,
            button: button,
            status: status
        };
        rows.push(item);
        button.addEventListener('click', function () { fillField(item); });
    }

    // ── VISION FILL (fill-all) + RE-RUN ──────────────────────────────────────
    function setVisionStatus(el, message, error) {
        if (!el) return;
        el.textContent = message || '';
        el.classList.toggle('is-error', Boolean(error));
    }

    function runVisionFill(button, status, force) {
        button.disabled = true;
        button.classList.add('is-working');
        setVisionStatus(status, force ? 'Re-analysing the photo…'
                                      : (visionCache ? 'Filling from the photo…' : 'Reading the photo…'), false);
        ensureVisionCache(force).then(function (result) {
            button.disabled = false;
            button.classList.remove('is-working');
            applyVision(result);
            setVisionStatus(status, 'Filled empty fields from the photo — review before publishing.', false);
        }).catch(function (e) {
            button.disabled = false;
            button.classList.remove('is-working');
            setVisionStatus(status, (e && e._friendly) || 'The vision request failed.', true);
        });
    }

    function wireVision() {
        var button = document.getElementById('btn-ai-vision');
        var fileInput = document.getElementById('post-file-input');
        var status = document.getElementById('ai-vision-status');
        var rerun = document.getElementById('btn-ai-vision-rerun');

        // Choosing a different file invalidates the cached pull.
        if (fileInput) {
            fileInput.addEventListener('change', clearVisionCache);
        }

        if (button && (fileInput || button.getAttribute('data-image-url'))) {
            button.addEventListener('click', function () { runVisionFill(button, status, false); });
        }
        if (rerun) {
            rerun.addEventListener('click', function () {
                clearVisionCache();
                runVisionFill(rerun, status, true);
            });
        }
    }

    function initialise() {
        wireVision();
        // Build the per-field fill buttons wherever those fields exist. No prompt
        // library to load — the buttons just draw from the single vision pull.
        Object.keys(fieldMap).forEach(function (type) {
            var field = document.querySelector(fieldMap[type].selector);
            if (field) buildRow(type, field);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialise);
    } else {
        initialise();
    }
})();

// ===== SNAPSMACK EOF =====
