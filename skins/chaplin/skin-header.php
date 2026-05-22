<?php
/**
 * SNAPSMACK - Chaplin skin header
 *
 * Outputs conditional CSS for: film tone filter, vignette, load flicker,
 * bevel style, intertitle card visibility. Loads the Chaplin film engine
 * (scratches + image flicker + gate-slip jump — all behind/on the frame,
 * never as an overlay on top of the photo).
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$tone          = $settings['chap_tone']            ?? 'sepia';
$grain_raw     = (int)($settings['chap_grain_intensity'] ?? 4);
$grain_opacity = round($grain_raw / 100, 3);
$flicker       = ($settings['chap_flicker']        ?? '1') === '1';
$bevel_style   = $settings['chap_bevel_style']     ?? 'single';
$card_style    = $settings['chap_card_style']      ?? 'card';
$scratch_freq  = $settings['chap_scratch_freq']    ?? 'normal';

// CSS var overrides — wired from manifest settings
$css_vars = [
    '--frame-color'        => $settings['chap_frame_color']   ?? '#1a1410',
    '--frame-width'        => ($settings['chap_frame_width']  ?? '10') . 'px',
    '--mat-color'          => $settings['chap_mat_color']     ?? '#f5efdf',
    '--mat-width'          => ($settings['chap_mat_width']    ?? '28') . 'px',
    '--wall-bg'            => $settings['chap_wall_color']    ?? '#100e0b',
    '--chap-card-bg'       => $settings['chap_card_bg']       ?? '#0d0b08',
    '--chap-card-text'     => $settings['chap_card_text']     ?? '#f0e8d4',
    '--chap-ink'           => $settings['chap_text_primary']  ?? '#ece6d4',
    '--chap-ink-dim'       => $settings['chap_text_secondary']?? '#8a8579',
    '--chap-amber'         => $settings['chap_accent']        ?? '#c8860a',
    '--header-height'      => ($settings['chap_header_height']?? '56') . 'px',
    '--chap-show-titles'   => ($settings['chap_show_titles']  ?? '1'),
    '--chap-bg-chrome'     => $settings['chap_header_bg']     ?? '#130f0c',
    '--chap-nav'           => $settings['chap_nav_color']     ?? '#8a8579',
    '--chap-nav-hover'     => $settings['chap_nav_hover']     ?? '#ece6d4',
    '--chap-footer-bg'     => $settings['chap_footer_bg']     ?? 'transparent',
    '--chap-footer-text'   => $settings['chap_footer_text']   ?? '#8a8579',
    '--chap-footer-link'   => $settings['chap_footer_link']   ?? '#8b7355',
    '--chap-title-font'    => "'" . ($settings['chap_title_font']   ?? 'Cinzel') . "', Georgia, serif",
    '--chap-heading-font'  => "'" . ($settings['chap_heading_font'] ?? 'Cinzel') . "', Georgia, serif",
    '--chap-body-font'     => "'" . ($settings['chap_body_font']    ?? 'Cormorant Garamond') . "', Georgia, serif",
    '--chap-title-size'    => ($settings['chap_title_size']   ?? '1.1') . 'rem',
    '--chap-title-color'   => $settings['chap_title_color']   ?? '#ece6d4',
    '--chap-archive-gap'   => ($settings['chap_archive_gap']  ?? '20') . 'px',
    '--chap-archive-max-width' => ($settings['chap_archive_max_width'] ?? '1400') . 'px',
];

$scratch_prob = [
    'off'    => 0,
    'sparse' => 0.003,
    'normal' => 0.008,
    'heavy'  => 0.02,
][$scratch_freq] ?? 0.008;
?>
<style>
/* ── CSS VARS ────────────────────────────────────────────────────────────── */
:root {
<?php foreach ($css_vars as $var => $val): ?>
    <?php echo $var; ?>: <?php echo htmlspecialchars($val); ?>;
<?php endforeach; ?>
}
/* ── FILM TONE ───────────────────────────────────────────────────────────── */
<?php if ($tone === 'sepia'): ?>
.chap-frame-image img,
.chap-gallery-room .frame-image img,
.htbs-slide-link .frame-image img,
.chap-archive-item .frame-image img,
.chap-archive-item .chap-plain-thumb img,
.chap-page-hero .frame-image img {
    filter: grayscale(0.88) sepia(0.30) contrast(1.10) brightness(0.88);
}
<?php else: /* bw */ ?>
.chap-frame-image img,
.chap-gallery-room .frame-image img,
.htbs-slide-link .frame-image img,
.chap-archive-item .frame-image img,
.chap-archive-item .chap-plain-thumb img,
.chap-page-hero .frame-image img {
    filter: grayscale(1) contrast(1.05) brightness(0.92);
}
<?php endif; ?>

/* ── VIGNETTE ────────────────────────────────────────────────────────────── */
/* ── LOAD FLICKER ────────────────────────────────────────────────────────── */
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

/* ── BEVEL STYLE ─────────────────────────────────────────────────────────── */
<?php if ($bevel_style === 'none'): ?>
.frame-bevel { box-shadow: none !important; }
.frame-bevel::after { display: none !important; }
<?php elseif ($bevel_style === 'double'): ?>
.frame-bevel {
    box-shadow:
        inset  5px  5px 0   rgba(255,255,255,0.92),
        inset  7px  7px 3px rgba(255,255,255,0.32),
        inset -4px -4px 0   rgba(0,0,0,0.30),
        inset -6px -6px 3px rgba(0,0,0,0.18),
        inset 11px 11px 0   rgba(0,0,0,0.16),
        inset 13px 13px 0   rgba(255,255,255,0.72),
        inset 15px 15px 2px rgba(255,255,255,0.25),
        inset -11px -11px 0 rgba(255,255,255,0.16),
        inset -13px -13px 0 rgba(0,0,0,0.20),
        inset -15px -15px 2px rgba(0,0,0,0.12) !important;
}
<?php endif; ?>

/* ── INTERTITLE CARD VISIBILITY ──────────────────────────────────────────── */
<?php if ($card_style === 'hidden'): ?>
.chap-intertitle { display: none !important; }
<?php elseif ($card_style === 'minimal'): ?>
.chap-intertitle-body,
.chap-intertitle-rule,
.chap-intertitle-date { display: none !important; }
<?php endif; ?>

/* ── SQUARE CROP ─────────────────────────────────────────────────────────── */
.frame-image { aspect-ratio: 1 / 1; overflow: hidden; }
.frame-image img { width: 100%; height: 100%; object-fit: cover; display: block; }
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

<div id="header" class="chap-header">
    <div class="inside">
        <?php include dirname(__DIR__, 2) . '/core/header.php'; ?>
    </div>
</div>
<?php // ===== SNAPSMACK EOF =====
