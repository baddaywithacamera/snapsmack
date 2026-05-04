/**
 * SNAPSMACK - Fullscreen Engine
 *
 * True browser fullscreen (requestFullscreen API) for distraction-free
 * image viewing. Works alongside the lightbox engine or standalone.
 *
 * Behaviour:
 *   - Adds a small fullscreen toggle button to the image container
 *   - Double-click on the main post image enters fullscreen
 *   - ESC or clicking the toggle exits fullscreen
 *   - When the lightbox is open, 'F' key toggles fullscreen on the
 *     lightbox overlay
 *
 * Targets: .post-image, .pg-post-image, #ss-lightbox-overlay
 *
 * Guards against double-loading with internal flag.
 */

if (!window._ssFullscreenLoaded) {
window._ssFullscreenLoaded = true;

function _ssFullscreenInit() {

    // --- FULLSCREEN API DETECTION ---
    const fsEnabled = document.fullscreenEnabled
        || document.webkitFullscreenEnabled
        || document.mozFullScreenEnabled
        || document.msFullscreenEnabled;

    if (!fsEnabled) return; // browser doesn't support it

    // --- HELPERS ---
    function enterFullscreen(el) {
        if (el.requestFullscreen)            el.requestFullscreen();
        else if (el.webkitRequestFullscreen) el.webkitRequestFullscreen();
        else if (el.mozRequestFullScreen)    el.mozRequestFullScreen();
        else if (el.msRequestFullscreen)     el.msRequestFullscreen();
    }

    function exitFullscreen() {
        if (document.exitFullscreen)            document.exitFullscreen();
        else if (document.webkitExitFullscreen) document.webkitExitFullscreen();
        else if (document.mozCancelFullScreen)  document.mozCancelFullScreen();
        else if (document.msExitFullscreen)     document.msExitFullscreen();
    }

    function isFullscreen() {
        return !!(document.fullscreenElement
            || document.webkitFullscreenElement
            || document.mozFullScreenElement
            || document.msFullscreenElement);
    }

    function toggleFullscreen(el) {
        if (isFullscreen()) {
            exitFullscreen();
        } else {
            enterFullscreen(el);
        }
    }

    // --- CREATE TOGGLE BUTTON ---
    function createToggleBtn() {
        const btn = document.createElement('button');
        btn.className = 'ss-fullscreen-btn';
        btn.setAttribute('aria-label', 'Toggle fullscreen');
        btn.setAttribute('title', 'Fullscreen (F)');
        btn.innerHTML = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 3H5a2 2 0 00-2 2v3m18 0V5a2 2 0 00-2-2h-3m0 18h3a2 2 0 002-2v-3M3 16v3a2 2 0 002 2h3"/></svg>';

        // Inline styles — no external CSS needed
        // Matches dock/download family colours but smaller (overlay on image)
        btn.style.cssText = [
            'position:absolute', 'bottom:12px', 'right:12px',
            'z-index:50', 'width:36px', 'height:36px',
            'display:flex', 'align-items:center', 'justify-content:center',
            'background:rgba(0,0,0,0.7)', 'border:2px solid rgba(255,255,255,0.3)',
            'border-radius:50%', 'color:#fff', 'cursor:pointer',
            'opacity:0', 'transition:opacity 0.2s ease, transform 0.15s ease',
            'padding:0', 'outline:none',
        ].join(';');

        return btn;
    }

    // --- ATTACH TO POST IMAGE ---
    const postImage = document.querySelector('.post-image, .pg-post-image');
    if (postImage) {
        // Make sure the parent is positioned for absolute button placement
        const wrapper = postImage.parentElement;
        if (wrapper && getComputedStyle(wrapper).position === 'static') {
            wrapper.style.position = 'relative';
        }

        const btn = createToggleBtn();
        wrapper.appendChild(btn);

        // Show on hover (matches dock-link hover pattern)
        wrapper.addEventListener('mouseenter', () => { btn.style.opacity = '1'; });
        wrapper.addEventListener('mouseleave', () => { btn.style.opacity = '0'; btn.style.transform = 'scale(1)'; });
        btn.addEventListener('mouseenter', () => { btn.style.transform = 'scale(1.1)'; btn.style.background = 'rgba(0,0,0,0.9)'; btn.style.borderColor = 'rgba(255,255,255,0.7)'; });
        btn.addEventListener('mouseleave', () => { btn.style.transform = 'scale(1)'; btn.style.background = 'rgba(0,0,0,0.7)'; btn.style.borderColor = 'rgba(255,255,255,0.3)'; });

        // Click toggle
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            toggleFullscreen(wrapper);
        });

        // Double-click on image
        postImage.addEventListener('dblclick', (e) => {
            e.preventDefault();
            toggleFullscreen(wrapper);
        });

        // Style the image in fullscreen
        const onFsChange = () => {
            if (isFullscreen()) {
                wrapper.style.background = '#000';
                postImage.style.cssText += ';max-height:100vh;max-width:100vw;object-fit:contain;margin:auto;display:block;';
            } else {
                wrapper.style.background = '';
                postImage.style.cssText = postImage.style.cssText
                    .replace(/max-height:[^;]+;?/g, '')
                    .replace(/max-width:[^;]+;?/g, '')
                    .replace(/object-fit:[^;]+;?/g, '')
                    .replace(/margin:[^;]+;?/g, '')
                    .replace(/display:block;?/g, '');
            }
        };
        document.addEventListener('fullscreenchange', onFsChange);
        document.addEventListener('webkitfullscreenchange', onFsChange);
    }

    // --- 'F' KEY FOR LIGHTBOX FULLSCREEN ---
    document.addEventListener('keydown', (e) => {
        if (e.key === 'f' || e.key === 'F') {
            // Don't capture if user is typing in an input
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;

            const lightbox = document.getElementById('ss-lightbox-overlay');
            if (lightbox) {
                e.preventDefault();
                toggleFullscreen(lightbox);
                return;
            }

            // If on a post page with an image, fullscreen that
            const img = document.querySelector('.post-image, .pg-post-image');
            if (img && img.parentElement) {
                e.preventDefault();
                toggleFullscreen(img.parentElement);
            }
        }
    });

    // Expose for other engines
    window.smackdown = window.smackdown || {};
    window.smackdown.toggleFullscreen = toggleFullscreen;
}

// Scripts load at end of <body> — DOMContentLoaded may have already fired.
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', _ssFullscreenInit);
} else {
    _ssFullscreenInit();
}

} // end double-load guard
// EOF
