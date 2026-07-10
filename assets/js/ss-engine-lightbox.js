/**
 * SNAPSMACK - Lightbox Engine (navigable gallery)
 *
 * Full-screen image viewer with fade-in overlay and PREV/NEXT navigation across
 * every lightboxable image on the page. Handles:
 *   - Single/post images (.post-image / .pg-post-image)
 *   - Inline images rendered by the [img:ID|size|align] shortcode parser
 *     (identified by the data-lightbox-src attribute — full-size original)
 *
 * Navigation (per Sean: ALL lightboxes navigate, not just the archive):
 *   - On-screen left/right arrow buttons
 *   - Keyboard ArrowLeft / ArrowRight
 *   - Touch swipe left/right (tablet)
 *   - ESC or click background to close
 * Clamps at the ends (arrow hidden at first/last; no wrap). With a single image
 * on the page the arrows never show, so behaviour is unchanged from before.
 *
 * NOTE: Scripts are loaded at end of <body> by skin-footer.php, so
 * DOMContentLoaded may have already fired — use a readyState guard.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

if (!window._ssLightboxLoaded) {
window._ssLightboxLoaded = true;

function _ssLightboxInit() {

    // --- CONFIG ---
    var opacitySetting = (window.SMACK_CONFIG && window.SMACK_CONFIG.lightbox && window.SMACK_CONFIG.lightbox.opacity)
        ? window.SMACK_CONFIG.lightbox.opacity
        : '0.8';

    // --- BUILD THE GALLERY SET (page DOM order) ---
    // Each entry: { src: <full-size url> }. A trigger element maps to its index.
    var items    = [];
    var triggers = [];

    function pushTrigger(el, src) {
        if (!src) return;
        el.dataset._ssLbIndex = String(items.length);
        items.push({ src: src });
        triggers.push(el);
    }

    // 1. Post/single images — full src is the element's own src.
    document.querySelectorAll('.post-image, .pg-post-image').forEach(function (photo) {
        pushTrigger(photo, photo.getAttribute('src'));
    });
    // 2. Inline shortcode images — data-lightbox-src is the full original.
    document.querySelectorAll('img[data-lightbox-src]').forEach(function (img) {
        pushTrigger(img, img.getAttribute('data-lightbox-src'));
    });

    if (!items.length) return;

    var multi = items.length > 1;

    // --- OVERLAY (built once, reused) ---
    var overlay = null, bigImg = null, prevBtn = null, nextBtn = null;
    var current = 0;

    function buildOverlay() {
        overlay = document.createElement('div');
        overlay.id = 'ss-lightbox-overlay';
        overlay.style.cssText =
            'position:fixed;top:0;left:0;width:100vw;height:100vh;' +
            'background:rgba(0,0,0,' + opacitySetting + ');' +
            'display:flex;align-items:center;justify-content:center;' +
            'z-index:9999;opacity:0;transition:opacity .18s ease-out;cursor:zoom-out;';

        bigImg = document.createElement('img');
        bigImg.style.cssText = 'max-width:95vw;max-height:95vh;object-fit:contain;box-shadow:0 0 40px rgba(0,0,0,.8);cursor:default;';
        overlay.appendChild(bigImg);

        if (multi) {
            prevBtn = _navBtn('‹', 'left');   // ‹
            nextBtn = _navBtn('›', 'right');  // ›
            overlay.appendChild(prevBtn);
            overlay.appendChild(nextBtn);
            prevBtn.addEventListener('click', function (e) { e.stopPropagation(); go(current - 1); });
            nextBtn.addEventListener('click', function (e) { e.stopPropagation(); go(current + 1); });
        }

        // Click background (not the image) closes; image click does nothing.
        overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
        bigImg.addEventListener('click', function (e) { e.stopPropagation(); });

        // Touch swipe (tablet).
        var tsx = 0, tsy = 0;
        overlay.addEventListener('touchstart', function (e) {
            tsx = e.changedTouches[0].clientX; tsy = e.changedTouches[0].clientY;
        }, { passive: true });
        overlay.addEventListener('touchend', function (e) {
            var dx = e.changedTouches[0].clientX - tsx;
            var dy = e.changedTouches[0].clientY - tsy;
            if (multi && Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy)) {
                go(dx < 0 ? current + 1 : current - 1);
            }
        }, { passive: true });

        document.body.appendChild(overlay);
    }

    function _navBtn(glyph, side) {
        var b = document.createElement('button');
        b.type = 'button';
        b.textContent = glyph;
        b.setAttribute('aria-label', side === 'left' ? 'Previous' : 'Next');
        b.style.cssText =
            'position:fixed;top:50%;' + side + ':2vw;transform:translateY(-50%);' +
            'width:52px;height:52px;border:none;border-radius:50%;' +
            'background:rgba(0,0,0,.45);color:#fff;font-size:30px;line-height:1;' +
            'cursor:pointer;z-index:10000;display:flex;align-items:center;justify-content:center;';
        return b;
    }

    function updateArrows() {
        if (!multi) return;
        prevBtn.style.display = current <= 0 ? 'none' : 'flex';
        nextBtn.style.display = current >= items.length - 1 ? 'none' : 'flex';
    }

    function go(i) {
        if (i < 0 || i >= items.length) return; // clamp, no wrap
        current = i;
        bigImg.setAttribute('src', items[current].src);
        updateArrows();
    }

    function open(index) {
        if (!overlay) buildOverlay();
        current = index;
        bigImg.setAttribute('src', items[current].src);
        updateArrows();
        overlay.style.display = 'flex';
        requestAnimationFrame(function () {
            requestAnimationFrame(function () { overlay.style.opacity = '1'; });
        });
        window.smackdown = window.smackdown || {};
        window.smackdown.closeLightbox = close;
    }

    function close() {
        if (!overlay) return;
        overlay.style.opacity = '0';
        setTimeout(function () {
            overlay.style.display = 'none';
            bigImg.setAttribute('src', '');
        }, 180);
        if (window.smackdown) window.smackdown.closeLightbox = null;
    }

    function isOpen() { return overlay && overlay.style.display !== 'none' && overlay.style.opacity !== '0'; }

    // --- WIRE TRIGGERS ---
    triggers.forEach(function (el, idx) {
        el.style.cursor = 'zoom-in';
        el.addEventListener('click', function (e) {
            if (e.target.closest && e.target.closest('a, button') && e.target !== el) return;
            open(idx);
        });
        el.addEventListener('touchend', function (e) {
            if (e.target.closest && e.target.closest('a, button') && e.target !== el) return;
            e.preventDefault();
            open(idx);
        }, { passive: false });
    });

    // --- KEYBOARD ---
    document.addEventListener('keydown', function (e) {
        if (!isOpen()) return;
        if (e.key === 'Escape') { close(); }
        else if (multi && e.key === 'ArrowLeft')  { go(current - 1); }
        else if (multi && e.key === 'ArrowRight') { go(current + 1); }
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', _ssLightboxInit);
} else {
    _ssLightboxInit();
}

} // end double-load guard
// ===== SNAPSMACK EOF =====
