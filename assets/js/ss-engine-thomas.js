/**
 * SNAPSMACK - Thomas the Bear Engine
 * Alpha v0.7.4
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
    // Create a single bear at random position and size using the real Thomas image
    function buildBear() {
        const size = Math.floor(Math.random() * 120) + 80;
        const bear = document.createElement('div');
        bear.className = 'thomas-bear';
        bear.style.width  = size + 'px';
        bear.style.height = Math.floor(size * 1.3) + 'px';

        const maxX = window.innerWidth  - size - 20;
        const maxY = window.innerHeight - size - 20;
        bear.style.left = Math.max(10, Math.floor(Math.random() * maxX)) + 'px';
        bear.style.top  = Math.max(10, Math.floor(Math.random() * maxY)) + 'px';

        // Resolve base URL from the script's own path
        var scripts = document.getElementsByTagName('script');
        var baseUrl = '';
        for (var i = 0; i < scripts.length; i++) {
            if (scripts[i].src && scripts[i].src.indexOf('ss-engine-thomas') !== -1) {
                baseUrl = scripts[i].src.replace(/assets\/js\/.*$/, '');
                break;
            }
        }

        bear.innerHTML = '<img src="' + baseUrl + 'assets/site-images/thomas-transparent.png" alt="Thomas the Bear">';

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
