/**
 * SnapSmack - Hotkey Engine
 * Version: 3.3 - X Key Added, Unified Close
 * MASTER DIRECTIVE: Full file return. Logic only.
 */

document.addEventListener('DOMContentLoaded', () => {
    if (window.SNAP_DATA) {
        createHelpToast();
    }
});

document.addEventListener('keydown', function(e) {
    if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
    if (document.getElementById('wall-canvas')) return;

    // HELP
    if (e.key === 'h' || e.key === 'H') {
        e.preventDefault();
        toggleHelpModal();
        return;
    }

    // CLOSE — X or Escape
    if (e.key === 'x' || e.key === 'X' || e.key === 'Escape') {
        closeAllOverlays();
        return;
    }

    // NAVIGATION
    if (window.SNAP_DATA) {
        if (e.key === 'ArrowLeft' && window.SNAP_DATA.prevUrl) {
            window.location.href = window.SNAP_DATA.prevUrl;
        }

        if (e.key === 'ArrowRight' && window.SNAP_DATA.nextUrl) {
            window.location.href = window.SNAP_DATA.nextUrl;
        }

        if (e.key === ' ') {
            e.preventDefault();
            const isAtBottom = (window.innerHeight + window.scrollY) >= document.body.offsetHeight - 5;

            // Space goes backward through archive (newest to oldest)
            if (window.SNAP_DATA.prevUrl) {
                window.location.href = window.SNAP_DATA.prevUrl;
            }
        }
    }

    // SHORTCUTS
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
});

function scrollToFooter() {
    const footer = document.getElementById('footer') || document.querySelector('footer');
    if (footer) {
        footer.scrollIntoView({ behavior: 'smooth' });
    }
}

function createHelpToast() {
    const isMobile = window.innerWidth <= 768 || window.matchMedia("(pointer: coarse)").matches;
    if (isMobile) return;
    if (localStorage.getItem('snapsmack_help_seen') === 'true' || window.HIDE_SNAP_HELP) return;

    const style = window.getComputedStyle(document.body);
    const bgColor = style.backgroundColor; 
    const textColor = style.color;        
    
    const toast = document.createElement('div');
    toast.id = 'snap-help-toast';
    toast.innerText = "PRESS H FOR HELP";
    
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
    const style = window.getComputedStyle(document.body);
    const bgColor = style.backgroundColor;
    const textColor = style.color;
    
    const commentsEnabled = document.getElementById('show-comments') !== null;
    const commentHint = commentsEnabled ? '<strong>[ 2 ]</strong> <span>Toggle Comments</span>' : '';

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
            <strong>LEFT</strong> <span>Prev Image</span>
            <strong>RIGHT</strong> <span>Next Image</span>
            <strong>SPACE</strong> <span>Next / Prev Image</span>
            <strong>[ 1 ]</strong> <span>Toggle Info</span>
            ${commentHint}
            <strong>[ H ]</strong> <span>This Menu</span>
            <strong>[ X ]</strong> <span>Close</span>
        </div>
        <div style="margin-top: 20px; font-size: 10px; opacity: 0.6; text-align:center;">PRESS X OR CLICK OUTSIDE TO CLOSE</div>
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
