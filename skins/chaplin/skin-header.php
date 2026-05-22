<?php
/**
 * SNAPSMACK - Chaplin skin header
 *
 * RG base + Google Fonts + Chaplin CSS vars + grayscale + film JS init.
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

// ── Settings ──────────────────────────────────────────────────────────────────
$site_display_name = $site_name ?? 'SNAPSMACK';

$flicker      = ($settings['chap_flicker']        ?? '1') === '1';
$scratch_freq = $settings['chap_scratch_freq']    ?? 'normal';
$grain_raw    = (int)($settings['chap_grain_intensity'] ?? 4);
$grain_opacity = round($grain_raw / 100, 3);
$card_style   = $settings['chap_card_style']      ?? 'card';

// Border / ornament settings
$line_count = (int)($settings['chap_line_count']   ?? 1);
$l1         = (int)($settings['chap_line_1_width'] ?? 2);
$l2         = (int)($settings['chap_line_2_width'] ?? 1);
$l3         = (int)($settings['chap_line_3_width'] ?? 1);
$lgap       = (int)($settings['chap_line_gap']     ?? 8);

// Build the box-shadow line stack.
// outline = line 1. Each extra line is a box-shadow ring, offset by gap.
// We use transparent rings to create the gap between lines.
$border_css = '';
if ($line_count === 1) {
    $border_css = "outline:{$l1}px solid #ece6d4;";
} elseif ($line_count === 2) {
    $off2 = $l1 + $lgap;
    $border_css = "outline:{$l1}px solid #ece6d4;"
        . "box-shadow:0 0 0 {$off2}px transparent,0 0 0 " . ($off2 + $l2) . "px #ece6d4;";
} else {
    $off2  = $l1 + $lgap;
    $off3  = $off2 + $l2 + $lgap;
    $border_css = "outline:{$l1}px solid #ece6d4;"
        . "box-shadow:"
        . "0 0 0 {$off2}px transparent,"
        . "0 0 0 " . ($off2 + $l2) . "px #ece6d4,"
        . "0 0 0 {$off3}px transparent,"
        . "0 0 0 " . ($off3 + $l3) . "px #ece6d4;";
}

// CSS vars for font overrides from manifest
$css_vars = [
    '--chap-title-font'   => "'" . ($settings['chap_title_font']   ?? 'Cinzel') . "', Georgia, serif",
    '--chap-heading-font' => "'" . ($settings['chap_heading_font'] ?? 'Cinzel') . "', Georgia, serif",
    '--chap-body-font'    => "'" . ($settings['chap_body_font']    ?? 'Cormorant Garamond') . "', Georgia, serif",
    '--chap-title-size'   => round((int)($settings['chap_title_size'] ?? 11) / 10, 1) . 'rem',
    '--chap-grain-opacity'=> $grain_opacity,
    '--header-height'     => ($settings['chap_header_height'] ?? '56') . 'px',
    '--chap-archive-gap'  => ($settings['chap_archive_gap']  ?? '20') . 'px',
    '--chap-archive-max-width' => ($settings['chap_archive_max_width'] ?? '1400') . 'px',
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
/* ── CSS VARS ── */
:root {
<?php foreach ($css_vars as $var => $val): ?>
    <?php echo $var; ?>: <?php echo htmlspecialchars($val); ?>;
<?php endforeach; ?>
}

/* ── GRAYSCALE — applied to the photo only, not to ornaments or UI ── */
.chap-photo {
    filter: grayscale(1) contrast(1.05) brightness(0.95);
}

/* ── BORDER LINES on photo ── */
.chap-photo {
    <?php echo $border_css; ?>
}

/* ── LOAD FLICKER ── */
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

/* ── INTERTITLE CARD VISIBILITY ── */
<?php if ($card_style === 'hidden'): ?>
.chap-intertitle { display: none !important; }
<?php elseif ($card_style === 'minimal'): ?>
.chap-intertitle-date { display: none !important; }
<?php endif; ?>
</style>

<script src="<?php echo BASE_URL; ?>skins/chaplin/assets/js/ss-engine-chaplin-film.js?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    ChaplinFilm.init({
        scratchFreq : <?php echo $scratch_prob; ?>,
        flickerFreq : 0.012,
        jumpFreq    : 0.004,
        jumpMaxPx   : 4,
    });
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
