<?php
/**
 * SLICKR solo photo fills the stage, small photos enlarged (Sean, 2026-09-11:
 * "smaller images were supposed to upscale"), with a control to turn it off.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */
function sf_check(string $label, bool $ok): void {
    if (!$ok) { fwrite(STDERR, "FAIL: {$label}\n"); exit(1); }
    echo "PASS {$label}\n";
}
$root = dirname(__DIR__);
$css = file_get_contents("$root/skins/slickr/style.css");
$php = file_get_contents("$root/skins/slickr/layout.php");
$man = json_decode(file_get_contents("$root/skins/slickr/manifest.json"), true);

sf_check('stage is a size container', preg_match('/#sl-photobox\s*\{[^}]*container-type:\s*inline-size;/s', $css) === 1);
sf_check('photo height grows to the stage, not max-only', strpos($css, 'height: min(80vh, calc(100cqw / var(--sl-ar, 1.5)));') !== false);
sf_check('template emits the aspect ratio and real dimensions', strpos($php, 'style="--sl-ar: <?php echo $_sl_ar; ?>;"') !== false
    && strpos($php, 'width="<?php echo $_sl_w; ?>" height="<?php echo $_sl_h; ?>"') !== false);
sf_check('template skips the ratio when dimensions are unknown', strpos($php, '($_sl_w > 0 && $_sl_h > 0) ? round($_sl_w / $_sl_h, 4) : 0') !== false);
$opt = $man['options']['solo_small_photos'] ?? null;
sf_check('Small Photos control exists, default fill', is_array($opt) && ($opt['default'] ?? '') === 'fill');
sf_check('never-enlarge option restores height:auto', strpos($opt['options']['native']['css'] ?? '', 'height: auto;') !== false);
echo "PASS: slickr solo fill regression\n";
// ===== SNAPSMACK EOF =====
