/*
 * SNAPSMACK - Thomas the Bear Engine
 * Version: 2026.2
 * Trigger: Ctrl+Shift+Y (original Picasa easter egg key combination)
 * Clear: Click anywhere, X, or Escape
 * Tribute: For Noah Grey. We remember.
 */

(function() {
    'use strict';

    let dedicationShown = false;
    let bearsActive = false;

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

    // Ctrl+Shift+Y to spawn
    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey && e.shiftKey && (e.key === 'y' || e.key === 'Y')) {
            e.preventDefault();
            if (!dedicationShown) {
                showDedication();
                dedicationShown = true;
            }
            buildBear();
        }

        // X or Escape clears all bears
        if (bearsActive && (e.key === 'x' || e.key === 'X' || e.key === 'Escape')) {
            clearAllBears();
        }
    });

    // Click anywhere clears all bears
    document.addEventListener('click', function() {
        if (bearsActive) {
            clearAllBears();
        }
    });

})();
