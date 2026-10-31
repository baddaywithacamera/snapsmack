// SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
/**
 * GLIDE Engine. A kinetic photo wall whose rows drag and slide along a chosen
 * axis (horizontal, vertical or diagonal), with pointer-drag plus momentum, and
 * it honours prefers-reduced-motion. Reach for it when a skin wants a playful,
 * motion-led gallery instead of a static grid. Declared in a skin manifest via
 * require_scripts (data hooks: [data-glide-wall], [data-glide-row]).
 */
(function () {
    'use strict';

    // ---- the arithmetic, kept pure and exposed so it can be MEASURED -------
    //
    // SS-1093. This used to wrap at scrollWidth / 4, which was right only
    // while the markup repeated each row four times -- the way the wall was
    // first built. The markup stopped doing that, the divisor stayed, and the
    // modulus became a quarter of a single-copy track: a row travelled through
    // a quarter of its own photographs and snapped back. Roughly half of each
    // long row could never be brought on screen, and rows with more portraits
    // wrapped sooner than the others, so no two rows agreed.
    //
    // The period is now MEASURED from what is actually in the track, and the
    // engine makes its own copies, so the two can never disagree again.

    /** Translate a running offset into one period. Never divides by zero. */
    function glideWrap(current, period) {
        if (!(period > 0)) return 0;
        return (((current % period) + period) % period) - period;
    }

    /**
     * The distance after which the pattern repeats.
     *
     * NOT the measured width of one copy. A flex gap sits BETWEEN items, so
     * one copy of n tiles measures sum + (n-1) gaps -- but the copy that
     * follows it starts one further gap along, so the period is sum + n gaps.
     * Getting this wrong by one gap is a slow drift at every wrap, and it is
     * the second thing the old divisor got wrong.
     */
    function glidePeriodFrom(widths, gap) {
        if (!widths || !widths.length) return 0;
        var sum = 0;
        for (var i = 0; i < widths.length; i++) sum += (parseFloat(widths[i]) || 0);
        return sum + (widths.length * (parseFloat(gap) || 0));
    }

    /**
     * Repeat the track's own children until the wrap point is off screen.
     *
     * The engine does this itself rather than asking the skin to emit copies:
     * a strict skin's layout is presentation only, and a count agreed between
     * two files is a count that drifts. Returns how many copies it added.
     */
    function glideFill(track, period, minWidth, gap) {
        if (!track || !track.children || !track.children.length) return 0;
        if (!(period > 0)) return 0;
        var originals = Array.prototype.slice.call(track.children);
        var copies = 0;
        while (track.scrollWidth < period + minWidth && copies < 8) {
            for (var i = 0; i < originals.length; i++) {
                var clone = originals[i].cloneNode(true);
                // A copy is scenery. The 3 August design note left open
                // whether duplicated links should be hidden from assistive
                // technology; they must be, or a screen reader reads the same
                // wall several times and every copy is a tab stop.
                clone.setAttribute('aria-hidden', 'true');
                clone.tabIndex = -1;
                track.appendChild(clone);
            }
            copies++;
        }
        return copies;
    }

    window.SSGlide = { wrap: glideWrap, periodFrom: glidePeriodFrom, fill: glideFill };

    var wall = document.querySelector('[data-glide-wall]');
    if (!wall) return;
    var rows = Array.prototype.slice.call(wall.querySelectorAll('[data-glide-row]'));
    var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var sensitivity = parseFloat(wall.dataset.travel || '0.9');
    var raf = 0;
    var baseAngles = { horizontal: 0, vertical: 90, diagonal_down: 45, diagonal_up: -45 };
    var axis = wall.dataset.flowAxis || 'horizontal';
    var fine = parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--glide-angle')) || 0;
    var radians = ((baseAngles[axis] || 0) + fine) * Math.PI / 180;
    var axisX = Math.cos(radians);
    var axisY = Math.sin(radians);
    /** The flex gap actually in force on this track, in pixels. */
    function trackGap(track) {
        if (!window.getComputedStyle) return 0;
        var cs = window.getComputedStyle(track);
        if (!cs) return 0;
        var g = parseFloat(cs.columnGap);
        if (!isFinite(g)) g = parseFloat(cs.gap);
        return isFinite(g) ? g : 0;
    }

    /** The widths of the ORIGINAL tiles, ignoring any copies behind them. */
    function originalWidths(state) {
        var out = [], kids = state.track.children;
        for (var i = 0; i < state.originals && i < kids.length; i++) out.push(kids[i].offsetWidth);
        return out;
    }

    /** Measure the period and make sure the track is long enough to hide the seam. */
    function measure(state) {
        var gap = trackGap(state.track);
        state.period = glidePeriodFrom(originalWidths(state), gap);
        glideFill(state.track, state.period, window.innerWidth || 0, gap);
    }

    var states = rows.map(function (row, index) {
        var track = row.firstElementChild;
        var state = {
            row: row, track: track,
            // How many tiles the SKIN emitted. Everything past this is a copy
            // this engine made, and the period is measured from these only.
            originals: track && track.children ? track.children.length : 0,
            period: 0,
            current: index * -90, target: index * -90,
            dragging: false, moved: false, x: 0, y: 0
        };
        measure(state);
        return state;
    });

    function paint() {
        var moving = false;
        for (var i = 0; i < states.length; i++) {
            var state = states[i];
            state.current += (state.target - state.current) * (reduced ? 1 : 0.18);
            var x = glideWrap(state.current, state.period);
            state.track.style.transform = 'translate3d(' + x + 'px,0,0)';
            if (Math.abs(state.target - state.current) > 0.1) moving = true;
        }
        raf = moving ? requestAnimationFrame(paint) : 0;
    }
    function requestPaint() { if (!raf) raf = requestAnimationFrame(paint); }

    states.forEach(function (state) {
        state.row.addEventListener('wheel', function (event) {
            event.preventDefault();
            var delta = Math.abs(event.deltaX) > Math.abs(event.deltaY) ? event.deltaX : event.deltaY;
            state.target -= delta * sensitivity;
            requestPaint();
        }, { passive: false });

        state.row.addEventListener('pointerdown', function (event) {
            if (event.button !== 0 && event.pointerType === 'mouse') return;
            state.dragging = true;
            state.moved = false;
            state.x = event.clientX;
            state.y = event.clientY;
            state.row.classList.add('is-dragging');
            state.row.setPointerCapture(event.pointerId);
        });
        state.row.addEventListener('pointermove', function (event) {
            if (!state.dragging) return;
            var dx = event.clientX - state.x;
            var dy = event.clientY - state.y;
            var projected = (dx * axisX) + (dy * axisY);
            if (Math.abs(dx) + Math.abs(dy) > 3) state.moved = true;
            state.target += projected * sensitivity;
            state.x = event.clientX;
            state.y = event.clientY;
            requestPaint();
        });
        function release(event) {
            if (!state.dragging) return;
            state.dragging = false;
            state.row.classList.remove('is-dragging');
            if (state.row.hasPointerCapture(event.pointerId)) state.row.releasePointerCapture(event.pointerId);
        }
        state.row.addEventListener('pointerup', release);
        state.row.addEventListener('pointercancel', release);
        state.row.addEventListener('click', function (event) {
            if (state.moved) { event.preventDefault(); event.stopPropagation(); state.moved = false; }
        }, true);
    });

    window.addEventListener('resize', function () {
        fine = parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--glide-angle')) || 0;
        radians = ((baseAngles[axis] || 0) + fine) * Math.PI / 180;
        axisX = Math.cos(radians);
        axisY = Math.sin(radians);
        // The row height changes at the 720px breakpoint, so the tiles change
        // width and the period changes with them. Re-measure rather than keep
        // wrapping at a distance that was true at the old size.
        for (var i = 0; i < states.length; i++) measure(states[i]);
        requestPaint();
    });
    if ('ResizeObserver' in window) {
        new ResizeObserver(requestPaint).observe(wall);
    }
    requestPaint();
}());
// ===== SNAPSMACK EOF =====
