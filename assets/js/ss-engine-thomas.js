/*
 * SNAPSMACK - Thomas the Bear Engine
 * Version: 2026.1
 * Trigger: Ctrl+Shift+Y (original Picasa easter egg key combination)
 * Tribute: For Noah Grey. We remember.
 */

(function() {
    'use strict';

    let dedicationShown = false;

    function buildBear() {
        const size = Math.floor(Math.random() * 120) + 80; // 80–200px
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

        bear.addEventListener('click', function() {
            bear.style.transition = 'transform 0.3s ease, opacity 0.3s ease';
            bear.style.transform = 'scale(0) rotate(20deg)';
            bear.style.opacity = '0';
            setTimeout(function() { bear.remove(); }, 300);
        });

        document.body.appendChild(bear);
    }

    function showDedication() {
        // Create dedication element if it doesn't exist
        let el = document.getElementById('thomas-dedication');
        if (!el) {
            el = document.createElement('div');
            el.id = 'thomas-dedication';
            el.innerHTML = '<span>Thomas the Bear</span> &nbsp;&middot;&nbsp; For Noah Grey. We remember.';
            document.body.appendChild(el);
        }

        // Fade in
        setTimeout(function() { el.classList.add('visible'); }, 50);

        // Fade out after 4 seconds
        setTimeout(function() {
            el.classList.remove('visible');
            setTimeout(function() { el.remove(); }, 800);
        }, 4000);
    }

    // Listen for Ctrl+Shift+Y
    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey && e.shiftKey && (e.key === 'y' || e.key === 'Y')) {
            e.preventDefault();

            // Show dedication on first trigger only
            if (!dedicationShown) {
                showDedication();
                dedicationShown = true;
            }

            // Spawn a bear
            buildBear();
        }
    });

})();
