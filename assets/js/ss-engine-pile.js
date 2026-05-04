/**
 * SNAPSMACK — Pile Engine
 * ss-engine-pile.js
 *
 * Randomised photo pile renderer for the 52 Card Pickup skin.
 * Scatters images with random rotation, position, and z-index to simulate
 * a pile of physical prints thrown on a surface. Handles reshuffle via AJAX,
 * hover lift, and keyboard shortcut.
 *
 * Expects a container element with [data-pile] and data attributes:
 *   data-pile               — marker attribute (presence triggers init)
 *   data-api-url            — URL returning JSON array of images
 *   data-pile-size          — number of images to request (10-30)
 *   data-scatter            — scatter radius: tight | medium | wide
 *   data-rotation-max       — max rotation degrees (1-12)
 *   data-max-width          — max image display width in px
 *   data-transition-speed   — fade duration in ms
 *   data-hover-title        — "1" to show title on hover
 *   data-keyboard-reshuffle — "1" to enable R key
 *   data-frame-styles       — comma-separated enabled frames (polaroid,print,borderless,slide)
 */

(function () {
    'use strict';

    var container = document.querySelector('[data-pile]');
    if (!container) return;

    // ── Config from data attributes ──────────────────────────────────────
    var apiUrl          = container.dataset.apiUrl || '';
    var pileSize        = parseInt(container.dataset.pileSize, 10) || 20;
    var scatter         = container.dataset.scatter || 'medium';
    var rotationMax     = parseInt(container.dataset.rotationMax, 10) || 8;
    var maxWidth        = parseInt(container.dataset.maxWidth, 10) || 280;
    var transitionSpeed = parseInt(container.dataset.transitionSpeed, 10) || 300;
    var hoverTitle      = container.dataset.hoverTitle === '1';
    var keyboardShuffle = container.dataset.keyboardReshuffle === '1';
    var frameStyles     = (container.dataset.frameStyles || 'polaroid,print').split(',')
                              .map(function (s) { return s.trim(); })
                              .filter(Boolean);

    // Scatter radius multipliers (fraction of container size)
    var scatterMap = { tight: 0.25, medium: 0.4, wide: 0.55 };
    var scatterFraction = scatterMap[scatter] || 0.4;

    var isLoading = false;
    var currentZ  = 1;

    // ── Fetch images from API ────────────────────────────────────────────
    function fetchImages(callback) {
        if (!apiUrl) return;
        var url = apiUrl + (apiUrl.indexOf('?') > -1 ? '&' : '?')
                + 'count=' + pileSize + '&_=' + Date.now();

        fetch(url)
            .then(function (r) { return r.json(); })
            .then(function (data) { callback(data.images || data); })
            .catch(function () { /* silent fail — pile stays as-is */ });
    }

    // ── Random helpers ───────────────────────────────────────────────────
    function rand(min, max) { return Math.random() * (max - min) + min; }
    function pickFrame() { return frameStyles[Math.floor(Math.random() * frameStyles.length)]; }

    // ── Render the pile ──────────────────────────────────────────────────
    function renderPile(images) {
        container.innerHTML = '';
        currentZ = 1;

        var rect = container.getBoundingClientRect();
        var cx = rect.width / 2;
        var cy = rect.height / 2;
        var spreadX = rect.width * scatterFraction / 2;
        var spreadY = rect.height * scatterFraction / 2;

        images.forEach(function (img, i) {
            var frame = pickFrame();
            var rotation = rand(-rotationMax, rotationMax);
            var offsetX = rand(-spreadX, spreadX);
            var offsetY = rand(-spreadY, spreadY);
            var z = i + 1;

            var card = document.createElement('a');
            card.href = img.url || '#';
            card.className = 'pile-card pile-frame-' + frame;
            card.style.cssText = 'position:absolute;'
                + 'left:' + (cx + offsetX) + 'px;'
                + 'top:' + (cy + offsetY) + 'px;'
                + 'z-index:' + z + ';'
                + 'transform:translate(-50%,-50%) rotate(' + rotation.toFixed(1) + 'deg);'
                + 'max-width:' + maxWidth + 'px;'
                + 'transition:transform ' + transitionSpeed + 'ms ease, box-shadow ' + transitionSpeed + 'ms ease;'
                + 'opacity:0;';

            var imgEl = document.createElement('img');
            imgEl.src = img.src || '';
            imgEl.alt = img.title || '';
            imgEl.loading = 'lazy';
            imgEl.draggable = false;
            card.appendChild(imgEl);

            if (hoverTitle && img.title) {
                var titleEl = document.createElement('span');
                titleEl.className = 'pile-title';
                titleEl.textContent = img.title;
                card.appendChild(titleEl);
            }

            // Hover: lift and raise z-index
            card.addEventListener('mouseenter', function () {
                currentZ++;
                card.style.zIndex = currentZ;
                card.classList.add('pile-card-hover');
            });
            card.addEventListener('mouseleave', function () {
                card.classList.remove('pile-card-hover');
            });

            container.appendChild(card);

            // Staggered fade-in
            setTimeout(function () {
                card.style.opacity = '1';
            }, 20 + i * 30);
        });
    }

    // ── Reshuffle ────────────────────────────────────────────────────────
    function reshuffle() {
        if (isLoading) return;
        isLoading = true;

        // Fade out current pile
        var cards = container.querySelectorAll('.pile-card');
        cards.forEach(function (c) { c.style.opacity = '0'; });

        setTimeout(function () {
            fetchImages(function (images) {
                renderPile(images);
                isLoading = false;
            });
        }, transitionSpeed);
    }

    // ── Reshuffle button binding ─────────────────────────────────────────
    var reshuffleBtn = document.querySelector('[data-pile-reshuffle]');
    if (reshuffleBtn) {
        reshuffleBtn.addEventListener('click', function (e) {
            e.preventDefault();
            reshuffle();
        });
    }

    // ── Keyboard shortcut ────────────────────────────────────────────────
    if (keyboardShuffle) {
        document.addEventListener('keydown', function (e) {
            if (e.key === 'r' || e.key === 'R') {
                if (document.activeElement.tagName === 'INPUT' || document.activeElement.tagName === 'TEXTAREA') return;
                e.preventDefault();
                reshuffle();
            }
        });
    }

    // ── Initial load ─────────────────────────────────────────────────────
    fetchImages(function (images) {
        renderPile(images);
    });

})();
// EOF
