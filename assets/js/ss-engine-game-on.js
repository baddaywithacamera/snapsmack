/**
 * SNAPSMACK — GAME ON sliding-puzzle engine
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-game-on]');
    if (!root) return;

    var reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
    var boards = [];
    var active = null;
    var modal = null;
    var modalOpener = null;
    var swipeStart = null;
    var session = { solved: 0, totalMs: 0, bestMs: null };
    var palettes = {
        electric: ['#B7FF00', '#FF2B9D', '#00D9FF', '#5A25B5'],
        'film-box': ['#F6C515', '#D82C2C', '#36A8B8', '#563B70'],
        monochrome: ['#F2F0E9', '#B7B7B2', '#626262', '#161616']
    };

    function rand(min, max) { return min + Math.random() * (max - min); }
    function shuffled(a) {
        a = a.slice();
        for (var i = a.length - 1; i > 0; i--) {
            var j = Math.floor(Math.random() * (i + 1));
            var t = a[i]; a[i] = a[j]; a[j] = t;
        }
        return a;
    }
    function legal(empty) {
        var r = Math.floor(empty / 4), c = empty % 4, out = [];
        if (r > 0) out.push(empty - 4);
        if (r < 3) out.push(empty + 4);
        if (c > 0) out.push(empty - 1);
        if (c < 3) out.push(empty + 1);
        return out;
    }
    function scramble(count) {
        var slots = [];
        for (var i = 0; i < 15; i++) slots.push(i);
        slots.push(null);
        var empty = 15, prior = -1;
        for (var n = 0; n < count; n++) {
            var moves = legal(empty).filter(function (x) { return x !== prior; });
            if (!moves.length) moves = legal(empty);
            var from = moves[Math.floor(Math.random() * moves.length)];
            slots[empty] = slots[from]; slots[from] = null;
            prior = empty; empty = from;
        }
        return { slots: slots, empty: empty };
    }
    function solved(board) {
        for (var i = 0; i < 15; i++) if (board.slots[i] !== i) return false;
        return board.slots[15] === null;
    }
    function durationFor(board) {
        if (board.modal) return 210;
        var density = root.dataset.density || 'normal';
        if (density === 'calm') return rand(320, 520);
        if (density === 'busy') return rand(150, 330);
        return rand(180, 450);
    }
    function delayFor() {
        var density = root.dataset.density || 'normal';
        if (density === 'calm') return rand(2400, 5000);
        if (density === 'busy') return rand(650, 1900);
        return rand(1000, 3000);
    }

    function tileImage(tile, board, full) {
        var image = full ? board.full : board.thumb;
        tile.style.backgroundImage = 'url("' + String(image).replace(/"/g, '%22') + '")';
        if (!full) {
            tile.style.backgroundSize = '400% 400%';
            var col = tile._piece % 4, row = Math.floor(tile._piece / 4);
            tile.style.backgroundPosition = (col * 100 / 3) + '% ' + (row * 100 / 3) + '%';
        }
    }

    function positionTiles(board, animate) {
        var dur = animate ? durationFor(board) : 0;
        board.tiles.forEach(function (tile) {
            var slot = board.slots.indexOf(tile._piece);
            var x = slot % 4, y = Math.floor(slot / 4);
            tile.style.transitionDuration = dur + 'ms';
            tile.style.transform = 'translate3d(' + (x * 100) + '%, ' + (y * 100) + '%, 0)';
        });
        board.busyUntil = performance.now() + dur;
        if (board.modal) updateModalImageGeometry(board);
    }

    function updateModalImageGeometry(board) {
        if (!board.modal || !board.naturalWidth || !board.el.clientWidth) return;
        var size = board.el.clientWidth;
        var base = Math.max(size / board.naturalWidth, size / board.naturalHeight);
        var zoom = Math.max(1, board.zoom / 100);
        var dw = board.naturalWidth * base * zoom;
        var dh = board.naturalHeight * base * zoom;
        var cropX = Math.max(0, dw - size) * board.focusX / 100;
        var cropY = Math.max(0, dh - size) * board.focusY / 100;
        var cell = size / 4;
        board.tiles.forEach(function (tile) {
            var col = tile._piece % 4, row = Math.floor(tile._piece / 4);
            tile.style.backgroundSize = dw + 'px ' + dh + 'px';
            tile.style.backgroundPosition = (-cropX - col * cell) + 'px ' + (-cropY - row * cell) + 'px';
        });
    }

    function makeTiles(board, full) {
        board.el.innerHTML = '';
        board.tiles = [];
        for (var i = 0; i < 15; i++) {
            var tile = document.createElement('span');
            tile.className = 'go-puzzle-piece';
            tile._piece = i;
            tileImage(tile, board, full);
            board.el.appendChild(tile);
            board.tiles.push(tile);
        }
        positionTiles(board, false);
    }

    function move(board, from, userMove) {
        if (performance.now() < board.busyUntil) return false;
        if (legal(board.empty).indexOf(from) === -1) return false;
        if (userMove && board.modal && !board.startedAt) board.startedAt = performance.now();
        board.slots[board.empty] = board.slots[from];
        board.slots[from] = null;
        board.empty = from;
        board.moves += userMove ? 1 : 0;
        positionTiles(board, true);
        if (board.modal) {
            updateStats();
            if (userMove && solved(board)) window.setTimeout(function () { finish(board); }, 240);
        }
        return true;
    }

    function autoStep(board) {
        if (document.hidden || reduced.matches || root.dataset.mode !== 'moving' || board.paused) return;
        var choices = legal(board.empty);
        if (choices.length > 1 && board.lastEmpty >= 0) {
            choices = choices.filter(function (x) { return x !== board.lastEmpty; });
        }
        var oldEmpty = board.empty;
        move(board, choices[Math.floor(Math.random() * choices.length)], false);
        board.lastEmpty = oldEmpty;
        if (solved(board) && !board.resetting) finishBackground(board);
    }

    function finishBackground(board) {
        board.resetting = true;
        board.paused = true;
        board.el.classList.add('is-solved-pulse');
        window.setTimeout(function () {
            board.el.classList.remove('is-solved-pulse');
            board.el.classList.add('is-changing');
            window.setTimeout(function () {
                var state = scramble(100 + Math.floor(Math.random() * 151));
                board.slots = state.slots; board.empty = state.empty;
                positionTiles(board, false);
                board.el.classList.remove('is-changing');
                board.resetting = false; board.paused = false;
            }, reduced.matches ? 80 : 650);
        }, reduced.matches ? 150 : 1900);
    }

    function schedule(board) {
        window.clearTimeout(board.timer);
        board.timer = window.setTimeout(function tick() {
            autoStep(board);
            board.timer = window.setTimeout(tick, delayFor());
        }, delayFor());
    }

    function boardFrom(el, state, isModal) {
        return {
            el: el,
            thumb: el.dataset.thumb || '',
            full: el.dataset.full || '',
            postUrl: el.dataset.postUrl || '#',
            label: el.dataset.label || 'Photograph',
            focusX: Math.max(0, Math.min(100, Number(el.dataset.focusX || 50))),
            focusY: Math.max(0, Math.min(100, Number(el.dataset.focusY || 50))),
            zoom: Math.max(100, Number(el.dataset.zoom || 100)),
            slots: state.slots.slice(), empty: state.empty,
            tiles: [], moves: 0, lastEmpty: -1, busyUntil: 0,
            paused: false, modal: !!isModal, startedAt: 0, finished: false,
            naturalWidth: 0, naturalHeight: 0, timer: 0
        };
    }

    function selectPalette() {
        var key = root.dataset.palette || 'automatic';
        if (!palettes[key]) {
            var keys = Object.keys(palettes);
            try { key = sessionStorage.getItem('snapsmack-game-on-palette') || ''; } catch (e) { key = ''; }
            if (!palettes[key]) key = keys[Math.floor(Math.random() * keys.length)];
            try { sessionStorage.setItem('snapsmack-game-on-palette', key); } catch (e) { /* private mode */ }
        }
        root.dataset.activePalette = key;
        root.style.setProperty('--go-c1', palettes[key][0]);
        root.style.setProperty('--go-c2', palettes[key][1]);
        root.style.setProperty('--go-c3', palettes[key][2]);
        root.style.setProperty('--go-c4', palettes[key][3]);
        var direction = root.dataset.direction || 'automatic';
        if (direction === 'automatic') {
            try { direction = sessionStorage.getItem('snapsmack-game-on-direction') || ''; } catch (e) { direction = ''; }
            if (direction !== 'horizontal' && direction !== 'vertical') direction = Math.random() < .72 ? 'horizontal' : 'vertical';
            try { sessionStorage.setItem('snapsmack-game-on-direction', direction); } catch (e) { /* private mode */ }
        }
        root.dataset.activeDirection = direction;
    }

    function createModal() {
        var wrap = document.createElement('div');
        wrap.className = 'go-game-modal'; wrap.hidden = true;
        wrap.innerHTML = '<div class="go-game-backdrop"></div>' +
            '<section class="go-game-dialog" role="dialog" aria-modal="true" aria-labelledby="go-game-title">' +
            '<button class="go-game-close" type="button" aria-label="Close puzzle">&times;</button>' +
            '<p id="go-game-title" class="go-game-invite">I want to play a game.</p>' +
            '<div class="go-game-board" role="application" aria-label="Sliding image puzzle"></div>' +
            '<div class="go-game-stats" aria-live="polite">' +
            '<span><b data-stat="time">0:00.0</b> time</span>' +
            '<span><b data-stat="moves">0</b> moves</span>' +
            '<span><b data-stat="solved">0</b> solved</span>' +
            '<span><b data-stat="best">—</b> best</span>' +
            '<span><b data-stat="average">—</b> average</span></div>' +
            '<div class="go-game-actions"><a data-game-post href="#">View photograph</a><button data-game-new type="button">New puzzle</button></div>' +
            '</section>';
        document.body.appendChild(wrap);
        wrap.querySelector('.go-game-close').addEventListener('click', closeModal);
        wrap.querySelector('.go-game-backdrop').addEventListener('click', closeModal);
        wrap.querySelector('[data-game-new]').addEventListener('click', nextPuzzle);
        var gameBoard = wrap.querySelector('.go-game-board');
        gameBoard.addEventListener('pointerdown', function (event) {
            swipeStart = { x: event.clientX, y: event.clientY };
        });
        gameBoard.addEventListener('pointerup', function (event) {
            if (!swipeStart || !active || active.finished) return;
            var dx = event.clientX - swipeStart.x, dy = event.clientY - swipeStart.y;
            swipeStart = null;
            if (Math.max(Math.abs(dx), Math.abs(dy)) < 28) return;
            var rect = active.el.getBoundingClientRect();
            var col = Math.max(0, Math.min(3, Math.floor((event.clientX - rect.left) / (rect.width / 4))));
            var row = Math.max(0, Math.min(3, Math.floor((event.clientY - rect.top) / (rect.height / 4))));
            move(active, row * 4 + col, true);
        });
        return wrap;
    }

    function openModal(source) {
        if (!modal) modal = createModal();
        modalOpener = source.el;
        source.paused = true;
        active = boardFrom(modal.querySelector('.go-game-board'), source, true);
        active.source = source;
        active.thumb = source.thumb; active.full = source.full;
        active.postUrl = source.postUrl; active.label = source.label;
        modal.querySelector('[data-game-post]').href = active.postUrl;
        modal.querySelector('[data-game-post]').textContent = 'View ' + active.label;
        modal.hidden = false;
        document.documentElement.classList.add('go-game-open');
        makeTiles(active, true);
        var requestedFull = active.full;
        var probe = new Image();
        probe.onload = function () {
            if (!active || active.full !== requestedFull) return;
            active.naturalWidth = probe.naturalWidth; active.naturalHeight = probe.naturalHeight;
            updateModalImageGeometry(active);
        };
        probe.src = active.full;
        modal.querySelector('.go-game-close').focus();
        updateStats(); startTicker();
    }

    function closeModal() {
        if (!active || !modal) return;
        if (active.source && !active.finished) {
            active.source.slots = active.slots.slice();
            active.source.empty = active.empty;
            positionTiles(active.source, false);
        }
        if (active.source) active.source.paused = false;
        active = null; modal.hidden = true;
        document.documentElement.classList.remove('go-game-open');
        if (modalOpener && typeof modalOpener.focus === 'function') modalOpener.focus({ preventScroll: true });
        modalOpener = null;
    }

    function nextPuzzle() {
        if (!active) return;
        var current = active.source;
        var candidates = boards.filter(function (b) { return b !== current; });
        var source = candidates.length ? candidates[Math.floor(Math.random() * candidates.length)] : current;
        if (current) current.paused = false;
        modal.hidden = true; active = null;
        window.setTimeout(function () { openModal(source); }, 30);
    }

    function formatMs(ms) {
        var total = Math.max(0, ms) / 1000;
        var min = Math.floor(total / 60);
        return min + ':' + (total % 60).toFixed(1).padStart(4, '0');
    }
    function updateStats() {
        if (!modal) return;
        var elapsed = active && active.startedAt ? performance.now() - active.startedAt : 0;
        var values = {
            time: formatMs(elapsed), moves: active ? active.moves : 0,
            solved: session.solved,
            best: session.bestMs === null ? '—' : formatMs(session.bestMs),
            average: session.solved ? formatMs(session.totalMs / session.solved) : '—'
        };
        Object.keys(values).forEach(function (key) {
            var el = modal.querySelector('[data-stat="' + key + '"]');
            if (el) el.textContent = values[key];
        });
    }
    function startTicker() {
        window.requestAnimationFrame(function tick() {
            if (!active) return;
            updateStats(); window.requestAnimationFrame(tick);
        });
    }

    function finish(board) {
        if (board.finished) return;
        board.finished = true;
        var elapsed = board.startedAt ? performance.now() - board.startedAt : 0;
        session.solved++;
        session.totalMs += elapsed;
        session.bestMs = session.bestMs === null ? elapsed : Math.min(session.bestMs, elapsed);
        updateStats();
        window.setTimeout(function () {
            if (!active) return;
            board.el.classList.add('is-solved-pulse');
            window.setTimeout(function () {
                if (!active) return;
                board.el.classList.remove('is-solved-pulse');
                modal.querySelector('.go-game-dialog').classList.add('is-changing');
                window.setTimeout(function () {
                    if (!active) return;
                    modal.querySelector('.go-game-dialog').classList.remove('is-changing');
                    nextPuzzle();
                }, reduced.matches ? 80 : 650);
            }, reduced.matches ? 150 : 1900);
        }, reduced.matches ? 300 : 2000);
    }

    function clickBoard(event) {
        if (!active || active.finished) return;
        var rect = active.el.getBoundingClientRect();
        var col = Math.max(0, Math.min(3, Math.floor((event.clientX - rect.left) / (rect.width / 4))));
        var row = Math.max(0, Math.min(3, Math.floor((event.clientY - rect.top) / (rect.height / 4))));
        move(active, row * 4 + col, true);
    }
    function keyBoard(event) {
        if (!active || active.finished) return;
        if (event.key === 'Tab' && modal) {
            var focusable = Array.prototype.slice.call(modal.querySelectorAll('button:not([disabled]),a[href]'));
            if (focusable.length) {
                var at = focusable.indexOf(document.activeElement);
                if (event.shiftKey && at <= 0) { event.preventDefault(); focusable[focusable.length - 1].focus(); }
                if (!event.shiftKey && at === focusable.length - 1) { event.preventDefault(); focusable[0].focus(); }
            }
            return;
        }
        var e = active.empty, from = -1;
        if (event.key === 'ArrowUp' || event.key.toLowerCase() === 'w') from = e + 4;
        if (event.key === 'ArrowDown' || event.key.toLowerCase() === 's') from = e - 4;
        if (event.key === 'ArrowLeft' || event.key.toLowerCase() === 'a') from = e + 1;
        if (event.key === 'ArrowRight' || event.key.toLowerCase() === 'd') from = e - 1;
        if (event.key === 'Escape') { closeModal(); return; }
        if (from >= 0 && from < 16) { event.preventDefault(); move(active, from, true); }
    }

    selectPalette();
    root.querySelectorAll('.go-puzzle').forEach(function (el) {
        var state = scramble(100 + Math.floor(Math.random() * 151));
        var board = boardFrom(el, state, false);
        boards.push(board); makeTiles(board, false); schedule(board);
        el.addEventListener('click', function () { openModal(board); });
    });
    document.addEventListener('click', function (e) {
        if (active && e.target.closest('.go-game-board')) clickBoard(e);
    });
    document.addEventListener('keydown', keyBoard);
    window.addEventListener('resize', function () { if (active) updateModalImageGeometry(active); });
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) boards.forEach(schedule);
    });

    // Thomas clause: the machine would like you to know it is enjoying itself.
    window.SnapSmackGameOn = { boards: boards.length };
}());
// ===== SNAPSMACK EOF =====
