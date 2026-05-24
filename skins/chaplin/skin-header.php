<?php
/**
 * SNAPSMACK - Chaplin skin header
 * v2.5
 *
 * RG base + Google Fonts (Cinzel/Cormorant/Playfair) + Chaplin CSS vars
 * + grayscale + border CSS + film JS init + overlay JS.
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

// ── Settings ──────────────────────────────────────────────────────────────────
$site_display_name = $site_name ?? 'SNAPSMACK';

$flicker       = ($settings['chap_flicker']              ?? '1') === '1';
$scratch_freq  = $settings['chap_scratch_freq']          ?? 'normal';
$grain_raw     = (int)($settings['chap_grain_intensity'] ?? 4);
$grain_opacity = round($grain_raw / 100, 3);
$card_style    = $settings['chap_card_style']            ?? 'card';

// ── Border settings ────────────────────────────────────────────────────────────
$line_count = (int)($settings['chap_line_count']   ?? 1);
$l1         = (int)($settings['chap_line_1_width'] ?? 2);
$l2         = (int)($settings['chap_line_2_width'] ?? 1);
$l3         = (int)($settings['chap_line_3_width'] ?? 1);
$lgap       = (int)($settings['chap_line_gap']     ?? 8);

// outline = line 1; extra lines are box-shadow rings with transparent gap rings
if ($line_count === 1) {
    $border_css = "outline:{$l1}px solid #ece6d4;";
} elseif ($line_count === 2) {
    $off2 = $l1 + $lgap;
    $border_css = "outline:{$l1}px solid #ece6d4;"
        . "box-shadow:0 0 0 {$off2}px transparent,0 0 0 " . ($off2 + $l2) . "px #ece6d4;";
} else {
    $off2 = $l1 + $lgap;
    $off3 = $off2 + $l2 + $lgap;
    $border_css = "outline:{$l1}px solid #ece6d4;"
        . "box-shadow:"
        . "0 0 0 {$off2}px transparent,"
        . "0 0 0 " . ($off2 + $l2) . "px #ece6d4,"
        . "0 0 0 {$off3}px transparent,"
        . "0 0 0 " . ($off3 + $l3) . "px #ece6d4;";
}

// ── CSS vars from settings ─────────────────────────────────────────────────────
$css_vars = [
    '--chap-title-font'        => "'" . ($settings['chap_title_font']   ?? 'Cinzel') . "', Georgia, serif",
    '--chap-heading-font'      => "'" . ($settings['chap_heading_font'] ?? 'Cinzel') . "', Georgia, serif",
    '--chap-body-font'         => "'" . ($settings['chap_body_font']    ?? 'Cormorant Garamond') . "', Georgia, serif",
    '--chap-title-size'        => round((int)($settings['chap_title_size'] ?? 11) / 10, 1) . 'rem',
    '--chap-grain-opacity'     => $grain_opacity,
    '--header-height'          => ($settings['chap_header_height']      ?? '56') . 'px',
    '--chap-archive-gap'       => ($settings['chap_archive_gap']        ?? '20') . 'px',
    '--chap-archive-max-width' => ($settings['chap_archive_max_width']  ?? '1400') . 'px',
];

$scratch_prob = [
    'off'    => 0,
    'sparse' => 0.003,
    'normal' => 0.008,
    'heavy'  => 0.02,
][$scratch_freq] ?? 0.008;
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;600;700;900&family=Cormorant+Garamond:ital,wght@0,300;0,400;0,600;1,300;1,400;1,600&family=Playfair+Display:ital,wght@0,400;0,700;0,900;1,400&display=swap" rel="stylesheet">

<style>
:root {
<?php foreach ($css_vars as $var => $val): ?>
    <?php echo $var; ?>: <?php echo htmlspecialchars($val); ?>;
<?php endforeach; ?>
}

.chap-photo {
    filter: grayscale(1) contrast(1.05) brightness(0.95);
    <?php echo $border_css; ?>
}

<?php if ($flicker): ?>
@keyframes chap-flicker-in {
    0%   { opacity: 0.55; }
    8%   { opacity: 1.00; }
    16%  { opacity: 0.72; }
    22%  { opacity: 1.00; }
    28%  { opacity: 0.85; }
    35%  { opacity: 1.00; }
    100% { opacity: 1.00; }
}
#scroll-stage { animation: chap-flicker-in 1.2s ease-out both; }
<?php endif; ?>

<?php if ($card_style === 'hidden'): ?>
.chap-intertitle { display: none !important; }
<?php elseif ($card_style === 'minimal'): ?>
.chap-intertitle-date { display: none !important; }
<?php endif; ?>
</style>

<script src="<?php echo BASE_URL; ?>skins/chaplin/assets/js/ss-engine-chaplin-film.js?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {

    /* ChaplinFilm engine */
    if (typeof ChaplinFilm !== 'undefined') {
        ChaplinFilm.init({
            scratchFreq : <?php echo $scratch_prob; ?>,
            flickerFreq : 0.012,
            jumpFreq    : 0.004,
            jumpMaxPx   : 4,
        });
    }

    /* Filmstrip: scroll active item into view */
    var activeThumb = document.querySelector('.chap-filmstrip-item.active');
    var activeThumb = document.querySelector('.chap-filmstrip-item.active');
    if (activeThumb) {
        activeThumb.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
    }

    /* ── Chaplin Overlay Controller ──────────────────────────────────────────
       Intercepts navigation-bar.php's #show-details / #show-comments in
       capture phase (before smack-footer.js bubble-phase listeners).
       Provides window.smackdown bridge for smack-keyboard.js hotkeys.
    ── */
    var overlay  = document.getElementById('chap-comments-drawer');
    if (!overlay) return; /* Not a single-image page */

    var backdrop = overlay.querySelector('.chap-overlay-backdrop');
    var closeBtn = overlay.querySelector('.chap-overlay-close');
    var tabs     = overlay.querySelectorAll('.chap-tab');
    var panes    = overlay.querySelectorAll('.chap-pane');

    function showPane(name) {
        for (var i = 0; i < tabs.length; i++) {
            tabs[i].classList.toggle('active', tabs[i].getAttribute('data-pane') === name);
        }
        for (var j = 0; j < panes.length; j++) {
            panes[j].classList.toggle('active', panes[j].id === 'chap-pane-' + name);
        }
    }

    function openOverlay(pane) {
        showPane(pane);
        overlay.classList.add('open');
        overlay.removeAttribute('aria-hidden');
    }

    function closeOverlay() {
        overlay.classList.remove('open');
        overlay.setAttribute('aria-hidden', 'true');
    }

    function isOpen()    { return overlay.classList.contains('open'); }
    function activePane() {
        for (var i = 0; i < tabs.length; i++) {
            if (tabs[i].classList.contains('active')) return tabs[i].getAttribute('data-pane');
        }
        return null;
    }

    for (var i = 0; i < tabs.length; i++) {
        tabs[i].addEventListener('click', function () { showPane(this.getAttribute('data-pane')); });
    }

    if (closeBtn) closeBtn.addEventListener('click', closeOverlay);
    if (backdrop)  backdrop.addEventListener('click', closeOverlay);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && isOpen()) closeOverlay();
    });

    /* Capture-phase intercept of INFO / COMMENTS nav buttons */
    document.addEventListener('click', function (e) {
        var t = e.target;
        if (!t) return;
        var infoBtn = t.id === 'show-details'  || (t.closest && t.closest('[id="show-details"]'));
        var commBtn = t.id === 'show-comments' || (t.closest && t.closest('[id="show-comments"]'));
        if (infoBtn) {
            e.preventDefault(); e.stopImmediatePropagation();
            if (isOpen() && activePane() === 'info') closeOverlay(); else openOverlay('info');
        } else if (commBtn) {
            e.preventDefault(); e.stopImmediatePropagation();
            if (isOpen() && activePane() === 'signals') closeOverlay(); else openOverlay('signals');
        }
    }, true /* capture */);

    /* smackdown bridge for smack-keyboard.js */
    window.smackdown = window.smackdown || {};
    window.smackdown.toggleFooter = function (target) {
        if (target === 'info') {
            if (isOpen() && activePane() === 'info') closeOverlay(); else openOverlay('info');
        } else if (target === 'comments') {
            if (isOpen() && activePane() === 'signals') closeOverlay(); else openOverlay('signals');
        }
    };
    window.smackdown.closeFooter = closeOverlay;
});
</script>

<div id="rg-header">
    <div class="rg-header-inside">
        <a href="<?php echo BASE_URL; ?>" class="rg-logo-link">
            <span class="rg-masthead"><?php echo htmlspecialchars($site_display_name); ?></span>
        </a>
        <nav class="rg-header-nav">
            <?php include dirname(__DIR__, 2) . '/core/header.php'; ?>
        </nav>
    </div>
</div>
<?php // ===== SNAPSMACK EOF =====
