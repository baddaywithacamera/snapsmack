/**
 * SNAPSMACK - Scroll-to-Top Engine
 *
 * Injects a floating back-to-top button that appears after scrolling
 * past a configurable threshold. Styled to blend with any skin via
 * semi-transparent dark backdrop with white icon.
 *
 * The button smoothly scrolls to the top of the page on click.
 * Automatically hidden when near the top.
 *
 * Guards against double-loading with internal flag.
 */

if (!window._ssScrollTopLoaded) {
window._ssScrollTopLoaded = true;

function _ssScrollTopInit() {

    // --- CONFIGURATION ---
    const threshold = (window.SMACK_CONFIG && window.SMACK_CONFIG.scrollTop && window.SMACK_CONFIG.scrollTop.threshold)
        ? parseInt(window.SMACK_CONFIG.scrollTop.threshold, 10)
        : 400;   // px scrolled before button appears

    const position = (window.SMACK_CONFIG && window.SMACK_CONFIG.scrollTop && window.SMACK_CONFIG.scrollTop.position)
        ? window.SMACK_CONFIG.scrollTop.position
        : 'right';  // 'left' or 'right'

    // --- CREATE BUTTON ---
    const btn = document.createElement('button');
    btn.className = 'ss-scroll-top-btn';
    btn.setAttribute('aria-label', 'Scroll to top');
    btn.setAttribute('title', 'Back to top');

    // Up arrow SVG
    btn.innerHTML = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="18 15 12 9 6 15"/></svg>';

    // --- INLINE STYLES ---
    // Matches the social dock / download button family:
    // 48px circles, rgba(0,0,0,0.7), 2px border, scale(1.1) hover
    const positionRule = position === 'left' ? 'left:16px' : 'right:16px';
    btn.style.cssText = [
        'position:fixed', 'bottom:16px', positionRule,
        'z-index:9000', 'width:48px', 'height:48px',
        'display:flex', 'align-items:center', 'justify-content:center',
        'background:rgba(0,0,0,0.7)', 'border:2px solid rgba(255,255,255,0.3)',
        'border-radius:50%', 'color:#fff', 'cursor:pointer',
        'opacity:0', 'visibility:hidden',
        'transition:opacity 0.3s ease, visibility 0.3s ease, transform 0.15s ease',
        'transform:translateY(10px)',
        'padding:0', 'outline:none',
    ].join(';');

    document.body.appendChild(btn);

    // --- HOVER EFFECT ---
    // Matches .dock-link:hover / .snap-download-btn:hover
    btn.addEventListener('mouseenter', () => {
        btn.style.background = 'rgba(0,0,0,0.9)';
        btn.style.borderColor = 'rgba(255,255,255,0.7)';
        btn.style.transform = visible ? 'scale(1.1)' : 'translateY(10px)';
    });
    btn.addEventListener('mouseleave', () => {
        btn.style.background = 'rgba(0,0,0,0.7)';
        btn.style.borderColor = 'rgba(255,255,255,0.3)';
        btn.style.transform = visible ? 'translateY(0)' : 'translateY(10px)';
    });

    // --- CLICK HANDLER ---
    btn.addEventListener('click', () => {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });

    // --- SCROLL LISTENER ---
    let visible = false;
    let ticking = false;

    function updateVisibility() {
        const scrollY = window.pageYOffset || document.documentElement.scrollTop;
        const shouldShow = scrollY > threshold;

        if (shouldShow && !visible) {
            visible = true;
            btn.style.opacity = '1';
            btn.style.visibility = 'visible';
            btn.style.transform = 'translateY(0)';
        } else if (!shouldShow && visible) {
            visible = false;
            btn.style.opacity = '0';
            btn.style.visibility = 'hidden';
            btn.style.transform = 'translateY(10px)';
        }
        ticking = false;
    }

    window.addEventListener('scroll', () => {
        if (!ticking) {
            requestAnimationFrame(updateVisibility);
            ticking = true;
        }
    }, { passive: true });

    // Initial check (page might be scrolled on load / back-nav)
    updateVisibility();
}

// Scripts load at end of <body> — DOMContentLoaded may have already fired.
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', _ssScrollTopInit);
} else {
    _ssScrollTopInit();
}

} // end double-load guard
