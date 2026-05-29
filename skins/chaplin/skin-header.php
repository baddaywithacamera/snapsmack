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
$title_pos     = $settings['chap_title_position']        ?? 'below_photo';
$grain_raw     = (int)($settings['chap_grain_intensity'] ?? 4);
$grain_opacity = round($grain_raw / 100, 3);
$card_style    = $settings['chap_card_style']            ?? 'card';
$orn_style     = $settings['chap_ornament_style']        ?? 'A';
$orn_gap       = (int)($settings['chap_ornament_gap']    ?? 8);
$frame_gap     = (int)($settings['chap_frame_gap']       ?? 8);
$photo_pad_v   = (int)($settings['chap_photo_pad_v']     ?? 56);
$footer_size   = round((int)($settings['chap_footer_size'] ?? 7) / 10, 1);
$show_corners  = ($settings['chap_corner_ornaments']     ?? '1') === '1';

// Natural SVG dimensions per ornament style (px). Sizes the ornament divs so
// each SVG renders at its designed size rather than scaled to a fixed box.
$orn_dims = [
    'A' => ['corner' => 56, 'top_w' => 120, 'top_h' => 36, 'side_w' => 36, 'side_h' => 120],
    'B' => ['corner' => 48, 'top_w' => 120, 'top_h' => 28, 'side_w' => 28, 'side_h' => 120],
    'C' => ['corner' => 64, 'top_w' => 140, 'top_h' => 40, 'side_w' => 40, 'side_h' => 140],
    'D' => ['corner' => 48, 'top_w' => 120, 'top_h' => 24, 'side_w' => 24, 'side_h' => 120],
];
$dim = $orn_dims[$orn_style] ?? $orn_dims['A'];

// Line insets = corner ornament size when corners are visible; 0 otherwise.
// Prevents lines from running under corner ornaments.
$line_h_inset  = ($show_corners && $orn_style !== 'none') ? $dim['corner'] : 0;
$line_v_inset  = $line_h_inset;

// ── Border settings ────────────────────────────────────────────────────────────
$line_count = (int)($settings['chap_line_count']   ?? 1);
$l1         = (int)($settings['chap_line_1_width'] ?? 2);
$l2         = (int)($settings['chap_line_2_width'] ?? 1);
$l3         = (int)($settings['chap_line_3_width'] ?? 1);
$lgap       = (int)($settings['chap_line_gap']     ?? 8);

// $frame_total = total px of all rule lines + gaps.
// Used as the cross-axis dimension of the four line connector divs.
if ($line_count === 1) {
    $frame_total = $l1;
} elseif ($line_count === 2) {
    $frame_total = $l1 + $lgap + $l2;
} else {
    $frame_total = $l1 + $lgap + $l2 + $lgap + $l3;
}

// Build linear-gradient background strings for each line div.
// The outermost rule (rule 1) is always at the leading edge of each div.
if (!function_exists('chap_line_gradient')) {
    function chap_line_gradient(string $dir, int $l1, int $l2, int $l3, int $lgap, int $lcount): string {
        $c     = '#f0f0f0';
        if ($lcount === 1) return $c;
        $stops = [];
        $pos   = 0;
        $stops[] = "{$c} {$pos}px"; $pos += $l1; $stops[] = "{$c} {$pos}px";
        if ($lcount >= 2) {
            $stops[] = "transparent {$pos}px"; $pos += $lgap; $stops[] = "transparent {$pos}px";
            $stops[] = "{$c} {$pos}px";        $pos += $l2;   $stops[] = "{$c} {$pos}px";
        }
        if ($lcount >= 3) {
            $stops[] = "transparent {$pos}px"; $pos += $lgap; $stops[] = "transparent {$pos}px";
            $stops[] = "{$c} {$pos}px";        $pos += $l3;   $stops[] = "{$c} {$pos}px";
        }
        return 'linear-gradient(' . $dir . ', ' . implode(', ', $stops) . ')';
    }
}
$grad_top   = chap_line_gradient('to bottom', $l1, $l2, $l3, $lgap, $line_count);
$grad_bot   = chap_line_gradient('to top',    $l1, $l2, $l3, $lgap, $line_count);
$grad_left  = chap_line_gradient('to right',  $l1, $l2, $l3, $lgap, $line_count);
$grad_right = chap_line_gradient('to left',   $l1, $l2, $l3, $lgap, $line_count);

// ── CSS vars from settings ─────────────────────────────────────────────────────
$css_vars = [
    '--chap-frame-total'        => $frame_total . 'px',
    '--chap-frame-gap'          => $frame_gap    . 'px',
    '--chap-line-h-inset'       => $line_h_inset . 'px',
    '--chap-line-v-inset'       => $line_v_inset . 'px',
    '--chap-title-font'        => "'" . ($settings['chap_title_font']   ?? 'Cinzel') . "', Georgia, serif",
    '--chap-heading-font'      => "'" . ($settings['chap_heading_font'] ?? 'Cinzel') . "', Georgia, serif",
    '--chap-body-font'         => "'" . ($settings['chap_body_font']    ?? 'Cormorant Garamond') . "', Georgia, serif",
    '--chap-title-size'        => round((int)($settings['chap_title_size'] ?? 11) / 10, 1) . 'rem',
    '--chap-grain-opacity'     => $grain_opacity,
    '--header-height'          => ($settings['chap_header_height']      ?? '56') . 'px',
    '--chap-archive-gap'       => ($settings['chap_archive_gap']        ?? '20') . 'px',
    '--chap-archive-max-width' => ($settings['chap_archive_max_width']  ?? '1400') . 'px',
    // Bottom chrome height = infobox(50) [+ intertitle(71) if shown below photo].
    // System footer is hidden on single view — do NOT include its 40px here.
    '--chap-bottom-chrome'    => ($title_pos === 'below_photo') ? '121px' : '50px',
    // Ornament natural sizes — sized to each SVG's designed dimensions so art
    // renders at 1:1 without scaling. Gap = masking-plate extension on each side.
    '--chap-orn-corner-size'  => $dim['corner']  . 'px',
    '--chap-orn-top-w'        => $dim['top_w']   . 'px',
    '--chap-orn-top-h'        => $dim['top_h']   . 'px',
    '--chap-orn-side-w'       => $dim['side_w']  . 'px',
    '--chap-orn-side-h'       => $dim['side_h']  . 'px',
    '--chap-orn-gap'          => $orn_gap         . 'px',
    '--chap-photo-pad-v'      => $photo_pad_v     . 'px',
    '--chap-heading-size'     => round((int)($settings['chap_heading_size'] ?? 9)  / 10, 1) . 'rem',
    '--chap-body-size'        => round((int)($settings['chap_body_size']    ?? 10) / 10, 1) . 'rem',
    '--chap-footer-font'      => "'" . ($settings['chap_footer_font'] ?? 'monospace') . "', monospace",
    '--chap-footer-size'      => $footer_size     . 'rem',
];

$scratch_prob = [
    'off'    => 0,
    'sparse' => 0.003,
    'normal' => 0.008,
    'heavy'  => 0.02,
][$scratch_freq] ?? 0.008;

// ── Dynamic font loading ──────────────────────────────────────────────────────
require_once dirname(__DIR__, 2) . '/core/font-loader.php';
snapsmack_emit_font_tags([
    $settings['chap_title_font']   ?? 'Cinzel',
    $settings['chap_heading_font'] ?? 'Cinzel',
    $settings['chap_body_font']    ?? 'Cormorant Garamond',
    $settings['chap_footer_font']  ?? '',
], BASE_URL);
?>

<style>
:root {
<?php foreach ($css_vars as $var => $val): ?>
    <?php echo $var; ?>: <?php echo htmlspecialchars($val); ?>;
<?php endforeach; ?>
}

.chap-line-top, .chap-line-bot { height: <?php echo $frame_total; ?>px; }
.chap-line-lft, .chap-line-rgt { width:  <?php echo $frame_total; ?>px; }
.chap-line-top { background: <?php echo $grad_top;   ?>; }
.chap-line-bot { background: <?php echo $grad_bot;   ?>; }
.chap-line-lft { background: <?php echo $grad_left;  ?>; }
.chap-line-rgt { background: <?php echo $grad_right; ?>; }

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
