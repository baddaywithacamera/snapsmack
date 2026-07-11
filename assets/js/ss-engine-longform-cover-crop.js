/**
 * SNAPSMACK - Longform cover pan/zoom cropper
 *
 * Drives the COVER FRAMING widget on smack-post-long.php. Drag the stage to pan
 * (object-position), slide to zoom (transform scale) — WYSIWYG, non-destructive.
 * The SMACKTALK skins render the cover the same way (object-fit cover +
 * object-position + scale), framed to the skin's manifest cover_aspect, so what
 * you frame here is what ships. Values persist in the post form's hidden inputs
 * (cover_pos_x / cover_pos_y / cover_zoom).
 *
 * Exposes window.SnapLongCoverCrop { setImage(url), reset(), show(), hide() } so
 * the Gallery cover picker can re-point it when the cover image changes.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */
(function () {
    'use strict';

    var stage = document.getElementById('lc-stage');
    var img   = document.getElementById('lc-cover-img');
    var wrap  = document.getElementById('long-cover-crop-wrap');
    var zoom  = document.getElementById('lc-zoom');
    var inX   = document.getElementById('lc-pos-x');
    var inY   = document.getElementById('lc-pos-y');
    var inZ   = document.getElementById('lc-zoom-val');
    var reset = document.getElementById('lc-recenter');
    if (!stage || !img || !zoom || !inX || !inY || !inZ) return;

    function clamp(v, lo, hi, d) {
        v = parseFloat(v);
        if (isNaN(v)) v = d;
        return Math.max(lo, Math.min(hi, v));
    }

    var posX = clamp(inX.value, 0, 100, 50);
    var posY = clamp(inY.value, 0, 100, 50);
    var z    = clamp(inZ.value, 100, 300, 100) / 100;

    function apply() {
        var pos = posX.toFixed(1) + '% ' + posY.toFixed(1) + '%';
        img.style.objectPosition  = pos;
        img.style.transformOrigin = pos;
        img.style.transform       = 'scale(' + z.toFixed(3) + ')';
        inX.value = posX.toFixed(1);
        inY.value = posY.toFixed(1);
        inZ.value = Math.round(z * 100);
        if (String(zoom.value) !== String(Math.round(z * 100))) zoom.value = Math.round(z * 100);
    }

    // ── Drag to pan ─────────────────────────────────────────────────────────
    // Dragging the image right reveals more of its LEFT, so object-position-x
    // decreases. A full-width drag pans the full 0-100% range (predictable at
    // any zoom); fine-tune by eye.
    var dragging = false, lastX = 0, lastY = 0;
    stage.addEventListener('pointerdown', function (e) {
        dragging = true; lastX = e.clientX; lastY = e.clientY;
        try { stage.setPointerCapture(e.pointerId); } catch (err) {}
        stage.style.cursor = 'grabbing';
    });
    stage.addEventListener('pointermove', function (e) {
        if (!dragging) return;
        var rect = stage.getBoundingClientRect();
        var dx = e.clientX - lastX, dy = e.clientY - lastY;
        lastX = e.clientX; lastY = e.clientY;
        posX = clamp(posX - (dx / rect.width)  * 100, 0, 100, 50);
        posY = clamp(posY - (dy / rect.height) * 100, 0, 100, 50);
        apply();
    });
    function endDrag() { dragging = false; stage.style.cursor = 'grab'; }
    stage.addEventListener('pointerup', endDrag);
    stage.addEventListener('pointercancel', endDrag);
    stage.addEventListener('pointerleave', endDrag);

    zoom.addEventListener('input', function () { z = clamp(this.value, 100, 300, 100) / 100; apply(); });
    if (reset) reset.addEventListener('click', function () { posX = 50; posY = 50; z = 1; apply(); });

    // Public API for the Gallery cover picker.
    window.SnapLongCoverCrop = {
        setImage: function (url) { if (url) img.src = url; },
        reset:    function () { posX = 50; posY = 50; z = 1; apply(); },
        show:     function () { if (wrap) wrap.style.display = ''; },
        hide:     function () { if (wrap) wrap.style.display = 'none'; }
    };

    apply();
}());
// ===== SNAPSMACK EOF =====
