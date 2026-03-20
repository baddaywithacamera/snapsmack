/**
 * SNAPSMACK - Center Overlay Controller
 * Alpha v0.7.5
 *
 * Shared overlay engine for skins that use the HTBS-style center-expand
 * info/comments panel (Galleria, Hip to be Square).
 * Replaces inline <script> blocks that were duplicated in both layout.php files.
 *
 * Depends on DOM elements:
 *   #htbs-info-overlay   — overlay wrapper
 *   .htbs-overlay-backdrop
 *   .htbs-overlay-close
 *   .htbs-tab[data-pane]
 *   .htbs-pane#htbs-pane-{name}
 *   #show-details        — nav bar info button
 *   #show-comments       — nav bar comments button
 *   .htbs-filmstrip-item.active — auto-scroll target
 */
document.addEventListener('DOMContentLoaded', function() {

    // --- Filmstrip auto-scroll ---
    var active = document.querySelector('.htbs-filmstrip-item.active');
    if (active) active.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });

    // --- Overlay elements ---
    var overlay  = document.getElementById('htbs-info-overlay');
    var backdrop = overlay ? overlay.querySelector('.htbs-overlay-backdrop') : null;
    var closeBtn = overlay ? overlay.querySelector('.htbs-overlay-close') : null;
    var tabs     = overlay ? overlay.querySelectorAll('.htbs-tab') : [];
    var panes    = overlay ? overlay.querySelectorAll('.htbs-pane') : [];
    var btnInfo  = document.getElementById('show-details');
    var btnComm  = document.getElementById('show-comments');

    function showPane(name) {
        for (var i = 0; i < tabs.length; i++) {
            tabs[i].classList.toggle('active', tabs[i].getAttribute('data-pane') === name);
        }
        for (var j = 0; j < panes.length; j++) {
            panes[j].classList.toggle('active', panes[j].id === 'htbs-pane-' + name);
        }
    }

    function openOverlay(pane) {
        if (!overlay) return;
        showPane(pane);
        overlay.classList.add('open');
    }

    function closeOverlay() {
        if (!overlay) return;
        overlay.classList.remove('open');
    }

    function isOpen() {
        return overlay && overlay.classList.contains('open');
    }

    function activePane() {
        for (var i = 0; i < tabs.length; i++) {
            if (tabs[i].classList.contains('active')) return tabs[i].getAttribute('data-pane');
        }
        return null;
    }

    // --- Tab clicks ---
    for (var i = 0; i < tabs.length; i++) {
        tabs[i].addEventListener('click', function() {
            var pane = this.getAttribute('data-pane');
            if (pane) showPane(pane);
        });
    }

    // --- Close triggers ---
    if (closeBtn) closeBtn.addEventListener('click', closeOverlay);
    if (backdrop) backdrop.addEventListener('click', closeOverlay);

    // --- Nav bar button intercepts ---
    if (btnInfo) {
        btnInfo.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopImmediatePropagation();
            if (isOpen() && activePane() === 'info') { closeOverlay(); }
            else { openOverlay('info'); }
        });
    }

    if (btnComm) {
        btnComm.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopImmediatePropagation();
            if (isOpen() && activePane() === 'comments') { closeOverlay(); }
            else { openOverlay('comments'); }
        });
    }

    // --- Smackdown API bridge (keyboard engine compat) ---
    window.smackdown = window.smackdown || {};
    window.smackdown.toggleFooter = function(target, e) {
        if (e) e.preventDefault();
        if (target === 'info') {
            if (isOpen() && activePane() === 'info') closeOverlay();
            else openOverlay('info');
        } else if (target === 'comments') {
            if (isOpen() && activePane() === 'comments') closeOverlay();
            else openOverlay('comments');
        }
    };
    window.smackdown.closeFooter = closeOverlay;
});
