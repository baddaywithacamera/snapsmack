// SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
(function () {
    'use strict';
    var wall = document.querySelector('[data-sideways-wall]');
    if (!wall) return;
    var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var rows = Array.prototype.slice.call(wall.querySelectorAll('[data-sideways-row]'));
    var restMin = Math.max(2, parseFloat(wall.dataset.restMin || '4')) * 1000;
    var restMax = Math.max(restMin, parseFloat(wall.dataset.restMax || '9') * 1000);
    var timer = 0;
    var active = null;
    var lastRow = -1;
    var states = rows.map(function (row, index) {
        var track = row.querySelector('.sideways-track');
        var offset = index * -137;
        track.style.transform = 'translate3d(' + offset + 'px,0,0)';
        return { row:row, track:track, offset:offset, lastMoved:Date.now() - ((rows.length - index) * 4000), direction:parseInt(row.dataset.direction || '1', 10) };
    });

    function resting(state) { return state.row.matches(':hover') || state.row.contains(document.activeElement); }
    function chooseRow() {
        var now = Date.now();
        var choices = states.filter(function (state, index) { return index !== lastRow && !resting(state); });
        if (!choices.length) choices = states.filter(function (state) { return !resting(state); });
        var total = choices.reduce(function (sum, state) { return sum + Math.max(1, now - state.lastMoved); }, 0);
        var pick = Math.random() * total;
        for (var i = 0; i < choices.length; i++) { pick -= Math.max(1, now - choices[i].lastMoved); if (pick <= 0) return choices[i]; }
        return choices[0] || null;
    }
    function normalize(state) {
        var cycle = state.track.scrollWidth / 5;
        if (!cycle) return;
        state.offset = -cycle + ((((state.offset + cycle) % cycle) + cycle) % cycle);
        state.track.style.transition = 'none';
        state.track.style.transform = 'translate3d(' + state.offset + 'px,0,0)';
        state.track.offsetWidth;
    }
    function schedule() {
        window.clearTimeout(timer);
        if (reduced || document.hidden) return;
        timer = window.setTimeout(moveOne, restMin + Math.random() * (restMax - restMin));
    }
    function moveOne() {
        if (active || document.hidden) return schedule();
        var state = chooseRow();
        if (!state) return schedule();
        normalize(state);
        var tile = state.track.querySelector('.sideways-tile');
        if (!tile) return schedule();
        var pitch = tile.getBoundingClientRect().width + parseFloat(getComputedStyle(state.track).gap || '0');
        var plates = 1 + Math.floor(Math.random() * 10);
        var imperfect = (Math.random() - .5) * pitch * .82;
        var distance = (plates * pitch + imperfect) * state.direction;
        var duration = Math.min(2400, 720 + plates * 145);
        state.offset -= distance;
        state.lastMoved = Date.now();
        lastRow = states.indexOf(state);
        active = state;
        state.track.style.transition = 'transform ' + duration + 'ms cubic-bezier(.42,0,.18,1)';
        state.track.style.transform = 'translate3d(' + state.offset + 'px,0,0)';
        window.setTimeout(function () { normalize(state); active = null; schedule(); }, duration + 40);
    }
    document.addEventListener('visibilitychange', schedule);
    window.addEventListener('resize', function () { states.forEach(normalize); });
    schedule();
}());
// ===== SNAPSMACK EOF =====
