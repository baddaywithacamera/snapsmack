/**
 * SNAPSMACK - GramOfSmack Post Composer engine
 *
 * Drop zone, large live-preview strip (each 1:1 tile renders as it will publish),
 * drag-reorder, per-image styling, XHR upload. Loaded by smack-post-gram.php.
 * Mirrors ss-engine-carousel-post.js but scoped to #gp-* IDs, no EXIF panels,
 * 10-image cap. Instant Camera straighten/scan-align turns on when the form
 * carries a data-ic-aspect attribute and ss-engine-scan-align.js is loaded.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */
(function () {
    'use strict';

    const MAX_IMAGES   = 10;
    const dropZone     = document.getElementById('gp-drop-zone');
    const fileInput    = document.getElementById('gp-file-input');
    const strip        = document.getElementById('gp-strip');
    const fileCount    = document.getElementById('gp-file-count');
    const submitBtn    = document.getElementById('gp-submit');
    const progressWrap = document.getElementById('gp-progress-wrap');
    const progressBar  = document.getElementById('gp-progress-bar');
    const errorDiv     = document.getElementById('gp-error');
    const form         = document.getElementById('gp-form');
    if (!form) return;

    // Instant Camera tile aspect (e.g. "1/1"); empty unless that skin is active.
    // Passed via a data attribute so this engine carries no server data inline.
    const IC_ASPECT = form.dataset.icAspect || '';

    let fileList = []; // [{file, objectUrl}]
    let dragSrc  = null;

    // Drop zone click
    dropZone.addEventListener('click', () => fileInput.click());

    // Drag-over styling
    dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.classList.add('is-over'); });
    dropZone.addEventListener('dragleave', () => dropZone.classList.remove('is-over'));
    dropZone.addEventListener('drop', e => {
        e.preventDefault();
        dropZone.classList.remove('is-over');
        addFiles(Array.from(e.dataTransfer.files));
    });

    fileInput.addEventListener('change', () => {
        addFiles(Array.from(fileInput.files));
        fileInput.value = '';
    });

    function addFiles(files) {
        const allowed = ['image/jpeg', 'image/png', 'image/webp'];
        files.forEach(f => {
            if (!allowed.includes(f.type)) return;
            if (fileList.length >= MAX_IMAGES) return;
            // Per-image styling defaults (decided individually in the strip).
            fileList.push({
                file: f, objectUrl: URL.createObjectURL(f),
                crop: 'fit', size: 100, bpx: 0, rotate: 0,
                bcol: '#000000', bg: '#ffffff', shadow: 0,
                // Square-crop framing: focal point (%) + zoom (%). Always editable.
                fx: 50, fy: 50, zoom: 100,
            });
        });
        renderStrip();
    }

    function removeFile(idx) {
        URL.revokeObjectURL(fileList[idx].objectUrl);
        fileList.splice(idx, 1);
        renderStrip();
    }

    // Live preview: render the 1:1 tile exactly as it will publish. fill = IG
    // square crop (cover fill); fit = image at size% inside the matte panel with
    // optional border + drop shadow. Mirrors the-grid's render so the panel IS
    // the post preview.
    const SHADOW_CSS = ['none',
        '0 2px 10px rgba(0,0,0,.20)',
        '0 4px 20px rgba(0,0,0,.45)',
        '0 8px 40px rgba(0,0,0,.70)'];
    function applyPreview(item, wrap, img) {
        const cropped = (item.crop === 'fill') || item.zoom > 100 || item.fx !== 50 || item.fy !== 50;
        wrap.style.position = 'relative';
        wrap.style.overflow = 'hidden';
        if (cropped) {
            // Show the exact square crop (focal point + zoom). Fill renders it on a
            // dark canvas; a fit tile that's been zoomed/panned previews the crop
            // its grid thumbnail will use.
            wrap.style.background = (item.crop === 'fill') ? '#111' : item.bg;
            img.style.objectFit = '';
            img.style.maxWidth = 'none'; img.style.maxHeight = 'none';
            img.style.border    = (item.crop !== 'fill' && item.bpx > 0) ? (item.bpx + 'px solid ' + item.bcol) : 'none';
            img.style.boxShadow = (item.crop !== 'fill') ? (SHADOW_CSS[item.shadow] || 'none') : 'none';
            applySquareCrop(item, wrap, img);
        } else {
            wrap.style.background = item.bg;                    // matte fills the tile
            img.style.position = 'static';
            img.style.left = ''; img.style.top = '';
            img.style.width = 'auto';  img.style.height = 'auto';
            img.style.maxWidth = item.size + '%';
            img.style.maxHeight = item.size + '%';
            img.style.objectFit = 'contain';
            img.style.border = item.bpx > 0 ? (item.bpx + 'px solid ' + item.bcol) : 'none';
            img.style.boxShadow = SHADOW_CSS[item.shadow] || 'none';
        }
        // 90° orientation preview. The square box contains the image, so a
        // rotated landscape simply re-fits as a portrait inside the tile.
        img.style.transform = 'rotate(' + (item.rotate || 0) + 'deg)';
    }

    // Position the image inside the square wrap to show exactly the crop the
    // server bakes (window = min(w,h)/(zoom/100), placed at fx%/fy%). Mirrors
    // smack-post-gram.php + core/thumb-generator.php so preview == published.
    function applySquareCrop(item, wrap, img) {
        const nw = img.naturalWidth, nh = img.naturalHeight;
        const S  = wrap.clientWidth || wrap.offsetWidth;
        if (!nw || !nh || !S) { img.addEventListener('load', () => applySquareCrop(item, wrap, img), { once: true }); return; }
        const zoom = Math.max(100, Math.min(300, item.zoom || 100));
        const winD = Math.min(nw, nh) / (zoom / 100);
        const offX = (nw - winD) * (Math.max(0, Math.min(100, item.fx)) / 100);
        const offY = (nh - winD) * (Math.max(0, Math.min(100, item.fy)) / 100);
        const scale = S / winD;
        img.style.position = 'absolute';
        img.style.width  = (nw * scale) + 'px';
        img.style.height = (nh * scale) + 'px';
        img.style.left   = (-offX * scale) + 'px';
        img.style.top    = (-offY * scale) + 'px';
    }

    function renderStrip() {
        strip.innerHTML = '';
        fileCount.textContent = fileList.length + ' / ' + MAX_IMAGES + ' images';
        submitBtn.disabled = fileList.length === 0;

        fileList.forEach((item, idx) => {
            const el = document.createElement('div');
            el.className = 'cp-strip-item';
            el.draggable  = true;
            el.dataset.idx = idx;

            const shadowOpts = [0,1,2,3].map(n =>
                '<option value="' + n + '"' + (item.shadow === n ? ' selected' : '') + '>' +
                ['None','Soft','Medium','Heavy'][n] + '</option>').join('');

            el.innerHTML =
                '<div class="cp-thumb-wrap">' +
                    (idx === 0 ? '<span class="cp-cover-badge">COVER</span>' : '') +
                    '<span class="cp-pos-badge">' + (idx + 1) + '</span>' +
                    '<img class="cp-thumb" src="' + item.objectUrl + '" alt="">' +
                    '<button type="button" class="cp-remove-btn" data-idx="' + idx + '">✕</button>' +
                '</div>' +
                '<div class="cp-rot-row">' +
                    '<button type="button" class="cp-rot-btn" data-rot="-90" title="Rotate left 90°">&#8634;</button>' +
                    '<button type="button" class="cp-rot-btn" data-rot="90" title="Rotate right 90°">&#8635;</button>' +
                '</div>' +
                '<div class="cp-item-label">' + escHtml(item.file.name) + '</div>' +
                '<div class="gp-style">' +
                    '<label class="gp-sqr"><input type="checkbox" class="gp-crop"' +
                        (item.crop === 'fill' ? ' checked' : '') + '> Square crop (IG)</label>' +
                    '<div class="gp-ctl gp-crop-ctl"><span>Zoom</span>' +
                        '<input type="range" class="gp-zoom" min="100" max="300" value="' + item.zoom + '">' +
                        '<input type="number" class="gp-zoom-v" min="100" max="300" step="5" value="' + item.zoom + '" style="width:52px;">%</div>' +
                    '<div class="gp-pan-hint" style="font:10px/1.3 monospace;opacity:.55;margin:-2px 0 6px;">drag the photo to reposition the crop</div>' +
                    '<div class="gp-fit"' + (item.crop === 'fill' ? ' style="display:none;"' : '') + '>' +
                        '<div class="gp-ctl"><span>Size</span>' +
                            '<input type="range" class="gp-size" min="10" max="100" value="' + item.size + '">' +
                            '<input type="number" class="gp-size-v" min="10" max="100" step="1" value="' + item.size + '" style="width:48px;">%</div>' +
                        '<div class="gp-ctl"><span>Border</span>' +
                            '<input type="range" class="gp-bpx" min="0" max="50" value="' + item.bpx + '">' +
                            '<input type="number" class="gp-bpx-v" min="0" max="50" step="1" value="' + item.bpx + '" style="width:48px;">px</div>' +
                        '<div class="gp-ctl gp-ctl-row">' +
                            '<label class="gp-swatch"><input type="color" class="gp-bcol" value="' + item.bcol + '"> Border</label>' +
                            '<label class="gp-swatch"><input type="color" class="gp-bg" value="' + item.bg + '"> Matte</label>' +
                        '</div>' +
                        '<div class="gp-ctl"><span>Shadow</span>' +
                            '<select class="gp-shadow">' + shadowOpts + '</select></div>' +
                    '</div>' +
                '</div>';

            const wrap     = el.querySelector('.cp-thumb-wrap');
            const thumbImg = el.querySelector('.cp-thumb');
            applyPreview(item, wrap, thumbImg);

            el.querySelector('.cp-remove-btn').addEventListener('click', e => {
                e.stopPropagation();
                removeFile(parseInt(e.currentTarget.dataset.idx));
            });

            // 90° orientation buttons — update the preview live; the real file is
            // rotated via canvas on submit (rotateFile). Don't let them start a drag.
            el.querySelectorAll('.cp-rot-btn').forEach(btn => {
                btn.addEventListener('mousedown',  e => e.stopPropagation());
                btn.addEventListener('mouseenter', () => { el.draggable = false; });
                btn.addEventListener('mouseleave', () => { el.draggable = true; });
                btn.addEventListener('click', e => {
                    e.stopPropagation();
                    item.rotate = (((item.rotate || 0) + parseInt(btn.dataset.rot)) % 360 + 360) % 360;
                    applyPreview(item, wrap, thumbImg);
                });
            });

            // Per-image style wiring — updates the item in place (no re-render, so
            // focus/value survive). stopPropagation keeps clicks off drag-reorder.
            const styleEl = el.querySelector('.gp-style');
            const cropCb  = styleEl.querySelector('.gp-crop');
            const fitBox  = styleEl.querySelector('.gp-fit');
            // Native form controls can't operate inside a draggable element — so
            // grabbing a slider was dragging the whole card. Disable card drag
            // while the pointer is over any control; restore it on the way out.
            styleEl.querySelectorAll('input, select, label').forEach(n => {
                n.addEventListener('click',      e => e.stopPropagation());
                n.addEventListener('mousedown',  e => e.stopPropagation());
                n.addEventListener('mouseenter', () => { el.draggable = false; });
                n.addEventListener('mouseleave', () => { el.draggable = true; });
            });
            cropCb.addEventListener('change', () => {
                item.crop = cropCb.checked ? 'fill' : 'fit';
                fitBox.style.display = cropCb.checked ? 'none' : '';
                applyPreview(item, wrap, thumbImg);
            });
            const sizeR = styleEl.querySelector('.gp-size'), sizeV = styleEl.querySelector('.gp-size-v');
            const syncSize = v => { v = Math.max(10, Math.min(100, parseInt(v) || 10)); item.size = v; sizeR.value = v; sizeV.value = v; applyPreview(item, wrap, thumbImg); };
            sizeR.addEventListener('input', () => syncSize(sizeR.value));
            sizeV.addEventListener('input', () => syncSize(sizeV.value));
            const bpxR = styleEl.querySelector('.gp-bpx'), bpxV = styleEl.querySelector('.gp-bpx-v');
            const syncBpx = v => { v = Math.max(0, Math.min(50, parseInt(v) || 0)); item.bpx = v; bpxR.value = v; bpxV.value = v; applyPreview(item, wrap, thumbImg); };
            bpxR.addEventListener('input', () => syncBpx(bpxR.value));
            bpxV.addEventListener('input', () => syncBpx(bpxV.value));
            styleEl.querySelector('.gp-bcol').addEventListener('input', e => { item.bcol = e.target.value; applyPreview(item, wrap, thumbImg); });
            styleEl.querySelector('.gp-bg').addEventListener('input',   e => { item.bg   = e.target.value; applyPreview(item, wrap, thumbImg); });
            styleEl.querySelector('.gp-shadow').addEventListener('change', e => { item.shadow = parseInt(e.target.value); applyPreview(item, wrap, thumbImg); });

            // Zoom (square crop) — always available, independent of fit/fill.
            const zoomR = styleEl.querySelector('.gp-zoom'), zoomV = styleEl.querySelector('.gp-zoom-v');
            const syncZoom = v => {
                v = Math.max(100, Math.min(300, parseInt(v) || 100));
                item.zoom = v; zoomR.value = v; zoomV.value = v;
                applyPreview(item, wrap, thumbImg);
            };
            zoomR.addEventListener('input', () => syncZoom(zoomR.value));
            zoomV.addEventListener('input', () => syncZoom(zoomV.value));

            // Drag the photo to pan the square crop (sets the focal point). Only
            // active when the crop is tighter than the whole image; disables the
            // card drag-reorder for the duration so the two don't fight.
            thumbImg.style.cursor = 'grab';
            thumbImg.addEventListener('mousedown', e => {
                const active = (item.crop === 'fill') || item.zoom > 100 || item.fx !== 50 || item.fy !== 50;
                if (!active) return;
                el.draggable = false; e.preventDefault(); e.stopPropagation();
                thumbImg.style.cursor = 'grabbing';
                let lastX = e.clientX, lastY = e.clientY;
                const move = ev => {
                    const nw = thumbImg.naturalWidth, nh = thumbImg.naturalHeight;
                    const S = wrap.clientWidth || wrap.offsetWidth;
                    if (!nw || !nh || !S) return;
                    const zoom = Math.max(100, Math.min(300, item.zoom || 100));
                    const winD = Math.min(nw, nh) / (zoom / 100);
                    const scale = S / winD;
                    const rangeX = (nw - winD) * scale, rangeY = (nh - winD) * scale;
                    const dx = ev.clientX - lastX, dy = ev.clientY - lastY;
                    lastX = ev.clientX; lastY = ev.clientY;
                    if (rangeX > 0) item.fx = Math.max(0, Math.min(100, item.fx - (dx / rangeX) * 100));
                    if (rangeY > 0) item.fy = Math.max(0, Math.min(100, item.fy - (dy / rangeY) * 100));
                    applySquareCrop(item, wrap, thumbImg);
                };
                const up = () => {
                    el.draggable = true; thumbImg.style.cursor = 'grab';
                    document.removeEventListener('mousemove', move);
                    document.removeEventListener('mouseup', up);
                };
                document.addEventListener('mousemove', move);
                document.addEventListener('mouseup', up);
            });

            // Drag-reorder
            el.addEventListener('dragstart', e => {
                dragSrc = el;
                el.classList.add('is-dragging');
                e.dataTransfer.effectAllowed = 'move';
            });
            el.addEventListener('dragend', () => {
                el.classList.remove('is-dragging');
                strip.querySelectorAll('.cp-strip-item').forEach(i => i.classList.remove('drag-over'));
            });
            el.addEventListener('dragover', e => { e.preventDefault(); el.classList.add('drag-over'); });
            el.addEventListener('dragleave', () => el.classList.remove('drag-over'));
            el.addEventListener('drop', e => {
                e.preventDefault();
                el.classList.remove('drag-over');
                if (!dragSrc || dragSrc === el) return;
                const fromIdx = parseInt(dragSrc.dataset.idx);
                const toIdx   = parseInt(el.dataset.idx);
                const moved   = fileList.splice(fromIdx, 1)[0];
                fileList.splice(toIdx, 0, moved);
                renderStrip();
            });

            strip.appendChild(el);

            if (IC_ASPECT) addRotateControl(el, item);
        });
    }

    // INSTANT CAMERA: per-thumb straighten slider (±5°, 0.1°). Sets item.angle
    // and shows a quick rotated/zoomed preview; the true crop-to-fill bake runs
    // on submit via ScanAlign.bakeFile. Gated — only added when IC_ASPECT is set.
    function addRotateControl(el, item) {
        const wrap  = el.querySelector('.cp-thumb-wrap');
        const thumb = el.querySelector('.cp-thumb');
        if (!wrap || !thumb) return;
        wrap.style.overflow = 'hidden';
        const row = document.createElement('div');
        row.style.cssText = 'display:flex;align-items:center;gap:6px;margin-top:6px;';
        const s = document.createElement('input');
        s.type = 'range'; s.min = '-5'; s.max = '5'; s.step = '0.1';
        s.value = String(item.angle || 0); s.style.flex = '1'; s.title = 'Straighten ±5°';
        const lab = document.createElement('span');
        lab.style.cssText = 'font:11px/1 monospace;min-width:42px;text-align:right;opacity:.75;';
        lab.textContent = (item.angle || 0).toFixed(1) + '°';
        const applyRotate = () => { thumb.style.transform = 'scale(1.18) rotate(' + (item.angle || 0) + 'deg)'; };
        s.addEventListener('input', () => {
            item.angle = parseFloat(s.value) || 0;
            lab.textContent = item.angle.toFixed(1) + '°';
            applyRotate();
        });
        // Keep the slider from starting a thumbnail drag-reorder.
        s.addEventListener('click', e => e.stopPropagation());
        s.addEventListener('mousedown', e => e.stopPropagation());
        s.addEventListener('mouseenter', () => { el.draggable = false; });
        s.addEventListener('mouseleave', () => { el.draggable = true; });
        if (item.angle) applyRotate();
        row.appendChild(s); row.appendChild(lab);
        el.appendChild(row);
    }

    function escHtml(s) {
        return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // Rotate an image File by 0/90/180/270° via canvas, returning a new File.
    // Swaps canvas dimensions for the quarter turns so nothing is clipped.
    function rotateFile(file, deg) {
        return new Promise(resolve => {
            deg = ((deg % 360) + 360) % 360;
            if (!deg) return resolve(file);
            const img = new Image();
            img.onload = () => {
                try {
                    const swap = (deg === 90 || deg === 270);
                    const c = document.createElement('canvas');
                    c.width  = swap ? img.naturalHeight : img.naturalWidth;
                    c.height = swap ? img.naturalWidth  : img.naturalHeight;
                    const ctx = c.getContext('2d');
                    ctx.translate(c.width / 2, c.height / 2);
                    ctx.rotate(deg * Math.PI / 180);
                    ctx.drawImage(img, -img.naturalWidth / 2, -img.naturalHeight / 2);
                    const isPng = (file.type || '').indexOf('png') > -1;
                    c.toBlob(blob => {
                        if (!blob) return resolve(file);
                        resolve(new File([blob], file.name, { type: blob.type || file.type }));
                    }, isPng ? 'image/png' : 'image/jpeg', 0.95);
                } catch (e) { resolve(file); }
            };
            img.onerror = () => resolve(file);
            img.src = URL.createObjectURL(file);
        });
    }

    // Form submit — rebuild file input in correct order then XHR
    form.addEventListener('submit', async e => {
        e.preventDefault();
        if (fileList.length === 0) return;

        errorDiv.style.display = 'none';
        submitBtn.disabled = true;
        progressWrap.style.display = '';
        progressBar.style.width = '0%';

        // INSTANT CAMERA scan-align: bake any per-image straighten into the
        // uploaded file. Gated to instant-camera + defensive — on any failure
        // the original file stands, so a post can never be blocked.
        if (IC_ASPECT && window.ScanAlign && ScanAlign.bakeFile) {
            try {
                await Promise.all(fileList.map(async (item) => {
                    if (item.angle) item.file = await ScanAlign.bakeFile(item.file, item.angle, IC_ASPECT);
                }));
            } catch (err) { /* leave originals */ }
        }

        // Bake any 90° orientation change into the actual uploaded file (canvas).
        // Defensive — on failure the original stands so a post is never blocked.
        try {
            await Promise.all(fileList.map(async (item) => {
                if (item.rotate) item.file = await rotateFile(item.file, item.rotate);
            }));
        } catch (err) { /* leave originals */ }

        const data = new FormData(form);

        // Remove any stale img_files entries and re-add in strip order
        data.delete('img_files');
        fileList.forEach((item, pos) => {
            data.append('img_files[]', item.file);
            data.append('sort_order[]', pos);
            data.append('crop_mode[]',        item.crop || 'fit');
            data.append('img_size_pct[]',     item.size   != null ? item.size   : 100);
            data.append('img_border_px[]',    item.bpx    != null ? item.bpx    : 0);
            data.append('img_border_color[]', item.bcol || '#000000');
            data.append('img_bg_color[]',     item.bg   || '#ffffff');
            data.append('img_shadow[]',       item.shadow != null ? item.shadow : 0);
            data.append('img_focus_x[]',      Math.round(item.fx != null ? item.fx : 50));
            data.append('img_focus_y[]',      Math.round(item.fy != null ? item.fy : 50));
            data.append('img_zoom[]',         item.zoom != null ? item.zoom : 100);
        });

        const xhr = new XMLHttpRequest();
        xhr.open('POST', form.action || window.location.href);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        xhr.upload.addEventListener('progress', evt => {
            if (evt.lengthComputable) {
                progressBar.style.width = Math.round((evt.loaded / evt.total) * 100) + '%';
            }
        });

        xhr.addEventListener('load', () => {
            progressBar.style.width = '100%';
            if (xhr.responseText.trim() === 'success') {
                window.location.href = 'smack-manage.php?msg=TRANSMISSION_LIVE';
            } else {
                errorDiv.textContent = xhr.responseText || 'Transmission failed.';
                errorDiv.style.display = '';
                submitBtn.disabled = false;
                progressWrap.style.display = 'none';
            }
        });

        xhr.addEventListener('error', () => {
            errorDiv.textContent = 'Network error during upload.';
            errorDiv.style.display = '';
            submitBtn.disabled = false;
            progressWrap.style.display = 'none';
        });

        xhr.send(data);
    });

}());
// ===== SNAPSMACK EOF =====
