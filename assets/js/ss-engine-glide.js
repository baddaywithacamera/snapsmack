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
    function glideFill(track, period, minExtent, gap, downPage) {
        if (!track || !track.children || !track.children.length) return 0;
        if (!(period > 0)) return 0;
        var originals = Array.prototype.slice.call(track.children);
        var copies = 0;
        while ((downPage ? track.scrollHeight : track.scrollWidth) < period + minExtent && copies < 8) {
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

    // How far the pointer may travel before a press counts as a drag
    // rather than a click. 10px on Sean's instruction: 3px discarded
    // intended clicks from an unsteady hand.
    var GLIDE_DRAG_SLOP = 10;

    // How a released drag comes to rest. Friction is per frame, so at
    // ~60fps 0.94 is about a second of travel; the floor is where the
    // remaining motion is below one pixel a frame and not worth painting.
    var GLIDE_FRICTION = 0.94;
    var GLIDE_VEL_FLOOR = 0.4;

    var wall = document.querySelector('[data-glide-wall]');
    if (!wall) return;
    var rows = Array.prototype.slice.call(wall.querySelectorAll('[data-glide-row]'));
    var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var sensitivity = parseFloat(wall.dataset.travel || '0.9');
    var raf = 0;
    var baseAngles = { horizontal: 0, vertical: 90, diagonal_down: 45, diagonal_up: -45 };
    var axis = wall.dataset.flowAxis || 'horizontal';
    var fine = parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--glide-angle')) || 0;
    // TWO VECTORS, AND CONFLATING THEM WAS THE BUG.
    //
    // The pointer moves in SCREEN space. The track translates in its OWN
    // space, inside a field the skin has already rotated by the lean. Those
    // are different frames, so they need different vectors:
    //
    //   WORLD, base + lean -- for reading the pointer. On screen the wall
    //   travels along the LEANED direction, because the field is tilted, so a
    //   drag is projected onto that direction.
    //
    //   LOCAL, base ONLY -- for writing the translate. The field's own
    //   rotate() already applies the lean to whatever the track does. Putting
    //   the lean in the translate as well applies it TWICE: the track creeps
    //   across its row as it travels, which is the sideways drift Codex
    //   measured. Excluding it leaves local X at zero for a vertical wall and
    //   lets the field supply the whole visible tilt.
    //
    // I GOT THIS WRONG IN PUBLIC AND IT IS WORTH WRITING DOWN. When Codex
    // reported the translation was "mostly Y" I read the small X term as proof
    // the lean was composing correctly. It was the defect. A leaned vertical
    // wall must paint PURE Y locally; anything else is the double-counting.
    var worldRadians = ((baseAngles[axis] || 0) + fine) * Math.PI / 180;
    var worldX = Math.cos(worldRadians);
    var worldY = Math.sin(worldRadians);
    var localRadians = (baseAngles[axis] || 0) * Math.PI / 180;
    var localX = Math.cos(localRadians);
    var localY = Math.sin(localRadians);
    // MOVING ALONG THE AXIS IS OPT-IN, and it has to be.
    //
    // Until now the axis vector only PROJECTED THE POINTER: it decided which
    // drag direction drives the wall. The wall itself always moved on X, and
    // the apparent direction came from the SKIN rotating the whole field --
    // skins/glide/style.css:29 rotates .glide-field by --glide-axis-angle plus
    // the -7deg lean, and skins/sliders/style.css:1373 does the same to
    // .sl-glide-field with its axis angle DEFAULTING TO -45deg. That is a
    // coherent design and its cost is the one Codex reported: a vertical axis
    // turns the photographs sideways with the field.
    //
    // So a skin can ask for the motion itself to run along the axis, which
    // lets it drop the axis term from its rotate() and keep only the lean,
    // leaving photographs upright. It is OPT-IN because switching every
    // consumer at once would double-transform any site whose owner has chosen
    // a non-horizontal axis -- the field would still be rotated AND the motion
    // would now follow the axis. Both GLIDE and SLIDERS expose the axis as an
    // owner setting, so those sites exist and I cannot see them from here.
    //
    // With the flag absent, every expression below reduces to exactly what it
    // computed before. That is asserted, not assumed.
    var alongAxis = wall.dataset.flowMotion === 'axis';
    // DERIVED FROM THE LOCAL AXIS, not the leaned one. Which edge of a tile is
    // the direction of travel is a fact about the track's own box, and the
    // lean is not in that box's frame. It also removes a wart: with the lean
    // in play a 45deg diagonal was resolved by whichever way -7deg tipped it.
    // Now a diagonal is an exact tie and the tie goes to horizontal, which is
    // arbitrary but FIXED, and a fixed arbitrary answer is a decision.
    var vertical = alongAxis && Math.abs(localY) > Math.abs(localX);
    /** The flex gap actually in force on this track, in pixels. */
    function trackGap(track) {
        if (!window.getComputedStyle) return 0;
        var cs = window.getComputedStyle(track);
        if (!cs) return 0;
        // A column track spaces its children with row-gap, not column-gap.
        var g = parseFloat(vertical ? cs.rowGap : cs.columnGap);
        if (!isFinite(g)) g = parseFloat(cs.gap);
        return isFinite(g) ? g : 0;
    }

    /**
     * The sizes of the ORIGINAL tiles along the direction of travel, ignoring
     * any copies behind them. Width for a horizontal wall, height for a
     * vertical one -- the period is a distance in the direction the wall
     * actually moves, and measuring the wrong edge wraps at the wrong place.
     */
    function originalExtents(state) {
        var out = [], kids = state.track.children;
        for (var i = 0; i < state.originals && i < kids.length; i++) {
            out.push(vertical ? kids[i].offsetHeight : kids[i].offsetWidth);
        }
        return out;
    }

    /** Measure the period and make sure the track is long enough to hide the seam. */
    function measure(state) {
        var gap = trackGap(state.track);
        state.period = glidePeriodFrom(originalExtents(state), gap);
        glideFill(state.track, state.period,
                  (vertical ? window.innerHeight : window.innerWidth) || 0, gap, vertical);
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
            dragging: false, moved: false, captured: false,
            // vel is the recent speed of the drag, carried past release;
            // flinging says the wall is still coasting on it.
            vel: 0, flinging: false,
            x: 0, y: 0, startX: 0, startY: 0
        };
        measure(state);
        return state;
    });

    function paint() {
        var moving = false;
        for (var i = 0; i < states.length; i++) {
            var state = states[i];
            if (state.flinging) {
                state.target += state.vel;
                state.vel *= GLIDE_FRICTION;
                if (Math.abs(state.vel) < GLIDE_VEL_FLOOR) {
                    state.flinging = false;
                    state.vel = 0;
                }
            }
            state.current += (state.target - state.current) * (reduced ? 1 : 0.18);
            var x = glideWrap(state.current, state.period);
            // THE LOCAL VECTOR, because this is the track's own frame and the
            // field's rotate() adds the lean on top. For a horizontal axis
            // localX is 1 and localY is 0, so the opted-in form is the same
            // translation as the original. The branch is kept anyway so a skin
            // that has not asked for this gets the original expression, not a
            // computed one that happens to agree.
            state.track.style.transform = alongAxis
                ? 'translate3d(' + (x * localX) + 'px,' + (x * localY) + 'px,0)'
                : 'translate3d(' + x + 'px,0,0)';
            // Without the flinging term the loop could stop while the wall
            // still had speed left, because target and current can be close
            // together at the moment of release.
            if (state.flinging
                || Math.abs(state.target - state.current) > 0.1) moving = true;
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
            // PUTTING A FINGER ON A COASTING WALL STOPS IT, which is what
            // every touch surface does and what makes a wall feel like an
            // object rather than a video.
            //
            // Clearing the fling is not enough on its own: the fling has
            // already pushed `target` far ahead, and `current` eases toward it,
            // so the wall went on gliding 233px out from under the finger.
            // Measured, not guessed. Grabbing it means taking it WHERE IT IS.
            state.flinging = false;
            state.vel = 0;
            state.target = state.current;
            state.x = event.clientX;
            state.y = event.clientY;
            // Where the press STARTED, kept separate from the running x/y that
            // the per-move delta uses. Travel is measured from here.
            state.startX = event.clientX;
            state.startY = event.clientY;
            state.captured = false;
            // THE is-dragging CLASS IS NOT ADDED HERE EITHER. Codex found the
            // fourth independent cause of Sean's dead clicks in GLIDE's CSS:
            //     .glide-row.is-dragging .glide-tile { pointer-events: none }
            // Because this engine announced "dragging" on pointerdown, that
            // rule disabled the tile before any click could land, so the click
            // hit the row instead of the photograph -- defeating even the
            // deferred pointer capture above.
            // He has removed the rule. But the engine should not have been
            // making the claim: it is not dragging until it is. The class now
            // appears at the same moment as the capture, which makes a rule
            // like that harmless if anyone writes one again, because by then a
            // drag really is under way and no click is owed.
            // CAPTURE IS NOT TAKEN HERE. Capturing on pointerdown retargets the
            // events that follow to the ROW, so a plain click never reached the
            // photograph inside it and the lightbox was never offered the
            // event. Capture is taken in pointermove, once travel says this is
            // actually a drag -- so a real click keeps its own target.
        });
        state.row.addEventListener('pointermove', function (event) {
            if (!state.dragging) return;
            var dx = event.clientX - state.x;
            var dy = event.clientY - state.y;
            // THE WORLD VECTOR: dx/dy are screen pixels, and on screen the
            // wall travels along the leaned direction.
            var projected = (dx * worldX) + (dy * worldY);
            // TOTAL TRAVEL SINCE THE PRESS, not the size of one pointermove.
            // dx/dy above are per-move deltas because x/y are rewritten each
            // move, so the old test "dx + dy > 3" asked whether the pointer
            // JUMPED 3px between two events -- a velocity test wearing a
            // displacement test's clothes. A slow, deliberate drag never
            // tripped it, and a single twitch always did. Sean has a tremor, so
            // he got the worst of both: wobble during an intended click threw
            // the click away, and a careful drag still counted as a click.
            // Raising the old number alone would have made it worse.
            if (Math.abs(event.clientX - state.startX)
                + Math.abs(event.clientY - state.startY) > GLIDE_DRAG_SLOP) {
                state.moved = true;
                // Only now is it honest to say so. See the note in pointerdown.
                state.row.classList.add('is-dragging');
                if (!state.captured) {
                    // Now it IS a drag, so take the pointer. In a try: capture
                    // throws if the pointer is no longer active, and losing the
                    // capture must not abort the drag.
                    try {
                        state.row.setPointerCapture(event.pointerId);
                        state.captured = true;
                    } catch (e) { state.captured = false; }
                }
            }
            state.target += projected * sensitivity;
            // A smoothed recent speed, not the last sample: one twitchy
            // pointermove at the end of a slow drag should not fling the
            // wall across the screen.
            state.vel = (state.vel * 0.6) + (projected * sensitivity * 0.4);
            state.x = event.clientX;
            state.y = event.clientY;
            requestPaint();
        });
        function release(event, cancelled) {
            if (!state.dragging) return;
            state.dragging = false;
            state.row.classList.remove('is-dragging');
            if (state.captured && state.row.hasPointerCapture(event.pointerId)) {
                state.row.releasePointerCapture(event.pointerId);
            }
            state.captured = false;
            // A CANCEL IS FOLLOWED BY NO CLICK, so the moved flag had nothing
            // to consume it and stayed true -- swallowing the NEXT genuine
            // click on that row. pointerup is left alone, because its click
            // has not fired yet and still needs the flag.
            if (cancelled) {
                state.moved = false;
                state.vel = 0;
                state.flinging = false;
                return;
            }
            // Let go of a moving wall and it keeps going. Only after a real
            // drag, never on a click, and never under prefers-reduced-motion
            // -- somebody who asked for less movement should not be handed
            // a second of it after every touch.
            if (state.moved && !reduced
                && Math.abs(state.vel) > GLIDE_VEL_FLOOR) {
                state.flinging = true;
                requestPaint();
            }
        }
        state.row.addEventListener('pointerup', function (event) { release(event, false); });
        state.row.addEventListener('pointercancel', function (event) { release(event, true); });
        state.row.addEventListener('click', function (event) {
            if (state.moved) { event.preventDefault(); event.stopPropagation(); state.moved = false; }
        }, true);
    });

    window.addEventListener('resize', function () {
        fine = parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--glide-angle')) || 0;
        worldRadians = ((baseAngles[axis] || 0) + fine) * Math.PI / 180;
        worldX = Math.cos(worldRadians);
        worldY = Math.sin(worldRadians);
        // The local vector carries no lean, so a changed --glide-angle cannot
        // move it. Recomputed anyway: the axis attribute is read once, but if
        // that ever changes the two must not drift apart.
        localRadians = (baseAngles[axis] || 0) * Math.PI / 180;
        localX = Math.cos(localRadians);
        localY = Math.sin(localRadians);
        // vertical is DERIVED from the vector, so it has to be recomputed with
        // it. --glide-angle can move the fine angle across the diagonal at a
        // new viewport size, and a stale flag would measure the wrong edge.
        vertical = alongAxis && Math.abs(localY) > Math.abs(localX);
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
