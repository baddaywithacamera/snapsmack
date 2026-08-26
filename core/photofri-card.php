<?php
/**
 * SNAPSMACK — PhotoFri prompt-card generator
 *
 * Builds the square PhotoFri prompt card from three variables — the prompt
 * WORD, the target-Friday DATE, and a PHOTO — over a fixed brand template
 * (PHOTOFRI.DAY wordmark, "THE FEDERATED PHOTO CHALLENGE" tagline, the framed
 * photo, and a footer line of Prompt / Hashtag / Post-on / Visit). Pixelfed
 * demands an image on every post, so the prompt ships as this card.
 *
 * The prompt is ONLY ever the word — the photo is an editorial/values choice,
 * not an instruction, so the caller supplies it (default: last week's winner;
 * override: any chosen photo). This file renders; it does not decide the word.
 *
 * Pure GD (same stack the thumbnailer uses) so it runs wherever the CMS runs.
 * Every size, colour, font and position lives in pfd_card_defaults() so the
 * look can be tuned without touching the compositing logic.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */

/** Template controls. Positions are pixels in the (square) `size` canvas. */
function pfd_card_defaults(): array {
    $fonts = dirname(__DIR__) . '/assets/fonts';
    return [
        'size'         => 1080,
        'red'          => [0xE6, 0x14, 0x14],
        'ink'          => [0xF4, 0xF4, 0xF4],
        'bg_darken'    => 0.55,                 // 0..1 how much to dim the blurred backdrop
        'font_display' => $fonts . '/BlackCasper/blackcasper.regular.ttf',
        'font_body'    => $fonts . '/SquareSanSerif7/square-sans-serif-7.regular.ttf',
        'wordmark'     => 'PHOTOFRI.DAY',
        'tagline'      => 'THE FEDERATED PHOTO CHALLENGE',
        'site_url'     => 'https://photofri.day',
        'margin'       => 60,
        'wordmark_baseline' => 190,   // baseline y of the top wordmark
        'wordmark_fill'     => 0.90,  // fraction of usable width the wordmark spans
        'frame_top'    => 250,        // photo frame box
        'frame_bottom' => 812,
        'frame_border' => 5,
        'tagline_baseline' => 880,
        'tagline_size'     => 40,
        'footer_bar_top'   => 912,    // dark bar for footer legibility (the contrast fix)
        'footer_line1_baseline' => 985,
        'footer_line2_baseline' => 1035,
        'footer_size'      => 26,
    ];
}

/** Normalise the theme word into the weekly hashtag, e.g. "Belonging" -> "PhotoFriBelonging". */
function pfd_card_hashtag(string $word): string {
    $clean = preg_replace('/[^A-Za-z0-9]+/', '', $word);
    return 'PhotoFri' . ucfirst(strtolower($clean));
}

/** Load any supported image into a truecolor GD resource (copies the thumbnailer's opener). */
function pfd_card_load(string $path) {
    if (!is_file($path)) return null;
    $info = @getimagesize($path);
    if (!$info) return null;
    switch ($info[2]) {
        case IMAGETYPE_JPEG: return @imagecreatefromjpeg($path);
        case IMAGETYPE_PNG:  return @imagecreatefrompng($path);
        case IMAGETYPE_WEBP: return function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : null;
        case IMAGETYPE_GIF:  return @imagecreatefromgif($path);
    }
    return null;
}

/** Cover-crop $src into a $dw x $dh truecolor image (fills the box, centred, no distortion). */
function pfd_card_cover($src, int $dw, int $dh) {
    $sw = imagesx($src); $sh = imagesy($src);
    $scale = max($dw / $sw, $dh / $sh);
    $cw = (int)round($dw / $scale); $ch = (int)round($dh / $scale);
    $cx = (int)(($sw - $cw) / 2);  $cy = (int)(($sh - $ch) / 2);
    $dst = imagecreatetruecolor($dw, $dh);
    imagecopyresampled($dst, $src, 0, 0, $cx, $cy, $dw, $dh, $cw, $ch);
    return $dst;
}

/** Point size at which $text in $font spans exactly $target_w pixels. */
function pfd_card_fit(string $font, string $text, float $target_w): float {
    $size = 100.0;
    $bb = imagettfbbox($size, 0, $font, $text);
    $w = abs($bb[2] - $bb[0]);
    if ($w <= 0) return $size;
    return $size * ($target_w / $w);
}

/** Total pixel width of a [text,color] segment run at $size. */
function pfd_card_segments_width(array $segs, float $size, string $font): float {
    $total = 0;
    foreach ($segs as $s) { $bb = imagettfbbox($size, 0, $font, $s[0]); $total += abs($bb[2] - $bb[0]); }
    return $total;
}

/** Largest size (<= $start) at which the run fits within $max_w. */
function pfd_card_fit_segments(array $segs, string $font, float $max_w, float $start): float {
    $size = $start;
    while ($size > 6 && pfd_card_segments_width($segs, $size, $font) > $max_w) $size -= 1;
    return $size;
}

/** Draw one centred line built of [text, colorIndex] segments; returns nothing. */
function pfd_card_centered_segments($img, array $segs, float $size, string $font, int $cx, int $baseline): void {
    $total = pfd_card_segments_width($segs, $size, $font);
    $x = $cx - $total / 2;
    foreach ($segs as $s) {
        $bb = imagettfbbox($size, 0, $font, $s[0]);
        imagettftext($img, $size, 0, (int)round($x - $bb[0]), $baseline, $s[1], $font, $s[0]);
        $x += abs($bb[2] - $bb[0]);
    }
}

/**
 * Render a prompt card to $out_path (PNG). Returns ['ok'=>bool,'path'=>?,'error'=>?].
 *   $word          the prompt, a single word ("Belonging")
 *   $target_friday ISO date the entries post on ("2026-09-04")
 *   $photo_path    the card photo (values/editorial choice; caller decides)
 */
function pfd_render_prompt_card(string $word, string $target_friday, string $photo_path, string $out_path, array $opt = []): array {
    if (!extension_loaded('gd') || !function_exists('imagettftext')) {
        return ['ok' => false, 'error' => 'GD with FreeType is required'];
    }
    $c = array_merge(pfd_card_defaults(), $opt);
    $photo = pfd_card_load($photo_path);
    if (!$photo) return ['ok' => false, 'error' => 'could not load photo: ' . $photo_path];

    $S = (int)$c['size'];
    $canvas = imagecreatetruecolor($S, $S);
    imagealphablending($canvas, true);

    $red = imagecolorallocate($canvas, $c['red'][0], $c['red'][1], $c['red'][2]);
    $ink = imagecolorallocate($canvas, $c['ink'][0], $c['ink'][1], $c['ink'][2]);

    // --- blurred, darkened backdrop from the same photo ---
    $small = pfd_card_cover($photo, max(1, (int)($S / 12)), max(1, (int)($S / 12)));
    for ($i = 0; $i < 4; $i++) @imagefilter($small, IMG_FILTER_GAUSSIAN_BLUR);
    $bg = pfd_card_cover($small, $S, $S);
    imagecopy($canvas, $bg, 0, 0, 0, 0, $S, $S);
    $shade = imagecolorallocatealpha($canvas, 0, 0, 0, (int)round(127 * (1 - $c['bg_darken'])));
    imagefilledrectangle($canvas, 0, 0, $S, $S, $shade);

    // --- framed hero photo ---
    $m = (int)$c['margin'];
    $fx = $m; $fy = (int)$c['frame_top'];
    $fw = $S - 2 * $m; $fh = (int)$c['frame_bottom'] - (int)$c['frame_top'];
    $b = (int)$c['frame_border'];
    imagefilledrectangle($canvas, $fx - $b, $fy - $b, $fx + $fw + $b, $fy + $fh + $b, $red);
    $hero = pfd_card_cover($photo, $fw, $fh);
    imagecopy($canvas, $hero, $fx, $fy, 0, 0, $fw, $fh);

    // --- wordmark (auto-sized to fill the usable width) ---
    $usable = $S - 2 * $m;
    $wsize = pfd_card_fit($c['font_display'], $c['wordmark'], $usable * $c['wordmark_fill']);
    $wbb = imagettfbbox($wsize, 0, $c['font_display'], $c['wordmark']);
    $wx = (int)round(($S - abs($wbb[2] - $wbb[0])) / 2 - $wbb[0]);
    imagettftext($canvas, $wsize, 0, $wx, (int)$c['wordmark_baseline'], $red, $c['font_display'], $c['wordmark']);

    // --- tagline ---
    pfd_card_centered_segments($canvas, [[$c['tagline'], $red]], (float)$c['tagline_size'],
        $c['font_display'], (int)($S / 2), (int)$c['tagline_baseline']);

    // --- footer legibility bar + two info lines (auto-fit to width) ---
    $barShade = imagecolorallocatealpha($canvas, 0, 0, 0, 40);
    imagefilledrectangle($canvas, 0, (int)$c['footer_bar_top'], $S, $S, $barShade);
    $tag  = '#' . pfd_card_hashtag($word);
    $body = $c['font_body'];
    $usable_footer = $S - 2 * $m;
    $line1 = [
        ['Prompt: ', $ink], [$word, $red], ['   Hashtag ', $ink], [$tag, $red],
        ['   Post on: ', $ink], [$target_friday, $red],
    ];
    $line2 = [['Visit ', $ink], [$c['site_url'], $red], ['  for complete information', $ink]];
    $fs = min(
        pfd_card_fit_segments($line1, $body, $usable_footer, (float)$c['footer_size']),
        pfd_card_fit_segments($line2, $body, $usable_footer, (float)$c['footer_size'])
    );
    pfd_card_centered_segments($canvas, $line1, $fs, $body, (int)($S / 2), (int)$c['footer_line1_baseline']);
    pfd_card_centered_segments($canvas, $line2, $fs, $body, (int)($S / 2), (int)$c['footer_line2_baseline']);

    $dir = dirname($out_path);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $ok = imagepng($canvas, $out_path);
    return $ok ? ['ok' => true, 'path' => $out_path] : ['ok' => false, 'error' => 'write failed: ' . $out_path];
}

// ===== SNAPSMACK EOF =====
