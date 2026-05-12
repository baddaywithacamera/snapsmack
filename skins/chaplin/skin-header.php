<?php
/**
 * SNAPSMACK - Skin header for Chaplin
 *
 * Outputs conditional CSS for: film stock filter, grain intensity,
 * vignette, load flicker, bevel style, and intertitle card visibility.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


$film_stock    = $settings['chap_film_stock']       ?? 'nitrate';
$grain_raw     = (int)($settings['chap_grain_intensity'] ?? 4);
$grain_opacity = round($grain_raw / 100, 3); // 0–0.12
$vignette      = ($settings['chap_vignette']        ?? '1') === '1';
$flicker       = ($settings['chap_flicker']         ?? '1') === '1';
$bevel_style   = $settings['chap_bevel_style']      ?? 'single';
$card_style    = $settings['chap_card_style']       ?? 'card';
?>
<style>
/* ── FILM STOCK FILTERS ─────────────────────────────────────────────────────── */
<?php if ($film_stock === 'ortho'): ?>
/* Orthochromatic: high-contrast, pure B&W — deep shadows, bright highs */
.chap-gallery-room .frame-image img,
.chap-filmstrip-item img,
.chap-archive-item .frame-image img,
.chap-archive-item .chap-plain-thumb img,
.htbs-slide-link .frame-image img,
.chap-page-hero .frame-image img {
    filter: grayscale(1) contrast(1.25) brightness(0.92);
}
<?php elseif ($film_stock === 'nitrate'): ?>
/* Nitrate print: warm aged B&W with slight sepia cast */
.chap-gallery-room .frame-image img,
.chap-filmstrip-item img,
.chap-archive-item .frame-image img,
.chap-archive-item .chap-plain-thumb img,
.htbs-slide-link .frame-image img,
.chap-page-hero .frame-image img {
    filter: grayscale(0.92) sepia(0.28) contrast(1.08) brightness(0.90);
}
<?php elseif ($film_stock === 'panchro'): ?>
/* Panchromatic: standard neutral B&W */
.chap-gallery-room .frame-image img,
.chap-filmstrip-item img,
.chap-archive-item .frame-image img,
.chap-archive-item .chap-plain-thumb img,
.htbs-slide-link .frame-image img,
.chap-page-hero .frame-image img {
    filter: grayscale(1);
}
<?php endif; /* color = no filter */ ?>

/* ── VIGNETTE ────────────────────────────────────────────────────────────────── */
<?php if ($vignette): ?>
.chap-gallery-room .frame-image::after,
.chap-archive-item .frame-image::after {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(ellipse at center,
        transparent 55%,
        rgba(0,0,0,0.55) 100%);
    pointer-events: none;
    z-index: 2;
}
.chap-gallery-room .frame-image,
.chap-archive-item .frame-image { position: relative; }
<?php endif; ?>

/* ── LOAD FLICKER ────────────────────────────────────────────────────────────── */
<?php if ($flicker): ?>
@keyframes chap-flicker-in {
    0%   { opacity: 0.60; }
    8%   { opacity: 1.00; }
    14%  { opacity: 0.78; }
    20%  { opacity: 1.00; }
    25%  { opacity: 0.88; }
    30%  { opacity: 1.00; }
    100% { opacity: 1.00; }
}
#scroll-stage { animation: chap-flicker-in 1.1s ease-out both; }
<?php endif; ?>

/* ── BEVEL STYLE ─────────────────────────────────────────────────────────────── */
<?php if ($bevel_style === 'none'): ?>
.frame-bevel { box-shadow: none !important; }
.frame-bevel::after { display: none !important; }
<?php elseif ($bevel_style === 'double'): ?>
.frame-bevel {
    box-shadow:
        inset 5px  5px  0   rgba(255,255,255,0.95),
        inset 7px  7px  3px rgba(255,255,255,0.35),
        inset -4px -4px 0   rgba(0,0,0,0.32),
        inset -6px -6px 3px rgba(0,0,0,0.20),
        inset 10px 10px 0   rgba(0,0,0,0.18),
        inset 12px 12px 0   rgba(255,255,255,0.75),
        inset 14px 14px 2px rgba(255,255,255,0.28),
        inset -10px -10px 0 rgba(255,255,255,0.18),
        inset -12px -12px 0 rgba(0,0,0,0.22),
        inset -14px -14px 2px rgba(0,0,0,0.14) !important;
}
.frame-bevel::after {
    border-width: 2px !important;
    border-color: rgba(0,0,0,0.18) !important;
    border-top-color: rgba(255,255,255,0.55) !important;
    border-left-color: rgba(255,255,255,0.55) !important;
    outline: 1px solid rgba(0,0,0,0.10);
    outline-offset: 4px;
}
<?php endif; ?>

/* ── INTERTITLE CARD VISIBILITY ──────────────────────────────────────────────── */
<?php if ($card_style === 'hidden'): ?>
.chap-intertitle { display: none !important; }
<?php elseif ($card_style === 'minimal'): ?>
.chap-intertitle-body,
.chap-intertitle-rule,
.chap-intertitle-date { display: none !important; }
<?php endif; ?>

/* ── SQUARE CROP ─────────────────────────────────────────────────────────────── */
.chap-gallery-room .frame-image,
.chap-archive-item .frame-image { aspect-ratio: 1 / 1; }
.chap-gallery-room .frame-image img,
.chap-archive-item .frame-image img { height: 100%; object-fit: cover; }
</style>

<div id="header" class="chap-header">
    <div class="inside">
        <?php include(dirname(__DIR__, 2) . '/core/header.php'); ?>
    </div>
</div>
<?php // ===== SNAPSMACK EOF =====
