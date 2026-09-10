<?php
/**
 * Vision enrichment must be TOLD the image's file name (Sean, 2026-09-10):
 * "the title is the filename" cannot work otherwise. Same fix as SYBU /
 * tools/_shared/snap_enrich.py.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */
require_once __DIR__ . '/../core/ai-enrichment-prompts.php';

function av_check(string $label, bool $ok): void {
    if (!$ok) { fwrite(STDERR, "FAIL: {$label}\n"); exit(1); }
    echo "PASS {$label}\n";
}

av_check('stem drops the extension', snap_ai_filename_stem('496, Car Show, Strathmore, AB, 2026-07-11.jpg') === '496, Car Show, Strathmore, AB, 2026-07-11');
av_check('stem takes the basename of a stored upload path', snap_ai_filename_stem('img_uploads/2026/09/Black Corvette Dash.PNG') === 'Black Corvette Dash');
$p = snap_ai_prompt_with_filename("TITLE: <the filename>", 'a.jpg');
av_check('no token: FILENAME line prepended', strpos($p, "FILENAME: a\n") === 0 && substr($p, -strlen("TITLE: <the filename>")) === "TITLE: <the filename>");
av_check('token substituted in place', snap_ai_prompt_with_filename("TITLE: {filename}\nTAGS: x", 'Candy Orange Fender.jpg') === "TITLE: Candy Orange Fender\nTAGS: x");
av_check('empty name leaves the prompt alone', snap_ai_prompt_with_filename('p', '') === 'p');

$assist = file_get_contents(__DIR__ . '/../smack-ai-assist.php');
av_check('VISION FILL binds the posted filename', strpos($assist, "snap_ai_prompt_with_filename(\$prompt, mb_substr((string)(\$_POST['filename']") !== false);
av_check('VISION FILL layers the saved site prompt over the contract', strpos($assist, 'snap_ai_post_enrichment_prompt($pdo)') !== false && strpos($assist, 'snap_ai_post_enrichment_default_prompt()') !== false && strpos($assist, ". \$contract;") !== false);
$gyss = file_get_contents(__DIR__ . '/../core/gyss-api.php');
av_check('GYSS server enrichment binds the stored file name', strpos($gyss, "snap_ai_prompt_with_filename(\$prompt . \$contract, (string)\$image['img_file'])") !== false);
$js = file_get_contents(__DIR__ . '/../assets/js/ss-engine-ai-enrichment.js');
av_check('browser sends the filename with the picture', strpos($js, "filename: currentImageName()") !== false);
echo "PASS: vision enrichment carries the filename\n";
// ===== SNAPSMACK EOF =====
