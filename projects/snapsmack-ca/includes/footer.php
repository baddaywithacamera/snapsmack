<?php
/**
 * SNAPSMACK.CA — Shared Page Footer
 *
 * Include at the bottom of every page.
 * Outputs the footer, mini-header scroll script, and closes body/html.
 * Pages that need additional inline scripts should echo them before this include.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */
?>

<!-- Kept with the footer markup so a stale shared stylesheet cannot break it. -->
<style>
.site-discovery {
    background: var(--black);
    border-top: 3px solid var(--red);
    padding: 48px 0 52px;
}
.site-discovery-kicker {
    margin: 0 0 8px;
    color: var(--red);
    font: 700 0.7rem/1 Arial, sans-serif;
    letter-spacing: 0.14em;
    text-transform: uppercase;
}
.site-discovery h2 {
    margin: 0 0 28px;
    color: var(--white);
    font: 900 clamp(1.7rem, 3vw, 2.4rem)/1.05 Arial, sans-serif;
    letter-spacing: -0.035em;
}
.site-discovery .footer-discovery {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 0;
    margin: 0;
    border-top: 1px solid #3b3b3b;
    border-left: 1px solid #3b3b3b;
    font: inherit;
    letter-spacing: normal;
    text-transform: none;
}
.site-discovery .footer-discovery a {
    min-height: 108px;
    padding: 20px 22px;
    color: var(--white);
    background: #181818;
    border-right: 1px solid #3b3b3b;
    border-bottom: 1px solid #3b3b3b;
    text-decoration: none;
    transition: background 120ms ease, color 120ms ease;
}
.site-discovery .footer-discovery strong {
    display: block;
    margin-bottom: 8px;
    font: 800 0.9rem/1.2 Arial, sans-serif;
}
.site-discovery .footer-discovery strong::after {
    content: " →";
    color: var(--red);
}
.site-discovery .footer-discovery span {
    display: block;
    color: #aaa;
    font: 0.84rem/1.45 Georgia, serif;
}
.site-discovery .footer-discovery a:hover,
.site-discovery .footer-discovery a:focus-visible {
    color: var(--white);
    background: #242424;
    box-shadow: inset 0 -3px 0 var(--red);
}
.site-discovery .footer-discovery a:hover span,
.site-discovery .footer-discovery a:focus-visible span { color: #ddd; }
#site-footer {
    background: var(--black);
    border-top: 0;
    padding: 26px 0;
}
#site-footer .footer-copy { line-height: 1.5; }
@media (max-width: 760px) {
    .site-discovery { padding: 36px 0 40px; }
    .site-discovery .footer-discovery { grid-template-columns: 1fr; }
    .site-discovery .footer-discovery a { min-height: 0; }
}
</style>

<!-- DISCOVERY -->
<aside class="site-discovery" aria-labelledby="site-discovery-title">
    <div class="wrap">
        <p class="site-discovery-kicker">Keep exploring</p>
        <h2 id="site-discovery-title">Find your way into SnapSmack.</h2>
        <nav class="footer-discovery" aria-label="Explore SnapSmack">
            <a href="instagram-alternative.php"><strong>Leave Instagram</strong><span>Keep the feed. Lose the landlord.</span></a>
            <a href="flickr-alternative.php"><strong>Move beyond Flickr</strong><span>Your archive, under your domain.</span></a>
            <a href="self-hosted-photography.php"><strong>Host it yourself</strong><span>Own the files, database, and future.</span></a>
            <a href="photo-blog-software.php"><strong>Build a photo blog</strong><span>Photography and writing, together again.</span></a>
            <a href="fediverse-photography.php"><strong>Join the Fediverse</strong><span>Connect without surrendering your home.</span></a>
            <a href="export-your-photos.php"><strong>Rescue your photos</strong><span>Get your work off somebody else’s platform.</span></a>
        </nav>
    </div>
</aside>

<!-- FOOTER -->
<footer id="site-footer">
    <div class="wrap">
        <nav class="footer-fine-print" aria-label="Fine print">
            <a href="security.php">Security</a>
            <a href="tnb.php">Privacy</a>
            <a href="hairy-muff.php">Ethics &amp; licence</a>
            <a href="buzzers.php">Security audits</a>
            <a href="ding-dong-bell.php">Operability audits</a>
            <a href="the-reckoning.php">By the numbers</a>
            <a href="hows-yer-father.php">Architecture</a>
            <a href="bugger.php">Emergency help</a>
            <a href="coming-soon.php">Coming soon</a>
        </nav>
        <p class="footer-copy">&copy; 2026 Sean McCormick &middot; Dedicated to Raymond A. Vanderwoning, photographer and friend. <a href="https://www.serenity.ca/obituaries/Raymond-Anthony-Vanderwoning?obId=30943370" target="_blank" rel="noopener noreferrer">He is missed</a>.</p>
    </div>
</footer>

<?php if (isset($page_footer_script)) echo $page_footer_script; ?>

<script>
const mainHeader = document.getElementById('site-header');
const miniHeader = document.getElementById('mini-header');
new IntersectionObserver(
    ([entry]) => miniHeader.classList.toggle('visible', !entry.isIntersecting),
    { threshold: 0 }
).observe(mainHeader);

// Native <details> elements do not dismiss themselves like menus. Make the
// navigation behave like one: one flyout at a time, click-away dismissal, and
// Escape support for keyboard users.
const navGroups = Array.from(document.querySelectorAll('.nav-group'));
function closeNavGroups(except) {
    navGroups.forEach(group => {
        if (group !== except) group.removeAttribute('open');
    });
}
navGroups.forEach(group => {
    group.addEventListener('toggle', () => {
        if (group.open) closeNavGroups(group);
    });
});
document.addEventListener('click', event => {
    if (!event.target.closest('.nav-group')) closeNavGroups();
});
document.addEventListener('keydown', event => {
    if (event.key === 'Escape') {
        closeNavGroups();
        document.querySelectorAll('.nav-toggle:checked').forEach(toggle => {
            toggle.checked = false;
        });
    }
});

// Live production statistics for every showcased skin.
(function () {
    const cards = Array.from(document.querySelectorAll('[data-stats]'));
    if (!cards.length) return;

    const fmt = value => {
        if (value == null) return '\u2014';
        if (value >= 1000000) return (value / 1000000).toFixed(1) + 'M';
        if (value >= 1000) return (value / 1000).toFixed(1) + 'K';
        return String(value);
    };

    const tip = document.createElement('div');
    tip.id = 'skin-stats-tooltip';
    tip.setAttribute('role', 'tooltip');
    // The page body is scaled. Keeping the fixed tooltip outside it prevents
    // pointer coordinates and rendered position from drifting apart.
    document.documentElement.appendChild(tip);

    let mouseX = 0;
    let mouseY = 0;

    function positionTip() {
        const gap = 30;
        const bounds = tip.getBoundingClientRect();
        tip.style.left = Math.max(gap, Math.min(mouseX + gap, window.innerWidth - bounds.width - gap)) + 'px';
        tip.style.top = Math.max(gap, Math.min(mouseY + gap, window.innerHeight - bounds.height - gap)) + 'px';
    }

    function content(card) {
        let stats = null;
        try { stats = JSON.parse(card.dataset.stats || 'null'); } catch (error) {}
        if (!stats || stats.error) return '<div class="stt-unavailable">Stats unavailable</div>';

        const site = stats.site_name || card.querySelector('strong, h2')?.textContent || '';
        return '<div class="stt-site">' + site + '</div>' +
            '<div class="stt-grid">' +
                '<div><span class="stt-stat-val">' + fmt(stats.posts) + '</span><span class="stt-stat-label">Photos</span></div>' +
                '<div><span class="stt-stat-val">' + fmt(stats.views_all || stats.views_30d) + '</span><span class="stt-stat-label">Views</span></div>' +
                '<div><span class="stt-stat-val">' + fmt(stats.unique_all || stats.unique_30d) + '</span><span class="stt-stat-label">Visitors</span></div>' +
                '<div><span class="stt-stat-val">' + (stats.version || '\u2014') + '</span><span class="stt-stat-label">Version</span></div>' +
            '</div>' +
            (stats.active_since ? '<div class="stt-since">Since ' + String(stats.active_since).slice(0, 4) + '</div>' : '');
    }

    function show(card, event) {
        if (event && event.clientX) {
            mouseX = event.clientX;
            mouseY = event.clientY;
        } else {
            const bounds = card.getBoundingClientRect();
            mouseX = bounds.left + Math.min(bounds.width, 40);
            mouseY = bounds.top + 20;
        }
        tip.innerHTML = content(card);
        tip.classList.add('visible');
        positionTip();
    }

    cards.forEach(card => {
        card.addEventListener('mouseenter', event => show(card, event));
        card.addEventListener('mousemove', event => {
            mouseX = event.clientX;
            mouseY = event.clientY;
            positionTip();
        });
        card.addEventListener('mouseleave', () => tip.classList.remove('visible'));
        card.addEventListener('focusin', event => show(card, event));
        card.addEventListener('focusout', () => tip.classList.remove('visible'));
    });
})();

// First-mention glossary popups (hover / focus / tap) across all pages.
(function () {
    const TERMS = [
        ['AT Protocol', 'The open networking protocol powering Bluesky, designed for portable accounts and feeds.'],
        ['ActivityPub', 'The open W3C protocol that lets independent sites and social apps follow, reply, and share across the fediverse.'],
        ['GoToSocial', 'A lightweight, self-hosted ActivityPub server built for small communities and solo publishers.'],
        ['Pixelfed', 'An open-source, federated photo-sharing platform \u2014 like Instagram without ads, tracking, or corporate lock-in.'],
        ['Mastodon', 'An open-source, federated microblogging network where anyone can run a server and connect across the fediverse.'],
        ['Fediverse', 'A network of independent social servers (like Mastodon and Pixelfed) that talk to each other using open standards instead of one company\u2019s silo.'],
        ['Bluesky', 'A decentralized social network built on the open AT Protocol.'],
        ['API key', 'A scoped secret token that lets a desktop tool post or back up to your site without sharing your main password.'],
        ['FOSS', 'Free, Open Source Software \u2014 code you can read, run, modify, and self-host without fees or lock-in.'],
        ['TOTP', 'Time-based One-Time Password \u2014 6-digit two-factor codes generated by an authenticator app on your phone.'],
        ['RSS', 'Really Simple Syndication \u2014 a standard feed format that lets readers subscribe to your site directly with no algorithm.']
    ];
    const seen = new Set();
    document.querySelectorAll('abbr.term-abbr').forEach(el => {
        const key = el.textContent.trim().toLowerCase();
        if (key) seen.add(key);
        if (!el.hasAttribute('tabindex')) el.setAttribute('tabindex', '0');
    });

    const SKIP_SEL = 'header, footer, nav, h1, h2, h3, h4, h5, h6, a, abbr, code, pre, script, style, button, .nav-flyout, #skin-stats-tooltip, #glossary-tooltip';
    const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, {
        acceptNode(node) {
            if (!node.nodeValue || !node.nodeValue.trim()) return NodeFilter.FILTER_REJECT;
            const parent = node.parentElement;
            if (!parent || parent.closest(SKIP_SEL)) return NodeFilter.FILTER_REJECT;
            if (!parent.closest('p, li, dd, blockquote, figcaption')) return NodeFilter.FILTER_REJECT;
            return NodeFilter.FILTER_ACCEPT;
        }
    });
    const textNodes = [];
    while (walker.nextNode()) textNodes.push(walker.currentNode);

    const pattern = new RegExp('\\b(' + TERMS.map(t => t[0].replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('|') + ')\\b', 'i');
    const defs = Object.fromEntries(TERMS.map(([k, v]) => [k.toLowerCase(), { label: k, def: v }]));

    textNodes.forEach(node => {
        let cur = node;
        while (cur && cur.nodeValue) {
            const m = pattern.exec(cur.nodeValue);
            if (!m) break;
            const matched = m[1];
            const key = matched.toLowerCase();
            const after = cur.splitText(m.index);
            const rest = after.splitText(matched.length);
            if (!seen.has(key) && defs[key]) {
                seen.add(key);
                const abbr = document.createElement('abbr');
                abbr.className = 'term-abbr';
                abbr.setAttribute('title', defs[key].def);
                abbr.setAttribute('data-glossary-term', defs[key].label);
                abbr.setAttribute('data-glossary-def', defs[key].def);
                abbr.setAttribute('tabindex', '0');
                abbr.textContent = matched;
                after.parentNode.replaceChild(abbr, after);
            }
            cur = rest;
        }
    });

    const abbrs = Array.from(document.querySelectorAll('abbr.term-abbr'));
    if (!abbrs.length) return;
    const tip = document.createElement('div');
    tip.id = 'glossary-tooltip';
    tip.setAttribute('role', 'tooltip');
    document.documentElement.appendChild(tip);

    let activeEl = null;
    function show(el) {
        const def = el.getAttribute('data-glossary-def') || el.getAttribute('title') || '';
        const term = el.getAttribute('data-glossary-term') || el.textContent.trim();
        if (!def) return;
        if (el.hasAttribute('title')) {
            el.setAttribute('data-glossary-def', def);
            el.removeAttribute('title');
        }
        activeEl = el;
        tip.innerHTML = '<span class="gt-term"></span><span class="gt-def"></span>';
        tip.querySelector('.gt-term').textContent = term;
        tip.querySelector('.gt-def').textContent = def;
        tip.classList.add('visible');
        const r = el.getBoundingClientRect();
        const tb = tip.getBoundingClientRect();
        const left = Math.max(12, Math.min(r.left, window.innerWidth - tb.width - 12));
        const top = (r.bottom + 8 + tb.height <= window.innerHeight - 12) ? (r.bottom + 8) : Math.max(12, r.top - tb.height - 8);
        tip.style.left = left + 'px';
        tip.style.top = top + 'px';
    }
    function hide() {
        activeEl = null;
        tip.classList.remove('visible');
    }

    abbrs.forEach(el => {
        el.addEventListener('mouseenter', () => show(el));
        el.addEventListener('mouseleave', hide);
        el.addEventListener('focusin', () => show(el));
        el.addEventListener('focusout', hide);
        el.addEventListener('click', ev => {
            ev.stopPropagation();
            show(el);
        });
    });
    document.addEventListener('click', ev => {
        if (!ev.target.closest('abbr.term-abbr')) hide();
    });
    document.addEventListener('keydown', ev => {
        if (ev.key === 'Escape') hide();
    });
})();
</script>
</body>
</html>
<!-- ===== SNAPSMACK EOF ===== -->
<?php // ===== SNAPSMACK EOF ===== ?>
