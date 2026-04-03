/**
 * SNAPSMACK - Wall Engine
 * Alpha v0.7.7
 *
 * Physics-driven horizontal gallery wall. CSS Grid handles multi-row layout;
 * this engine drives panning, depth zoom, focus tracking, and infinite scroll.
 *
 * Key design decisions:
 *  - Y axis is locked; the grid fills 100vh so vertical panning is meaningless.
 *  - getBoundingClientRect() is only called when movement has nearly stopped,
 *    not every animation frame.
 *  - Wheel zoom uses its own multiplier, independent of touch pinch power.
 *  - Everything is wrapped in an IIFE to avoid polluting the global scope.
 */

(function () {
    'use strict';

    const canvas    = document.getElementById('wall-canvas');
    const zoomLayer = document.getElementById('zoom-layer');
    if (!canvas || !zoomLayer) return;

    // ----------------------------------------------------------------
    // CONFIG  (data attributes on #wall-canvas, with sensible defaults)
    // ----------------------------------------------------------------
    const cfg = {
        friction:     parseFloat(canvas.dataset.friction)     || 0.94,
        dragWeight:   parseFloat(canvas.dataset.dragWeight)   || 1.2,
        pinchPower:   parseFloat(canvas.dataset.pinchPower)   || 30,
        totalImages:  parseInt(canvas.dataset.totalImages, 10) || 0,
        initialLimit: parseInt(canvas.dataset.initialLimit, 10) || 50,
    };

    // ----------------------------------------------------------------
    // PHYSICS STATE  (X pan + Z depth only — Y is always 0)
    // ----------------------------------------------------------------
    let aimX = 0, posX = 0, velX = 0;
    let aimZ = -500, posZ = -500, velZ = 0;
    let tilt = 0;

    // ----------------------------------------------------------------
    // INTERACTION STATE
    // ----------------------------------------------------------------
    let dragging = false, dragX = 0;
    let pinching = false, pinchD0 = 0, pinchZ0 = 0;

    // ----------------------------------------------------------------
    // ZOOM VIEWER STATE
    // ----------------------------------------------------------------
    let zoomedTile = null, zoomClone = null, zoomScale = 1, zoomRect = null;
    let swipeY0 = 0, swipeLive = false;

    // ----------------------------------------------------------------
    // FOCUS TRACKING  (throttled — not every frame)
    // ----------------------------------------------------------------
    let focusTile  = null;
    let focusTimer = null;

    function updateFocus() {
        const cx = window.innerWidth  / 2;
        const cy = window.innerHeight / 2;
        let best = null, bestD = Infinity;

        canvas.querySelectorAll('.wall-tile').forEach(tile => {
            const r = tile.getBoundingClientRect();
            const d = Math.hypot(cx - (r.left + r.width  / 2),
                                 cy - (r.top  + r.height / 2));
            if (d < bestD) { bestD = d; best = tile; }
        });

        if (best !== focusTile) {
            if (focusTile) focusTile.classList.remove('is-centered');
            if (best)      best.classList.add('is-centered');
            focusTile = best;
        }
    }

    function scheduleFocus() {
        clearTimeout(focusTimer);
        focusTimer = setTimeout(updateFocus, 180);
    }

    // ----------------------------------------------------------------
    // CENTERING  — snap to mid-tile once layout has settled
    // ----------------------------------------------------------------
    function snapToMid() {
        const tiles = canvas.querySelectorAll('.wall-tile');
        if (!tiles.length) return;
        const mid  = tiles[Math.floor(tiles.length / 2)];
        const cr   = canvas.getBoundingClientRect();
        const mr   = mid.getBoundingClientRect();
        aimX = posX = window.innerWidth  / 2 - (mr.left - cr.left + mr.width  / 2);
        updateFocus();
    }

    // Two rAFs: first lets the grid paint, second lets images report their widths.
    requestAnimationFrame(() => requestAnimationFrame(() => setTimeout(snapToMid, 80)));

    // ----------------------------------------------------------------
    // ANIMATION LOOP  (lerp toward aim; apply friction to velocities)
    // ----------------------------------------------------------------
    function lerp(a, b, t) { return a + (b - a) * t; }

    (function tick() {
        if (!dragging && !pinching) {
            aimX += velX;   velX *= cfg.friction;   if (Math.abs(velX) < 0.04) velX = 0;
            aimZ += velZ;   velZ *= cfg.friction;   if (Math.abs(velZ) < 0.04) velZ = 0;
            aimZ  = Math.max(-7000, Math.min(700, aimZ));

            // Schedule a focus update whenever we're nearly stopped
            if (velX !== 0 || velZ !== 0) scheduleFocus();
        }

        posX  = lerp(posX, aimX, 0.10);
        posZ  = lerp(posZ, aimZ, 0.10);
        tilt  = lerp(tilt, velX * 0.55, 0.07);
        const lean = Math.max(-14, Math.min(14, tilt));

        canvas.style.transform =
            `translate3d(${posX}px, 0px, ${posZ}px) rotateY(${-lean}deg)`;

        requestAnimationFrame(tick);
    }());

    // ----------------------------------------------------------------
    // MOUSE PAN
    // ----------------------------------------------------------------
    window.addEventListener('mousedown', e => {
        if (zoomedTile) return;
        dragging = true;
        dragX    = e.pageX;
        velX     = 0;
        document.body.style.cursor = 'grabbing';
    });

    window.addEventListener('mousemove', e => {
        if (!dragging) return;
        const dx = (e.pageX - dragX) * cfg.dragWeight;
        aimX += dx;
        velX  = dx;
        dragX = e.pageX;
    });

    window.addEventListener('mouseup', () => {
        dragging = false;
        document.body.style.cursor = '';
    });

    // ----------------------------------------------------------------
    // WHEEL ZOOM  (independent of pinchPower; clamped per event)
    // ----------------------------------------------------------------
    window.addEventListener('wheel', e => {
        if (zoomedTile) return;
        e.preventDefault();
        const kick = Math.sign(e.deltaY) * Math.min(Math.abs(e.deltaY), 100) * 0.32;
        velZ -= kick;
    }, { passive: false });

    // ----------------------------------------------------------------
    // TOUCH — one finger pan, two finger pinch zoom
    // ----------------------------------------------------------------
    function touchDist(t) {
        return Math.hypot(t[0].pageX - t[1].pageX, t[0].pageY - t[1].pageY);
    }

    window.addEventListener('touchstart', e => {
        if (zoomedTile) return;
        if (e.touches.length === 2) {
            pinching = true;
            dragging = false;
            pinchD0  = touchDist(e.touches);
            pinchZ0  = aimZ;
            velZ     = 0;
        } else {
            dragging = true;
            dragX    = e.touches[0].pageX;
            velX     = 0;
        }
    }, { passive: true });

    window.addEventListener('touchmove', e => {
        if (pinching && e.touches.length === 2) {
            const d = touchDist(e.touches) - pinchD0;
            aimZ = Math.max(-7000, Math.min(700, pinchZ0 + d * cfg.pinchPower));
            e.preventDefault();
        } else if (dragging) {
            const dx = (e.touches[0].pageX - dragX) * cfg.dragWeight;
            aimX += dx;
            velX  = dx;
            dragX = e.touches[0].pageX;
            if (Math.abs(dx) > 4) e.preventDefault();
        }
    }, { passive: false });

    window.addEventListener('touchend', () => { dragging = false; pinching = false; });

    // ----------------------------------------------------------------
    // ZOOM VIEWER
    // ----------------------------------------------------------------
    canvas.addEventListener('click', e => {
        const tile = e.target.closest('.wall-tile');
        if (tile) openZoom(tile);
    });

    zoomLayer.addEventListener('click', closeZoom);

    function openZoom(tile) {
        if (zoomedTile) closeZoom(true);
        zoomedTile = tile;

        const img  = tile.querySelector('img');
        zoomRect   = img.getBoundingClientRect();

        zoomClone  = document.createElement('img');
        zoomClone.className = 'zoom-clone';
        zoomClone.src       = img.src;
        zoomClone.style.cssText =
            `width:${zoomRect.width}px; height:${zoomRect.height}px;` +
            `left:${zoomRect.left}px;  top:${zoomRect.top}px;`;

        // Title in zoom layer
        const existingTitle = zoomLayer.querySelector('.zoom-title');
        if (existingTitle) existingTitle.remove();
        if (tile.dataset.title) {
            const t = document.createElement('div');
            t.className   = 'zoom-title';
            t.textContent = tile.dataset.title;
            zoomLayer.appendChild(t);
        }

        document.body.appendChild(zoomClone);
        zoomLayer.classList.add('active');

        requestAnimationFrame(() => {
            const pad  = 0.91;
            zoomScale  = Math.min(
                (window.innerWidth  * pad) / zoomRect.width,
                (window.innerHeight * pad) / zoomRect.height
            );
            const tx = window.innerWidth  / 2 - zoomRect.left - zoomRect.width  / 2;
            const ty = window.innerHeight / 2 - zoomRect.top  - zoomRect.height / 2;
            zoomClone.style.transform = `translate(${tx}px, ${ty}px) scale(${zoomScale})`;

            const hi  = new Image();
            hi.src    = tile.dataset.full;
            hi.onload = () => { if (zoomClone) zoomClone.src = hi.src; };
        });

        zoomClone.addEventListener('click',      closeZoom);
        zoomClone.addEventListener('touchstart', onSwipeStart, { passive: true });
        zoomClone.addEventListener('touchmove',  onSwipeMove,  { passive: true });
        zoomClone.addEventListener('touchend',   onSwipeEnd,   { passive: true });
    }

    function closeZoom(instant) {
        if (!zoomedTile) return;
        zoomLayer.classList.remove('active');
        zoomLayer.style.opacity = '';
        if (zoomClone) {
            if (!instant) {
                zoomClone.style.transform = 'translate(0,0) scale(1)';
                zoomClone.style.opacity   = '0';
            }
            const c = zoomClone; zoomClone = null;
            setTimeout(() => c.parentNode && c.parentNode.removeChild(c),
                       instant ? 0 : 380);
        }
        zoomedTile = null;
    }

    function galleryNav(dir) {
        if (!zoomedTile) return;
        // Walk siblings, skipping non-tile nodes (e.g. sentinel)
        let next = dir > 0 ? zoomedTile.nextElementSibling
                            : zoomedTile.previousElementSibling;
        while (next && !next.classList.contains('wall-tile')) {
            next = dir > 0 ? next.nextElementSibling : next.previousElementSibling;
        }
        if (!next) return;
        closeZoom(true);
        openZoom(next);
    }

    // Swipe-down-to-dismiss in zoom viewer
    function onSwipeStart(e) { swipeY0 = e.touches[0].pageY; swipeLive = true; }
    function onSwipeMove(e) {
        if (!swipeLive || !zoomClone || !zoomRect) return;
        const dy = e.touches[0].pageY - swipeY0;
        if (dy < 0) return;
        const tx   = window.innerWidth  / 2 - zoomRect.left - zoomRect.width  / 2;
        const ty   = window.innerHeight / 2 - zoomRect.top  - zoomRect.height / 2;
        const sc   = Math.max(0.55, zoomScale * (1 - dy / 900));
        zoomClone.style.transform  = `translate(${tx}px, ${ty + dy}px) scale(${sc})`;
        zoomLayer.style.opacity    = String(Math.max(0, 1 - dy / 450));
    }
    function onSwipeEnd(e) {
        swipeLive = false;
        if (!zoomClone || !zoomRect) return;
        if (e.changedTouches[0].pageY - swipeY0 > 110) { closeZoom(); return; }
        const tx = window.innerWidth  / 2 - zoomRect.left - zoomRect.width  / 2;
        const ty = window.innerHeight / 2 - zoomRect.top  - zoomRect.height / 2;
        zoomClone.style.transform = `translate(${tx}px, ${ty}px) scale(${zoomScale})`;
        zoomLayer.style.opacity   = '1';
    }

    // ----------------------------------------------------------------
    // KEYBOARD
    // ----------------------------------------------------------------
    const helpModal = buildHelpModal();

    window.addEventListener('keydown', e => {
        const modalOpen = helpModal.style.display === 'block';

        if (e.key === 'F1') {
            e.preventDefault();
            helpModal.style.display = modalOpen ? 'none' : 'block';
            return;
        }

        if (e.key === 'Escape') {
            if (modalOpen)  { helpModal.style.display = 'none'; return; }
            if (zoomedTile) { closeZoom(); return; }
            history.back();
            return;
        }

        if (e.key === '1') {
            document.body.classList.toggle('wall-hide-titles');
            return;
        }

        if (zoomedTile) {
            if (e.key === 'ArrowLeft'  || e.key === 'ArrowUp')   galleryNav(-1);
            if (e.key === 'ArrowRight' || e.key === 'ArrowDown')  galleryNav(1);
            if (e.key === ' ' || e.key === 'Enter') closeZoom();
            return;
        }

        const pan = 28, zoom = 48;
        if (e.key === 'ArrowLeft')  velX += pan;
        if (e.key === 'ArrowRight') velX -= pan;
        if (e.key === 'PageUp')   { e.preventDefault(); velZ += zoom; }
        if (e.key === 'PageDown') { e.preventDefault(); velZ -= zoom; }
        if (e.key === 'Home') snapToEdge('first');
        if (e.key === 'End')  snapToEdge('last');
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            if (focusTile) openZoom(focusTile);
        }
    });

    function snapToEdge(pos) {
        const tiles = canvas.querySelectorAll('.wall-tile');
        if (!tiles.length) return;
        const tile = pos === 'first' ? tiles[0] : tiles[tiles.length - 1];
        const cr   = canvas.getBoundingClientRect();
        const tr   = tile.getBoundingClientRect();
        aimX = posX = window.innerWidth / 2 - (tr.left - cr.left + tr.width / 2);
        velX = 0;
    }

    // ----------------------------------------------------------------
    // INFINITE SCROLL
    // ----------------------------------------------------------------
    let loadOffset = cfg.initialLimit;
    let loading    = false;
    let hasMore    = loadOffset < cfg.totalImages;
    const sentinel = document.getElementById('wall-sentinel');

    if (sentinel && hasMore) {
        const io = new IntersectionObserver(entries => {
            if (entries[0].isIntersecting && !loading && hasMore) fetchMore();
        }, { rootMargin: '500px' });
        io.observe(sentinel);
    }

    async function fetchMore() {
        loading = true;
        try {
            const r    = await fetch(`load-more.php?offset=${loadOffset}`);
            const html = await r.text();
            if (html.trim()) {
                sentinel.insertAdjacentHTML('beforebegin', html);
                loadOffset += 20;
                hasMore = loadOffset < cfg.totalImages;
                if (!hasMore) sentinel.remove();
            } else {
                hasMore = false;
            }
        } catch (err) {
            console.warn('Wall: load-more failed', err);
        } finally {
            loading = false;
        }
    }

    // ----------------------------------------------------------------
    // HELP MODAL
    // ----------------------------------------------------------------
    function buildHelpModal() {
        const hint = document.createElement('div');
        hint.textContent = 'F1 FOR HELP';
        Object.assign(hint.style, {
            position: 'fixed', bottom: '18px', left: '18px',
            color: 'var(--wall-text)', background: 'var(--wall-bg)',
            padding: '7px 14px', border: '1px solid var(--wall-text)',
            fontFamily: 'monospace', fontSize: '11px', letterSpacing: '1px',
            zIndex: '9999999', pointerEvents: 'none',
            opacity: '0', transition: 'opacity 1.2s',
        });
        document.body.appendChild(hint);
        setTimeout(() => hint.style.opacity = '1', 700);
        setTimeout(() => hint.style.opacity = '0', 5500);

        const modal = document.createElement('div');
        modal.style.display = 'none';
        Object.assign(modal.style, {
            position: 'fixed', top: '50%', left: '50%',
            transform: 'translate(-50%, -50%)',
            background: 'var(--wall-bg)', border: '1px solid var(--wall-text)',
            padding: '32px 44px', color: 'var(--wall-text)',
            fontFamily: 'monospace', fontSize: '12px',
            zIndex: '10000000', boxShadow: '0 0 60px rgba(0,0,0,0.9)',
            minWidth: '260px', lineHeight: '2.2',
        });
        modal.innerHTML = `
            <div style="text-transform:uppercase;letter-spacing:2px;font-size:10px;
                        opacity:.5;margin-bottom:14px;border-bottom:1px solid currentColor;
                        padding-bottom:10px;">Controls</div>
            <table style="border-collapse:collapse;width:100%">
                <tr><td style="opacity:.55;padding-right:20px">Drag / ← →</td><td>Pan wall</td></tr>
                <tr><td style="opacity:.55">Scroll / PgUp·Dn</td><td>Zoom depth</td></tr>
                <tr><td style="opacity:.55">Click / Enter</td><td>Open image</td></tr>
                <tr><td style="opacity:.55">← → (in viewer)</td><td>Navigate</td></tr>
                <tr><td style="opacity:.55">Home / End</td><td>Jump to start / end</td></tr>
                <tr><td style="opacity:.55">1</td><td>Toggle titles</td></tr>
                <tr><td style="opacity:.55">Esc / F1</td><td>Close / Help</td></tr>
            </table>`;
        document.body.appendChild(modal);
        return modal;
    }

}());
