/**
 * SNAPSMACK - Image Fade Load Engine
 *
 * Gracefully fades in images as they load, preventing layout jumps and font resets
 * on pages where image dimensions aren't predetermined. Images start at opacity 0
 * and fade in to opacity 1 once they've loaded.
 *
 * Applies to:
 *   - .post-image (solo photo pages, archive)
 *   - .pg-post-image (photo gallery pages)
 *   - .tg-image (true-grit skin specific)
 *   - .inline-asset (parsed shortcode images in descriptions)
 *   - img[data-lightbox-src] (CSS starts these at opacity:0 — MUST be revealed)
 *
 * 2026-07-18: re-scan on modal open. GRAM-family solo posts open as an AJAX
 * modal (ss-engine-grid-modal.js injects the post AFTER load, then fires
 * 'snapsmack:modal:opened'). This engine only ran on DOMContentLoaded, so a
 * modal image carrying data-lightbox-src (which CSS sets to opacity:0) was never
 * faded in → it stayed invisible (black panel). Now we re-run the reveal on the
 * modal's injected content too.
 *
 * Prevents layout shift by keeping space reserved for images during load.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

if (!window._ssImageFadeLoaded) {
window._ssImageFadeLoaded = true;

var _ssImageFadeSelectors = [
    '.post-image',
    '.pg-post-image',
    '.tg-image',
    '.inline-asset',
    'img[data-lightbox-src]'
];

/* Does this visitor ask for less motion?
   Checked per call rather than cached: the setting can change while the page
   is open, and a stale "no" would keep animating at someone who just asked it
   to stop. Wrapped because matchMedia can throw in odd embedding contexts, and
   a thrown error here would leave every photograph invisible. */
function _ssFadeReduceMotion() {
    try {
        return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    } catch (e) {
        return false;
    }
}

/* Reveal on the next frame so the transition has a start state to animate FROM.
   Setting opacity to 0 and then to 1 in the same tick is one style computation:
   the browser never sees the 0 and the photograph appears instantly. Two frames,
   because one is not guaranteed to have flushed the first value in every engine.
   Only the FIRST reveal goes through here. Every "just make it visible" path
   (the timeout net, visibilitychange, pageshow) sets opacity directly, because
   requestAnimationFrame does not fire in a background tab and a photograph must
   never be left at zero waiting for a frame that is not coming. */
function _ssFadeRaiseNextFrame(img) {
    var raf = window.requestAnimationFrame || function (cb) { return window.setTimeout(cb, 16); };
    raf(function () {
        raf(function () {
            img.style.opacity = '1';
            img.dataset._ssFadeDone = '1';
        });
    });
}

function _ssImageFadeInit(root) {
    root = root || document;
    var images = root.querySelectorAll(_ssImageFadeSelectors.join(','));
    var reduceMotion = _ssFadeReduceMotion();

    images.forEach(function (img) {
        // Skip if already handled (idempotent across the initial scan + modal re-scans).
        if (img.dataset._ssFadeDone) { img.style.opacity = '1'; return; }

        /* Reduced motion: show it, do not animate it. The engine previously
           animated everything unconditionally, which is out of step with the
           rest of the product — the archive reveal already respects this. Note
           the answer is "visible immediately", never "left at zero": refusing
           to animate must not refuse to show. */
        if (reduceMotion) {
            img.style.opacity = '1';
            img.dataset._ssFadeDone = '1';
            return;
        }

        /* A CACHED PHOTOGRAPH USED TO BLINK INSTEAD OF FADING. This branch read
           "already complete, so set opacity 1 and return" — before any
           transition was attached. The better the browser cached, the worse the
           site looked, so it degraded most for the owner and for returning
           visitors, which is exactly who notices. A cached image now takes the
           same path as any other: start at zero, then raise on a later frame so
           the transition has something to animate. (Sean, 2026-11-10: "they
           blink in rapidly if already loaded".) */
        var alreadyDecoded = img.complete && img.naturalHeight !== 0;

        // Set initial state: invisible, but space reserved.
        img.style.opacity = '0';
        // Keep presentation-owned hover easing (for example TILEZ's post-image
        // zoom). Replacing the complete shorthand here made the transform snap
        // immediately even though the stylesheet requested a smooth change.
        var existingTransition = window.getComputedStyle(img).transition;
        var opacityTransition = 'opacity 0.4s ease-in-out';
        img.style.transition = (!existingTransition || existingTransition === 'none')
            ? opacityTransition
            : existingTransition + ', ' + opacityTransition;

        // The animated reveal, for the first showing of this image.
        var fadeIn = function () { _ssFadeRaiseNextFrame(img); };
        // The unconditional reveal, for the safety nets below. No frame needed,
        // and none available in a background tab.
        var showNow = function () { img.style.opacity = '1'; img.dataset._ssFadeDone = '1'; };

        img.addEventListener('load', fadeIn, { once: true });
        img.addEventListener('error', showNow, { once: true });

        // Already decoded when we got here (cached), or completed synchronously
        // after addEventListener as Safari and Firefox can. Either way the load
        // event may never arrive, so reveal it ourselves — through the animated
        // path, which is the whole point of this change.
        if (alreadyDecoded || (img.complete && img.naturalHeight !== 0)) { fadeIn(); }
        // Some browsers complete an image while the page/tab is being resumed
        // without delivering the load callback or a visibility/pageshow event.
        // Do one bounded reconciliation so a healthy decoded image cannot stay
        // permanently washed out at the CSS initial opacity.
        // showNow, not fadeIn: this is the net, and a net that waits for an
        // animation frame catches nothing in a background tab.
        window.setTimeout(function () {
            if (img.complete) showNow();
        }, 1500);
    });
}

/* Chrome can complete an image while its tab is suspended without delivering
   the load callback until much later (or at all). The element then retains the
   engine's inline opacity:0 even though its pixels are ready. Reconcile that
   state whenever the page returns to the foreground. */
function _ssImageFadeRevealCompleted(root) {
    root = root || document;
    root.querySelectorAll(_ssImageFadeSelectors.join(',')).forEach(function (img) {
        if (!img.complete) return;
        img.style.opacity = '1';
        if (img.naturalHeight !== 0) img.dataset._ssFadeDone = '1';
    });
}

// Re-reveal images inside a GRAM modal once its content is injected.
document.addEventListener('snapsmack:modal:opened', function (e) {
    var root = (e && e.target && e.target.querySelectorAll) ? e.target : document;
    _ssImageFadeInit(root);
});

document.addEventListener('visibilitychange', function () {
    if (!document.hidden) _ssImageFadeRevealCompleted(document);
});
window.addEventListener('pageshow', function () { _ssImageFadeRevealCompleted(document); });

// Scripts load at end of <body> — DOMContentLoaded may have already fired.
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { _ssImageFadeInit(document); });
} else {
    _ssImageFadeInit(document);
}

} // end double-load guard
// ===== SNAPSMACK EOF =====
