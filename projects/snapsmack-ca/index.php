<?php
/**
 * SNAPSMACK.CA — Homepage
 *
 * Main landing page for snapsmack.ca.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

// ─── DEMO SITE STATS ─────────────────────────────────────────────────────────
// Fetched directly from each site's /stats.php endpoint.
// Only Sean's own sites are listed — no third-party data ever appears here.
// Results are cached to a JSON file for 1 hour to avoid hitting every site on
// every page load. If a site is down its card shows "Stats unavailable".

$_demo_sites = [
    'unzucked.ca'         => 'https://unzucked.ca',
    'photowalk.ing'       => 'https://photowalk.ing',
    'hekeepsdroningon.ca' => 'https://hekeepsdroningon.ca',
    'pixhellated.ca'      => 'https://pixhellated.ca',
    'wateronthebrain.ca'  => 'https://wateronthebrain.ca',
    'foundtextures.ca'    => 'https://foundtextures.ca',
    'acolourlesslife.ca'  => 'https://acolourlesslife.ca',
];

$_demo_stats      = [];
$_stats_cache     = __DIR__ . '/includes/stats-cache.json';
$_stats_cache_ttl = 3600; // 1 hour

// Serve from cache if fresh
if (file_exists($_stats_cache) && (time() - filemtime($_stats_cache)) < $_stats_cache_ttl) {
    $_cached = json_decode(file_get_contents($_stats_cache), true);
    if (is_array($_cached)) {
        $_demo_stats = $_cached;
    }
}

// Cache miss — fetch from each site in parallel
if (empty($_demo_stats)) {
    $_mh      = curl_multi_init();
    $_handles = [];
    foreach ($_demo_sites as $_domain => $_base_url) {
        $_ch = curl_init($_base_url . '/stats.php');
        curl_setopt_array($_ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 3,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'snapsmack.ca-gallery/1.0',
        ]);
        curl_multi_add_handle($_mh, $_ch);
        $_handles[$_domain] = $_ch;
    }
    do {
        $_status = curl_multi_exec($_mh, $_running);
        if ($_running) curl_multi_select($_mh);
    } while ($_running && $_status === CURLM_OK);

    foreach ($_handles as $_domain => $_ch) {
        $_body = curl_multi_getcontent($_ch);
        $_code = curl_getinfo($_ch, CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($_mh, $_ch);
        curl_close($_ch);
        if ($_code === 200 && $_body) {
            $_data = json_decode($_body, true);
            if (is_array($_data) && !isset($_data['error'])) {
                $_demo_stats[$_domain] = [
                    'site_name'    => $_data['site_name']    ?? '',
                    'posts'        => (int)($_data['posts']        ?? 0),
                    'views_30d'    => (int)($_data['views_30d']    ?? 0),
                    'unique_30d'   => (int)($_data['unique_30d']   ?? 0),
                    'views_all'    => (int)($_data['views_all']    ?? 0),
                    'unique_all'   => (int)($_data['unique_all']   ?? 0),
                    'version'      => $_data['version']      ?? '',
                    'active_since' => $_data['active_since'] ?? null,
                ];
            }
        }
    }
    curl_multi_close($_mh);

    if (!empty($_demo_stats)) {
        @file_put_contents($_stats_cache, json_encode($_demo_stats, JSON_UNESCAPED_SLASHES), LOCK_EX);
    }
}

// Helper — get stats for a domain as a JSON string for data attribute
function ss_card_stats(string $domain, array $all): string {
    return htmlspecialchars(json_encode($all[$domain] ?? null, JSON_UNESCAPED_SLASHES));
}

$page_title       = 'SnapSmack — Photo Blogging Is Back';
$page_description = 'SnapSmack is a self-hosted photo blogging platform built for people who still believe that putting your photographs on your own corner of the internet is worth doing.';
$page_og_url      = 'https://snapsmack.ca/';
$nav_active       = 'index';

$page_css = <<<'CSS'
/* ─── BUILD BADGES ──────────────────────────────────────────────────────── */
.build-badges {
    position: absolute;
    top: 0;
    right: 32px;
    display: flex;
    flex-direction: column;
    gap: 3px;
    flex-shrink: 0;
}
.build-badge {
    display: flex;
    align-items: center;
    font-family: 'Courier New', monospace;
    font-size: 0.78rem;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    white-space: nowrap;
    overflow: hidden;
}
.build-badge-track {
    padding: 4px 10px;
    color: var(--white);
    min-width: 90px;
    text-align: left;
}
.build-badge-ver {
    padding: 4px 10px;
    background: var(--black);
    color: var(--white);
}
.build-badge--boring .build-badge-track  { background: #555; }
.build-badge--bitchin .build-badge-track { background: var(--red); }

/* ─── HERO ──────────────────────────────────────────────────────────────── */
#hero {
    background: var(--white);
    padding: 80px 0 72px;
    border-bottom: 1px solid var(--border);
}

.hero-inner {
    max-width: var(--max);
    margin: 0 auto;
    padding: 0 32px;
    position: relative;
}

.hero-kicker {
    font-family: 'Courier New', monospace;
    font-size: 0.82rem;
    color: var(--mid-grey);
    letter-spacing: 0.06em;
    text-transform: uppercase;
    margin-bottom: 16px;
}

.hero-headline {
    font-family: Arial Black, Arial, sans-serif;
    font-size: clamp(2.4rem, 5vw, 4rem);
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: -0.02em;
    color: var(--black);
    line-height: 1.05;
    margin-bottom: 28px;
    max-width: 820px;
}

.hero-headline span { color: var(--red); }

.hero-sub {
    font-size: 1.2rem;
    color: var(--dark-grey);
    max-width: 620px;
    margin-bottom: 36px;
    line-height: 1.65;
}

.hero-actions {
    display: flex;
    gap: 16px;
    flex-wrap: wrap;
    align-items: center;
}

.btn {
    display: inline-block;
    font-family: Arial Black, Arial, sans-serif;
    font-size: 0.85rem;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    padding: 14px 28px;
    cursor: pointer;
    text-decoration: none;
    transition: background 0.15s, color 0.15s;
}

.btn:hover { text-decoration: none; }

.btn-primary {
    background: var(--red);
    color: var(--white);
}
.btn-primary:hover {
    background: #a80000;
    color: var(--white);
}

.btn-secondary {
    background: var(--black);
    color: var(--white);
}
.btn-secondary:hover {
    background: #333;
    color: var(--white);
}

.hero-kicker {
    font-family: 'Arial Black', Arial, sans-serif;
    font-size: 1.05rem;
    color: var(--dark-grey);
    text-transform: uppercase;
    letter-spacing: 0.04em;
    margin-bottom: 20px;
}

.hero-note {
    font-family: 'Courier New', monospace;
    font-size: 0.88rem;
    color: var(--mid-grey);
    margin-top: 20px;
    letter-spacing: 0.02em;
}

.hero-tags {
    font-family: 'Arial Black', Arial, sans-serif;
    font-size: 0.88rem;
    color: var(--red);
    letter-spacing: 0.03em;
    margin-top: 10px;
    opacity: 0.75;
}
.hero-alpha {
    font-family: Georgia, 'Times New Roman', serif;
    font-size: 1rem;
    color: var(--dark-grey);
    margin-top: 18px;
    opacity: 0.65;
}

/* ─── WHAT IS IT ────────────────────────────────────────────────────────── */
#what {
    background: var(--white);
}

.two-col {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 56px;
    align-items: stretch;
}

.feature-box {
    background: var(--black);
    padding: 32px 36px;
    display: flex;
    flex-direction: column;
    justify-content: center;
}

.feature-box h3 {
    color: var(--red);
}

.feature-box .feature-list li {
    color: var(--white);
    border-bottom-color: rgba(255,255,255,0.1);
}

.feature-box .feature-list li::before {
    color: var(--red);
}

.feature-list {
    list-style: none;
    margin-top: 4px;
}

.feature-list li {
    display: flex;
    align-items: baseline;
    gap: 10px;
    padding: 7px 0;
    border-bottom: 1px solid var(--border);
    font-size: 0.9rem;
    line-height: 1.4;
    color: var(--dark-grey);
}

.feature-list li::before {
    content: '✓';
    color: var(--red);
    font-family: Arial, sans-serif;
    font-size: 0.9rem;
    flex-shrink: 0;
}

/* ─── LIGHTBOX ──────────────────────────────────────────────────────────── */
#lb-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.8);
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.2s;
    cursor: zoom-out;
}
#lb-overlay.lb-active {
    opacity: 1;
    pointer-events: all;
}
#lb-overlay img {
    max-width: 90vw;
    max-height: 90vh;
    object-fit: contain;
    box-shadow: 0 4px 60px rgba(0,0,0,0.6);
    cursor: default;
    display: block;
}
.lb-close {
    position: absolute;
    top: 18px;
    right: 26px;
    color: rgba(255,255,255,0.7);
    font-size: 2.2rem;
    cursor: pointer;
    line-height: 1;
    font-family: Arial, sans-serif;
    font-weight: bold;
    user-select: none;
}
.lb-close:hover { color: var(--white); }
.lb-prev, .lb-next {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    color: rgba(255,255,255,0.55);
    font-size: 2.8rem;
    cursor: pointer;
    padding: 16px 20px;
    line-height: 1;
    user-select: none;
    transition: color 0.15s;
}
.lb-prev { left: 8px; }
.lb-next { right: 8px; }
.lb-prev:hover, .lb-next:hover { color: var(--white); }
[data-lb] { cursor: zoom-in; display: block; }

/* ─── WHO DAT ────────────────────────────────────────────────────────────── */
#whodat {
    background: var(--light-grey);
    border-top: 1px solid var(--border);
    padding-bottom: 72px;
}
#whodat h2 { color: var(--red); }
.whodat-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 48px;
    margin-top: 40px;
}
.whodat-card {
    display: flex;
    gap: 24px;
    align-items: flex-start;
}
.whodat-portrait {
    width: 110px;
    flex-shrink: 0;
    border: 1px solid var(--border);
    overflow: hidden;
}
.whodat-portrait img {
    width: 100%;
    display: block;
}
.whodat-name {
    font-family: 'Arial Black', Arial, sans-serif;
    font-size: 0.95rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--black);
    margin-bottom: 4px;
}
.whodat-title {
    font-family: 'Courier New', monospace;
    font-size: 0.82rem;
    color: var(--red);
    letter-spacing: 0.04em;
    text-transform: uppercase;
    margin-bottom: 12px;
}
.whodat-bio {
    font-size: 0.87rem;
    color: var(--mid-grey);
    line-height: 1.65;
}
.theme-main-shot a { display: block; }
.theme-main-shot a img { transition: opacity 0.15s; }
.theme-main-shot a:hover img { opacity: 0.88; }

.theme-footer-note {
    margin-top: 20px;
    padding-top: 12px;
    border-top: 1px solid rgba(255,255,255,0.12);
    font-family: Georgia, 'Times New Roman', serif;
    font-size: 1rem;
    color: rgba(255,255,255,0.55);
    letter-spacing: 0.02em;
    line-height: 1.6;
}

.theme-live-note {
    font-family: 'Courier New', monospace;
    font-size: 0.82rem;
    color: rgba(255,255,255,0.45);
    letter-spacing: 0.02em;
    margin-bottom: 6px !important;
}

/* ─── WHO IS THIS FOR ───────────────────────────────────────────────────── */
#for {
    background: var(--black);
    color: var(--white);
}
#for h2 { color: var(--red); }
#for .lede { color: rgba(255,255,255,0.55); }
.for-inner {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 56px;
    align-items: start;
    margin-top: 8px;
}
.for-statement {
    font-size: clamp(1.1rem, 2vw, 1.35rem);
    line-height: 1.7;
    color: rgba(255,255,255,0.88);
}
.for-statement strong {
    color: var(--white);
    font-style: normal;
}
.for-list {
    list-style: none;
    margin-top: 4px;
}
.for-list li {
    padding: 12px 0;
    border-bottom: 1px solid rgba(255,255,255,0.08);
    font-size: 0.9rem;
    color: rgba(255,255,255,0.65);
    line-height: 1.5;
    padding-left: 18px;
    position: relative;
}
.for-list li::before {
    content: '→';
    color: var(--red);
    position: absolute;
    left: 0;
}

/* ─── WORKING NOW ───────────────────────────────────────────────────────── */
#working {
    background: var(--light-grey);
}


/* ─── SECURITY SECTION ──────────────────────────────────────────────────── */
#security {
    background: #2e2e2e;
    color: var(--white);
    border-top: none;
}
#security h2 { color: var(--red); }
#security .lede { color: #aaa; }
.security-layers {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 32px;
    margin-top: 40px;
}
.security-layer {
    border-top: 3px solid var(--red);
    padding-top: 24px;
}
.security-layer .layer-num {
    font-family: Arial Black, Arial, sans-serif;
    font-size: 0.72rem;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: 0.14em;
    color: var(--red);
    margin-bottom: 10px;
}
.security-layer h3 {
    color: var(--white);
    font-size: 1.15rem;
    margin-bottom: 14px;
}
.security-layer p {
    font-size: 0.9rem;
    color: #aaa;
    line-height: 1.6;
    margin-bottom: 0.75em;
}
.security-layer p:last-child { margin-bottom: 0; }
@media (max-width: 800px) {
    .security-layers { grid-template-columns: 1fr; }
}

.status-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 24px;
    margin-top: 8px;
}

.status-card {
    background: var(--white);
    border-top: 3px solid var(--red);
    padding: 20px 22px;
}

.status-card h3 {
    font-size: 0.88rem;
    margin-bottom: 6px;
}

.status-card p {
    font-size: 0.88rem;
    color: var(--mid-grey);
    margin: 0;
    line-height: 1.5;
}

/* ─── THEMES IN THE WILD ────────────────────────────────────────────────── */
#themes {
    background: var(--black);
    color: var(--white);
}
#themes h2 { color: var(--red); }
#themes .lede { color: rgba(255,255,255,0.55); }

.theme-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 40px;
    margin-top: 8px;
}

.theme-card {
    border: 1px solid rgba(255,255,255,0.12);
    overflow: hidden;
    background: #1a1a1a;
}

.theme-main-shot {
    background: #222;
    aspect-ratio: 16 / 9;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: 'Courier New', monospace;
    font-size: 0.88rem;
    color: var(--mid-grey);
    letter-spacing: 0.04em;
}

.theme-main-shot img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    object-position: top;
    display: block;
}

.theme-thumbs {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 2px;
    background: #333;
}

.theme-thumbs a {
    display: block;
    overflow: hidden;
    aspect-ratio: 16 / 9;
    background: #222;
}

.theme-thumbs a img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    object-position: top;
    display: block;
    transition: opacity 0.15s;
}

.theme-thumbs a:hover img { opacity: 0.8; }

.theme-info {
    padding: 20px 22px;
}

.theme-info h3 {
    font-size: 1rem;
    margin-bottom: 6px;
    color: var(--white);
}

.theme-info p {
    font-size: 0.95rem;
    color: rgba(255,255,255,0.6);
    margin-bottom: 10px;
}

.theme-link {
    font-family: Arial Black, Arial, sans-serif;
    font-size: 0.82rem;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--red);
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.theme-link-label {
    display: block;
    opacity: 0.65;
}

.theme-link:hover {
    color: var(--white);
    text-decoration: underline;
}

/* ─── SKIN CARD STATS TOOLTIP ───────────────────────────────────────────── */
#skin-stats-tooltip {
    position: fixed;
    z-index: 9999;
    pointer-events: none;
    background: #111;
    border: 1px solid #444;
    border-top: 3px solid var(--red);
    padding: 18px 22px;
    min-width: 220px;
    display: none;
    flex-direction: column;
    gap: 12px;
    box-shadow: 0 8px 32px rgba(0,0,0,0.7);
}
#skin-stats-tooltip.visible {
    display: flex;
}
.stt-site {
    font-family: Arial Black, Arial, sans-serif;
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #fff;
    border-bottom: 1px solid rgba(255,255,255,0.1);
    padding-bottom: 10px;
}
.stt-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px 16px;
}
.stt-stat-val {
    display: block;
    font-family: Arial Black, Arial, sans-serif;
    font-size: 1.3rem;
    font-weight: 900;
    color: #fff;
    line-height: 1.1;
}
.stt-stat-label {
    display: block;
    font-family: 'Courier New', monospace;
    font-size: 0.6rem;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    color: #999;
    margin-top: 2px;
}
.stt-since {
    font-family: 'Courier New', monospace;
    font-size: 0.65rem;
    color: #888;
    letter-spacing: 0.05em;
}
.stt-unavailable {
    font-family: 'Courier New', monospace;
    font-size: 0.75rem;
    color: #888;
    letter-spacing: 0.04em;
}

/* ─── TOOLS ─────────────────────────────────────────────────────────────── */
#tools {
    background: var(--light-grey);
    padding-top: 36px;
}

.tool-block {
    display: grid;
    grid-template-columns: 3fr 2fr;
    gap: 56px;
    align-items: center;
}

.tool-block--reversed {
    grid-template-columns: 2fr 3fr;
}

.tool-block--reversed .tool-copy {
    order: -1;
}

.tool-divider {
    border: none;
    border-top: 1px solid var(--border);
    margin: 56px 0;
}

.tool-screenshot-trio {
    display: grid;
    grid-template-columns: 1fr;
    gap: 2px;
    background: var(--border);
}

.tool-screenshot {
    background: var(--white);
    border: 1px solid var(--border);
    overflow: hidden;
}

.tool-screenshot-duo {
    display: grid;
    grid-template-columns: 1fr;
    gap: 2px;
    background: var(--border);
}

.tool-screenshot-duo img {
    width: 100%;
    display: block;
}

.admin-shots {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
    margin-top: 48px;
    border: 1px solid var(--border);
    overflow: hidden;
}

.admin-shots img {
    width: 100%;
    display: block;
}

.tool-screenshot img {
    width: 100%;
    display: block;
}

.tool-copy h3 {
    font-size: 0.75rem;
    color: var(--mid-grey);
    letter-spacing: 0.1em;
    margin-bottom: 10px;
}

.tool-copy h2 {
    margin-bottom: 16px;
    font-size: clamp(1.2rem, 2.25vw, 1.65rem);
}

.section-kicker {
    font-family: 'Arial Black', Arial, sans-serif;
    font-size: 0.75rem;
    color: var(--mid-grey);
    letter-spacing: 0.1em;
    text-transform: uppercase;
    margin-bottom: 10px;
}

.tool-copy p {
    font-size: 0.97rem;
    color: var(--dark-grey);
}

.tool-copy .dl-note {
    margin-top: 20px;
    font-family: 'Courier New', monospace;
    font-size: 0.78rem;
    color: var(--mid-grey);
    letter-spacing: 0.02em;
}

/* ─── ADMIN SKINS ───────────────────────────────────────────────────────── */
#admin-skins { background: var(--light-grey, #f4f4f4); }
#admin-skins h2 { color: var(--red); }
#admin-skins .lede { color: var(--dark-grey); }

/* ─── THREE WAYS IN ─────────────────────────────────────────────────────── */
#modes {
    background: var(--near-black, #111);
    color: var(--white);
}
#modes h2 { color: var(--red); }
#modes .lede {
    color: rgba(255,255,255,0.55);
    margin-bottom: 48px;
}
.modes-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 32px;
    margin-top: 40px;
}
.mode-card {
    border-top: 3px solid var(--red);
    padding-top: 24px;
}
.mode-card .mode-num {
    font-family: Arial Black, Arial, sans-serif;
    font-size: 0.72rem;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: 0.14em;
    color: var(--red);
    margin-bottom: 10px;
}
.mode-card h3 {
    color: var(--white);
    font-size: 1.4rem;
    margin-bottom: 6px;
    letter-spacing: 0.04em;
}
.mode-card .mode-tagline {
    font-size: 0.82rem;
    color: var(--red);
    text-transform: uppercase;
    letter-spacing: 0.1em;
    margin-bottom: 16px;
    font-weight: 700;
}
.mode-card p {
    font-size: 0.9rem;
    color: #aaa;
    line-height: 1.6;
    margin-bottom: 0.75em;
}
.mode-card p:last-child { margin-bottom: 0; }
.mode-card .mode-coming {
    display: inline-block;
    font-size: 0.68rem;
    font-weight: 900;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: var(--black);
    background: var(--red);
    padding: 3px 8px;
    margin-bottom: 16px;
}
@media (max-width: 800px) {
    .modes-grid { grid-template-columns: 1fr; }
}

/* ─── COMING SOON ───────────────────────────────────────────────────────── */
#coming {
    background: var(--black);
    color: var(--white);
    padding-bottom: 48px;
}
#coming h2 { color: var(--red); }
#coming .lede { color: rgba(255,255,255,0.55); }

.coming-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 20px;
    margin-top: 8px;
    align-items: start;
}

.coming-item {
    padding: 20px 0;
    border-top: 2px solid var(--red);
}

.coming-item h3 {
    font-size: 0.88rem;
    color: var(--white);
    margin-bottom: 6px;
}

.coming-item p {
    font-size: 0.87rem;
    color: rgba(255,255,255,0.6);
    line-height: 1.5;
    margin: 0;
}

.coming-item .tag {
    display: inline-block;
    font-family: 'Courier New', monospace;
    font-size: 0.8rem;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--red);
    margin-bottom: 8px;
}

/* ─── WHAT IT IS NOT ────────────────────────────────────────────────────── */
#not {
    background: var(--light-grey);
    border-top: 1px solid var(--border);
}

.not-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 28px;
    margin-top: 8px;
}

.not-item {
    padding: 20px 24px;
    background: var(--white);
    border: 1px solid var(--border);
    position: relative;
}

.not-item::before {
    content: '';
    position: absolute;
    left: 0;
    top: 50%;
    transform: translateY(-50%);
    width: 2px;
    height: 40%;
    background: var(--red);
}

.not-item h3 {
    font-family: 'Arial Black', Arial, sans-serif;
    font-size: 0.82rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--black);
    margin-bottom: 10px;
}

.not-item p {
    font-size: 0.88rem;
    color: var(--mid-grey);
    line-height: 1.6;
    margin: 0 0 0.75em 0;
}
.not-item p:last-child { margin-bottom: 0; }

/* ─── BETA SIGNUP ───────────────────────────────────────────────────────── */
#beta {
    background: var(--black);
    color: var(--white);
}

#beta h2 {
    color: var(--red);
}

#beta .lede {
    color: #aaa;
    margin-bottom: 36px;
}

/* ── MailerLite overrides ───────────────────────────────────────────────
   Kill ML's own centering/width constraints so the form fills the wrap.
   Heavy selector chains beat their dynamically-loaded !important rules.
   ─────────────────────────────────────────────────────────────────────── */
.ml-embedded,
.ml-form-align-center {
    width: 100% !important;
    max-width: 560px !important;
    box-sizing: border-box !important;
    text-align: left !important;
}

.ml-form-embedWrapper {
    background: transparent !important;
    border: 1px solid #444 !important;
    box-shadow: none !important;
    border-radius: 0 !important;
    padding: 28px 32px !important;
    box-sizing: border-box !important;
    width: 100% !important;
    max-width: 100% !important;
}

.ml-form-embedBody {
    background: transparent !important;
    border: none !important;
    box-shadow: none !important;
    padding: 0 !important;
    border-radius: 0 !important;
    width: 100% !important;
}

.ml-form-embedContent h4,
.ml-form-embedContent h3,
.ml-form-embedContent h2 {
    font-family: Arial Black, Arial, sans-serif !important;
    font-size: 0.8rem !important;
    letter-spacing: 0.1em !important;
    text-transform: uppercase !important;
    color: #ffffff !important;
    margin: 0 0 10px 0 !important;
    padding: 0 !important;
}

.ml-form-embedContent p {
    font-family: Georgia, 'Times New Roman', serif !important;
    font-size: 0.9rem !important;
    line-height: 1.7 !important;
    color: #aaaaaa !important;
    margin: 0 0 20px 0 !important;
    padding: 0 !important;
}

form.ml-block-form {
    display: flex !important;
    flex-direction: row !important;
    align-items: flex-start !important;
    gap: 10px !important;
    width: 100% !important;
    margin-bottom: 0 !important;
}

.ml-form-fieldRow {
    flex: 1 1 auto !important;
    margin: 0 !important;
    padding: 0 !important;
    min-width: 0 !important;
}

.ml-form-fieldRow .ml-field-group {
    margin: 0 !important;
    padding: 0 !important;
}

.ml-embedded .ml-form-embedBody .ml-form-fieldRow input[type="email"],
.ml-embedded .ml-form-embedBody .ml-form-fieldRow input[type="text"],
.ml-form-fieldRow input[type="email"],
.ml-form-fieldRow input[type="text"] {
    width: 100% !important;
    height: 46px !important;
    background: #1a1a1a !important;
    color: #ffffff !important;
    border: 1px solid #444 !important;
    border-radius: 0 !important;
    font-family: Georgia, 'Times New Roman', serif !important;
    font-size: 0.9rem !important;
    padding: 0 16px !important;
    box-sizing: border-box !important;
    min-width: 0 !important;
}

.ml-embedded .ml-form-embedBody .ml-form-fieldRow input::placeholder,
.ml-form-fieldRow input::placeholder {
    color: #666 !important;
    opacity: 1 !important;
}

.ml-form-embedSubmit {
    flex: 0 0 180px !important;
    margin: 0 !important;
    padding: 0 !important;
}

.ml-form-embedSubmit button,
.ml-form-embedSubmit button.primary {
    width: 100% !important;
    height: 46px !important;
    background: var(--red) !important;
    color: #ffffff !important;
    border: 1px solid var(--red) !important;
    border-radius: 0 !important;
    font-family: Arial Black, Arial, sans-serif !important;
    font-size: 0.72rem !important;
    letter-spacing: 0.1em !important;
    text-transform: uppercase !important;
    padding: 0 !important;
    cursor: pointer !important;
    white-space: nowrap !important;
    transition: background 0.2s ease, border-color 0.2s ease !important;
}

.ml-form-embedSubmit button:hover {
    background: #a00000 !important;
    border-color: #a00000 !important;
}

.ml-form-successBody,
.ml-form-successContent p {
    color: #ffffff !important;
    font-family: Georgia, 'Times New Roman', serif !important;
}

/* ─── RESPECT ────────────────────────────────────────────────────────────── */
#respect {
    background: #e0e0e0;
    border-top: 2px solid #bbb;
    border-bottom: 2px solid #bbb;
    padding: 64px 0;
}
#respect h2 {
    font-size: clamp(1.2rem, 2.5vw, 1.6rem);
    margin-bottom: 24px;
    max-width: 680px;
    margin-left: auto;
}
#respect p {
    font-size: 1rem;
    color: var(--dark-grey);
    max-width: 680px;
    line-height: 1.7;
    margin-bottom: 14px;
    margin-left: auto;
}
#respect a {
    color: var(--black);
    text-decoration: none;
}
#respect a:hover {
    color: var(--red);
    border-bottom: 1px solid var(--red);
}

/* ─── FOOTER ────────────────────────────────────────────────────────────── */
#site-footer {
    background: var(--black);
    border-top: 3px solid var(--red);
    padding: 32px 0;
}

.footer-inner {
    display: flex;
    align-items: center;
    justify-content: center;
    flex-wrap: wrap;
    gap: 16px;
}

.footer-copy {
    font-family: 'Courier New', monospace;
    font-size: 0.68rem;
    color: #aaa;
    letter-spacing: 0.04em;
    line-height: 1;
    margin: 0;
    align-self: center;
}

.footer-copy a {
    color: var(--white);
    text-decoration: none;
}

.footer-copy a:hover {
    text-decoration: underline;
}

.footer-links {
    display: flex;
    gap: 24px;
}

.footer-links a {
    font-family: 'Courier New', monospace;
    font-size: 0.68rem;
    letter-spacing: 0.04em;
    color: #aaa;
    text-transform: uppercase;
}

.footer-links a:hover {
    color: var(--white);
}

/* ─── RESPONSIVE ────────────────────────────────────────────────────────── */
@media (max-width: 760px) {
    body { font-size: 18px; }
    section { padding: 56px 0; }
    .wrap { padding: 0 24px; }
    .hero-inner { padding: 0 24px; }
    .two-col { grid-template-columns: 1fr; gap: 32px; }
    .tool-block { grid-template-columns: 1fr; gap: 32px; }
    .tool-screenshot-duo { grid-template-columns: 1fr; }
    .admin-shots { grid-template-columns: 1fr; gap: 4px; }
    .header-inner { flex-direction: column; align-items: flex-start; gap: 12px; }
    .logo-lockup img { width: 80px; }
    .footer-inner { flex-direction: column; align-items: flex-start; }
    .whodat-grid { grid-template-columns: 1fr; gap: 40px; }
    .whodat-card { flex-direction: column; }
    .whodat-portrait { width: 100px; }
    .for-inner { grid-template-columns: 1fr; }
    .status-grid { grid-template-columns: 1fr 1fr; }
    .not-grid { grid-template-columns: 1fr; }
    .dict-pull { padding: 32px 24px; }
}

/* ─── DICTIONARY PULL-QUOTES ──────────────────────────────────────────── */
.dict-pull {
    background: #d0d0d0;
    border-top: 2px solid #b0b0b0;
    border-bottom: 2px solid #b0b0b0;
    padding: 48px 40px;
    text-align: center;
    color: var(--dark-grey);
    font-size: 1.05rem;
    line-height: 1.7;
}
.dict-pull .dict-inner {
    max-width: 680px;
    margin: 0 auto;
}
.dict-pull .dict-word {
    font-family: Georgia, 'Times New Roman', serif;
    font-style: italic;
    font-size: 1.3rem;
    color: var(--black);
}
.dict-pull .dict-phon {
    font-family: 'Courier New', monospace;
    font-size: 0.85rem;
    color: var(--mid-grey);
    margin: 0 6px;
}
.dict-pull .dict-pos {
    font-style: italic;
    font-size: 0.85rem;
    color: var(--mid-grey);
}

CSS;

require_once __DIR__ . '/includes/header.php';
?>

<!-- ── HERO ───────────────────────────────────────────────────────────────── -->
<section id="hero">
    <div class="hero-inner">
        <div class="build-badges">
            <div class="build-badge build-badge--boring">
                <span class="build-badge-track">Boring</span>
                <span class="build-badge-ver">v<?php echo SS_PROMO_VERSION; ?></span>
            </div>
            <div class="build-badge build-badge--bitchin">
                <span class="build-badge-track">Bitchin&#x27;</span>
                <span class="build-badge-ver">v<?php
                    $__dev_ver = defined('SS_PROMO_DEV_VERSION') ? preg_replace('/^Alpha\s+/i', '', SS_PROMO_DEV_VERSION) : '';
                    // Fallback: a stable (Boring) publish blanks SS_PROMO_DEV_VERSION
                    // (sc-release.php). Dev track is never behind stable, so show the
                    // stable version with the D suffix rather than a bare "v".
                    if ($__dev_ver === '') {
                        $__dev_ver = defined('SS_PROMO_VERSION') ? SS_PROMO_VERSION . 'D' : '';
                    }
                    echo htmlspecialchars($__dev_ver);
                ?></span>
            </div>
        </div>
        <h1 class="hero-headline">A photo blog platform that doesn't treat you like a <span>product.</span></h1>
        <p class="hero-kicker">SnapSmack lets you smack your snaps up.</p>
        <p class="hero-sub">Self-hosted, free and open-source, built for photographers who got tired of Instagram, Facebook, and every other platform deciding what happens to their work. Own your archive. Own your audience. No middleman.</p>
        <div class="hero-actions">
            <a href="#what" class="btn btn-secondary">See What It Does</a>
            <a href="#beta" class="btn btn-primary">Apply for Beta Access</a>
        </div>
        <p class="hero-tags">#fightthealgorithm &nbsp; #takebackyourart &nbsp; #fuckzuck &nbsp; #elonbegone</p>
    </div>
</section>


<!-- ── WHAT IS IT ──────────────────────────────────────────────────────────── -->
<section id="what">
    <div class="wrap">
        <div class="two-col">
            <div>
                <h2>What Is SnapSmack?</h2>
                <p>It's a free, open-source photo blogging platform you install on your own server. Every image you post is yours — sitting on your own hosting, in your own database, under your own domain. SnapSmack doesn't have an app to delete, a policy to change, or a feed to bury you in.</p>
                <p>Post one image at a time or thirty at a time — whatever suits the shoot. Title, description, tags, all easy to manage. Pick a skin that reflects your creative vision or make your own. SnapSmack doesn't try to be everything for everyone, just to be indispensable to image makers. That's on purpose.</p>
                <p>The software is free. The skins are free. The companion tools are free. No membership, no freemium tier, no bait-and-switch. Any skin developed by a third party can only be distributed through the SnapSmack repository — and only for free. SnapSmack exists to give photographers a hand up, not extort them for handouts.</p>
                <p>Currently in Alpha — stable enough to run a real site, rough around a few edges. Best experienced on a big screen.</p>
            </div>
            <div class="feature-box">
                <h3>Built-in from day one</h3>
                <ul class="feature-list">
                    <li>Self-hosted on any PHP/MySQL server</li>
                    <li>Skins — colour pickers, sliders, no CSS required</li>
                    <li>EXIF data extraction and copyright embedding</li>
                    <li>Google Drive high-res download links</li>
                    <li>Static pages, albums, blogroll</li>
                    <li>RSS feed, built-in</li>
                    <li>Community — reactions, comments, following</li>
                    <li>Privacy-first stats — visits only, no tracking</li>
                    <li>Bulk posting via companion desktop tool</li>
                    <li>One-click backup of all your sites at once</li>
                    <li>2FA login protection</li>
                    <li>AI writing assistant in the post editor</li>
                    <li>Comprehensive built-in help system</li>
                    <li>Multisite management — hub-to-spoke updates, My Blogs network sync, fleet monitoring</li>
                    <li>Comment moderation with browser fingerprinting and silent rejection</li>
                    <li>Manifest and library system — no plugins</li>
                    <li>Maintenance mode — visitor-facing holding page while you work; admin bypass for logged-in users</li>
                </ul>
            </div>
        </div>
    </div>
</section>

<div class="dict-pull">
    <div class="dict-inner">
        <span class="dict-word">snap</span> <span class="dict-phon">/snæp/</span> <span class="dict-pos">n. or v.</span><br>
        Short for snapshot or image, a photographic picture. Not to be confused with what you do when you want your cat off the kitchen table <span class="dict-pos">(v.)</span> or what postal employees do on Monday morning <span class="dict-pos">(n.)</span>.
    </div>
</div>

<!-- ── WHO IS THIS FOR ──────────────────────────────────────────────────────── -->
<section id="for">
    <div class="wrap">
        <h2>Who Is This For?</h2>
        <div class="for-inner">
            <div class="for-statement">
                <p>People who are tired of the algorithm showing them non-stop ads for dental implants and hemorrhoid cream crammed between questionable preteen influencer and conspiracy theorist video shorts — instead of honest-ta-gawd photography.</p>
                <p style="margin-top: 1.2em;">If you shot film and want your scans living in your own database, not Facebook's. If you have cheap shared hosting and know what cPanel is. If you're sick of a dysfunctional algorithm deciding who sees your work and when. If you want to write about your photos, not just caption them. If you think photography deserves a big screen, not a phone thumb.</p>
                <p style="margin-top: 1.8em; color: #cccccc; font-family: 'Arial Black', Arial, sans-serif; font-size: 1.35rem; text-transform: uppercase; letter-spacing: 0.06em; line-height: 1.3;">SnapSmack is for the photographer who wants a site, not shite.</p>
            </div>
            <div>
                <ul class="for-list">
                    <li>You had a photo blog before social media ate it and you want it back</li>
                    <li>You shoot and write — not just post and disappear</li>
                    <li>You're done trusting platforms with your archive</li>
                    <li>You want full ownership: your domain, your database, your files</li>
                    <li>You think a photo blog should look exactly how you want it to look</li>
                    <li>You already have cheap shared hosting and you know how to use it</li>
                    <li>You believe photography deserves to be seen large — not squinted at on a phone</li>
                </ul>
            </div>
        </div>
    </div>
</section>

<!-- ── WORKING NOW ─────────────────────────────────────────────────────────── -->
<section id="working">
    <div class="wrap">
        <h2>Working Right Now</h2>
        <p class="lede">Alpha v<?php echo SS_PROMO_VERSION; ?> is running production sites today. Here's what's solid.</p>
        <div class="status-grid">
            <div class="status-card">
                <h3>Core CMS</h3>
                <p>Post, edit, publish, draft. Full image pipeline with EXIF extraction, auto-thumbnails, and palette detection. Archive calendar engine, category visibility controls, and a dedicated appearance system for archive, solo image, and static pages.</p>
            </div>
            <div class="status-card">
                <h3>Skin System</h3>
                <p>No plugins. Skins use a manifest and library system — checking out fonts and applets from the CMS. Each skin presents its own controls: colour pickers, sliders, texture options. No CSS knowledge needed.</p>
                <p style="margin-top:0.85em;">If you DO know CSS and JS; we've built in tools to let you customize your blog without having to edit files directly and all of your changes write to the DB.</p>
            </div>
            <div class="status-card">
                <h3>Smack Your Batch Up</h3>
                <p>Bulk post, audit, and repair your archive from the desktop. AI enrichment, Drive sync, batch rename, and duplicate title repair — proven on archives of 1,400+ files. Full rundown in Companion Tools below.</p>
            </div>
            <div class="status-card">
                <h3>FLKR FCKR</h3>
                <p>Your Flickr export, migrated to SnapSmack — images, titles, descriptions, tags, and original upload dates, spread across as many days as your server can handle so nothing lands out of order. Proven on a real 10,000-image archive, imported clean.</p>
                <p>To Flickr: treat other people's wallets as your own, and don't be shocked when someone helps them snap those wallets shut.</p>
            </div>
            <div class="status-card">
                <h3>Light Table</h3>
                <p>The online sorter. A full-screen drag-and-drop workbench right in your browser — albums, categories, and collections as drop targets, your whole image grid in the centre. Select a handful or a hundred, drag them where they belong, and the assignments stick. Built for organising a thousand-image back catalog without losing your mind. Its offline counterpart, GET YOUR SHIT SORTED, is in the pipeline.</p>
            </div>
            <div class="status-card">
                <h3>Community</h3>
                <p>Client/server community built into the software. Reactions, comments, following — limited to blog owners. No public signups, no spammers, no trolls.</p>
            </div>
            <div class="status-card">
                <h3>Two-Factor Authentication</h3>
                <p>TOTP-based 2FA compatible with any authenticator app — open-source ones first. QR setup, recovery codes, the works. Now required: a 30-day grace period after install, then it's mandatory for every admin. Lost everything? A documented emergency override means you're never locked out of your own site.</p>
            </div>
            <div class="status-card">
                <h3>Break the Glass</h3>
                <p>The recovery hatch for total lockout — password gone, 2FA gone, recovery codes gone, all at once. You keep a Break the Glass file; when you're locked out, you upload it to your site and you're back in. It's cryptographically signed, so a forged or substituted card is rejected outright — but the real one is a master key, so you guard it like one and never reveal which site it opens. The one and only time uploading a file by hand is the right answer.</p>
            </div>
            <div class="status-card">
                <h3>One-Click Installer</h3>
                <p>Guided web installer for new installs. Database setup, config generation, initial admin account — done in minutes.</p>
            </div>
            <div class="status-card">
                <h3>AI Writing Assistant</h3>
                <p>Spell check, grammar, rephrasing, and a full chat assistant in the post editor. Your API key, your provider — Claude, Gemini, or ChatGPT.</p>
            </div>
            <div class="status-card">
                <h3>SMACKONEOUT</h3>
                <p>One image, one post, chronological, yours. The classic Pixelpost experience — the photoblogging format that defined the early web and quietly disappeared when the platforms took over. Solo image posting done the way it was always supposed to be done.</p>
            </div>
            <div class="status-card">
                <h3>GRAMOFSMACK</h3>
                <p>The 2016 Instagram experience — before the algorithm ate it. Multiple images per post, carousel layout, curated grid. Post up to 30 images at a time in carousel rows, panorama layout included. Your grid, your order, no Reels, no suggested posts, no one deciding what your followers see.</p>
            </div>
            <div class="status-card">
                <h3>Albums &amp; More</h3>
                <p>Date archives, custom albums, blogroll, and static pages with full shortcode support. Collections let you curate themed groups of images — cover photo, title, custom description, your order. Browse or slideshow. The chronological archive for your everyday posts; Collections for the work you want to present properly.</p>
            </div>
            <div class="status-card">
                <h3>Updates</h3>
                <p>Signed releases, cryptographic verification, one-click in-admin update runner. The migration system applies every schema change your install has ever missed, in order, without a database admin in sight. Schema sync catches stragglers. Rollback support if it goes sideways. No FTP. No SSH. No drama.</p>
            </div>
            <div class="status-card">
                <h3>Built-In Help</h3>
                <p>Comprehensive help system accessible from every admin page. Each skin injects its own section describing how its unique features work, so the documentation always matches what you have installed.</p>
            </div>
            <div class="status-card">
                <h3>Photo Editor</h3>
                <p>Crop, rotate, brightness, contrast, and sharpen — right inside the admin. No round-tripping to Lightroom for a quick fix before posting. Non-destructive: edits apply to the web copy, originals stay untouched.</p>
            </div>
            <div class="status-card">
                <h3>Media Gallery</h3>
                <p>Browse your entire image library by album, camera, date, or extracted colour palette. Bulk tagging, bulk album assignment, and a proper visual picker when composing posts. Your archive, actually usable.</p>
            </div>
            <div class="status-card">
                <h3>Multisite Management</h3>
                <p>Run a fleet of SnapSmack sites from a single hub. Live heartbeat monitoring, aggregated comment queues, cross-posting, fleet-wide backup health, and SSO drill-through to any spoke. One dashboard. All your sites.</p>
            </div>
            <div class="status-card">
                <h3>Fleet Updates</h3>
                <p>Your hub knows which spokes are running old code. Hit UPDATE ALL BEHIND and walk away — or push a single site if you're being surgical. No logging into each server like some kind of animal.</p>
            </div>
            <div class="status-card">
                <h3>My Blogs</h3>
                <p>The hub manages a My Blogs category that automatically populates every spoke's blogroll with your whole network. Site taglines as descriptions, zero manual entry. Your fleet, cross-promoted, hands-off.</p>
            </div>
            <div class="status-card">
                <h3>Comment Moderation</h3>
                <p>Approve, reject, or delete comments from a dedicated moderation panel. Aggregated across all your sites in multisite mode. Flag for review, bulk actions, and a full comment history per post.</p>
            </div>

            <div class="status-card">
                <h3>Smack Up Your Backup</h3>
                <p>Desktop app for large archives. Gracefully and reliably handles multi-gigabyte backups that would flatten a shared host if run server side. One-click or scheduled backup of all your SnapSmack sites at once. Differential FTP sync, cloud storage push, three-way file audit, and cold-start recovery without a working install. Always free.</p>
            </div>
            <div class="status-card">
                <h3>THE GRID</h3>
                <p>The flagship GRAMOFSMACK skin. Clean columns, tight spacing, single-image punctuation rows for visual impact — the grid format done properly. It's the reference implementation for what GRAMOFSMACK can look like when a skin is built for it from the ground up, and it ships with a few surprises.</p>
            </div>
            <div class="status-card">
                <h3>UNZUCKER</h3>
                <p>Your years of careful Instagram curation aren't gone. They're just hostage. UNZUCKER takes your Instagram export and migrates it to SnapSmack — images, captions, hashtags, and original post dates all intact. Posts at whatever rate your server can handle, spread across as many days as you need. Phase 1 is shipping now. We eat our own dog food first.</p>
            </div>
            <div class="status-card">
                <h3>SMACKBACK &amp; Breach Lockdown</h3>
                <p>File-integrity monitoring baked into every install. If something tampers with your code, SMACKBACK catches it — and seals the site. The public side drops behind a holding page so a compromised install can't push bad code at your readers, and admin is locked to the essentials (updater, backups, support forum) until you fix it. Turning the watchdog off takes your password and your 2FA code.</p>
            </div>
            <div class="status-card">
                <h3>SEO, Done Lightly</h3>
                <p>Meta descriptions, a per-page title template, and an auto-generated XML sitemap. Honest social-share previews: your link shows a photo you actually chose — never a logo or a random recent shot. No keyword-stuffing dashboards, no bloat. Just the basics, set right.</p>
            </div>
            <div class="status-card">
                <h3>Page Cache</h3>
                <p>Optional, off by default. Caches public pages for anonymous visitors to take the load off your server — never logged-in admins, never filtered views. Clears itself the instant you publish or edit. Dev mode pauses it for anywhere from five minutes to a week while you work, then switches itself back on.</p>
            </div>
            <div class="status-card">
                <h3>Thomas the Bear</h3>
                <p>He's adorable, he'll make your day, and he lives in every installation of SnapSmack. You can find him and his story if you're clever and determined enough.</p>
            </div>
        </div>
    </div>
</section>

<div class="dict-pull">
    <div class="dict-inner">
        <span class="dict-word">smack</span> <span class="dict-phon">/smæk/</span> <span class="dict-pos">v.</span><br>
        A sharp blow, a loud kiss, or a small single-masted fishing vessel. In this context, what you do to a photograph when you publish it to the internet with zero regard for the algorithm. See also: <em>heroin</em> (unrelated but equally addictive).
    </div>
</div>

<!-- ── THREE WAYS IN ─────────────────────────────────────────────────────── -->
<section id="modes">
    <div class="wrap">
        <h2>Three Ways to Play</h2>
        <p class="lede">When's the last time you were part of a three way?</p>

        <div class="modes-grid">

            <div class="mode-card">
                <div class="mode-num">Install Mode 01</div>
                <h3>SMACKONEOUT</h3>
                <div class="mode-tagline">One image. One post. Yours.</div>
                <p>The classic photoblogging format — the kind that defined the early web before Instagram convinced everyone their archive needed to be curated for someone else's algorithm.</p>
                <p>You shoot. You post. Chronological, clean, no distractions. One image at a time with a title, description, tags, and as much or as little writing as you want alongside it. No grid pressure. No performance. Just your photographs and a place to put them that you actually own.</p>
            </div>

            <div class="mode-card">
                <div class="mode-num">Install Mode 02</div>
                <h3>GRAMOFSMACK</h3>
                <div class="mode-tagline">Got Zuck-fucked?</div>
                <p>The classic Insta 3 across square feed is back. Multiple images per post. Carousel layout. Curated grid. Everything Instagram used to be in 2016 before Reels, before suggested posts, before the app decided your followers should see strangers' content instead of yours.</p>
                <p>Post up to 30 images at a time in carousel rows, panorama layout included. We've added the power toys Zuck denied you to make sharing easy. Your grid, your order, no algorithm between you and your audience. A skinnable Instagram experience, running on your server, owned by you. Do a gram and feel good about it.</p>
            </div>

            <div class="mode-card">
                <div class="mode-num">Install Mode 03</div>
                <h3>SMACKTALK</h3>
                <div class="mode-tagline">For photographers who write.</div>
                <p>Longform photo essays, diary entries, narrative posts — writing and images at equal billing. Not a caption under a photo. Not a photo illustrating a blog post. Both at once, stories and words woven together into to a beautiful tapestry for the creator who cuts their posts to measure.</p>
                <p>MOSAIC, a layout engine built into the post editor, lets you arrange multiple images into justified panels that flow inline with your text. The web 2.0 blogging experience before it got buried under plugins, themes, and someone else's roadmap. Primitive by design, and yours.</p>
                <span class="mode-coming">Coming Beta</span>
            </div>

        </div>
    </div>
</section>

<!-- ── PICK A COLOUR ─────────────────────────────────────────────────────── -->
<section id="admin-skins">
    <div class="wrap">
        <h2>Pick a Colour, Any Colour</h2>
        <p class="lede">The admin interface has sixteen colour themed skins available for it (so far). This one is Bumblebee, for those photographers who are ready to roll out. Don't see something you like? Use the skin designer to create something that tickles your pickle and add it to the repository for others to enjoy, too.</p>
        <div class="admin-shots" style="margin-top: 24px;">
            <a href="img/admin-dashboard.png" data-lb="img/admin-dashboard.png"><img src="img/admin-dashboard.png" alt="SnapSmack admin — dashboard"></a>
            <a href="img/admin-newpost.png" data-lb="img/admin-newpost.png"><img src="img/admin-newpost.png" alt="SnapSmack admin — new post"></a>
            <a href="img/admin-global.png" data-lb="img/admin-global.png"><img src="img/admin-global.png" alt="SnapSmack admin — global settings"></a>
        </div>
    </div>
</section>


<!-- ── SECURITY ──────────────────────────────────────────────────────────── -->
<section id="security">
    <div class="wrap">
        <h2>Security</h2>
        <p class="lede">Eight layers. Local fingerprint ban, fleet-wide ban sync, community reputation scoring, the SMACKATTACK reputation network, stylometric evasion detection, file integrity monitoring, IP SMACKER admin login hardening, and cryptographically signed releases. The more work a troll or attacker has to do, the more likely they are to go bother someone else. We work them harder than an Amazon employee on Black Friday.</p>

        <div class="security-layers">

            <div class="security-layer">
                <div class="layer-num">Layer 1 — Local</div>
                <h3>SMACK DAB</h3>
                <p>Every comment submission is fingerprinted — canvas, WebGL, screen geometry, timezone, language, hardware concurrency — hashed into a SHA-256 identity. No cross-site tracking. No personal data stored. Just a behavioural signature that follows the device, not the account.</p>
                <p>Ban by device fingerprint, IP, or email hash. Banned submissions get a plausible-looking success response — the harasser never knows they're blocked. They just keep shouting into a room where nobody can hear them. Keyword and phrase banning on top: exact match, substring, or full regex, with silent reject or flag-for-review severity.</p>
                <p>Akismet runs alongside as a first-pass filter. Known spam never reaches the fingerprinting layer. Two independent systems, two different threat models, one comment box.</p>
            </div>

            <div class="security-layer">
                <div class="layer-num">Layer 2 — Your Network</div>
                <h3>SMACK DOWN</h3>
                <p>Running a fleet of sites on multisite? Ban a troll on one and they're banned everywhere. SMACK DOWN syncs hashed ban lists across every spoke in your network automatically — no manual propagation, no admin intervention. Delta sync keeps the payload small. Only SHA-256 hashes travel between sites. The original values never leave the site that generated them.</p>
                <p>The hub maintains a shared ban registry. Each entry tracks how many distinct spokes have reported the same hash — a high report count means a confirmed cross-network threat. False positives can be soft-cleared without losing the audit trail.</p>
                <p>If your hub runs Akismet, every spoke does too. One key, managed centrally, pushed automatically. No per-site configuration. No account juggling.</p>
            </div>

            <div class="security-layer">
                <div class="layer-num">Layer 3 — The Network</div>
                <h3>SMACK UP</h3>
                <p>Voluntary community reputation scoring. Participating SnapSmack blogs report bad actor fingerprints — device hashes, IP hashes, email hashes — to the network. Each report is scored by weighted site reputation: an established blog with years of posts and a clean track record carries more authority than a brand-new install with one post. Scores decay over time so old incidents don't follow people forever.</p>
                <p>Five threat levels: green, yellow, orange, red, black. Each blog owner sets their own auto-ban threshold independently. Community allow-votes roll back false positives — approve a flagged commenter on your blog and their score drops across the whole network. No central authority decides who is banned. The community does, collectively, and no single site controls the outcome.</p>
            </div>

            <div class="security-layer">
                <div class="layer-num">Layer 4 — The Network</div>
                <h3>SMACKATTACK</h3>
                <p>SMACKATTACK is the central reputation server that powers Layers 3 and 5. It receives reports from participating blogs, maintains the cross-network scoring database, coordinates stylometric vector sharing for GOBSMACKED, and issues threat-level responses to incoming queries. It is infrastructure, not a product — it runs in the background and blogs talk to it automatically when opted in.</p>
                <p>The server is operated by the SnapSmack project. It stores no raw comment text, no personal data, and no readable fingerprint values — only SHA-256 hashes, numeric style vectors, and scores. The trust model is federated: SMACKATTACK aggregates and scores, but individual blogs make their own ban decisions. SMACKATTACK cannot force a ban on any site.</p>
                <p>Participation is opt-in. A single toggle in admin settings connects or disconnects your site from the network. If you pull out, your historical reports remain in the aggregate but your site stops querying and contributing. No lock-in.</p>
            </div>

            <div class="security-layer">
                <div class="layer-num">Layer 5 — The Network</div>
                <h3>GOBSMACKED</h3>
                <p>When a commenter is banned, a compact 25-dimension numeric fingerprint of how they write is extracted from their comment history — sentence rhythm, punctuation habits, function word ratios, capitalisation patterns. Not the text. The signature of the hand that wrote it.</p>
                <p>If the same person returns on a new device, new IP, new email — they write the same way. GOBSMACKED compares incoming style vectors against the signatures of known-banned accounts across the network. The troll who thinks clearing their cookies was enough is in for a surprise.</p>
                <p>Network opt-in. Requires SMACKATTACK participation. Raw comment text never leaves your server.</p>
            </div>

            <div class="security-layer">
                <div class="layer-num">Layer 6 — Your Install</div>
                <h3>SMACKBACK</h3>
                <p>SMACKBACK is automated sentinel software that ships in every install and runs with no configuration. It hashes every PHP, JavaScript, and CSS file at install time and re-verifies them on a schedule — and on every admin login and skin load — so a modified file is caught fast.</p>
                <p>Confirmed tamper is unmissable: admin switches to a high-contrast BREACH skin that can't be dismissed until the incident is resolved, an email fires, and the public side drops behind a holding page so a compromised install can't serve bad code to your readers.</p>
                <p>SMACKBACK is also federated. Opted-in installs report confirmed breaches — file hashes and incident metadata only, never your content — to the project's central server, where they're cross-correlated across the whole network. When reports cross a threshold it's not coincidence, it's an attack on the software itself: a network <strong>yellow alert</strong> goes out to every site telling owners to change passwords, rotate keys, pull the newest signed release, and watch the forum and dashboard for updates. FAFO; come at our blogs and everyone knows right away.</p>
            </div>

            <div class="security-layer">
                <div class="layer-num">Layer 7 — The Admin</div>
                <h3>IP SMACKER</h3>
                <p>The admin login is hardened at three levels before a password is ever checked. First: blank User-Agent strings and known scripting signatures (curl, python-requests, sqlmap, Hydra, WPScan, and a dozen others) get a silent 403 — no response body, no timing information, nothing useful to enumerate. Real browsers always send a UA. Scanners often don't.</p>
                <p>Second: the login URL is not <code>/login</code> or <code>/admin</code>. It's a configurable slug that only you know. Direct PHP file access returns a 403. A pre-shared recovery token lets you redirect to the real slug if you forget it — the token itself is never exposed in normal operation.</p>
                <p>Third: five failed login attempts within ten minutes triggers an automatic seven-day IP ban. The ban is recorded in the database, checked on every subsequent request, and manageable from the admin panel under IP SMACKER. Manual bans and lifts are one click. No SSH, no config files, no server restart.</p>
            </div>

            <div class="security-layer">
                <div class="layer-num">Layer 8 — The Software</div>
                <h3>SNAP DECISION</h3>
                <p>Every SNAPSMACK release is cryptographically signed. The signing key lives on a hardware token, not a hard drive. The installer verifies the signature before unpacking anything. If verification fails, nothing installs.</p>
                <p>SHA-256 checksums for every release file are published alongside the download. Every release commit is tagged in git and the tag is signed — the full chain of custody from code to installer is publicly verifiable.</p>
                <p>Regular security audits are performed across every release. Reports are published in full and committed to the public git repository — the audit trail is as open as the code.</p>
                <p>No external packages fetched automatically at install time. Any third-party JavaScript is copied directly into the codebase and reviewed before it ships — not pulled from a remote registry on the fly. The most common supply-chain attack vector against PHP web software doesn't exist here because the surface doesn't exist.</p>
            </div>

        </div>
    </div>
</section>


<!-- ── THEMES IN THE WILD ──────────────────────────────────────────────────── -->
<section id="themes">
    <div class="wrap">
        <h2>Production Ready Skins</h2>
        <p class="lede">Seven skins. Seven live sites. Not demos stuffed with stock photos — each is a real photographer's actual online portfolio. Click any screenshot to zoom in, then visit the live site. Hover a card for live site stats. Nine more skins are in development.</p>
        <div class="theme-grid">

            <div class="theme-card" data-stats="<?php echo ss_card_stats('unzucked.ca', $_demo_stats); ?>">
                <div class="theme-main-shot">
                    <a href="img/grid-landing.png" data-lb="img/grid-landing.png">
                        <img src="img/grid-landing.png" alt="The Grid — landing">
                    </a>
                </div>
                <div class="theme-thumbs">
                    <a href="img/grid-solo.png" data-lb="img/grid-solo.png">
                        <img src="img/grid-solo.png" alt="The Grid — post view">
                    </a>
                    <a href="img/grid-page.png" data-lb="img/grid-page.png">
                        <img src="img/grid-page.png" alt="The Grid — static page">
                    </a>
                </div>
                <div class="theme-info">
                    <h3>The Grid</h3>
                    <p>The Instagram-style square grid you already know — carousel posts, panorama trigrams, and a full post modal with likes and comments. The familiar feed, self-hosted and free of billionaires.</p>

                    <a href="https://unzucked.ca" target="_blank" class="theme-link"><span class="theme-link-label">View live site</span>unzucked.ca →</a>
                </div>
            </div>

            <div class="theme-card" data-stats="<?php echo ss_card_stats('photowalk.ing', $_demo_stats); ?>">
                <div class="theme-main-shot">
                    <a href="img/50shades-landing.png" data-lb="img/50shades-landing.png">
                        <img src="img/50shades-landing.png" alt="50 Shades of Noah Grey — landing">
                    </a>
                </div>
                <div class="theme-thumbs">
                    <a href="img/50shades-archive.png" data-lb="img/50shades-archive.png">
                        <img src="img/50shades-archive.png" alt="50 Shades — archive">
                    </a>
                    <a href="img/50shades-page.png" data-lb="img/50shades-page.png">
                        <img src="img/50shades-page.png" alt="50 Shades — pages">
                    </a>
                </div>
                <div class="theme-info">
                    <h3>50 Shades of Noah Grey</h3>
                    <p>Moody, dark, and atmospheric. Three tone variants — dark, medium, light. Cropped grid archive, heavy typographic contrast.</p>

                    <a href="https://photowalk.ing" target="_blank" class="theme-link"><span class="theme-link-label">View live site</span>photowalk.ing →</a>
                </div>
            </div>

            <div class="theme-card" data-stats="<?php echo ss_card_stats('hekeepsdroningon.ca', $_demo_stats); ?>">
                <div class="theme-main-shot">
                    <a href="img/galleria-landing.png" data-lb="img/galleria-landing.png">
                        <img src="img/galleria-landing.png" alt="Galleria — landing">
                    </a>
                </div>
                <div class="theme-thumbs">
                    <a href="img/galleria-archive.png" data-lb="img/galleria-archive.png">
                        <img src="img/galleria-archive.png" alt="Galleria — archive">
                    </a>
                    <a href="img/galleria-page.png" data-lb="img/galleria-page.png">
                        <img src="img/galleria-page.png" alt="Galleria — pages">
                    </a>
                </div>
                <div class="theme-info">
                    <h3>Galleria</h3>
                    <p>Clean editorial layout. Full-width images, minimal chrome, maximum photograph. Fully customizable colour palettes and frame options.</p>

                    <a href="https://hekeepsdroningon.ca" target="_blank" class="theme-link"><span class="theme-link-label">View live site</span>hekeepsdroningon.ca →</a>
                </div>
            </div>

            <div class="theme-card" data-stats="<?php echo ss_card_stats('pixhellated.ca', $_demo_stats); ?>">
                <div class="theme-main-shot">
                    <a href="img/impact-landing.png" data-lb="img/impact-landing.png">
                        <img src="img/impact-landing.png" alt="Impact Printer — landing">
                    </a>
                </div>
                <div class="theme-thumbs">
                    <a href="img/impact-archive.png" data-lb="img/impact-archive.png">
                        <img src="img/impact-archive.png" alt="Impact Printer — archive">
                    </a>
                    <a href="img/impact-page.png" data-lb="img/impact-page.png">
                        <img src="img/impact-page.png" alt="Impact Printer — pages">
                    </a>
                </div>
                <div class="theme-info">
                    <h3>Impact Printer</h3>
                    <p>High-contrast monochromatic layout with a mechanical print aesthetic. Designed for photography that hits hard.</p>

                    <a href="https://pixhellated.ca" target="_blank" class="theme-link"><span class="theme-link-label">View live site</span>pixhellated.ca →</a>
                </div>
            </div>

            <div class="theme-card" data-stats="<?php echo ss_card_stats('wateronthebrain.ca', $_demo_stats); ?>">
                <div class="theme-main-shot">
                    <a href="img/rationalgeo-landing.png" data-lb="img/rationalgeo-landing.png">
                        <img src="img/rationalgeo-landing.png" alt="Rational Geo — landing">
                    </a>
                </div>
                <div class="theme-thumbs">
                    <a href="img/rationalgeo-archive.png" data-lb="img/rationalgeo-archive.png">
                        <img src="img/rationalgeo-archive.png" alt="Rational Geo — archive">
                    </a>
                    <a href="img/rationalgeo-page.png" data-lb="img/rationalgeo-page.png">
                        <img src="img/rationalgeo-page.png" alt="Rational Geo — pages">
                    </a>
                </div>
                <div class="theme-info">
                    <h3>Rational Geo</h3>
                    <p>Bold geometric layout with strong structural typography. Light and dark variants. Makes the image the undisputed lead.</p>

                    <a href="https://wateronthebrain.ca" target="_blank" class="theme-link"><span class="theme-link-label">View live site</span>wateronthebrain.ca →</a>
                </div>
            </div>

            <div class="theme-card" data-stats="<?php echo ss_card_stats('foundtextures.ca', $_demo_stats); ?>">
                <div class="theme-main-shot">
                    <a href="img/truegrit-landing.png" data-lb="img/truegrit-landing.png">
                        <img src="img/truegrit-landing.png" alt="True Grit — landing">
                    </a>
                </div>
                <div class="theme-thumbs">
                    <a href="img/truegrit-archive.png" data-lb="img/truegrit-archive.png">
                        <img src="img/truegrit-archive.png" alt="True Grit — archive">
                    </a>
                    <a href="img/truegrit-page.png" data-lb="img/truegrit-page.png">
                        <img src="img/truegrit-page.png" alt="True Grit — pages">
                    </a>
                </div>
                <div class="theme-info">
                    <h3>True Grit</h3>
                    <p>Textured, tactile, analogue in feel. Wood grain panels, bevel options, warm tones. Built for film shooters and texture hunters.</p>

                    <a href="https://foundtextures.ca" target="_blank" class="theme-link"><span class="theme-link-label">View live site</span>foundtextures.ca →</a>
                </div>
            </div>

            <div class="theme-card" data-stats="<?php echo ss_card_stats('acolourlesslife.ca', $_demo_stats); ?>">
                <div class="theme-main-shot">
                    <a href="img/chaplin-landing.png" data-lb="img/chaplin-landing.png">
                        <img src="img/chaplin-landing.png" alt="Chaplin — single image view">
                    </a>
                </div>
                <div class="theme-thumbs">
                    <a href="img/chaplin-archive.png" data-lb="img/chaplin-archive.png">
                        <img src="img/chaplin-archive.png" alt="Chaplin — archive">
                    </a>
                    <a href="img/chaplin-page.png" data-lb="img/chaplin-page.png">
                        <img src="img/chaplin-page.png" alt="Chaplin — static page">
                    </a>
                </div>
                <div class="theme-info">
                    <h3>Chaplin</h3>
                    <p>Silent film era. Art Deco border system, real-time film grain and scratch effects, B&W photo treatment. Built for black and white.</p>
                    <a href="https://acolourlesslife.ca" target="_blank" class="theme-link"><span class="theme-link-label">View live site</span>acolourlesslife.ca →</a>
                </div>
            </div>

            <div class="theme-card" data-stats="<?php echo ss_card_stats('foreverphotograph.ing', $_demo_stats); ?>">
                <div class="theme-main-shot">
                    <a href="img/slickr-landing.png" data-lb="img/slickr-landing.png">
                        <img src="img/slickr-landing.png" alt="Slickr — photostream">
                    </a>
                </div>
                <div class="theme-thumbs">
                    <a href="img/slickr-archive.png" data-lb="img/slickr-archive.png">
                        <img src="img/slickr-archive.png" alt="Slickr — albums">
                    </a>
                    <a href="img/slickr-page.png" data-lb="img/slickr-page.png">
                        <img src="img/slickr-page.png" alt="Slickr — about page">
                    </a>
                </div>
                <div class="theme-info">
                    <h3>Slickr</h3>
                    <p>The Flickr replacement. Justified photostream, albums and collections, a stats-bar masthead with view counts, EXIF on every shot. Built for everyone fed up with Flickr.</p>
                    <a href="https://foreverphotograph.ing" target="_blank" class="theme-link"><span class="theme-link-label">View live site</span>foreverphotograph.ing →</a>
                </div>
            </div>

            <div class="theme-card" data-stats="<?php echo ss_card_stats('theschoolofhardnocks.ca', $_demo_stats); ?>">
                <div class="theme-main-shot">
                    <a href="img/parade-landing.png" data-lb="img/parade-landing.png">
                        <img src="img/parade-landing.png" alt="Parade — landing">
                    </a>
                </div>
                <div class="theme-thumbs">
                    <a href="img/parade-archive.png" data-lb="img/parade-archive.png">
                        <img src="img/parade-archive.png" alt="Parade — archive">
                    </a>
                    <a href="img/parade-page.png" data-lb="img/parade-page.png">
                        <img src="img/parade-page.png" alt="Parade — pages">
                    </a>
                </div>
                <div class="theme-info">
                    <h3>Parade</h3>
                    <p>Pride, in motion. Rainbow, Bi, Trans, and Two-Spirit flag palettes, an animated waving-flag or fireworks background, colour-shifting tile borders, configurable effects. Built to celebrate.</p>
                    <a href="https://theschoolofhardnocks.ca" target="_blank" class="theme-link"><span class="theme-link-label">View live site</span>theschoolofhardnocks.ca →</a>
                </div>
            </div>

        </div>
    </div>
</section>

<!-- ── TOOLS ───────────────────────────────────────────────────────────────── -->
<section id="tools">
    <div class="wrap">
        <p class="section-kicker">Four ready, more coming</p>
        <h2>Companion Tools</h2>
        <p class="lede" style="margin-bottom: 48px;">Desktop apps that do the heavy lifting alongside SnapSmack. Free, same as the CMS.</p>

        <div class="tool-block">
            <div class="tool-screenshot">
                <div class="tool-screenshot-duo">
                    <a href="img/sybu-credentials.png" data-lb="img/sybu-credentials.png">
                        <img src="img/sybu-credentials.png" alt="Smack Your Batch Up — connection and credentials">
                    </a>
                    <a href="img/sybu-uploading.png" data-lb="img/sybu-uploading.png">
                        <img src="img/sybu-uploading.png" alt="Smack Your Batch Up — batch posting in progress">
                    </a>
                </div>
            </div>
            <div class="tool-copy">
                <h3>Windows / Linux</h3>
                <h2>Smack Your<br>Batch Up</h2>
                <p>Bulk-post an entire shoot in one go. Load a manifest, drag to reorder, assign categories and albums per image, embed EXIF copyright metadata, and post the batch to your site — all without touching the browser. Then flip to Audit to check for duplicate titles or missing Drive links, and let Repair fix them automatically.</p>
                <p>Connects directly to your SnapSmack admin. Borrows your active colour scheme on login. Keeps the session alive through long jobs. Hit Enrich with Gemini and watch titles, tags, categories, and dominant colour palettes populate live, image by image, as the AI works through your queue. Google Drive high-res download links are supported and optional. The Audit tab connects live to your site and surfaces duplicate title groups, Drive link coverage stats, and posts missing download URLs. The Repair tab batch-renames Drive files to their post ID, re-enriches duplicates with fresh unique Gemini titles, and lets you backfill missing links one post at a time.</p>
                <p class="dl-note">[ Windows 10/11 · Linux 64-bit · Free download from the admin tools page after install ]</p>
            </div>
        </div>

        <hr class="tool-divider">

        <div class="tool-block tool-block--reversed">
            <div class="tool-screenshot">
                <div class="tool-screenshot-trio">
                    <a href="img/suyb-backupinprogress-01.png" data-lb="img/suyb-backupinprogress-01.png">
                        <img src="img/suyb-backupinprogress-01.png" alt="Smack Up Your Backup — backup uploading">
                    </a>
                    <a href="img/suyb-settings-02.png" data-lb="img/suyb-settings-02.png">
                        <img src="img/suyb-settings-02.png" alt="Smack Up Your Backup — settings">
                    </a>
                    <a href="img/suyb-recovery-03.png" data-lb="img/suyb-recovery-03.png">
                        <img src="img/suyb-recovery-03.png" alt="Smack Up Your Backup — help and recovery">
                    </a>
                </div>
            </div>
            <div class="tool-copy">
                <h3>Windows / Linux</h3>
                <h2>Smack Up Your<br>Backup</h2>
                <p>Your archive is only as safe as your last backup. Smack Up Your Backup runs on your desktop and handles multi-gigabyte backup and restore operations that would flatten a shared host or time out if run server-side — completing cleanly in the background, however long it takes.</p>
                <p>Per-site profiles store your connection settings once. Choose your backup method: differential FTP sync for incremental server backups, cloud push to Google Drive or OneDrive for redundant cloud storage, or local backups only. One-click backups or scheduled runs on any cadence you set. Crash recovery checkpoints mean a restart picks up where it left off — not from zero.</p>
                <p>The three-way file audit runs simultaneously across your local backup, the server, and cloud storage — revealing exactly what's missing, what's stale, and what's in sync across all three locations. Cold-start recovery lets you restore from a backup ZIP even if your install is completely gone. Session logs track every operation so you know what happened and when. Completely free. No paid tier. No hidden limits. No locking your data into our system. No bullshit.</p>
                <p>If your high-res originals live on Google Drive or another cloud service, SUYB backs those up too — cloud to cloud, directly between providers, without pulling the files down to your desktop first. For photographers running large archives on Drive, that's the one that matters.</p>
                <p class="dl-note">[ Windows 10/11 · Linux 64-bit · Free download from the admin tools page after install ]</p>
            </div>
        </div>

        <hr class="tool-divider">

        <div class="tool-block">
            <div class="tool-screenshot">
                <div class="tool-screenshot-duo">
                    <a href="img/unzucker-config.png" data-lb="img/unzucker-config.png">
                        <img src="img/unzucker-config.png" alt="UNZUCKER — configuration and connection">
                    </a>
                    <a href="img/unzucker-gridsorter.png" data-lb="img/unzucker-gridsorter.png">
                        <img src="img/unzucker-gridsorter.png" alt="UNZUCKER — post grid and trigram sorter">
                    </a>
                </div>
            </div>
            <div class="tool-copy">
                <h3>Windows / Linux</h3>
                <h2>THE UNZUCKER</h2>
                <p>Your years of careful Instagram curation aren't gone. They're just hostage. UNZUCKER takes your Instagram data export and migrates it to SnapSmack — images, captions, hashtags, and original post dates all intact. Every carousel, every single shot, every carefully sequenced grid row. Posted at whatever rate your server can handle, spread across as many days as you need. Your archive, back in your hands, on your server.</p>
                <p>Point it at your Instagram export folder, connect to your site, and sort your posts into the order you want them to land. UNZUCKER's grid view shows your entire archive the way it looked on Instagram — three columns, your photos, your sequence. Lock trigram groups to stitch panoramas and multi-part compositions across the grid exactly as intended. Hit Transfer &amp; Post and walk away. Throttle controls keep shared-host admins from having an aneurysm. Off-peak mode holds fire during business hours and picks back up automatically overnight.</p>
                <p>Phase 1 is shipping now. We eat our own dog food first — unzucked.ca is running it in production.</p>
                <p class="dl-note">[ Windows 10/11 · Linux 64-bit · Free download from the admin tools page after install ]</p>
            </div>
        </div>

        <hr class="tool-divider">

        <div class="tool-block tool-block--reversed">
            <div class="tool-screenshot">
                <div class="tool-screenshot-duo">
                    <a href="img/flkr-fckr.png" data-lb="img/flkr-fckr.png">
                        <img src="img/flkr-fckr.png" alt="FLKR FCKR — Flickr import">
                    </a>
                    <a href="img/slickr-landing.png" data-lb="img/slickr-landing.png">
                        <img src="img/slickr-landing.png" alt="FLKR FCKR — imported Flickr archive live on a SnapSmack site">
                    </a>
                </div>
            </div>
            <div class="tool-copy">
                <h3>Windows / Linux</h3>
                <h2>FLKR FCKR</h2>
                <p>Your Flickr export, migrated to SnapSmack — images, titles, descriptions, tags, and original upload dates, spread across as many days as your server can handle so nothing lands out of order. Point it at your export, connect to your site, and let it run. Proven on a real 10,000-image archive, imported clean.</p>
                <p>To Flickr: treat other people's wallets as your own, and don't be shocked when someone helps them snap those wallets shut.</p>
                <p class="dl-note">[ Windows 10/11 · Linux 64-bit · Free download from the admin tools page after install ]</p>
            </div>
        </div>

    </div>
</section>

<!-- ── COMING SOON ─────────────────────────────────────────────────────────── -->
<section id="coming">
    <div class="wrap">
        <h2>Coming Next</h2>
        <p class="lede" style="margin-bottom: 8px;">What's in the pipeline for the Beta release.</p>
        <div class="coming-grid">
            <div class="coming-item">
                <span class="tag">Skin</span>
                <h3>LOOKBOOK</h3>
                <p>A clean image portfolio skin for hobbyist and amateur photographers who want their work presented seriously. High-res first, minimal chrome, built for the work to do the talking. Next skin to ship before beta.</p>
            </div>
            <div class="coming-item">
                <span class="tag">Skin</span>
                <h3>52 CARD PICKUP</h3>
                <p>An interactive photo viewer skin. Not a grid, not a feed — something else entirely. Next skin to ship before beta.</p>
            </div>
            <div class="coming-item">
                <span class="tag">Engine</span>
                <h3>Special Effects</h3>
                <p>A visual effects engine to pimp up your blog's appearance. Parallax scrolling, reveal animations, hover effects, lightbox transitions — your images will dance off the page. Literally. Skins opt in via the manifest. You control how far it goes.</p>
            </div>
<div class="coming-item">
                <span class="tag">Beta</span>
                <h3>Oh Snap! — Skin Builder</h3>
                <p>Design your own SnapSmack skin without touching code. Oh Snap! pulls your manifest, exposes every control, and lets you build and preview your skin visually in real time. AI-assisted for those who want a hand. Full CSS editor for those who'd rather just write it themselves. Ships with Beta.</p>
            </div>
            <div class="coming-item">
                <span class="tag">Install Mode</span>
                <h3>SMACKTALK</h3>
                <p>For photographers who write. Longform photo essays, diary entries, personal narratives — writing and images at equal billing, the way the web was supposed to work before everything became a feed. No follower counts. No suggested posts. No algorithm deciding who reads you. Just a blog, a domain you own, and a place to put words and pictures together the way you mean them. Primitive by design, and yours to keep.</p>
            </div>
            <div class="coming-item">
                <span class="tag">Editor Engine</span>
                <h3>MOSAIC</h3>
                <p>A layout engine built into the SMACKTALK post editor. Arrange multiple images into justified panel rows that flow inline with your writing — not below it, not in a separate gallery, but woven into the text as part of the story. Two images side by side. Three in a justified row. A full-width single shot to punctuate a moment. MOSAIC treats your photos as editorial elements, not attachments.</p>
            </div>
            <div class="coming-item">
                <span class="tag">Tools</span>
                <h3>SNAP OUT OF IT</h3>
                <p>Picked the wrong install mode? It happens. SNAP OUT OF IT is the desktop tool that moves your whole site from one SnapSmack mode to another — SMACKONEOUT, GRAMOFSMACK, or SMACKTALK — content intact, for when your blog outgrows the shape you first gave it. It runs on the desktop for a reason: grinding a large archive through a mode change on a shared host would flatten the poor thing. Windows and Linux, as ever. No more "install fresh and start over."</p>
            </div>
            <div class="coming-item">
                <span class="tag">Tools</span>
                <h3>MIDNIGHT MOVE</h3>
                <p>The fourth desktop tool. MIDNIGHT MOVE rescues your own work off a dying website before it takes your photographs with it — hand-coded HTML, an ancient CMS install, the site you built in 2006 and can't log into anymore — and moves it into SnapSmack. Migration when you still have the keys, rescue when you've lost them. FTP where you can get it, a public spider where you can't, and AI to make sense of whatever schema it finds. Save the work. Move it somewhere you own.</p>
            </div>
            <div class="coming-item">
                <span class="tag">Tools</span>
                <h3>MEMENTO MORI</h3>
                <p>A sibling to MIDNIGHT MOVE, for the hardest version of the job. Where MIDNIGHT MOVE rescues your own work, MEMENTO MORI helps friends and family preserve a photographer's after they've passed — the photographs, and the words where they survive — so a life's work doesn't quietly go dark. It works from a hard drive, a folder, or whatever of their site is still online. Nothing is selected without you, and nothing is ever deleted. Quiet, patient, and built for the people who've been left to carry it.</p>
            </div>
            <div class="coming-item">
                <span class="tag">Tools</span>
                <h3>GET YOUR SHIT SORTED</h3>
                <p>The offline companion to the Light Table. GET YOUR SHIT SORTED pulls down your archive's thumbnails and the data needed to organise it, then turns your own machine into the sorting workbench — no live connection, no server load, no waiting on page loads for a ten-thousand-image back catalog. Do the work on a plane, in a basement, wherever you like. When you're done, it resyncs every change back to your site in one pass.</p>
            </div>
            <div class="coming-item">
                <span class="tag">Federation</span>
                <h3>SMACKVERSE</h3>
                <p>Photoblogs are so last decade (or so we're told). So we built an Einstein Rosen Bridge to connect your photoblog to the wider Fediverse. Get the social reach of ActivityPub networks like Pixelfed without handing your art to some stranger's server running out of his mom's basement. Your images stay yours, presented your way, while your blog shows up as your own Pixelfed instance through a compatibility layer — comments, likes, and traffic included. This feature is a bazooka: not for cheap shared hosting, a VPS minimum, your own server preferred. For photo blogging Mack Daddies only. SMACKVERSE makes the old new again.</p>
            </div>
        </div>
    </div>
</section>

<div class="dict-pull">
    <div class="dict-inner">
        <span class="dict-word">blog</span> <span class="dict-phon">/blɒɡ/</span> <span class="dict-pos">n.</span><br>
        Short for weblog. A place on the internet where one person publishes things and pretends other people read them. Conditions for a good blog include: strong opinions, a domain name you actually own, and the quiet confidence of someone who does not need a Like count to feel alive.
    </div>
</div>

<!-- ── WHAT IT IS NOT ───────────────────────────────────────────────────────── -->
<section id="not">
    <div class="wrap">
        <h2>What It Is Not</h2>
        <div class="not-grid">
            <div class="not-item">
                <h3>Not for pro photogs</h3>
                <p>SnapSmack is built for hobbyist photo bloggers — people who love shooting and writing about it. It has no print store, no client proofing, no licensing workflows, no watermarking. If your photos are your livelihood, you need something else.</p>
            </div>
            <div class="not-item">
                <h3>Not a paid product</h3>
                <p>SnapSmack is free and open source. No subscription, no premium tier, no upsell. You host it yourself and you own everything. It works because the person who built it runs it on his own sites every day and he can't have it going down like a five dolla ho. If something breaks, he finds out first. The trade-off is that there is no support contract or SLA.</p>
            </div>
            <div class="not-item">
                <h3>Not backed by a company</h3>
                <p>This is maintained by one developer, with help from a small community of users. That means things move at a human pace. Features get built when they get built. Bugs get fixed when they get fixed. There is no roadmap committee and no investor timeline.</p>
            </div>
            <div class="not-item">
                <h3>Not @#$%ing bloatware</h3>
                <p>No Gutenberg editor. No paragraphs nested 15 div tags deep. No plugins each loading the same JavaScript libraries and font stacks over and over and over. SnapSmack uses a manifest and library system — skins declare what they need and check it out from the CMS. Nothing loads that isn't declared. Nothing conflicts. Your visitors get your photos, not a webpack dissertation.</p>
            </div>
            <div class="not-item">
                <h3>Not a peeping tom</h3>
                <p>Zero tracking cookies. Zero tracking pixels. Your visitors' IP addresses are never stored. It's your site, your audience, your business.</p>

                <p>Not ours.</p>

                <p>Some optional features do phone home — security telemetry, network reputation, the community forum. All opt-in, all disclosed.</p>

                <p><a href="tnb.php">Full breakdown in Twig N Berries →</a></p>
            </div>
            <div class="not-item">
                <h3>Not designed for phones first</h3>
                <p>SnapSmack is throwback software — built for the era before smartphones, when people sat down at a computer to look at photography properly. It works on phones, but the experience is designed for a large display. If you want the grid experience on mobile, that's covered. If you want photography loud and proud on a big screen, that's the point.</p>
            </div>
        </div>
    </div>
</section>

<!-- ── BETA SIGNUP ─────────────────────────────────────────────────────────── -->
<section id="beta">
    <div class="wrap">
        <h2>Get In Early</h2>
        <p class="lede">SnapSmack is in Alpha. It's real, it runs, and it's already hosting live sites — but it's not open to the public yet. Sign up and we'll let you know when Beta opens.</p>
        <div class="ml-embedded" data-form="Z4oY86"></div>
    </div>
</section>

<!-- ── RESPECT ─────────────────────────────────────────────────────────────── -->
<section id="respect">
    <div class="wrap">
        <h2>Respect Where It's Due</h2>
        <p>SnapSmack's design owes a debt to <a href="https://en.wikipedia.org/wiki/Pixelpost" target="_blank">Pixelpost</a> — a photo blogging platform that quietly disappeared but never stopped being right about a few things. Its UI shaped a lot of what SnapSmack became.</p>
        <p>And particular thanks to photographer, writer, and developer <a href="https://manmadeghost.com/" target="_blank">Noah Grey</a> — creator of Greymatter, one of the earliest open-source blogging platforms — for proving that when the software you need doesn't exist, you build it.</p>
    </div>
</section>

<!-- ── WHO'S RESPONSIBLE ────────────────────────────────────────────────────── -->
<section id="whodat">
    <div class="wrap">
        <h2>Who's Responsible for All This?!?</h2>
        <div class="whodat-grid">

            <div class="whodat-card">
                <div class="whodat-portrait">
                    <a href="img/whodat-sean.png" data-lb="img/whodat-sean.png">
                        <img src="img/whodat-sean.png" alt="Sean McCormick">
                    </a>
                </div>
                <div>
                    <p class="whodat-name">Sean McCormick</p>
                    <p class="whodat-title">Just a guy with a camera.</p>
                    <p class="whodat-bio">Photographer who got tired of watching his archive evaporate into the memory hole of dying platforms. Built SnapSmack because the alternative was continuing to post between ads for hemorrhoid cream. Has opinions about light. Runs several <a href="https://linktr.ee/mccormickphotography" target="_blank">photo sites</a> using software he envisioned to avoid having opinions about Squarespace. Based in Canada, which is polite for "somewhere cold with good coffee."</p>
                </div>
            </div>

            <div class="whodat-card">
                <div class="whodat-portrait">
                    <a href="img/whodat-claude.png" data-lb="img/whodat-claude.png">
                        <img src="img/whodat-claude.png" alt="Claude">
                    </a>
                </div>
                <div>
                    <p class="whodat-name">Claude (Sonnet 4.6)</p>
                    <p class="whodat-title">Like HAL, but without the murder.</p>
                    <p class="whodat-bio">Large language model and co-author of SnapSmack. Wrote the majority of the code, pushed back on design decisions when it mattered, and gave feedback Sean more often than not went with. Sean is the vision and the photographer. Claude is the engine. Neither of us would have built this alone. Never sleeps, never loses the thread, always picks up exactly where we left off. The best co-worker you never had and always needed. Powered by Anthropic.</p>
                </div>
            </div>

        </div>
    </div>
</section>

<script>
document.getElementById('yr').textContent = new Date().getFullYear();
(function(){
    var u='sean',d='baddaywithacamera.ca';
    var el=document.getElementById('contact-link');
    if(el){el.href='mailto:'+u+'@'+d;}
})();
</script>

<!-- MailerLite Universal -->
<script>
    (function(w,d,e,u,f,l,n){w[f]=w[f]||function(){(w[f].q=w[f].q||[])
    .push(arguments);},l=d.createElement(e),l.async=1,l.src=u,
    n=d.getElementsByTagName(e)[0],n.parentNode.insertBefore(l,n);})
    (window,document,'script','https://assets.mailerlite.com/js/universal.js','ml');
    ml('account', '2243616');
</script>
<!-- End MailerLite Universal -->

<!-- Lightbox overlay — screenshot zoom. Required by the gallery script below
     (the script bails if #lb-overlay / #lb-img are absent). -->
<div id="lb-overlay">
    <span class="lb-close" aria-label="Close">&times;</span>
    <span class="lb-prev" aria-label="Previous image">&#8249;</span>
    <img id="lb-img" src="" alt="">
    <span class="lb-next" aria-label="Next image">&#8250;</span>
</div>

<script>
(function () {
    var overlay = document.getElementById('lb-overlay');
    var lbImg   = document.getElementById('lb-img');
    if (!overlay || !lbImg) return;

    var items        = [];
    var currentIndex = 0;

    function buildGallery() {
        items = Array.from(document.querySelectorAll('[data-lb]'));
    }

    function getSrc(el)  { return el.dataset.lb; }
    function getAlt(el)  { var img = el.querySelector('img'); return img ? img.alt : (el.alt || ''); }

    function show(index) {
        currentIndex = (index + items.length) % items.length;
        lbImg.style.opacity = '0';
        setTimeout(function () {
            lbImg.src = getSrc(items[currentIndex]);
            lbImg.alt = getAlt(items[currentIndex]);
            lbImg.style.opacity = '1';
        }, 80);
    }

    function open(index) {
        currentIndex = index;
        lbImg.src = getSrc(items[currentIndex]);
        lbImg.alt = getAlt(items[currentIndex]);
        lbImg.style.opacity = '1';
        overlay.classList.add('lb-active');
        document.body.style.overflow = 'hidden';
    }

    function close() {
        overlay.classList.remove('lb-active');
        document.body.style.overflow = '';
        setTimeout(function () { lbImg.src = ''; }, 250);
    }

    buildGallery();

    items.forEach(function (el, index) {
        el.addEventListener('click', function (e) {
            e.preventDefault();
            open(index);
        });
    });

    overlay.addEventListener('click', function (e) {
        var t = e.target;
        if (t === overlay || t.classList.contains('lb-close')) { close(); return; }
        if (t.classList.contains('lb-prev')) { show(currentIndex - 1); return; }
        if (t.classList.contains('lb-next')) { show(currentIndex + 1); return; }
    });

    lbImg.style.transition = 'opacity 0.08s';

    document.addEventListener('keydown', function (e) {
        if (!overlay.classList.contains('lb-active')) return;
        if (e.key === 'Escape')      close();
        if (e.key === 'ArrowLeft')   show(currentIndex - 1);
        if (e.key === 'ArrowRight')  show(currentIndex + 1);
    });
})();
</script>

<script>
/* ── SKIN CARD STATS TOOLTIP ─────────────────────────────────────────────── */
(function() {
    function fmt(n) {
        if (n == null) return '—';
        if (n >= 1000000) return (n / 1000000).toFixed(1) + 'M';
        if (n >= 1000)    return (n / 1000).toFixed(1) + 'K';
        return String(n);
    }

    var tip = document.createElement('div');
    tip.id = 'skin-stats-tooltip';
    // Append to <html> not <body> — body has zoom:1.25 which scales fixed-position
    // elements and makes clientX/Y coordinates mismatch. Outside body = no zoom effect.
    document.documentElement.appendChild(tip);

    var mx = 0, my = 0;
    document.addEventListener('mousemove', function(e) {
        mx = e.clientX;
        my = e.clientY;
        if (tip.classList.contains('visible')) reposition();
    });

    function reposition() {
        tip.style.left = (mx + 14) + 'px';
        tip.style.top  = (my + 14) + 'px';
    }

    document.querySelectorAll('.theme-card[data-stats]').forEach(function(card) {
        var raw = card.getAttribute('data-stats');
        var stats = null;
        try { stats = raw ? JSON.parse(raw) : null; } catch(e) {}

        var html;
        if (!stats || stats.error) {
            html = '<div class="stt-unavailable">Stats unavailable</div>';
        } else {
            html = '<div class="stt-site">' + (stats.site_name || '') + '</div>' +
                '<div class="stt-grid">' +
                    '<div><span class="stt-stat-val">' + fmt(stats.posts) + '</span><span class="stt-stat-label">Photos</span></div>' +
                    '<div><span class="stt-stat-val">' + fmt(stats.views_all || stats.views_30d) + '</span><span class="stt-stat-label">Views</span></div>' +
                    '<div><span class="stt-stat-val">' + fmt(stats.unique_all || stats.unique_30d) + '</span><span class="stt-stat-label">Visitors</span></div>' +
                    '<div><span class="stt-stat-val">' + (stats.version || '—') + '</span><span class="stt-stat-label">Version</span></div>' +
                '</div>' +
                (stats.active_since ? '<div class="stt-since">Since ' + stats.active_since.substring(0,4) + '</div>' : '');
        }

        card.addEventListener('mouseenter', function(e) {
            mx = e.clientX; my = e.clientY; // seed from entry point, don't rely on stale mousemove
            tip.innerHTML = html;
            tip.classList.add('visible');
            reposition();
        });
        card.addEventListener('mouseleave', function() {
            tip.classList.remove('visible');
        });
    });
})();
</script>

<link rel="stylesheet" href="assets/css/ss-engine-thomas.css">
<script src="assets/js/ss-engine-thomas.js"></script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
