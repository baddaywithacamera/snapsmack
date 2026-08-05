// SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
(function () {
    'use strict';
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
    var states = rows.map(function (row, index) {
        return { row: row, track: row.firstElementChild, current: index * -90, target: index * -90, dragging: false, moved: false, x: 0, y: 0 };
    });

    function paint() {
        var moving = false;
        for (var i = 0; i < states.length; i++) {
            var state = states[i];
            state.current += (state.target - state.current) * (reduced ? 1 : 0.18);
            var cycle = state.track.scrollWidth / 4;
            var raw = state.current;
            var x = cycle > 0 ? (((raw % cycle) + cycle) % cycle) - cycle : 0;
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
        requestPaint();
    });
    if ('ResizeObserver' in window) {
        new ResizeObserver(requestPaint).observe(wall);
    }
    requestPaint();
}());
// ===== SNAPSMACK EOF =====
