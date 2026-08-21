/**
 * OH SNAP! — srcdoc preview renderer
 * v0.1.0
 *
 * Builds a self-contained HTML document inside the preview iframe using the
 * skin's CSS and real post/media data fetched from the API. CSS variable
 * overrides are injected into a dedicated <style> block, so every control
 * change is instant without reloading the iframe.
 *
 * Architecture:
 *   - iframe sandbox: allow-same-origin allow-scripts
 *   - srcdoc content is generated once on connect, then kept live via
 *     contentDocument style manipulation
 *   - Images load cross-origin (img src to live site) — that's fine
 *   - Three view templates: post, archive, landing
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


const OhSnapPreview = (() => {

    // --- STATE ---

    let _frame        = null;   // The <iframe> element
    let _skinData     = null;   // skinData from API
    let _postsData    = null;   // posts from API
    let _baseUrl      = '';     // Connected site base URL
    let _currentView  = 'post'; // 'post' | 'archive' | 'landing'
    let _ready        = false;
    let _project      = null;

    // --- PUBLIC ---

    /**
     * Initialise the preview with all required data.
     * @param {HTMLIFrameElement} frame
     * @param {Object}  skinData   From api.skin()
     * @param {Object}  postsData  From api.posts()
     * @param {string}  baseUrl    Connected site URL (for image hrefs)
     */
    function init(frame, skinData, postsData, baseUrl) {
        _frame       = frame;
        _skinData    = skinData;
        _postsData   = postsData;
        _baseUrl     = baseUrl.replace(/\/$/, '');
        _ready       = false;

        _buildSrcdoc('post');

        // Once the iframe loads, mark ready and apply any pending overrides
        frame.addEventListener('load', () => {
            _ready = true;
            // Ensure the overrides style block exists
            _ensureOverrideBlock();
        }, { once: true });
    }

    function initOffline(frame, project) {
        _project = project;
        _frame = frame;
        _skinData = { skin_slug: project.skin_slug, manifest: { name: project.name }, style_css: project.base_css || _offlineShellCss(), css_variables: {} };
        _postsData = { posts: project.preview_content?.posts || [] };
        _baseUrl = '';
        _currentView = 'post';
        _ready = false;
        _buildSrcdoc('post');
        frame.addEventListener('load', () => { _ready = true; _ensureOverrideBlock(); }, { once: true });
    }

    function setProject(project) {
        _project = project;
        if (!_frame || !_skinData) return;
        if (project.base_css) _skinData.style_css = project.base_css;
        _postsData = { posts: project.preview_content?.posts || [] };
        if (_ready) _buildSrcdoc(_currentView);
    }

    /**
     * Apply CSS variable overrides to the live preview.
     * Can be called any time after init(); queues if iframe not ready yet.
     * @param {Object} overrides  { '--var-name': 'value', ... }
     */
    function applyOverrides(overrides) {
        if (!_frame) return;

        const css = _buildOverrideCss(overrides);

        if (_ready) {
            _writeOverrideBlock(css);
        } else {
            // The load event hasn't fired — patch srcdoc before load
            const doc = _frame.contentDocument;
            if (doc) {
                const el = doc.getElementById('oh-snap-overrides');
                if (el) el.textContent = css;
            }
        }
    }

    /**
     * Switch the preview between post, archive, and landing views.
     * @param {string} view  'post' | 'archive' | 'landing'
     */
    function switchView(view) {
        if (view === _currentView) return;
        _currentView = view;
        _ready = false;
        _buildSrcdoc(view);
        _frame.addEventListener('load', () => {
            _ready = true;
            _ensureOverrideBlock();
        }, { once: true });
    }

    // --- SRCDOC BUILDERS ---

    function _buildSrcdoc(view) {
        const css         = _skinData?.style_css || '';
        const siteName    = _skinData?.manifest?.name || 'Preview';
        const overrideCss = _buildOverrideCss(controlsGetOverrides());

        let bodyHtml = '';
        if (view === 'post')    bodyHtml = _buildPostView();
        if (view === 'archive') bodyHtml = _buildArchiveView();
        if (view === 'landing') bodyHtml = _buildLandingView();

        const doc = `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>${_escHtml(siteName)}</title>
<style id="oh-snap-skin-css">
/* ── Skin Base CSS ── */
${css}
</style>
<style id="oh-snap-overrides">
/* ── Oh Snap! Overrides ── */
${overrideCss}
</style>
<style id="oh-snap-user-css">${_project?.user_css || ''}</style>
<style id="oh-snap-chrome">
/* ── Preview chrome resets (don't interfere with skin) ── */
* { box-sizing: border-box; }
img { display: block; max-width: 100%; }
.oh-snap-preview-bar {
    display: none !important;
}
/* Prevent full-height scroll-lock skins from breaking the preview */
html, body { height: auto !important; overflow: auto !important; }
#page-wrapper { height: auto !important; }
#scroll-stage { overflow: visible !important; flex: none !important; }
</style>
</head>
<body>
${bodyHtml}
</body>
</html>`;

        _frame.srcdoc = doc;
        document.getElementById('preview-placeholder')?.classList.add('hidden');
    }

    function _buildPostView() {
        if (_project?.mode === 'GRAMOFSMACK') return _buildGramView();
        if (_project?.mode === 'SMACKTALK') return _buildLongformView();
        if (_project?.mode === 'FEDISTRUCTURE') return _buildFedistructureView();
        const post = _postsData?.posts?.[0];
        const siteName = _project?.preview_content?.site_name || 'Preview Site';
        const tagline  = '';

        const imgHtml = post?.cover_url
            ? `<img src="${_escHtml(post.cover_url)}" class="post-image" alt="${_escHtml(post.title || '')}">`
            : _placeholderImage(post?.width || 1200, post?.height || 800, post?.title || 'Sample photograph', post?.colour);

        const title       = _escHtml(post?.title || 'Untitled Post');
        const description = post?.description
            ? `<p class="description">${_escHtml(post.description)}</p>`
            : '<p class="description">Your caption will appear here.</p>';
        const dateStr = post?.created_at
            ? new Date(post.created_at).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })
            : new Date().toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });

        return `
<div id="page-wrapper">
  <div id="header">
    <div class="inside">
      <div class="logo-area"><a href="#"><span class="site-title-text">${_escHtml(siteName)}</span></a></div>
      <nav><ul class="nav-menu">
        <li><a href="#">Archive</a></li>
        <li><a href="#">About</a></li>
      </ul></nav>
    </div>
  </div>

  <div id="scroll-stage">
    <div id="photobox">
      <div class="post-image-wrap">${imgHtml}</div>
    </div>

    <div id="infobox">
      <div class="inside">
        <span class="post-date">${dateStr}</span>
        <span class="post-nav">← Previous &nbsp; Next →</span>
      </div>
    </div>

    <div class="static-transmission">
      <div class="description">
        <h1 class="photo-title-footer">${title}</h1>
        ${description}
      </div>
    </div>

    <div id="system-footer">
      <div class="inside">
        <p id="sig-text">Powered by SnapSmack</p>
      </div>
    </div>
  </div>
</div>`;
    }

    function _sharedHeader() {
        return `<div id="header"><div class="inside"><div class="logo-area"><a href="#"><span class="site-title-text">${_escHtml(_project?.preview_content?.site_name || 'Preview Site')}</span></a></div><nav><ul class="nav-menu"><li><a href="#">Archive</a></li><li><a href="#">About</a></li></ul></nav></div></div>`;
    }

    function _buildGramView() {
        const posts = _postsData?.posts || [];
        const images = posts.slice(0, 5).map(p => _placeholderImage(p.width || 900, p.height || 900, p.title, p.colour)).join('');
        return `<div id="page-wrapper">${_sharedHeader()}<div id="scroll-stage"><div class="gram-strip">${images}</div><div class="static-transmission"><h1 class="photo-title-footer">${_escHtml(posts[0]?.title || 'Carousel')}</h1><p>${_escHtml(posts[0]?.description || 'A multi-image story.')}</p></div>${_footer()}</div></div>`;
    }

    function _buildLongformView() {
        const p = _postsData?.posts?.[0] || {};
        return `<div id="page-wrapper">${_sharedHeader()}<div id="scroll-stage"><article class="longform"><h1 class="static-page-title">${_escHtml(p.title || 'A Longer Story')}</h1><p class="post-date">August 21, 2026</p>${_placeholderImage(1400,700,p.title || 'Feature image',p.colour)}<p>${_escHtml(p.description || 'The opening paragraph of a long-form post.')}</p><p>Photography rewards patience. This fixture includes enough text to test measure, rhythm, headings, links, and the way a design behaves when the image is no longer the only thing on the page.</p><h2>Second movement</h2><p>Every generated skin must remain readable when the story continues below the fold.</p></article>${_footer()}</div></div>`;
    }

    function _buildFedistructureView() {
        const posts = _postsData?.posts || [];
        const cards = posts.map(p => `<article class="fedi-card"><strong>@photographer@example.social</strong><p>${_escHtml(p.description)}</p>${_placeholderImage(1200,800,p.title,p.colour)}<p><a href="#">View at origin ↗</a></p></article>`).join('');
        return `<div id="page-wrapper">${_sharedHeader()}<div id="scroll-stage">${cards}${_footer()}</div></div>`;
    }

    function _footer() { return `<div id="system-footer"><div class="inside"><p id="sig-text">Powered by SnapSmack</p></div></div>`; }

    function _buildArchiveView() {
        const posts = _postsData?.posts?.slice(0, 12) || [];

        const thumbs = posts.length ? posts.map(p => {
            const img = p.thumb_url
                ? `<img src="${_escHtml(p.thumb_url)}" alt="${_escHtml(p.title || '')}">`
                : _placeholderImage(300, 300, p.title || '', p.colour);
            return `<a href="#" class="thumb-link">${img}</a>`;
        }).join('\n') : Array(9).fill(0).map((_, i) =>
            `<a href="#" class="thumb-link">${_placeholderImage(300, 300, `Sample ${i + 1}`)}</a>`
        ).join('\n');

        return `
<div id="page-wrapper">
  <div id="header">
    <div class="inside">
      <div class="logo-area"><a href="#"><span class="site-title-text">${_escHtml(_project?.preview_content?.site_name || 'Preview Site')}</span></a></div>
      <nav><ul class="nav-menu">
        <li><a href="#">Archive</a></li>
        <li><a href="#">About</a></li>
      </ul></nav>
    </div>
  </div>

  <div id="scroll-stage">
    <div id="browse-grid" class="square-grid" style="--grid-cols:4;">
      ${thumbs}
    </div>

    <div id="system-footer">
      <div class="inside">
        <p id="sig-text">Powered by SnapSmack</p>
      </div>
    </div>
  </div>
</div>`;
    }

    function _buildLandingView() {
        return `
<div id="page-wrapper">
  <div id="header">
    <div class="inside">
      <div class="logo-area"><a href="#"><span class="site-title-text">${_escHtml(_project?.preview_content?.site_name || 'Preview Site')}</span></a></div>
    </div>
  </div>

  <div id="scroll-stage">
    <div class="static-transmission">
      <div class="static-content">
        <h1 class="static-page-title">Coming Soon</h1>
        <p>A new photography site is on the way. Check back soon.</p>
      </div>
    </div>

    <div id="system-footer">
      <div class="inside">
        <p id="sig-text">Powered by SnapSmack</p>
      </div>
    </div>
  </div>
</div>`;
    }

    // --- OVERRIDE BLOCK MANAGEMENT ---

    function _buildOverrideCss(overrides) {
        if (!overrides || !Object.keys(overrides).length) return '';
        const props = Object.entries(overrides)
            .map(([p, v]) => `  ${p}: ${v};`)
            .join('\n');
        return `:root {\n${props}\n}`;
    }

    function _ensureOverrideBlock() {
        const doc = _frame?.contentDocument;
        if (!doc) return;
        if (!doc.getElementById('oh-snap-overrides')) {
            const style = doc.createElement('style');
            style.id = 'oh-snap-overrides';
            doc.head.appendChild(style);
        }
    }

    function _writeOverrideBlock(css) {
        const doc = _frame?.contentDocument;
        if (!doc) return;
        let el = doc.getElementById('oh-snap-overrides');
        if (!el) {
            el = doc.createElement('style');
            el.id = 'oh-snap-overrides';
            doc.head.appendChild(el);
        }
        el.textContent = css;
    }

    // --- UTILS ---

    function _escHtml(str) {
        return String(str || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    /**
     * Generate an inline SVG placeholder image (no external requests).
     */
    function _placeholderImage(w, h, label, colour = '') {
        const bg  = colour || '#1a1a1a';
        const fg  = '#444444';
        const txt = label ? `<text x="50%" y="50%" dy=".3em" text-anchor="middle" font-family="sans-serif" font-size="14" fill="${fg}">${label}</text>` : '';
        const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${w}" height="${h}" viewBox="0 0 ${w} ${h}">
            <rect width="${w}" height="${h}" fill="${bg}"/>
            ${txt}
        </svg>`;
        return `<img src="data:image/svg+xml;base64,${btoa(svg)}" width="${w}" height="${h}" alt="${label}">`;
    }

    function _offlineShellCss() {
        return `:root{--bg-page:#101010;--bg-secondary:#191919;--text-bright:#fff;--text-primary:#d0d0d0;--text-dim:#8a8a8a;--border-primary:#343434;--content-lh:1.6}body{margin:0;background:var(--bg-page);color:var(--text-primary);font-family:Arial,sans-serif}a{color:inherit;text-decoration:none}#header{border-bottom:1px solid var(--border-primary);padding:22px 5vw}.inside{max-width:1280px;margin:auto}.logo-area{font-size:28px;font-weight:700;color:var(--text-bright)}nav ul{display:flex;gap:22px;list-style:none;padding:0}.post-image-wrap{max-width:1100px;margin:36px auto}.post-image{width:100%;height:auto}.static-transmission,#infobox{max-width:900px;margin:24px auto;line-height:var(--content-lh)}.photo-title-footer{color:var(--text-bright)}#browse-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;max-width:1100px;margin:36px auto}.thumb-link img{width:100%;aspect-ratio:1;object-fit:cover}#system-footer{padding:36px 5vw;color:var(--text-dim)}@media(max-width:600px){#browse-grid{grid-template-columns:repeat(2,1fr);margin:16px}.post-image-wrap,.static-transmission,#infobox{margin:16px}.site-title-text{font-size:22px}}`;
    }

    // --- EXPOSE ---

    return { init, initOffline, setProject, applyOverrides, switchView };

})();
// ===== SNAPSMACK EOF =====
