/**
 * SNAPSMACK - ALFRED archive gallery lightbox engine
 *
 * Powers the ALFRED (SMACKTALK) ARCHIVE view: a grid of individual published
 * photographs (snap_images) that open in a full-screen lightbox navigable across
 * the WHOLE archive set.
 *
 * Navigation:
 *   - Left / right on-screen arrow BUTTONS
 *   - Keyboard Left / Right ARROW keys
 *   - Touch SWIPE (tablet / mobile)
 *   ESC or click-background closes. Clamps at the ends (no wrap): the arrow at
 *   the first / last image is disabled + hidden.
 *
 * The ordered image list is collected from the grid DOM at load: each grid tile
 * carries data-full="<full image url>" (and optional data-title). No server data
 * is injected inline — this reads the DOM the skin rendered.
 *
 * Markup contract (rendered by skins/alfred/preload.php archive branch):
 *   .alfred-archive-grid  a.alfred-archive-tile[data-full][data-title]
 *   #alfred-archive-lightbox  (hidden until opened) containing:
 *     .alfred-lb-img            (the <img>)
 *     .alfred-lb-close          (close button)
 *     .alfred-lb-prev           (previous button)
 *     .alfred-lb-next           (next button)
 *     .alfred-lb-caption        (optional caption text target)
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */
(function () {
    'use strict';

    function ready(fn) {
        if (document.readyState !== 'loading') { fn(); }
        else { document.addEventListener('DOMContentLoaded', fn); }
    }

    ready(function () {
        var box = document.getElementById('alfred-archive-lightbox');
        var grid = document.querySelector('.alfred-archive-grid');
        if (!box || !grid) { return; }

        var tiles = Array.prototype.slice.call(
            grid.querySelectorAll('.alfred-archive-tile[data-full]')
        );
        if (!tiles.length) { return; }

        // Build the ordered navigation array from the grid DOM.
        var items = tiles.map(function (t) {
            return {
                full:  t.getAttribute('data-full') || '',
                title: t.getAttribute('data-title') || ''
            };
        });

        var img      = box.querySelector('.alfred-lb-img');
        var closeBtn = box.querySelector('.alfred-lb-close');
        var prevBtn  = box.querySelector('.alfred-lb-prev');
        var nextBtn  = box.querySelector('.alfred-lb-next');
        var caption  = box.querySelector('.alfred-lb-caption');
        if (!img) { return; }

        var current = -1;
        var openClass = 'alfred-lb-open';

        function clampButtons() {
            if (prevBtn) {
                var atStart = (current <= 0);
                prevBtn.disabled = atStart;
                prevBtn.hidden = atStart;
            }
            if (nextBtn) {
                var atEnd = (current >= items.length - 1);
                nextBtn.disabled = atEnd;
                nextBtn.hidden = atEnd;
            }
        }

        function show(i) {
            if (i < 0 || i >= items.length) { return; }
            current = i;
            var it = items[i];
            img.setAttribute('src', it.full);
            img.setAttribute('alt', it.title || '');
            if (caption) { caption.textContent = it.title || ''; }
            clampButtons();
        }

        function open(i) {
            show(i);
            box.hidden = false;
            void box.offsetWidth;              // force reflow so the transition runs
            box.classList.add('is-open');
            document.body.classList.add(openClass);
        }

        function close() {
            box.classList.remove('is-open');
            document.body.classList.remove(openClass);
            window.setTimeout(function () {
                box.hidden = true;
                img.setAttribute('src', '');
                current = -1;
            }, 200);
        }

        function next() { if (current < items.length - 1) { show(current + 1); } }
        function prev() { if (current > 0) { show(current - 1); } }

        // --- Tile click / keyboard activation ---
        tiles.forEach(function (t, i) {
            t.addEventListener('click', function (e) {
                e.preventDefault();
                open(i);
            });
            t.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    open(i);
                }
            });
        });

        // --- Buttons ---
        if (closeBtn) { closeBtn.addEventListener('click', close); }
        if (nextBtn)  { nextBtn.addEventListener('click', function (e) { e.stopPropagation(); next(); }); }
        if (prevBtn)  { prevBtn.addEventListener('click', function (e) { e.stopPropagation(); prev(); }); }

        // --- Click background to close (not the image or the arrows) ---
        box.addEventListener('click', function (e) {
            if (e.target === box || e.target === img) { close(); }
        });

        // --- Keyboard: ESC + Left / Right arrows ---
        document.addEventListener('keydown', function (e) {
            if (box.hidden) { return; }
            if (e.key === 'Escape') { close(); }
            else if (e.key === 'ArrowRight') { next(); }
            else if (e.key === 'ArrowLeft')  { prev(); }
        });

        // --- Touch swipe (tablet / mobile) ---
        var touchStartX = 0, touchStartY = 0, touchActive = false;
        box.addEventListener('touchstart', function (e) {
            if (box.hidden || e.touches.length !== 1) { return; }
            touchStartX = e.touches[0].clientX;
            touchStartY = e.touches[0].clientY;
            touchActive = true;
        }, { passive: true });

        box.addEventListener('touchend', function (e) {
            if (!touchActive) { return; }
            touchActive = false;
            var t = (e.changedTouches && e.changedTouches[0]) || null;
            if (!t) { return; }
            var dx = t.clientX - touchStartX;
            var dy = t.clientY - touchStartY;
            // Horizontal swipe only: enough travel, mostly horizontal.
            if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy)) {
                if (dx < 0) { next(); }   // swipe left → next
                else { prev(); }          // swipe right → prev
            }
        }, { passive: true });
    });
}());
// ===== SNAPSMACK EOF =====
