/**
 * SNAPSMACK - HEURISTIC logic memory centre and infomatic engine
 *
 * One background canvas, one temporary overlay at a time. Motion is disabled
 * on phones and whenever the visitor requests reduced motion.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */
(function () {
    'use strict';

    var host = document.querySelector('[data-heuristic-memory]');
    if (!host) return;

    var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var phone = window.matchMedia('(max-width: 680px)').matches;
    var tablet = !phone && window.matchMedia('(max-width: 1024px)').matches;
    var mode = host.dataset.mode || 'live';
    var animate = mode === 'live' && !reduced && !phone;
    var memoryOn = host.dataset.memory !== '0';
    var infomaticsOn = host.dataset.infomatics !== '0';
    var classicFallback = host.dataset.classicFallback !== '0';
    var firstDelay = number(host.dataset.firstDelay, 20) * 1000 * (tablet ? 1.8 : 1);
    var hold = number(host.dataset.hold, 8) * 1000;
    var rest = number(host.dataset.rest, 16) * 1000 * (tablet ? 1.7 : 1);
    var pulses = Math.max(0, Math.min(3, number(host.dataset.pulses, 3)));
    var classic = [
        ['ATM', 'ATMOSPHERIC CONTROL', 'fault'],
        ['COM', 'COMMUNICATIONS', 'violet'],
        ['CNT', 'CONTENTS', 'calm'],
        ['DMG', 'DAMAGE ASSESSMENT', 'fault'],
        ['GDE', 'GUIDANCE SYSTEM', 'blue'],
        ['HIB', 'HIBERNATION CONTROL', 'blue'],
        ['NAV', 'NAVIGATION', 'violet'],
        ['NUC', 'NUCLEAR SYSTEMS', 'blue'],
        ['VEH', 'VEHICLE STATUS', 'calm']
    ];

    function number(value, fallback) {
        var parsed = parseInt(value, 10);
        return Number.isFinite(parsed) ? parsed : fallback;
    }

    function visibleTiles() {
        return Array.prototype.filter.call(document.querySelectorAll('.he-tile:not(.he-tile--phantom)'), function (tile) {
            var r = tile.getBoundingClientRect();
            return r.bottom > 0 && r.top < window.innerHeight && r.right > 0 && r.left < window.innerWidth;
        });
    }

    function screenFor(tile) {
        var code = (tile.dataset.heCode || '').trim();
        if (code) {
            return [code, tile.dataset.heLabel || 'HEURISTIC ANALYSIS', tile.dataset.heColour || 'calm', tile.dataset.heValue || ''];
        }
        if (!classicFallback) return null;
        var seed = number(tile.dataset.hePost, 0) + number(tile.dataset.row, 0) * 3 + number(tile.dataset.col, 0);
        var item = classic[Math.abs(seed) % classic.length].slice();
        item.push('');
        return item;
    }

    function buildOverlay(tile, screen) {
        var overlay = document.createElement('div');
        overlay.className = 'he-infomatic he-infomatic--' + screen[2];
        overlay.setAttribute('aria-hidden', 'true');

        var telemetry = document.createElement('span');
        telemetry.className = 'he-infomatic-telemetry';
        telemetry.textContent = 'HAL 9000 / ' + String(tile.dataset.hePost || '000').padStart(4, '0');
        var code = document.createElement('strong');
        code.className = 'he-infomatic-code';
        code.textContent = screen[0];
        var label = document.createElement('span');
        label.className = 'he-infomatic-label';
        label.textContent = screen[1];
        overlay.appendChild(telemetry);
        overlay.appendChild(code);
        if (screen[3]) {
            var value = document.createElement('span');
            value.className = 'he-infomatic-value';
            value.textContent = screen[3];
            overlay.appendChild(value);
        }
        overlay.appendChild(label);
        tile.appendChild(overlay);
        return overlay;
    }

    function runInfomatic() {
        if (!animate || !infomaticsOn || document.hidden) return schedule(rest);
        var tiles = visibleTiles().filter(function (tile) {
            return !tile.matches(':hover') && !tile.contains(document.activeElement);
        });
        if (!tiles.length) return schedule(rest);
        var tile = tiles[Math.floor(Math.random() * tiles.length)];
        var screen = screenFor(tile);
        if (!screen) return schedule(rest);
        var overlay = buildOverlay(tile, screen);
        requestAnimationFrame(function () { overlay.classList.add('is-online'); });

        window.setTimeout(function () {
            if (!document.body.contains(overlay)) return schedule(rest);
            var done = function () {
                overlay.classList.add('is-offline');
                window.setTimeout(function () {
                    if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
                    schedule(rest);
                }, 550);
            };
            if (!pulses) return done();
            overlay.style.setProperty('--he-pulses', String(pulses));
            overlay.classList.add('is-pulsing');
            window.setTimeout(done, pulses * 900);
        }, hold);
    }

    function schedule(delay) {
        window.setTimeout(runInfomatic, delay);
    }

    if (memoryOn) {
        var canvas = document.createElement('canvas');
        canvas.className = 'he-memory-canvas';
        host.appendChild(canvas);
        var ctx = canvas.getContext('2d');
        var cells = [];
        var last = 0;

        function resize() {
            var dpr = Math.min(window.devicePixelRatio || 1, 1.5);
            canvas.width = Math.round(window.innerWidth * dpr);
            canvas.height = Math.round(window.innerHeight * dpr);
            canvas.style.width = window.innerWidth + 'px';
            canvas.style.height = window.innerHeight + 'px';
            ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
            cells = [];
            var size = Math.max(9, Math.min(18, window.innerWidth / 90));
            for (var y = -size; y < window.innerHeight + size; y += size * 1.55) {
                for (var x = -size; x < window.innerWidth + size; x += size * 1.45) {
                    cells.push({x: x, y: y, w: size * .72, h: size, energy: Math.random() * .32});
                }
            }
        }

        function draw(time) {
            if (!ctx) return;
            if (!document.hidden && time - last > (animate ? 70 : 800)) {
                last = time;
                ctx.clearRect(0, 0, window.innerWidth, window.innerHeight);
                ctx.fillStyle = '#030305';
                ctx.fillRect(0, 0, window.innerWidth, window.innerHeight);
                cells.forEach(function (cell) {
                    if (animate && Math.random() < .018) cell.energy = .55 + Math.random() * .45;
                    cell.energy *= animate ? .91 : 1;
                    var alpha = .12 + cell.energy * .78;
                    ctx.fillStyle = 'rgba(222,20,52,' + alpha.toFixed(3) + ')';
                    ctx.fillRect(cell.x, cell.y, cell.w, cell.h);
                });
                ctx.fillStyle = 'rgba(0,0,0,.62)';
                ctx.fillRect(window.innerWidth * .18, 0, window.innerWidth * .64, window.innerHeight);
            }
            if (animate) requestAnimationFrame(draw);
        }
        resize();
        window.addEventListener('resize', resize, {passive: true});
        if (animate) requestAnimationFrame(draw); else draw(0);
    }

    window.SnapSmackHeuristic = {
        test: function () {
            if (reduced || phone) return false;
            runInfomatic();
            return true;
        }
    };
    if (animate && infomaticsOn) schedule(firstDelay);
}());
// ===== SNAPSMACK EOF =====
