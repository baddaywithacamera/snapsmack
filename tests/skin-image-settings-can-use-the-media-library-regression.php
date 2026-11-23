<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

// AN IMAGE YOU ALREADY UPLOADED CAN BE USED AGAIN. (SS-1528 item 1)
//
// A skin's image settings could only UPLOAD. An owner who had already put their
// logo in the media library had no way to point at it, so the same file had to
// be uploaded a second time to a second place. Sean hit this on ALFRED.
//
// The picker already existed and smack-asset-picker.js was ALREADY loaded on
// the skin settings page -- the type:'asset' control has used it all along. So
// this is a wiring job, and the two real faults were both in the wiring.
//
// FAULT ONE, WHICH WOULD HAVE SHIPPED A BUTTON THAT OPENED AN EMPTY BOX. The
// media library is only fetched when $skin_asset_picker_needed is set, and that
// was set by the 'asset' type ALONE. 14 of the 15 skins with image controls
// declare no 'asset' control: ALFRED, AURORA, GAME ON, GLIDE, INSTANT CAMERA,
// JIVE TURKEY, ONYX, PARADE, SCROLL, SUDDEN IMPACT, TELEGRAM, THE GRID, TILEZ
// and WRITING WITH IMPACT. Only STANLEY has one. So on the skin the ticket is
// about, the picker would have opened with nothing in it.
//
// FAULT TWO, WHICH WOULD HAVE OPENED A WAY ROUND A CONSTRAINT. A manifest can
// declare min_width/min_height on an image control -- THE GRID's treatment
// image needs 1920x1080 -- and that minimum was enforced in exactly one place:
// the upload handler. A picked path never went near it.
//
// WHAT IS EXECUTED HERE: snapsmack_reject_undersized_skin_images(), for real,
// against REAL IMAGE FILES written to a temporary directory and measured with
// getimagesize. That is why it lives in core/ and not in the admin page.
// WHAT IS READ: smack-skin.php's markup and the picker's JS contract, because
// an admin page wanting a session and a database cannot be loaded by a test.

$root = dirname(__DIR__);
require_once $root . '/core/skin-presentation.php';

$failures = [];
$check = static function (string $what, bool $ok) use (&$failures): void {
    printf("  [%s] %s\n", $ok ? 'ok' : 'FAIL', $what);
    if (!$ok) { $failures[] = $what; }
};

echo "SS-1528 item 1: a skin image setting can use the media library\n\n";

// ─────────────────────────────────────────────────────────────────────────────
echo "STAGE 1 — THE MINIMUM IS ENFORCED ON A PICKED PATH, RUN FOR REAL\n";
// ─────────────────────────────────────────────────────────────────────────────

$tmp = sys_get_temp_dir() . '/ss1528-' . getmypid();
@mkdir($tmp . '/uploads', 0777, true);
$made = [];

// A REAL PNG, WRITTEN BY HAND, because GD is not installed here and
// imagecreatetruecolor() is a fatal rather than something @ can swallow.
// getimagesize() reads the dimensions out of the IHDR chunk, so a correct
// signature plus a correct IHDR is a genuinely measurable image file and the
// measurement stays real with no extension to depend on.
$write_png = static function (string $path, int $w, int $h) use (&$made): bool {
    $ihdr = pack('NN', $w, $h) . chr(8) . chr(0) . chr(0) . chr(0) . chr(0);
    $chunk = static function (string $type, string $data): string {
        return pack('N', strlen($data)) . $type . $data
             . pack('N', crc32($type . $data));
    };
    $png = "\x89PNG\r\n\x1a\n" . $chunk('IHDR', $ihdr) . $chunk('IEND', '');
    if (@file_put_contents($path, $png) === false) { return false; }
    $made[] = $path;
    return is_file($path);
};

$big   = $tmp . '/uploads/big.png';
$small = $tmp . '/uploads/small.png';
$write_png($big, 1920, 1080);
$write_png($small, 320, 200);

// Proving the fixtures before trusting them: if getimagesize cannot read these,
// every result below would be a pass that measured nothing.
$big_dim   = @getimagesize($big);
$small_dim = @getimagesize($small);
$have_gd   = $big_dim && $small_dim
             && (int) $big_dim[0] === 1920 && (int) $big_dim[1] === 1080
             && (int) $small_dim[0] === 320 && (int) $small_dim[1] === 200;
$check('the hand-written PNGs measure as 1920x1080 and 320x200', (bool) $have_gd);

if (!$have_gd) {
    // Said rather than skipped silently: a test that passes while having
    // measured nothing is worse than one that admits it could not.
    $check('NOT CHECKED - getimagesize could not read the fixtures', false);
} else {
    $meta = [
        'treatment_image' => ['type' => 'image', 'label' => 'Treatment Image',
                              'min_width' => 1920, 'min_height' => 1080],
        'site_logo'       => ['type' => 'image', 'label' => 'Site Logo'],
        'caption_color'   => ['type' => 'color', 'label' => 'Caption Colour'],
    ];

    // The one that matters: an image under the declared minimum is refused.
    $out = snapsmack_reject_undersized_skin_images(
        ['treatment_image' => 'uploads/small.png'], $meta, $tmp);
    $check('an UNDERSIZED picked image is refused',
           !array_key_exists('treatment_image', $out['keep']));
    $check('  and the refusal names the control and both sizes',
           count($out['rejects']) === 1
           && str_contains($out['rejects'][0], 'Treatment Image')
           && str_contains($out['rejects'][0], '1920')
           && str_contains($out['rejects'][0], '320'));

    $out = snapsmack_reject_undersized_skin_images(
        ['treatment_image' => 'uploads/big.png'], $meta, $tmp);
    $check('a big enough picked image is kept',
           ($out['keep']['treatment_image'] ?? null) === 'uploads/big.png'
           && $out['rejects'] === []);

    // A leading slash is how the picker's stored paths actually look.
    $out = snapsmack_reject_undersized_skin_images(
        ['treatment_image' => '/uploads/big.png'], $meta, $tmp);
    $check('a leading slash still resolves', $out['rejects'] === []);

    echo "\n";
    echo "STAGE 2 — WHAT IT MUST NOT TOUCH\n";
    // Conservative on purpose: "I could not check" is not "it is too small".
    $out = snapsmack_reject_undersized_skin_images(
        ['site_logo' => 'uploads/small.png'], $meta, $tmp);
    $check('a control with NO declared minimum is left alone',
           ($out['keep']['site_logo'] ?? null) === 'uploads/small.png' && $out['rejects'] === []);

    $out = snapsmack_reject_undersized_skin_images(
        ['caption_color' => '#cc0000'], $meta, $tmp);
    $check('a control that is not an image is left alone',
           ($out['keep']['caption_color'] ?? null) === '#cc0000' && $out['rejects'] === []);

    $out = snapsmack_reject_undersized_skin_images(
        ['treatment_image' => 'uploads/not-here.png'], $meta, $tmp);
    $check('a path that does not resolve is left alone, not refused',
           ($out['keep']['treatment_image'] ?? null) === 'uploads/not-here.png'
           && $out['rejects'] === []);

    $out = snapsmack_reject_undersized_skin_images(
        ['treatment_image' => ''], $meta, $tmp);
    $check('an empty value is left alone', $out['rejects'] === []);

    $out = snapsmack_reject_undersized_skin_images(
        ['unknown_control' => 'uploads/small.png'], $meta, $tmp);
    $check('a control the manifest does not declare is left alone',
           ($out['keep']['unknown_control'] ?? null) === 'uploads/small.png');

    // Not measured, refused outright.
    $out = snapsmack_reject_undersized_skin_images(
        ['treatment_image' => '../../etc/passwd'], $meta, $tmp);
    $check('a path climbing out of the install is REFUSED without measuring',
           !array_key_exists('treatment_image', $out['keep'])
           && count($out['rejects']) === 1
           && str_contains($out['rejects'][0], 'not a path inside this site'));

    echo "\n";
    echo "STAGE 2b - THE THREE CASES MUTATION TESTING FOUND UNCOVERED\n";

    // HEIGHT ALONE. The fixtures were 320x200 (under on both) and 1920x1080
    // (over on both), so dropping the height half of the comparison changed
    // nothing any check could see. A wide, short image is the only shape that
    // tells them apart.
    $wide_short = $tmp . '/uploads/wide-short.png';
    $write_png($wide_short, 1920, 400);
    $out = snapsmack_reject_undersized_skin_images(
        ['treatment_image' => 'uploads/wide-short.png'], $meta, $tmp);
    $check('wide enough but TOO SHORT is still refused',
           !array_key_exists('treatment_image', $out['keep'])
           && count($out['rejects']) === 1
           && str_contains($out['rejects'][0], '400'));

    // A MINIMUM ON A CONTROL THAT IS NOT AN IMAGE must be ignored. Without a
    // case for it, deleting the type check changed nothing, because the only
    // non-image control in the fixture declared no minimum either.
    $odd = ['brand_colour' => ['type' => 'color', 'label' => 'Brand Colour',
                               'min_width' => 1920, 'min_height' => 1080]];
    $out = snapsmack_reject_undersized_skin_images(
        ['brand_colour' => 'uploads/small.png'], $odd, $tmp);
    $check('a minimum declared on a NON-image control is ignored',
           ($out['keep']['brand_colour'] ?? null) === 'uploads/small.png'
           && $out['rejects'] === []);

    // TRAVERSAL ON A CONTROL WITH NO MINIMUM. This is the one that was a real
    // defect rather than a missing test: the traversal refusal used to sit
    // AFTER the minimum gate, so a control declaring no minimum never reached
    // it and would have accepted the path.
    $out = snapsmack_reject_undersized_skin_images(
        ['site_logo' => '../../etc/passwd'], $meta, $tmp);
    $check('traversal is refused even with NO declared minimum',
           !array_key_exists('site_logo', $out['keep'])
           && count($out['rejects']) === 1
           && str_contains($out['rejects'][0], 'not a path inside this site'));
}

foreach ($made as $f) { @unlink($f); }
@rmdir($tmp . '/uploads');
@rmdir($tmp);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 3 — THE CONTROL IS WIRED (READ FROM SOURCE, NOT EXECUTED)\n";
// ─────────────────────────────────────────────────────────────────────────────
// smack-skin.php wants a session and a database, so this reads it. Stated so
// nobody mistakes these for proof that the button works in a browser.

$skin_src = (string) file_get_contents($root . '/smack-skin.php');

$check('the image control offers CHOOSE FROM MEDIA LIBRARY',
       str_contains($skin_src, 'CHOOSE FROM MEDIA LIBRARY'));
$check('  and it opens the EXISTING picker rather than a new one',
       str_contains($skin_src, "window.ssOpenAssetPicker('field'"));
$check('the hidden input carries an id for the picker to write into',
       str_contains($skin_src, "id=\"<?php echo htmlspecialchars(\$img_input_id); ?>\""));
$check('  and a preview target, which is how the picker refreshes the thumbnail',
       str_contains($skin_src, 'data-preview-target="<?php echo htmlspecialchars($img_preview_id); ?>"'));

// FAULT ONE. The library is only fetched when this flag is set.
$check('THE LIBRARY IS FETCHED FOR image CONTROLS, not only asset ones',
       (bool) preg_match('/\$_t === \'asset\' \|\| \$_t === \'image\'/', $skin_src));

// FAULT TWO, at the call site.
$check('the save path runs the minimum check on picked values',
       str_contains($skin_src, 'snapsmack_reject_undersized_skin_images($_POST[\'skin_opt\']'));
$check('  and drops the refused ones from what gets saved',
       str_contains($skin_src, "\$_POST['skin_opt'] = \$_picked['keep'];"));

// The refusal has to reach the owner even when nothing was uploaded, or a
// setting silently stays as it was with nothing said.
$flash_at  = strpos($skin_src, "\$_SESSION['gallery_flash'] = 'Image not saved");
$upload_at = strpos($skin_src, "if (!empty(\$_FILES['skin_img_opt']['name']))");
$check('the refusal is reported outside the upload block',
       $flash_at !== false && $upload_at !== false && $flash_at > $upload_at);

$check('UPLOAD is still offered alongside it',
       str_contains($skin_src, 'file-custom-btn'));

// The picker's own contract, so this test fails if the picker stops writing
// where the markup above expects.
$picker = (string) file_get_contents($root . '/assets/js/smack-asset-picker.js');
$check("the picker's field mode writes the chosen path into the input",
       str_contains($picker, 'pickerTarget.value = selectedAsset.path'));
$check('  and refreshes the element named by data-preview-target',
       str_contains($picker, 'pickerTarget.dataset.previewTarget'));
$check('the skin settings page loads the picker script',
       str_contains($skin_src, 'assets/js/smack-asset-picker.js'));

// No static style attributes: a hard rule here, and the preview is sized by a
// class precisely because the picker replaces the container's innerHTML.
$check('the preview is sized by a class, not an inline style',
       str_contains($skin_src, 'class="skin-image-preview mb-8"'));
$css = (string) file_get_contents($root . '/assets/css/admin-theme-geometry-master.css');
$check('  and that class styles the CHILD img, which is what the picker injects',
       str_contains($css, '.skin-image-preview img'));

// ─────────────────────────────────────────────────────────────────────────────
echo "\n";
if ($failures) {
    echo 'FAIL (' . count($failures) . "):\n";
    foreach ($failures as $f) { echo "  - $f\n"; }
    exit(1);
}
echo "PASS — a picked image is measured against the control's declared minimum,\n";
echo "the library is fetched for image controls, and the existing picker is\n";
echo "reused rather than a second one built.\n";
echo "NOT PROVED HERE: the button in a browser. smack-skin.php cannot be loaded\n";
echo "by a test, so Stage 3 reads it. Sean clicking it on ALFRED is the check\n";
echo "that matters, and it has not happened yet.\n";
exit(0);

// ===== SNAPSMACK EOF =====
