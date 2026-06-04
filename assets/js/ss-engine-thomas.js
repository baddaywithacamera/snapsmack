/**
 * SNAPSMACK - Thomas the Bear Engine
 *
 * Easter egg: Ctrl+Shift+Y spawns dancing bears on the page. Click, press X,
 * or press ESC to clear them. Ctrl+Shift+Z opens the story of Noah and Thomas.
 * Tribute to Noah Grey.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


(function() {
    'use strict';

    let dedicationShown = false;
    let bearsActive     = false;
    let thomasPinged    = false;

    // --- THOMAS PING ---
    // One silent ping per browser session when Thomas is first found.
    // Uses the install UID (window.ssUid, set by footer-scripts.php).
    // &s=1 marks the first find in this browser session (sessionStorage flag)
    // for unique-finder counting. No personal data leaves the browser.
    // type: 'y' = bear spawned, 'z' = Noah modal opened
    function pingThomas(type) {
        var uid = (window.ssUid || '').replace(/[^a-f0-9]/gi, '').toLowerCase().substring(0, 32);
        if (!uid || uid.length !== 32) return;
        var firstSession = false;
        try {
            firstSession = !sessionStorage.getItem('ss_thomas');
            if (firstSession) sessionStorage.setItem('ss_thomas', '1');
        } catch (e) { firstSession = true; }
        var img = new Image();
        img.src = 'https://snapsmack.ca/releases/thomas-ping.php?uid='
                + encodeURIComponent(uid) + '&t=' + (type || 'y') + '&s=' + (firstSession ? 1 : 0);
    }

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

    // --- NOAH MODAL ---
    // Resolve bg/text colours the same way ss-engine-comms.js does,
    // duplicated here so thomas.js has no load-order dependency on comms.
    function getThomasColors() {
        var rootStyle = getComputedStyle(document.documentElement);
        var bodyStyle = getComputedStyle(document.body);
        var bgColor   = rootStyle.getPropertyValue('--bg-primary').trim();
        var textColor = rootStyle.getPropertyValue('--text-primary').trim();
        if (!bgColor)   bgColor   = bodyStyle.backgroundColor;
        if (!textColor) textColor = bodyStyle.color;
        if (!bgColor || bgColor === 'rgba(0, 0, 0, 0)' || bgColor === 'transparent') bgColor = '#1a1a1a';
        if (!textColor) textColor = '#e0e0e0';
        return { bgColor: bgColor, textColor: textColor };
    }

    function showNoahModal() {
        if (document.getElementById('thomas-noah-modal')) {
            document.getElementById('thomas-noah-modal').style.display = 'flex';
            return;
        }

        var colors  = getThomasColors();
        var bg      = colors.bgColor;
        var fg      = colors.textColor;

        var backdrop = document.createElement('div');
        backdrop.id = 'thomas-noah-modal';
        backdrop.style.cssText = 'position:fixed;top:0;left:0;width:100vw;height:100vh;' +
            'background:rgba(0,0,0,0.75);display:flex;align-items:center;' +
            'justify-content:center;z-index:99999;';

        var panel = document.createElement('div');
        panel.style.cssText = 'background:' + bg + ';color:' + fg + ';' +
            'border:1px solid ' + fg + ';padding:40px;max-width:480px;width:90%;' +
            'border-radius:4px;font-family:"Courier Prime","Courier New",monospace;' +
            'box-shadow:0 20px 50px rgba(0,0,0,0.9);';

        panel.innerHTML =
            '<h2 style="margin-top:0;margin-bottom:20px;font-size:1.1rem;letter-spacing:2px;' +
                'border-bottom:1px solid rgba(128,128,128,0.4);padding-bottom:10px;' +
                'text-align:center;text-transform:uppercase;">' +
                'Noah Grey &amp; Thomas the Bear</h2>' +
            '<div style="font-size:13px;line-height:1.8;text-transform:none;">' +
                '<p style="margin-top:0;">In November 2000, Noah Grey released Greymatter — the original ' +
                'open-source blogging software, written in Perl, requiring nothing but a webserver. ' +
                'He pioneered personal blogging and photoblogging before either word was common. ' +
                'To many people in the early days of the web, Noah Grey was photography on the web. ' +
                'Bloggers built their entire lives on what he created.</p>' +
                '<p>In 2004, Noah worked with the Google Picasa team, helping to shape what photo ' +
                'software could feel like in the hands of someone who actually cared about photographs.</p>' +
                '<p>The Picasa developers put Thomas inside their software as a gift to Noah — ' +
                'a tribute from people who understood what he had built and what he meant. ' +
                'Thomas was a small stuffed bear, given to Noah by an old friend, ' +
                'a companion for over fifteen years. He became a hidden resident of one of the ' +
                'world\'s most widely used photo applications.</p>' +
                '<p>Press Ctrl+Shift+Y in Picasa and Thomas appeared. Press it again and again ' +
                'and he multiplied across the screen in varying sizes. Millions of photographers ' +
                'found him and smiled, most never knowing whose bear he was or why he was there.</p>' +
                '<p>Google discontinued Picasa in 2016. Thomas went with it — no ceremony, ' +
                'no acknowledgment. Just gone.</p>' +
                '<p style="margin-bottom:0;">SnapSmack carries the shortcut forward because photo software ' +
                'should know what it is built on. Ctrl+Shift+Y still summons Thomas here. ' +
                'He belongs wherever photographs are made.</p>' +
            '</div>' +
            '<div style="margin-top:24px;text-align:center;font-size:11px;' +
                'letter-spacing:2px;text-transform:uppercase;opacity:0.7;">' +
                '&mdash;&nbsp; For Noah Grey &nbsp;&mdash;</div>' +
            '<div style="margin-top:20px;text-align:center;font-size:11px;' +
                'letter-spacing:1px;text-transform:uppercase;opacity:0.5;">' +
                '[ ESC ] Close</div>';

        backdrop.appendChild(panel);
        backdrop.addEventListener('click', function(e) {
            if (e.target === backdrop) backdrop.style.display = 'none';
        });
        document.body.appendChild(backdrop);
    }

    function closeNoahModal() {
        var modal = document.getElementById('thomas-noah-modal');
        if (modal) modal.style.display = 'none';
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
                pingThomas('y'); // first bear spawned
            }
            buildBear();
        }

        // Open Noah & Thomas story with Ctrl+Shift+Z
        if (e.ctrlKey && e.shiftKey && (e.key === 'z' || e.key === 'Z')) {
            e.preventDefault();
            showNoahModal();
            pingThomas('z'); // Noah modal opened
        }

        // Clear bears with X or ESC; also close Noah modal on ESC
        if (e.key === 'Escape') {
            clearAllBears();
            closeNoahModal();
        }
        if (bearsActive && (e.key === 'x' || e.key === 'X')) {
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
// ===== SNAPSMACK EOF =====
