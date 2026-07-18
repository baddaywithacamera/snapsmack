/**
 * SNAPSMACK - Lightbox Engine (navigable gallery)
 *
 * Full-screen image viewer with fade-in overlay and PREV/NEXT navigation across
 * every lightboxable image in scope. Handles:
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
 * in scope the arrows never show.
 *
 * 2026-07-18: re-scan support. The GRAM-family solo view is an AJAX modal
 * (ss-engine-grid-modal.js injects the post AFTER load, then fires
 * 'snapsmack:modal:opened'). The old build scanned once on DOMContentLoaded, so
 * modal images were never wired → clicking the solo image did nothing on every
 * GRAM skin. Wiring is now idempotent and re-runnable; on modal-open we reset the
 * gallery to the modal's own images (carousel = a navigable set, single = one).
 *
 * NOTE: the overlay + arrow buttons are built in JS with inline style.cssText.
 * That is PRE-EXISTING (predates the no-inline-styles rule) and is preserved
 * as-is here — NOT expanded. It wants its own de-inline pass (move the overlay
 * chrome into a stylesheet); flagged, out of scope for this lightbox fix.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

if (!window._ssLightboxLoaded) {
window._ssLightboxLoaded = true;

// --- module state (shared across the initial scan + modal re-scans) ---
var _lbItems = [];
var _lbOverlay = null, _lbBigImg = null, _lbPrev = null, _lbNext = null;
var _lbCurrent = 0;
var _lbOpacity = (window.SMACK_CONFIG && window.SMACK_CONFIG.lightbox && window.SMACK_CONFIG.lightbox.opacity)
    ? window.SMACK_CONFIG.lightbox.opacity
    : '0.8';

function _lbMulti() { return _lbItems.length > 1; }

function _lbNavBtn(glyph, side) {
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

function _lbUpdateArrows() {
    if (!_lbPrev || !_lbNext) return;
    if (!_lbMulti()) { _lbPrev.style.display = 'none'; _lbNext.style.display = 'none'; return; }
    _lbPrev.style.display = _lbCurrent <= 0 ? 'none' : 'flex';
    _lbNext.style.display = _lbCurrent >= _lbItems.length - 1 ? 'none' : 'flex';
}

function _lbGo(i) {
    if (i < 0 || i >= _lbItems.length) return; // clamp, no wrap
    _lbCurrent = i;
    _lbBigImg.setAttribute('src', _lbItems[_lbCurrent].src);
    _lbUpdateArrows();
}

function _lbBuildOverlay() {
    _lbOverlay = document.createElement('div');
    _lbOverlay.id = 'ss-lightbox-overlay';
    _lbOverlay.style.cssText =
        'position:fixed;top:0;left:0;width:100vw;height:100vh;' +
        'background:rgba(0,0,0,' + _lbOpacity + ');' +
        'display:flex;align-items:center;justify-content:center;' +
        'z-index:9999;opacity:0;transition:opacity .18s ease-out;cursor:zoom-out;';

    _lbBigImg = document.createElement('img');
    _lbBigImg.style.cssText = 'max-width:95vw;max-height:95vh;object-fit:contain;box-shadow:0 0 40px rgba(0,0,0,.8);cursor:default;';
    _lbOverlay.appendChild(_lbBigImg);

    _lbPrev = _lbNavBtn('‹', 'left');
    _lbNext = _lbNavBtn('›', 'right');
    _lbOverlay.appendChild(_lbPrev);
    _lbOverlay.appendChild(_lbNext);
    _lbPrev.addEventListener('click', function (e) { e.stopPropagation(); _lbGo(_lbCurrent - 1); });
    _lbNext.addEventListener('click', function (e) { e.stopPropagation(); _lbGo(_lbCurrent + 1); });

    // Click background (not the image) closes; image click does nothing.
    _lbOverlay.addEventListener('click', function (e) { if (e.target === _lbOverlay) _lbClose(); });
    _lbBigImg.addEventListener('click', function (e) { e.stopPropagation(); });

    // Touch swipe (tablet).
    var tsx = 0, tsy = 0;
    _lbOverlay.addEventListener('touchstart', function (e) {
        tsx = e.changedTouches[0].clientX; tsy = e.changedTouches[0].clientY;
    }, { passive: true });
    _lbOverlay.addEventListener('touchend', function (e) {
        var dx = e.changedTouches[0].clientX - tsx;
        var dy = e.changedTouches[0].clientY - tsy;
        if (_lbMulti() && Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy)) {
            _lbGo(dx < 0 ? _lbCurrent + 1 : _lbCurrent - 1);
        }
    }, { passive: true });

    document.body.appendChild(_lbOverlay);
}

function _lbOpen(index) {
    if (!_lbOverlay) _lbBuildOverlay();
    _lbCurrent = index;
    _lbBigImg.setAttribute('src', _lbItems[_lbCurrent].src);
    _lbUpdateArrows();
    _lbOverlay.style.display = 'flex';
    requestAnimationFrame(function () {
        requestAnimationFrame(function () { _lbOverlay.style.opacity = '1'; });
    });
    window.smackdown = window.smackdown || {};
    window.smackdown.closeLightbox = _lbClose;
}

function _lbClose() {
    if (!_lbOverlay) return;
    _lbOverlay.style.opacity = '0';
    setTimeout(function () {
        _lbOverlay.style.display = 'none';
        _lbBigImg.setAttribute('src', '');
    }, 180);
    if (window.smackdown) window.smackdown.closeLightbox = null;
}

function _lbIsOpen() {
    return _lbOverlay && _lbOverlay.style.display !== 'none' && _lbOverlay.style.opacity !== '0';
}

// Idempotent: an element is wired at most once (dataset guard).
function _lbWire(el, src) {
    if (!src || el.dataset._ssLbWired) return;
    el.dataset._ssLbWired = '1';
    var idx = _lbItems.length;
    _lbItems.push({ src: src });
    el.style.cursor = 'zoom-in';
    el.addEventListener('click', function (e) {
        if (e.target.closest && e.target.closest('a, button') && e.target !== el) return;
        _lbOpen(idx);
    });
    el.addEventListener('touchend', function (e) {
        if (e.target.closest && e.target.closest('a, button') && e.target !== el) return;
        e.preventDefault();
        _lbOpen(idx);
    }, { passive: false });
}

function _lbScan(root) {
    root = root || document;
    root.querySelectorAll('.post-image, .pg-post-image').forEach(function (photo) {
        _lbWire(photo, photo.getAttribute('src'));
    });
    root.querySelectorAll('img[data-lightbox-src]').forEach(function (img) {
        _lbWire(img, img.getAttribute('data-lightbox-src'));
    });
}

function _ssLightboxInit() {
    _lbScan(document);
}

// GRAM-family modal injects its post after load; scope the gallery to that modal
// (reset first so a carousel navigates its own frames and stale frames from a
// previously-opened modal are dropped).
document.addEventListener('snapsmack:modal:opened', function (e) {
    var root = (e && e.target && e.target.querySelectorAll) ? e.target : document;
    _lbItems = [];
    _lbCurrent = 0;
    _lbScan(root);
});

// --- KEYBOARD (bound once) ---
document.addEventListener('keydown', function (e) {
    if (!_lbIsOpen()) return;
    if (e.key === 'Escape') { _lbClose(); }
    else if (_lbMulti() && e.key === 'ArrowLeft')  { _lbGo(_lbCurrent - 1); }
    else if (_lbMulti() && e.key === 'ArrowRight') { _lbGo(_lbCurrent + 1); }
});

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', _ssLightboxInit);
} else {
    _ssLightboxInit();
}

} // end double-load guard
// ===== SNAPSMACK EOF =====
