/**
 * SNAPSMACK - Thomas the Bear Engine
 * Alpha v0.6
 *
 * Easter egg: Ctrl+Shift+Y spawns dancing bears on the page. Click, press X,
 * or press ESC to clear them. Tribute to Noah Grey.
 */

(function() {
    'use strict';

    let dedicationShown = false;
    let bearsActive = false;

    // --- BEAR REMOVAL ---
    // Clear all bears with fade-out animation
    function clearAllBears() {
        const bears = document.querySelectorAll('.thomas-bear');
        if (bears.length === 0) return false;
        bears.forEach(function(bear) {
            bear.style.transition = 'transform 0.3s ease, opacity 0.3s ease';
            bear.style.transform = 'scale(0) rotate(20deg)';
            bear.style.opacity = '0';
            setTimeout(function() { bear.remove(); }, 300);
        });
        bearsActive = false;
        return true;
    }

    // --- BEAR CONSTRUCTION ---
    // Create a single bear at random position and size
    function buildBear() {
        const size = Math.floor(Math.random() * 120) + 80;
        const bear = document.createElement('div');
        bear.className = 'thomas-bear';
        bear.style.width  = size + 'px';
        bear.style.height = size + 'px';

        const maxX = window.innerWidth  - size - 20;
        const maxY = window.innerHeight - size - 20;
        bear.style.left = Math.max(10, Math.floor(Math.random() * maxX)) + 'px';
        bear.style.top  = Math.max(10, Math.floor(Math.random() * maxY)) + 'px';

        bear.innerHTML = `
            <div class="bear">
                <div class="bear-body"><div class="bear-belly"></div></div>
                <div class="bear-arm left"></div>
                <div class="bear-arm right"></div>
                <div class="bear-leg left"><div class="bear-foot"></div></div>
                <div class="bear-leg right"><div class="bear-foot"></div></div>
                <div class="bear-head">
                    <div class="bear-ear left"><div class="bear-ear-inner"></div></div>
                    <div class="bear-ear right"><div class="bear-ear-inner"></div></div>
                    <div class="bear-face">
                        <div class="bear-eye left"></div>
                        <div class="bear-eye right"></div>
                        <div class="bear-nose"></div>
                        <div class="bear-mouth"></div>
                    </div>
                </div>
                <div class="bear-bowtie"><div class="bear-bowtie-knot"></div></div>
            </div>
        `;

        document.body.appendChild(bear);
        bearsActive = true;
    }

    // --- DEDICATION DISPLAY ---
    // Show memorial message
    function showDedication() {
        let el = document.getElementById('thomas-dedication');
        if (!el) {
            el = document.createElement('div');
            el.id = 'thomas-dedication';
            el.innerHTML = '<span>Thomas the Bear</span> &nbsp;&middot;&nbsp; For Noah Grey. We remember.';
            document.body.appendChild(el);
        }
        setTimeout(function() { el.classList.add('visible'); }, 50);
        setTimeout(function() {
            el.classList.remove('visible');
            setTimeout(function() { el.remove(); }, 800);
        }, 4000);
    }

    // --- KEYBOARD CONTROL ---
    document.addEventListener('keydown', function(e) {
        // Spawn bears with Ctrl+Shift+Y
        if (e.ctrlKey && e.shiftKey && (e.key === 'y' || e.key === 'Y')) {
            e.preventDefault();
            if (!dedicationShown) {
                showDedication();
                dedicationShown = true;
            }
            buildBear();
        }

        // Clear bears with X or ESC
        if (bearsActive && (e.key === 'x' || e.key === 'X' || e.key === 'Escape')) {
            clearAllBears();
        }
    });

    // --- CLICK TO CLEAR ---
    document.addEventListener('click', function() {
        if (bearsActive) {
            clearAllBears();
        }
    });

})();
