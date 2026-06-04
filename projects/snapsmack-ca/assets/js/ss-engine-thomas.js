/**
 * SNAPSMACK.CA — Thomas the Bear Engine
 *
 * Mirrors ss-engine-thomas.js from the SnapSmack core.
 * Image path is hardcoded to img/thomas-transparent.png (snapsmack.ca layout).
 * No UID tracking — snapsmack.ca is not a SnapSmack install.
 *
 * Easter egg: Ctrl+Shift+Y spawns bears. Click, press X, or ESC to clear.
 * Ctrl+Shift+Z opens the Noah Grey story.
 */

(function() {
    'use strict';

    var dedicationShown = false;
    var bearsActive     = false;

    function clearAllBears() {
        var bears = document.querySelectorAll('.thomas-bear');
        if (bears.length === 0) return false;
        bears.forEach(function(bear) {
            bear.style.transition = 'transform 0.3s ease, opacity 0.3s ease';
            bear.style.transform  = 'scale(0) rotate(20deg)';
            bear.style.opacity    = '0';
            setTimeout(function() { bear.remove(); }, 300);
        });
        bearsActive = false;
        return true;
    }

    function buildBear() {
        var size  = Math.floor(Math.random() * 120) + 80;
        var bear  = document.createElement('div');
        bear.className    = 'thomas-bear';
        bear.style.width  = size + 'px';
        bear.style.height = Math.floor(size * 1.3) + 'px';

        var maxX = window.innerWidth  - size - 20;
        var maxY = window.innerHeight - size - 20;
        bear.style.left = Math.max(10, Math.floor(Math.random() * maxX)) + 'px';
        bear.style.top  = Math.max(10, Math.floor(Math.random() * maxY)) + 'px';

        bear.innerHTML = '<img src="img/thomas-transparent.png" alt="Thomas the Bear">';
        document.body.appendChild(bear);
        bearsActive = true;
    }

    function showDedication() {
        var el = document.getElementById('thomas-dedication');
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

    function showNoahModal() {
        if (document.getElementById('thomas-noah-modal')) {
            document.getElementById('thomas-noah-modal').style.display = 'flex';
            return;
        }

        var backdrop = document.createElement('div');
        backdrop.id = 'thomas-noah-modal';
        backdrop.style.cssText = 'position:fixed;top:0;left:0;width:100vw;height:100vh;' +
            'background:rgba(0,0,0,0.75);display:flex;align-items:center;' +
            'justify-content:center;z-index:99999;padding:48px 24px;box-sizing:border-box;';

        var panel = document.createElement('div');
        panel.style.cssText = 'background:#111111;color:#e0e0e0;' +
            'border:1px solid #e0e0e0;padding:56px 48px;max-width:660px;width:100%;' +
            'border-radius:4px;font-family:"Courier New",monospace;' +
            'box-shadow:0 20px 50px rgba(0,0,0,0.9);overflow-y:auto;box-sizing:border-box;';

        panel.innerHTML =
            '<h2 style="margin-top:0;margin-bottom:20px;font-size:1.1rem;letter-spacing:2px;' +
                'border-bottom:1px solid rgba(255,255,255,0.2);padding-bottom:10px;' +
                'text-align:center;text-transform:uppercase;color:#ffffff;">' +
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
                'letter-spacing:1px;text-transform:uppercase;opacity:0.5;">[ ESC ] Close</div>';

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

    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey && e.shiftKey && (e.key === 'y' || e.key === 'Y')) {
            e.preventDefault();
            if (!dedicationShown) {
                showDedication();
                dedicationShown = true;
            }
            buildBear();
        }
        if (e.ctrlKey && e.shiftKey && (e.key === 'z' || e.key === 'Z')) {
            e.preventDefault();
            showNoahModal();
        }
        if (e.key === 'Escape') {
            clearAllBears();
            closeNoahModal();
        }
        if (bearsActive && (e.key === 'x' || e.key === 'X')) {
            clearAllBears();
        }
    });

    document.addEventListener('click', function() {
        if (bearsActive) clearAllBears();
    });

})();
