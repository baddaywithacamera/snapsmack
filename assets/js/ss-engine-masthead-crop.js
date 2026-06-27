/**
 * SNAPSMACK - Masthead cover position / zoom editor
 *
 * Drives the Slickr masthead cover editor in smack-masthead.php: drag the cover
 * to pan (object-position), slide to zoom (transform scale), live WYSIWYG. The
 * preview uses the SAME markup/CSS the skin renders (.sl-cover-img: object-fit
 * cover + object-position + scale), so what you frame here is exactly what ships.
 *
 * Pan/zoom are resolution-independent (percent + multiplier), so the content-
 * width preview matches the full-bleed live banner. No image is re-encoded; the
 * original is positioned by CSS at render time. Drag loop lifted from the
 * panorama slicer (ss-engine-slicer.js).
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */
(function () {
    'use strict';

    const stage = document.getElementById('mh-stage');
    const img   = document.getElementById('mh-cover-img');
    const zoom  = document.getElementById('mh-zoom');
    const inX   = document.getElementById('mh-pos-x');
    const inY   = document.getElementById('mh-pos-y');
    const inZ   = document.getElementById('mh-zoom-val');
    const reset = document.getElementById('mh-recenter');
    if (!stage || !img || !zoom || !inX || !inY || !inZ) return;

    // Initial state from the hidden inputs (server-rendered current values).
    let posX = clamp(parseFloat(inX.value), 0, 100, 50);
    let posY = clamp(parseFloat(inY.value), 0, 100, 50);
    let z    = clamp(parseFloat(inZ.value), 100, 300, 100) / 100;

    function clamp(v, lo, hi, dflt) {
        if (isNaN(v)) v = dflt;
        return Math.max(lo, Math.min(hi, v));
    }

    function apply() {
        const pos = posX.toFixed(1) + '% ' + posY.toFixed(1) + '%';
        img.style.objectPosition  = pos;
        img.style.transformOrigin = pos;
        img.style.transform       = 'scale(' + z.toFixed(3) + ')';
        inX.value = posX.toFixed(1);
        inY.value = posY.toFixed(1);
        inZ.value = Math.round(z * 100);
        zoom.value = Math.round(z * 100);
    }

    // ── Drag to pan ─────────────────────────────────────────────────────────
    // Grab-and-move feel: dragging the image right reveals more of its LEFT, so
    // object-position-x decreases. Scaled by stage size; sensitivity rises with
    // zoom (more overflow to traverse).
    let dragging = false, lastX = 0, lastY = 0;
    stage.addEventListener('pointerdown', e => {
        dragging = true; lastX = e.clientX; lastY = e.clientY;
        stage.setPointerCapture(e.pointerId);
        stage.classList.add('mh-grabbing');
        e.preventDefault();
    });
    stage.addEventListener('pointermove', e => {
        if (!dragging) return;
        const rect = stage.getBoundingClientRect();
        const dx = e.clientX - lastX, dy = e.clientY - lastY;
        lastX = e.clientX; lastY = e.clientY;
        // Divide by zoom so the image tracks the cursor 1:1 at any scale.
        posX = clamp(posX - (dx / rect.width  * 100) / z, 0, 100, 50);
        posY = clamp(posY - (dy / rect.height * 100) / z, 0, 100, 50);
        apply();
    });
    function endDrag(e) {
        if (!dragging) return;
        dragging = false;
        stage.classList.remove('mh-grabbing');
        if (e && e.pointerId != null && stage.hasPointerCapture(e.pointerId)) {
            stage.releasePointerCapture(e.pointerId);
        }
    }
    stage.addEventListener('pointerup', endDrag);
    stage.addEventListener('pointercancel', endDrag);

    // ── Zoom slider ─────────────────────────────────────────────────────────
    zoom.addEventListener('input', () => {
        z = clamp(parseFloat(zoom.value), 100, 300, 100) / 100;
        apply();
    });

    // ── Re-centre / reset ───────────────────────────────────────────────────
    if (reset) reset.addEventListener('click', () => {
        posX = 50; posY = 50; z = 1; apply();
    });

    apply();
}());
// ===== SNAPSMACK EOF =====
