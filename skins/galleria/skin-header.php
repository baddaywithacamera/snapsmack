<?php
/**
 * SNAPSMACK - Skin header for Galleria
 * v1.0
 *
 * Also outputs conditional CSS overrides for settings that can't be expressed
 * as simple CSS custom properties (bevel style, wood grain toggle).
 */

// --- Conditional settings ---
$bevel_style  = $settings['htbs_bevel_style']  ?? 'single';
$wood_grain   = $settings['htbs_wood_grain']    ?? 'natural';
$force_square = ($settings['htbs_force_square'] ?? '0') === '1';

$needs_overrides = ($bevel_style !== 'single' || $wood_grain === 'none' || $force_square);

if ($needs_overrides):
?>
<style>
<?php if ($force_square): ?>
/* Force square crop on all image containers */
.htbs-gallery-room .frame-image,
.htbs-slide-link .frame-image,
.htbs-archive-item .frame-image { aspect-ratio: 1 / 1; }
.htbs-gallery-room .frame-image img,
.htbs-slide-link .frame-image img,
.htbs-archive-item .frame-image img { height: 100%; object-fit: cover; }
<?php endif; ?>
<?php if ($bevel_style === 'none'): ?>
/* Bevel: NONE — flat mat, no window cut */
.frame-bevel { box-shadow: none !important; }
.frame-bevel::after { display: none !important; }
.htbs-archive-item .frame-bevel { box-shadow: none !important; }
.htbs-archive-item .frame-bevel::after { display: none !important; }
<?php elseif ($bevel_style === 'double'): ?>
/* Bevel: DOUBLE — outer bevel + inner V-groove (2× intensity) */
.frame-bevel {
    box-shadow:
        inset 5px 5px 0 rgba(255, 255, 255, 1.0),
        inset 7px 7px 3px rgba(255, 255, 255, 0.40),
        inset -4px -4px 0 rgba(0, 0, 0, 0.28),
        inset -6px -6px 3px rgba(0, 0, 0, 0.18),
        inset 10px 10px 0 rgba(0, 0, 0, 0.16),
        inset 12px 12px 0 rgba(255, 255, 255, 0.80),
        inset 14px 14px 2px rgba(255, 255, 255, 0.30),
        inset -10px -10px 0 rgba(255, 255, 255, 0.20),
        inset -12px -12px 0 rgba(0, 0, 0, 0.20),
        inset -14px -14px 2px rgba(0, 0, 0, 0.12) !important;
}
.frame-bevel::after {
    border-width: 2px !important;
    border-color: rgba(0, 0, 0, 0.16) !important;
    border-top-color: rgba(255, 255, 255, 0.60) !important;
    border-left-color: rgba(255, 255, 255, 0.60) !important;
    outline: 1px solid rgba(0, 0, 0, 0.10);
    outline-offset: 4px;
}
.htbs-archive-item .frame-bevel {
    box-shadow:
        inset 3px 3px 0 rgba(255, 255, 255, 1.0),
        inset 4px 4px 2px rgba(255, 255, 255, 0.36),
        inset -2px -2px 0 rgba(0, 0, 0, 0.24),
        inset -3px -3px 2px rgba(0, 0, 0, 0.14),
        inset 6px 6px 0 rgba(0, 0, 0, 0.12),
        inset 7px 7px 0 rgba(255, 255, 255, 0.60),
        inset 8px 8px 1px rgba(255, 255, 255, 0.24),
        inset -6px -6px 0 rgba(255, 255, 255, 0.16),
        inset -7px -7px 0 rgba(0, 0, 0, 0.16),
        inset -8px -8px 1px rgba(0, 0, 0, 0.10) !important;
}
<?php endif; ?>
<?php if ($wood_grain === 'none'): ?>
/* Wood grain: OFF — solid frame colour with directional light only */
.frame-border {
    background-image:
        linear-gradient(
            145deg,
            rgba(255, 255, 255, 0.14) 0%,
            rgba(255, 255, 255, 0.06) 20%,
            transparent 45%,
            rgba(0, 0, 0, 0.04) 65%,
            rgba(0, 0, 0, 0.10) 100%
        ) !important;
}
<?php endif; ?>
</style>
<?php endif; /* $needs_overrides */ ?>

<div id="header" class="htbs-header">
    <div class="inside">
        <?php include(dirname(__DIR__, 2) . '/core/header.php'); ?>
    </div>
</div>
