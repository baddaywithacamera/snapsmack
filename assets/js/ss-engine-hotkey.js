/**
 * SnapSmack - Hotkey Engine
 * Version: 3.2 - Renamed ss-engine-hotkey + Download Key [D]
 * MASTER DIRECTIVE: Full file return. Logic only. Uses ss-engine-hotkey.css.
 * Keys: H = help, X = close, 1 = info, 2 = comments, D = download, arrows = navigate
 */

document.addEventListener('DOMContentLoaded', () => {
    if (window.SNAP_DATA) createHelpToast();
});

document.addEventListener('keydown', function(e) {
    if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;

    if (e.key === 'h' || e.key === 'H') { toggleHelpModal(); return; }
    if (e.key === 'x' || e.key === 'X') { closeAllOverlays(); return; }
    if (e.key === 'Escape') { closeAllOverlays(); return; }

    if (window.SNAP_DATA) {
        if (e.key === 'ArrowLeft' && window.SNAP_DATA.prevUrl)
            window.location.href = window.SNAP_DATA.prevUrl;
        if ((e.key === 'ArrowRight' || e.key === ' ') && window.SNAP_DATA.nextUrl) {
            e.preventDefault();
            window.location.href = window.SNAP_DATA.nextUrl;
        }
    }

    if (e.key === '1') {
        const btn = document.getElementById('show-details');
        if (btn) { btn.click(); scrollToFooter(); }
    }
    if (e.key === '2') {
        const btn = document.getElementById('show-comments');
        if (btn) { btn.click(); scrollToFooter(); }
    }
    if (e.key === 'd' || e.key === 'D') {
        const link = document.getElementById('download-link');
        if (link) { e.preventDefault(); link.click(); }
    }
});

function scrollToFooter() {
    const footer = document.getElementById('footer');
    if (footer) footer.scrollIntoView({ behavior: 'smooth' });
}

function toggleHelpModal() {
    const existing = document.getElementById('snap-help-backdrop');
    if (existing) {
        closeAllOverlays();
        return;
    }

    // Build the backdrop — fullscreen, sits above everything
    const backdrop = document.createElement('div');
    backdrop.id = 'snap-help-backdrop';
    backdrop.style.cssText = `
        position: fixed;
        top: 0; left: 0;
        width: 100vw; height: 100vh;
        background: rgba(0,0,0,0.85);
        z-index: 2147483647;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
    `;

    // Build the modal panel inside the backdrop
    const panel = document.createElement('div');
    panel.id = 'snap-help-modal';
    panel.style.cssText = `
        cursor: default;
        pointer-events: auto;
        background: #0a0a0a;
        color: #cccccc;
        border: 1px solid #cccccc;
        padding: 30px 40px;
        font-family: 'Courier Prime', 'Courier New', monospace;
        font-size: 0.85rem;
        text-transform: uppercase;
        letter-spacing: 1px;
        box-shadow: 0 20px 60px rgba(0,0,0,0.9);
        min-width: 300px;
        line-height: 1;
    `;

    const commentsEnabled = !!document.getElementById('show-comments');
    const downloadEnabled = !!document.getElementById('download-link');

    panel.innerHTML = `
        <div style="font-size:1rem; letter-spacing:3px; margin-bottom:20px; padding-bottom:15px; border-bottom:1px solid #333; text-align:center;">SYSTEM CONTROLS</div>
        <table style="border-collapse:collapse; width:100%;">
            <tr><td style="padding:7px 30px 7px 0; color:#666;">&#8592; LEFT</td><td style="padding:7px 0; color:#aaa;">Previous Image</td></tr>
            <tr><td style="padding:7px 30px 7px 0; color:#666;">&#8594; RIGHT</td><td style="padding:7px 0; color:#aaa;">Next Image</td></tr>
            <tr><td style="padding:7px 30px 7px 0; color:#666;">SPACE</td><td style="padding:7px 0; color:#aaa;">Next Image</td></tr>
            <tr><td style="padding:7px 30px 7px 0; color:#666;">[ 1 ]</td><td style="padding:7px 0; color:#aaa;">Toggle Info</td></tr>
            ${commentsEnabled ? `<tr><td style="padding:7px 30px 7px 0; color:#666;">[ 2 ]</td><td style="padding:7px 0; color:#aaa;">Toggle Comments</td></tr>` : ''}
            ${downloadEnabled ? `<tr><td style="padding:7px 30px 7px 0; color:#666;">[ D ]</td><td style="padding:7px 0; color:#aaa;">Download Hi-Res</td></tr>` : ''}
            <tr><td style="padding:7px 30px 7px 0; color:#666;">[ H ]</td><td style="padding:7px 0; color:#aaa;">This Menu</td></tr>
            <tr><td style="padding:7px 30px 7px 0; color:#666;">[ X ]</td><td style="padding:7px 0; color:#aaa;">Close</td></tr>
        </table>
        <div style="margin-top:20px; font-size:0.7rem; text-align:center; color:#444; letter-spacing:2px;">PRESS X OR CLICK OUTSIDE TO CLOSE</div>
    `;

    backdrop.appendChild(panel);

    // Click backdrop (outside panel) to close
    backdrop.addEventListener('click', function(e) {
        if (e.target === backdrop) closeAllOverlays();
    });

    // Append directly to body — nothing can trap a 100vw/100vh fixed div
    document.body.appendChild(backdrop);
}

function closeAllOverlays() {
    // Close help backdrop
    const backdrop = document.getElementById('snap-help-backdrop');
    if (backdrop) backdrop.parentNode.removeChild(backdrop);

    // Close lightbox (script.js creates a fixed div with z-index 9999)
    document.querySelectorAll('body > div[style*="position: fixed"][style*="z-index: 9999"]').forEach(el => {
        el.style.opacity = '0';
        setTimeout(() => { if (el.parentNode) el.parentNode.removeChild(el); }, 200);
    });

    // Close footer via smackdown bridge
    if (window.smackdown && window.smackdown.close) {
        window.smackdown.close();
    }
}

function createHelpToast() {
    if (window.innerWidth <= 768) return;
    if (localStorage.getItem('snapsmack_help_seen') === 'true') return;

    const style = window.getComputedStyle(document.body);
    const toast = document.createElement('div');
    toast.id = 'snap-help-toast';
    toast.innerText = 'PRESS H FOR HELP';
    toast.style.cssText = `
        position: fixed; bottom: 20px; left: 20px;
        color: ${style.color}; background: ${style.backgroundColor};
        padding: 10px 20px; border: 1px solid ${style.color};
        font-family: 'Courier Prime', monospace; font-size: 12px;
        z-index: 9999999; pointer-events: none; opacity: 0;
        transition: opacity 1s; box-shadow: 0 5px 15px rgba(0,0,0,0.5);
        text-transform: uppercase; letter-spacing: 1px;
    `;
    document.body.appendChild(toast);
    setTimeout(() => toast.style.opacity = '1', 500);
    setTimeout(() => {
        toast.style.opacity = '0';
        localStorage.setItem('snapsmack_help_seen', 'true');
    }, 5000);
    setTimeout(() => { if (toast.parentNode) toast.parentNode.removeChild(toast); }, 6000);
}
