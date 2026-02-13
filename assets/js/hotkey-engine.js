/**
 * SnapSmack - Hotkey Engine
 * Version: 3.1 - Kill-Switch Aware
 * MASTER DIRECTIVE: Full file return. Logic only. Uses hotkey-engine.css.
 * - FIXED: Shortcut [2] now respects the global/post comment kill-switch.
 * - FIXED: Input protection prevents hotkey interference during typing.
 * - FIXED: Wall collision check ensures no conflict with 3D Wall logic.
 * - FIXED: Spacebar scroll protection (only navigates at bottom of page).
 * - FIXED: Persistence check prevents help toast from showing every time.
 */

document.addEventListener('DOMContentLoaded', () => {
    if (window.SNAP_DATA) {
        createHelpToast();
    }
});

document.addEventListener('keydown', function(e) {
    // 0. PROTECTION: Stop if user is typing or if we are on the 3D Wall
    if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
    if (document.getElementById('wall-canvas')) return;

    // 1. HELP SYSTEM (F1)
    if (e.key === 'F1') {
        e.preventDefault();
        toggleHelpModal();
        return;
    }

    // 2. ESCAPE (Master Close)
    if (e.key === 'Escape') {
        closeAllOverlays();
        return;
    }

    // 3. NAVIGATION (Arrows & Space)
    if (window.SNAP_DATA) {
        if (e.key === 'ArrowLeft' && window.SNAP_DATA.prevUrl) {
            window.location.href = window.SNAP_DATA.prevUrl;
        }
        
        if ((e.key === 'ArrowRight' || e.key === ' ') && window.SNAP_DATA.nextUrl) {
            // Only allow Spacebar to navigate if user has scrolled to the bottom
            const isAtBottom = (window.innerHeight + window.scrollY) >= document.body.offsetHeight - 5;
            
            if (e.key === 'ArrowRight' || isAtBottom) {
                e.preventDefault();
                window.location.href = window.SNAP_DATA.nextUrl;
            }
        }
    }

    // 4. SHORTCUTS (1 & 2)
    if (e.key === '1') {
        const infoBtn = document.getElementById('show-details');
        if (infoBtn) {
            infoBtn.click();
            scrollToFooter();
        }
    }

    // [MODIFIED] Shortcut 2: Only fire if the button exists (is enabled)
    if (e.key === '2') {
        const commBtn = document.getElementById('show-comments');
        if (commBtn) {
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
    // 1. SILENCE ON MOBILE: Detect touch-primary or small screens
    const isMobile = window.innerWidth <= 768 || window.matchMedia("(pointer: coarse)").matches;
    if (isMobile) return;

    // 2. PERSISTENCE: Check if already acknowledged or flagged by header
    if (localStorage.getItem('snapsmack_help_seen') === 'true' || window.HIDE_SNAP_HELP) return;

    const style = window.getComputedStyle(document.body);
    const bgColor = style.backgroundColor; 
    const textColor = style.color;        
    
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
    
    // Hide and Mark as Seen so it never bugs them again
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
    modal.style.display = (modal.style.display === 'none' || modal.style.display === '') ? 'block' : 'none';
}

function createHelpModal() {
    const style = window.getComputedStyle(document.body);
    const bgColor = style.backgroundColor;
    const textColor = style.color;
    
    // Logic check: If comments are disabled, we hide the [2] hint from the help menu
    const commentsEnabled = document.getElementById('show-comments') !== null;
    const commentHint = commentsEnabled ? '<strong>[ 2 ]</strong> <span>Toggle Comments</span>' : '';

    const modal = document.createElement('div');
    modal.id = 'snap-help-modal';
    modal.style.backgroundColor = bgColor;
    modal.style.color = textColor;
    modal.style.borderColor = textColor;
    
    modal.innerHTML = `
        <h2>System Controls</h2>
        <div class="help-grid" style="display: grid; grid-template-columns: 100px 1fr; gap: 10px; text-align: left; font-size: 13px; width: fit-content; margin: 0 auto;">
            <strong>LEFT</strong> <span>Prev Image</span>
            <strong>RIGHT</strong> <span>Next Image</span>
            <strong>SPACE</strong> <span>Next Image</span>
            <strong>[ 1 ]</strong> <span>Toggle Info</span>
            ${commentHint}
            <strong>F1</strong> <span>Menu</span>
            <strong>ESC</strong> <span>Close</span>
        </div>
        <div class="help-footer" style="margin-top: 20px; font-size: 10px; opacity: 0.6;">PRESS ESC TO CLOSE</div>
    `;
    
    document.body.appendChild(modal);
}

function closeAllOverlays() {
    const modal = document.getElementById('snap-help-modal');
    if (modal) modal.style.display = 'none';

    const activeOverlays = document.querySelectorAll('.footer-pane.active, .drawer.open');
    activeOverlays.forEach(el => el.classList.remove('active', 'open'));
}