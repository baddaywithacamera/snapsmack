/**
 * SNAPSMACK - Photo Editor Engine
 *
 * Canvas-based non-destructive image editor launched from smack-edit.php.
 * Features: crop (freeform + fixed ratios), rotate (90° + freeform), flip,
 * brightness, contrast, sharpen, black & white conversion.
 *
 * Usage: call SnapPhotoEditor.open(imgSrc, postId) to launch the overlay.
 * On save, POSTs the canvas blob to core/photo-editor-save.php which
 * writes the web copy and regenerates thumbnails.
 */
var SnapPhotoEditor = (function () {
    'use strict';

    // ── STATE ────────────────────────────────────────────────────────────
    var overlay, canvas, ctx, toolbar, previewCanvas, previewCtx;
    var original = null;       // original Image object
    var postId   = 0;
    var state    = resetState();

    function resetState() {
        return {
            rotation:    0,       // degrees (0, 90, 180, 270 or freeform)
            flipH:       false,
            flipV:       false,
            brightness:  0,       // -100 to 100
            contrast:    0,       // -100 to 100
            sharpen:     0,       // 0 to 100
            bw:          false,
            crop:        null,    // {x, y, w, h} in image coords or null
            cropRatio:   'free',  // 'free', '1:1', '4:3', '16:9', '3:2'
            history:     [],
            historyIdx:  -1,
        };
    }

    // ── BUILD UI ─────────────────────────────────────────────────────────
    function buildUI() {
        if (overlay) return;

        overlay = document.createElement('div');
        overlay.className = 'pe-overlay';
        overlay.innerHTML =
            '<div class="pe-container">' +
                '<div class="pe-toolbar" id="pe-toolbar">' +
                    '<div class="pe-tool-group">' +
                        '<button type="button" class="pe-btn" data-action="crop" title="Crop"><span class="pe-icon">⛶</span> Crop</button>' +
                        '<button type="button" class="pe-btn" data-action="rotate-cw" title="Rotate 90° CW"><span class="pe-icon">↻</span> Rotate</button>' +
                        '<button type="button" class="pe-btn" data-action="rotate-ccw" title="Rotate 90° CCW"><span class="pe-icon">↺</span></button>' +
                        '<button type="button" class="pe-btn" data-action="flip-h" title="Flip Horizontal"><span class="pe-icon">⇔</span> Flip H</button>' +
                        '<button type="button" class="pe-btn" data-action="flip-v" title="Flip Vertical"><span class="pe-icon">⇕</span> Flip V</button>' +
                    '</div>' +
                    '<div class="pe-tool-group pe-sliders">' +
                        '<label class="pe-slider-label">Brightness<input type="range" id="pe-brightness" min="-100" max="100" value="0"><span id="pe-brightness-val">0</span></label>' +
                        '<label class="pe-slider-label">Contrast<input type="range" id="pe-contrast" min="-100" max="100" value="0"><span id="pe-contrast-val">0</span></label>' +
                        '<label class="pe-slider-label">Sharpen<input type="range" id="pe-sharpen" min="0" max="100" value="0"><span id="pe-sharpen-val">0</span></label>' +
                    '</div>' +
                    '<div class="pe-tool-group">' +
                        '<button type="button" class="pe-btn pe-toggle" data-action="bw" title="Black & White"><span class="pe-icon">◐</span> B&W</button>' +
                    '</div>' +
                    '<div class="pe-tool-group pe-crop-ratios" id="pe-crop-ratios" style="display:none;">' +
                        '<span class="pe-label">Ratio:</span>' +
                        '<button type="button" class="pe-btn pe-btn--sm pe-active" data-ratio="free">Free</button>' +
                        '<button type="button" class="pe-btn pe-btn--sm" data-ratio="1:1">1:1</button>' +
                        '<button type="button" class="pe-btn pe-btn--sm" data-ratio="4:3">4:3</button>' +
                        '<button type="button" class="pe-btn pe-btn--sm" data-ratio="16:9">16:9</button>' +
                        '<button type="button" class="pe-btn pe-btn--sm" data-ratio="3:2">3:2</button>' +
                        '<button type="button" class="pe-btn pe-btn--sm pe-btn--accent" data-action="apply-crop">Apply</button>' +
                        '<button type="button" class="pe-btn pe-btn--sm" data-action="cancel-crop">Cancel</button>' +
                    '</div>' +
                '</div>' +
                '<div class="pe-canvas-wrap" id="pe-canvas-wrap">' +
                    '<canvas id="pe-canvas"></canvas>' +
                    '<canvas id="pe-crop-overlay" style="display:none;"></canvas>' +
                '</div>' +
                '<div class="pe-actions">' +
                    '<button type="button" class="pe-btn" data-action="undo" title="Undo">Undo</button>' +
                    '<button type="button" class="pe-btn" data-action="reset" title="Reset to Original">Reset</button>' +
                    '<div class="pe-spacer"></div>' +
                    '<button type="button" class="pe-btn" data-action="cancel">Cancel</button>' +
                    '<button type="button" class="pe-btn pe-btn--accent" data-action="save">Save</button>' +
                '</div>' +
            '</div>';

        document.body.appendChild(overlay);

        canvas = document.getElementById('pe-canvas');
        ctx    = canvas.getContext('2d');
        toolbar = document.getElementById('pe-toolbar');

        // Prevent body scroll
        overlay.addEventListener('wheel', function (e) { e.preventDefault(); }, { passive: false });

        // Wire toolbar buttons
        overlay.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-action]');
            if (btn) handleAction(btn.getAttribute('data-action'));
            var ratio = e.target.closest('[data-ratio]');
            if (ratio) setCropRatio(ratio.getAttribute('data-ratio'));
        });

        // Wire sliders
        var bSlider = document.getElementById('pe-brightness');
        var cSlider = document.getElementById('pe-contrast');
        var sSlider = document.getElementById('pe-sharpen');

        bSlider.addEventListener('input', function () {
            state.brightness = parseInt(this.value);
            document.getElementById('pe-brightness-val').textContent = this.value;
            render();
        });
        cSlider.addEventListener('input', function () {
            state.contrast = parseInt(this.value);
            document.getElementById('pe-contrast-val').textContent = this.value;
            render();
        });
        sSlider.addEventListener('input', function () {
            state.sharpen = parseInt(this.value);
            document.getElementById('pe-sharpen-val').textContent = this.value;
            render();
        });

        // Keyboard
        document.addEventListener('keydown', onKey);
    }

    // ── ACTION HANDLER ───────────────────────────────────────────────────
    function handleAction(action) {
        switch (action) {
            case 'rotate-cw':
                pushUndo();
                state.rotation = (state.rotation + 90) % 360;
                render();
                break;
            case 'rotate-ccw':
                pushUndo();
                state.rotation = (state.rotation + 270) % 360;
                render();
                break;
            case 'flip-h':
                pushUndo();
                state.flipH = !state.flipH;
                render();
                break;
            case 'flip-v':
                pushUndo();
                state.flipV = !state.flipV;
                render();
                break;
            case 'bw':
                pushUndo();
                state.bw = !state.bw;
                var btn = overlay.querySelector('[data-action="bw"]');
                if (btn) btn.classList.toggle('pe-active', state.bw);
                render();
                break;
            case 'crop':
                enterCropMode();
                break;
            case 'apply-crop':
                applyCrop();
                break;
            case 'cancel-crop':
                exitCropMode();
                break;
            case 'undo':
                undo();
                break;
            case 'reset':
                state = resetState();
                syncSliders();
                render();
                break;
            case 'save':
                save();
                break;
            case 'cancel':
                close();
                break;
        }
    }

    // ── UNDO ─────────────────────────────────────────────────────────────
    function pushUndo() {
        var snap = JSON.parse(JSON.stringify(state));
        delete snap.history;
        delete snap.historyIdx;
        state.history = state.history.slice(0, state.historyIdx + 1);
        state.history.push(snap);
        state.historyIdx = state.history.length - 1;
    }

    function undo() {
        if (state.historyIdx < 0) return;
        var snap = state.history[state.historyIdx];
        state.historyIdx--;
        var h = state.history;
        var hi = state.historyIdx;
        Object.assign(state, snap);
        state.history = h;
        state.historyIdx = hi;
        syncSliders();
        render();
    }

    function syncSliders() {
        var b = document.getElementById('pe-brightness');
        var c = document.getElementById('pe-contrast');
        var s = document.getElementById('pe-sharpen');
        if (b) { b.value = state.brightness; document.getElementById('pe-brightness-val').textContent = state.brightness; }
        if (c) { c.value = state.contrast;   document.getElementById('pe-contrast-val').textContent = state.contrast; }
        if (s) { s.value = state.sharpen;    document.getElementById('pe-sharpen-val').textContent = state.sharpen; }
        var bwBtn = overlay.querySelector('[data-action="bw"]');
        if (bwBtn) bwBtn.classList.toggle('pe-active', state.bw);
    }

    // ── CROP MODE ────────────────────────────────────────────────────────
    var cropOverlay, cropCtx, cropDrag;

    function enterCropMode() {
        var ratioBar = document.getElementById('pe-crop-ratios');
        if (ratioBar) ratioBar.style.display = 'flex';

        cropOverlay = document.getElementById('pe-crop-overlay');
        cropOverlay.width  = canvas.width;
        cropOverlay.height = canvas.height;
        cropOverlay.style.display = 'block';
        cropOverlay.style.position = 'absolute';
        cropOverlay.style.top = canvas.offsetTop + 'px';
        cropOverlay.style.left = canvas.offsetLeft + 'px';
        cropCtx = cropOverlay.getContext('2d');

        // Default crop = 80% centered
        var w = Math.round(canvas.width * 0.8);
        var h = Math.round(canvas.height * 0.8);
        state.crop = {
            x: Math.round((canvas.width - w) / 2),
            y: Math.round((canvas.height - h) / 2),
            w: w,
            h: h
        };
        state.cropRatio = 'free';

        drawCropOverlay();
        initCropDrag();
    }

    function exitCropMode() {
        state.crop = null;
        var ratioBar = document.getElementById('pe-crop-ratios');
        if (ratioBar) ratioBar.style.display = 'none';
        if (cropOverlay) cropOverlay.style.display = 'none';
        removeCropDrag();
    }

    function applyCrop() {
        if (!state.crop) return;
        pushUndo();

        // Get the crop in canvas coordinates and apply to actual image
        var c = state.crop;
        var tempCanvas = document.createElement('canvas');
        tempCanvas.width = c.w;
        tempCanvas.height = c.h;
        var tCtx = tempCanvas.getContext('2d');
        tCtx.drawImage(canvas, c.x, c.y, c.w, c.h, 0, 0, c.w, c.h);

        // Replace original with cropped version
        var croppedImg = new Image();
        croppedImg.onload = function () {
            original = croppedImg;
            state.crop = null;
            state.rotation = 0;
            state.flipH = false;
            state.flipV = false;
            exitCropMode();
            render();
        };
        croppedImg.src = tempCanvas.toDataURL('image/png');
    }

    function setCropRatio(ratio) {
        state.cropRatio = ratio;
        // Update active class
        var btns = document.querySelectorAll('#pe-crop-ratios [data-ratio]');
        for (var i = 0; i < btns.length; i++) {
            btns[i].classList.toggle('pe-active', btns[i].getAttribute('data-ratio') === ratio);
        }

        if (!state.crop) return;

        // Constrain crop to ratio
        if (ratio !== 'free') {
            var parts = ratio.split(':');
            var rw = parseInt(parts[0]);
            var rh = parseInt(parts[1]);
            var aspect = rw / rh;

            var newW = state.crop.w;
            var newH = Math.round(newW / aspect);
            if (newH > canvas.height) {
                newH = canvas.height;
                newW = Math.round(newH * aspect);
            }
            state.crop.w = newW;
            state.crop.h = newH;
            // Center
            state.crop.x = Math.round((canvas.width - newW) / 2);
            state.crop.y = Math.round((canvas.height - newH) / 2);
        }
        drawCropOverlay();
    }

    function drawCropOverlay() {
        if (!cropCtx || !state.crop) return;
        var c = state.crop;
        cropCtx.clearRect(0, 0, cropOverlay.width, cropOverlay.height);

        // Darken outside crop
        cropCtx.fillStyle = 'rgba(0, 0, 0, 0.55)';
        cropCtx.fillRect(0, 0, cropOverlay.width, cropOverlay.height);
        cropCtx.clearRect(c.x, c.y, c.w, c.h);

        // Crop border
        cropCtx.strokeStyle = '#fff';
        cropCtx.lineWidth = 2;
        cropCtx.strokeRect(c.x, c.y, c.w, c.h);

        // Rule of thirds
        cropCtx.strokeStyle = 'rgba(255,255,255,0.3)';
        cropCtx.lineWidth = 1;
        for (var i = 1; i <= 2; i++) {
            // Vertical
            var vx = c.x + (c.w / 3) * i;
            cropCtx.beginPath(); cropCtx.moveTo(vx, c.y); cropCtx.lineTo(vx, c.y + c.h); cropCtx.stroke();
            // Horizontal
            var hy = c.y + (c.h / 3) * i;
            cropCtx.beginPath(); cropCtx.moveTo(c.x, hy); cropCtx.lineTo(c.x + c.w, hy); cropCtx.stroke();
        }

        // Corner handles
        cropCtx.fillStyle = '#fff';
        var hs = 8;
        var corners = [
            [c.x, c.y], [c.x + c.w, c.y],
            [c.x, c.y + c.h], [c.x + c.w, c.y + c.h]
        ];
        for (var i = 0; i < corners.length; i++) {
            cropCtx.fillRect(corners[i][0] - hs/2, corners[i][1] - hs/2, hs, hs);
        }
    }

    // Crop drag logic
    var cropDragState = null;

    function initCropDrag() {
        cropOverlay.addEventListener('mousedown', cropMouseDown);
        document.addEventListener('mousemove', cropMouseMove);
        document.addEventListener('mouseup', cropMouseUp);
    }

    function removeCropDrag() {
        if (cropOverlay) {
            cropOverlay.removeEventListener('mousedown', cropMouseDown);
        }
        document.removeEventListener('mousemove', cropMouseMove);
        document.removeEventListener('mouseup', cropMouseUp);
    }

    function cropMouseDown(e) {
        if (!state.crop) return;
        var rect = cropOverlay.getBoundingClientRect();
        var mx = e.clientX - rect.left;
        var my = e.clientY - rect.top;
        var c = state.crop;
        var hs = 12; // hit area

        // Check corners first
        var corners = [
            { name: 'tl', x: c.x, y: c.y },
            { name: 'tr', x: c.x + c.w, y: c.y },
            { name: 'bl', x: c.x, y: c.y + c.h },
            { name: 'br', x: c.x + c.w, y: c.y + c.h },
        ];
        for (var i = 0; i < corners.length; i++) {
            if (Math.abs(mx - corners[i].x) < hs && Math.abs(my - corners[i].y) < hs) {
                cropDragState = { type: corners[i].name, sx: mx, sy: my, orig: Object.assign({}, c) };
                e.preventDefault();
                return;
            }
        }

        // Check inside crop for move
        if (mx >= c.x && mx <= c.x + c.w && my >= c.y && my <= c.y + c.h) {
            cropDragState = { type: 'move', sx: mx, sy: my, orig: Object.assign({}, c) };
            e.preventDefault();
        }
    }

    function cropMouseMove(e) {
        if (!cropDragState || !state.crop) return;
        var rect = cropOverlay.getBoundingClientRect();
        var mx = e.clientX - rect.left;
        var my = e.clientY - rect.top;
        var dx = mx - cropDragState.sx;
        var dy = my - cropDragState.sy;
        var o = cropDragState.orig;
        var c = state.crop;

        if (cropDragState.type === 'move') {
            c.x = Math.max(0, Math.min(canvas.width - c.w, o.x + dx));
            c.y = Math.max(0, Math.min(canvas.height - c.h, o.y + dy));
        } else {
            // Corner resize
            var ratio = null;
            if (state.cropRatio !== 'free') {
                var parts = state.cropRatio.split(':');
                ratio = parseInt(parts[0]) / parseInt(parts[1]);
            }

            switch (cropDragState.type) {
                case 'br':
                    c.w = Math.max(40, o.w + dx);
                    c.h = ratio ? Math.round(c.w / ratio) : Math.max(40, o.h + dy);
                    break;
                case 'bl':
                    c.w = Math.max(40, o.w - dx);
                    c.x = o.x + o.w - c.w;
                    c.h = ratio ? Math.round(c.w / ratio) : Math.max(40, o.h + dy);
                    break;
                case 'tr':
                    c.w = Math.max(40, o.w + dx);
                    c.h = ratio ? Math.round(c.w / ratio) : Math.max(40, o.h - dy);
                    c.y = ratio ? o.y + o.h - c.h : o.y + o.h - c.h;
                    break;
                case 'tl':
                    c.w = Math.max(40, o.w - dx);
                    c.x = o.x + o.w - c.w;
                    c.h = ratio ? Math.round(c.w / ratio) : Math.max(40, o.h - dy);
                    c.y = o.y + o.h - c.h;
                    break;
            }

            // Clamp to canvas bounds
            if (c.x < 0) { c.w += c.x; c.x = 0; }
            if (c.y < 0) { c.h += c.y; c.y = 0; }
            if (c.x + c.w > canvas.width)  c.w = canvas.width - c.x;
            if (c.y + c.h > canvas.height) c.h = canvas.height - c.y;
        }

        drawCropOverlay();
    }

    function cropMouseUp() {
        cropDragState = null;
    }

    // ── RENDER ───────────────────────────────────────────────────────────
    function render() {
        if (!original) return;

        var w = original.width;
        var h = original.height;

        // Swap dimensions for 90/270 rotation
        var isRotated = (state.rotation === 90 || state.rotation === 270);
        var cw = isRotated ? h : w;
        var ch = isRotated ? w : h;

        // Scale to fit viewport (max 80% of viewport)
        var maxW = window.innerWidth * 0.78;
        var maxH = window.innerHeight * 0.68;
        var scale = Math.min(1, maxW / cw, maxH / ch);

        canvas.width  = Math.round(cw * scale);
        canvas.height = Math.round(ch * scale);

        ctx.save();
        ctx.clearRect(0, 0, canvas.width, canvas.height);

        // Move origin to center for transforms
        ctx.translate(canvas.width / 2, canvas.height / 2);
        ctx.rotate(state.rotation * Math.PI / 180);
        if (state.flipH) ctx.scale(-1, 1);
        if (state.flipV) ctx.scale(1, -1);
        ctx.drawImage(original, -w * scale / 2, -h * scale / 2, w * scale, h * scale);
        ctx.restore();

        // Apply adjustments via pixel manipulation
        if (state.brightness !== 0 || state.contrast !== 0 || state.bw || state.sharpen > 0) {
            var imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
            var data = imageData.data;

            // Sharpen first (convolution)
            if (state.sharpen > 0) {
                imageData = applySharpen(imageData, state.sharpen / 100);
                data = imageData.data;
            }

            var bFactor = state.brightness * 2.55; // map -100..100 to -255..255
            var cFactor = (259 * (state.contrast + 255)) / (255 * (259 - state.contrast));

            for (var i = 0; i < data.length; i += 4) {
                var r = data[i];
                var g = data[i + 1];
                var b = data[i + 2];

                // Brightness
                if (state.brightness !== 0) {
                    r += bFactor;
                    g += bFactor;
                    b += bFactor;
                }

                // Contrast
                if (state.contrast !== 0) {
                    r = cFactor * (r - 128) + 128;
                    g = cFactor * (g - 128) + 128;
                    b = cFactor * (b - 128) + 128;
                }

                // B&W (luminosity method)
                if (state.bw) {
                    var lum = 0.299 * r + 0.587 * g + 0.114 * b;
                    r = g = b = lum;
                }

                data[i]     = Math.max(0, Math.min(255, r));
                data[i + 1] = Math.max(0, Math.min(255, g));
                data[i + 2] = Math.max(0, Math.min(255, b));
            }

            ctx.putImageData(imageData, 0, 0);
        }
    }

    // ── SHARPEN (3×3 UNSHARP MASK) ───────────────────────────────────────
    function applySharpen(imageData, amount) {
        var w = imageData.width;
        var h = imageData.height;
        var src = imageData.data;
        var out = new Uint8ClampedArray(src);

        // Simple 3×3 sharpen kernel weighted by amount
        // kernel: [0, -a, 0, -a, 1+4a, -a, 0, -a, 0]
        var a = amount * 0.5;
        var center = 1 + 4 * a;

        for (var y = 1; y < h - 1; y++) {
            for (var x = 1; x < w - 1; x++) {
                var idx = (y * w + x) * 4;
                for (var ch = 0; ch < 3; ch++) {
                    var val = center * src[idx + ch]
                            - a * src[((y - 1) * w + x) * 4 + ch]
                            - a * src[((y + 1) * w + x) * 4 + ch]
                            - a * src[(y * w + x - 1) * 4 + ch]
                            - a * src[(y * w + x + 1) * 4 + ch];
                    out[idx + ch] = val;
                }
            }
        }

        return new ImageData(out, w, h);
    }

    // ── SAVE ─────────────────────────────────────────────────────────────
    function save() {
        // Render at full resolution for save
        var fullCanvas = document.createElement('canvas');
        var fullCtx = fullCanvas.getContext('2d');

        var w = original.width;
        var h = original.height;
        var isRotated = (state.rotation === 90 || state.rotation === 270);
        fullCanvas.width  = isRotated ? h : w;
        fullCanvas.height = isRotated ? w : h;

        fullCtx.save();
        fullCtx.translate(fullCanvas.width / 2, fullCanvas.height / 2);
        fullCtx.rotate(state.rotation * Math.PI / 180);
        if (state.flipH) fullCtx.scale(-1, 1);
        if (state.flipV) fullCtx.scale(1, -1);
        fullCtx.drawImage(original, -w / 2, -h / 2, w, h);
        fullCtx.restore();

        // Apply adjustments
        if (state.brightness !== 0 || state.contrast !== 0 || state.bw || state.sharpen > 0) {
            var imageData = fullCtx.getImageData(0, 0, fullCanvas.width, fullCanvas.height);
            var data = imageData.data;

            if (state.sharpen > 0) {
                imageData = applySharpen(imageData, state.sharpen / 100);
                data = imageData.data;
            }

            var bFactor = state.brightness * 2.55;
            var cFactor = (259 * (state.contrast + 255)) / (255 * (259 - state.contrast));

            for (var i = 0; i < data.length; i += 4) {
                var r = data[i], g = data[i + 1], b = data[i + 2];
                if (state.brightness !== 0) { r += bFactor; g += bFactor; b += bFactor; }
                if (state.contrast !== 0) {
                    r = cFactor * (r - 128) + 128;
                    g = cFactor * (g - 128) + 128;
                    b = cFactor * (b - 128) + 128;
                }
                if (state.bw) { var lum = 0.299 * r + 0.587 * g + 0.114 * b; r = g = b = lum; }
                data[i] = Math.max(0, Math.min(255, r));
                data[i + 1] = Math.max(0, Math.min(255, g));
                data[i + 2] = Math.max(0, Math.min(255, b));
            }
            fullCtx.putImageData(imageData, 0, 0);
        }

        // Export as JPEG blob and POST
        fullCanvas.toBlob(function (blob) {
            var fd = new FormData();
            fd.append('post_id', postId);
            fd.append('image', blob, 'edited.jpg');

            var saveBtn = overlay.querySelector('[data-action="save"]');
            if (saveBtn) { saveBtn.textContent = 'Saving…'; saveBtn.disabled = true; }

            var xhr = new XMLHttpRequest();
            xhr.open('POST', 'core/photo-editor-save.php');
            xhr.onload = function () {
                if (xhr.status === 200) {
                    try {
                        var resp = JSON.parse(xhr.responseText);
                        if (resp.ok) {
                            // Refresh the preview image on the edit page
                            var preview = document.querySelector('.swap-preview');
                            if (preview) preview.src = resp.path + '?t=' + Date.now();
                            close();
                            return;
                        }
                    } catch (e) {}
                }
                alert('Save failed. Check server logs.');
                if (saveBtn) { saveBtn.textContent = 'Save'; saveBtn.disabled = false; }
            };
            xhr.onerror = function () {
                alert('Network error during save.');
                if (saveBtn) { saveBtn.textContent = 'Save'; saveBtn.disabled = false; }
            };
            xhr.send(fd);
        }, 'image/jpeg', 0.92);
    }

    // ── OPEN / CLOSE ─────────────────────────────────────────────────────
    function open(imgSrc, pid) {
        postId = pid || 0;
        state  = resetState();
        buildUI();
        syncSliders();

        overlay.style.display = 'flex';
        document.body.style.overflow = 'hidden';

        // Load image
        original = new Image();
        original.crossOrigin = 'anonymous';
        original.onload = function () {
            render();
        };
        original.onerror = function () {
            alert('Could not load image for editing.');
            close();
        };
        original.src = imgSrc;
    }

    function close() {
        if (overlay) overlay.style.display = 'none';
        document.body.style.overflow = '';
        exitCropMode();
    }

    // ── KEYBOARD ─────────────────────────────────────────────────────────
    function onKey(e) {
        if (!overlay || overlay.style.display === 'none') return;
        if (e.key === 'Escape') close();
        if ((e.ctrlKey || e.metaKey) && e.key === 'z') { e.preventDefault(); undo(); }
    }

    // ── PUBLIC API ───────────────────────────────────────────────────────
    return { open: open, close: close };

})();
