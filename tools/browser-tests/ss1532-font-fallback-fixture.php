<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

$root = dirname(__DIR__, 2);
require_once $root . '/core/skin-presentation.php';

$impact = snapsmack_declared_skin_style('impact-printer', [
    'footer_font_family' => 'DotMatrix-Condensed-Bold',
]);
$writing = snapsmack_declared_skin_style('writing-with-impact', [
    'body_font_family' => 'DotMatrix-Condensed-Bold',
]);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>SS-1532 font fallback fixture</title>
<?php echo $impact, $writing; ?>
</head>
<body class="wwi">
<main>WRITING WITH IMPACT body</main>
<footer id="system-footer"><div class="inside">IMPACT PRINTER footer</div></footer>
</body>
</html>
<?php // ===== SNAPSMACK EOF =====
