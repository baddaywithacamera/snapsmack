/**
 * SNAPSMACK - Hotkey Engine
 * Alpha v0.7
 *
 * Keyboard navigation and shortcuts. F1 opens help menu, ESC closes overlays,
 * arrow keys navigate gallery (or control slider when present), number keys
 * toggle info panes.
 *
 * Double-load guard: this script is loaded both by skin-footer.php (via the
 * manifest require_scripts list) and globally by core/footer-scripts.php.
 * The guard ensures only one set of event listeners is registered.
 */

if (!window._ssCommsLoaded) {
window._ssCommsLoaded = true;

// --- HELP SYSTEM ---

document.addEventListener('DOMContentLoaded', () => {
    if (window.SNAP_DATA) {
        createHelpToast();
    }
});

// --- KEYBOARD SHORTCUTS ---

document.addEventListener('keydown', function(e) {
    if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
    if (document.getElementById('wall-canvas')) return;

    // Help menu
    if (e.key === 'F1') {
        e.preventDefault();
        toggleHelpModal();
        return;
    }

    // Close all overlays
    if (e.key === 'Escape') {
        closeAllOverlays();
        return;
    }

    // Slider detection: if a slider is active on the page, arrow keys and
    // spacebar should control the slider, not navigate between posts.
    var sliderActive = !!document.querySelector('.ss-slider');

    // Gallery navigation with arrow keys (single post view only)
    if (window.SNAP_DATA && !sliderActive) {
        if (e.key === 'ArrowLeft' && window.SNAP_DATA.prevUrl) {
            window.location.href = window.SNAP_DATA.prevUrl;
        }

        if (e.key === 'ArrowRight' && window.SNAP_DATA.nextUrl) {
            window.location.href = window.SNAP_DATA.nextUrl;
        }

        // Spacebar navigates backward through archive (newest to oldest)
        if (e.key === ' ') {
            e.preventDefault();

            if (window.SNAP_DATA.prevUrl) {
                window.location.href = window.SNAP_DATA.prevUrl;
            }
        }
    }

    // Quick toggle shortcuts for info panes
    if (e.key === '1') {
        if (window.smackdown && window.smackdown.toggleFooter) {
            window.smackdown.toggleFooter('info', null);
            scrollToFooter();
        } else {
            const infoBtn = document.getElementById('show-details');
            if (infoBtn) { infoBtn.click(); scrollToFooter(); }
        }
    }

    if (e.key === '2') {
        const commBtn = document.getElementById('show-comments');
        if (!commBtn) return;
        if (window.smackdown && window.smackdown.toggleFooter) {
            window.smackdown.toggleFooter('comments', null);
            scrollToFooter();
        } else {
            commBtn.click();
            scrollToFooter();
        }
    }

    // Download shortcut
    if (e.key === 'd' || e.key === 'D') {
        const dlBtn = document.querySelector('.snap-download-btn');
        if (dlBtn) dlBtn.click();
    }
});

// --- UTILITY FUNCTIONS ---

// getThemeColors()
// Resolves the modal's bg/text colours in the right priority order:
//   1. CSS custom properties (--bg-primary / --text-primary on :root)
//   2. Computed body backgroundColor / color
//   3. Hard fallbacks (#1a1a1a / #e0e0e0)
//
// This handles skins that set background via background-image only (e.g.
// Impact Printer's tractor-feed texture), where computed backgroundColor
// is rgba(0,0,0,0) — transparent — making the modal invisible.
function getThemeColors() {
    var rootStyle  = getComputedStyle(document.documentElement);
    var bodyStyle  = getComputedStyle(document.body);

    var bgColor   = rootStyle.getPropertyValue('--bg-primary').trim();
    var textColor = rootStyle.getPropertyValue('--text-primary').trim();

    if (!bgColor)   bgColor   = bodyStyle.backgroundColor;
    if (!textColor) textColor = bodyStyle.color;

    // If background is still transparent (no bg-color, background-image only)
    // fall back to a safe near-black so the panel is always visible.
    if (!bgColor || bgColor === 'rgba(0, 0, 0, 0)' || bgColor === 'transparent') {
        bgColor = '#1a1a1a';
    }
    if (!textColor) textColor = '#e0e0e0';

    return { bgColor: bgColor, textColor: textColor };
}

function scrollToFooter() {
    const target = document.getElementById('footer')
        || document.getElementById('htbs-info-overlay')
        || document.querySelector('.pwa-drawer-top')
        || document.querySelector('footer');
    if (target) {
        target.scrollIntoView({ behavior: 'smooth' });
    }
}

// --- HELP UI ---

function createHelpToast() {
    const isMobile = window.innerWidth <= 768 || window.matchMedia("(pointer: coarse)").matches;
    if (isMobile) return;
    if (localStorage.getItem('snapsmack_help_seen') === 'true' || window.HIDE_SNAP_HELP) return;

    const { bgColor, textColor } = getThemeColors();

    const toast = document.createElement('div');
    toast.id = 'snap-help-toast';
    toast.innerText = "PRESS F1 FOR HELP";

    toast.style.cssText = `
        position: fixed; bottom: 20px; left: 20px;
        color: ${textColor}; background: ${bgColor};
        padding: 10px 20px; border: 1px solid ${textColor};
        font-family: 'Courier Prime', monospace; font-size: 12px;
        z-index: 9999999; pointer-events: none; opacity: 0;
        transition: opacity 1s; box-shadow: 0 5px 15px rgba(0,0,0,0.5);
    `;

    document.body.appendChild(toast);
    setTimeout(() => toast.style.opacity = '1', 500);

    setTimeout(() => {
        toast.style.opacity = '0';
        localStorage.setItem('snapsmack_help_seen', 'true');
    }, 5000);

    setTimeout(() => { if(toast.parentNode) toast.parentNode.removeChild(toast); }, 6000);
}

function toggleHelpModal() {
    let modal = document.getElementById('snap-help-modal');
    if (!modal) {
        createHelpModal();
        modal = document.getElementById('snap-help-modal');
    }
    const isHidden = modal.style.display === 'none' || modal.style.display === '';
    modal.style.display = isHidden ? 'flex' : 'none';
}

function createHelpModal() {
    const { bgColor, textColor } = getThemeColors();

    // Detect available features to show relevant shortcuts
    var sliderPresent = !!document.querySelector('.ss-slider');
    var commentsEnabled = document.getElementById('show-comments') !== null;
    var commentHint = commentsEnabled ? '<strong>[ 2 ]</strong> <span>Toggle Comments</span>' : '';
    var downloadAvail = document.querySelector('.snap-download-btn') !== null;
    var downloadHint = downloadAvail ? '<strong>[ D ]</strong> <span>Download</span>' : '';

    // Arrow key labels adapt to slider vs single post context
    var leftLabel = sliderPresent ? 'Prev Slide' : 'Prev Image';
    var rightLabel = sliderPresent ? 'Next Slide' : 'Next Image';
    var spaceLabel = sliderPresent ? '' : '<strong>SPACE</strong> <span>Prev Image</span>';

    const backdrop = document.createElement('div');
    backdrop.id = 'snap-help-modal';
    backdrop.style.cssText = `
        position: fixed; top: 0; left: 0; width: 100vw; height: 100vh;
        background: rgba(0,0,0,0.75); display: flex; align-items: center;
        justify-content: center; z-index: 99999;
    `;

    const panel = document.createElement('div');
    panel.style.cssText = `
        background: ${bgColor}; color: ${textColor}; border: 1px solid ${textColor};
        padding: 40px; min-width: 320px; border-radius: 4px;
        font-family: 'Courier Prime', 'Courier New', monospace;
        text-transform: uppercase; box-shadow: 0 20px 50px rgba(0,0,0,0.9);
    `;

    panel.innerHTML = `
        <h2 style="margin-top:0; margin-bottom:20px; font-size:1.2rem; letter-spacing:2px; border-bottom:1px solid rgba(255,255,255,0.2); padding-bottom:10px; text-align:center;">System Controls</h2>
        <div style="display: grid; grid-template-columns: 100px 1fr; gap: 10px; text-align: left; font-size: 13px; width: fit-content; margin: 0 auto;">
            <strong>LEFT</strong> <span>${leftLabel}</span>
            <strong>RIGHT</strong> <span>${rightLabel}</span>
            ${spaceLabel}
            <strong>[ 1 ]</strong> <span>Toggle Info</span>
            ${commentHint}
            ${downloadHint}
            <strong>[ F1 ]</strong> <span>This Menu</span>
            <strong>[ ESC ]</strong> <span>Close</span>
        </div>
        <div style="margin-top: 20px; font-size: 10px; opacity: 0.6; text-align:center;">PRESS ESC OR CLICK OUTSIDE TO CLOSE</div>
    `;

    backdrop.appendChild(panel);
    backdrop.addEventListener('click', (e) => { if (e.target === backdrop) backdrop.style.display = 'none'; });
    backdrop.style.display = 'none';
    document.body.appendChild(backdrop);
}

function closeAllOverlays() {
    const modal = document.getElementById('snap-help-modal');
    if (modal) modal.style.display = 'none';

    if (window.smackdown && window.smackdown.closeFooter) {
        window.smackdown.closeFooter();
    }

    if (window.smackdown && window.smackdown.closeLightbox) {
        window.smackdown.closeLightbox();
    }
}

} // end double-load guard
