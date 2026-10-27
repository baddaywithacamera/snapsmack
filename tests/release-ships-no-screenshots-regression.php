<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * The SnapSmack release contains no screenshots. Skins may contain images.
 *
 * Two rails, two rules, and only one of them is about pictures being large.
 *
 * The CORE zip is downloaded and unzipped by every site on the fleet. The more
 * a server unzips, the more chances it has to hit a resource limit, and a site
 * that trips one installs nothing at all. So the release carries runtime files
 * and no screenshots -- gallery previews are served from snapsmack.ca.
 *
 * Skins are a separate rail. A skin legitimately carries images: textures,
 * ornaments, backgrounds, its own previews. Nothing here touches the skin
 * packager, and nothing here should grow into touching it.
 *
 * The old core rule looked only at skins/ and only at .png, so
 * opaudits/evidence/027/2026-10-26-chaplin-owner-screenshot.png walked straight
 * past it and took the 0.7.953D package from 2,710 KB to 4,032 KB overnight. A
 * rule that names one directory is a rule waiting for the next directory, so
 * this one is by filename, anywhere in the tree, for every image type.
 */

$root  = dirname(__DIR__);
$screl = (string)file_get_contents($root . '/smack-central/sc-release.php');

$fail = 0;
function rs_test(bool $ok, string $message): void {
    global $fail;
    echo ($ok ? "PASS " : "FAIL ") . $message . "\n";
    if (!$ok) $fail++;
}

// The rule is by filename and carries no directory condition.
$ruleIsGlobal = preg_match(
    '/\$screenshotish\s*=\s*str_contains\(\$basename, \'screenshot\'\)\s*\|\|\s*str_contains\(\$basename, \'screencapture\'\)/',
    $screl
) === 1;
rs_test($ruleIsGlobal, 'the core packager drops screenshots by filename, anywhere in the tree');

rs_test(preg_match('/\$screenshotish && preg_match\(\'\/\\\\\.\(png\|jpe\?g\|gif\|webp\|bmp\|avif\)\$\/\'/', $screl) === 1,
    'it covers every image type, not just .png');

rs_test(!preg_match('/screenshot.{0,80}str_starts_with\(\$rel, \'skins\/\'\)/s', $screl),
    'the rule is no longer limited to skins/ -- that limit is what let the audit screenshot through');

// The backstop refuses the build instead of shipping quietly.
rs_test(str_contains($screl, 'BUILD REFUSED — screenshot'),
    'a screenshot that reaches the zip hard-fails the build');
rs_test(preg_match('/BUILD REFUSED — screenshot.{0,400}/s', $screl)
        && substr_count($screl, '@unlink($zip_dest);') >= 2,
    'the refused build deletes the half-written package');

// SKINS ARE A SEPARATE RAIL AND STAY ONE.
$skins = (string)file_get_contents($root . '/smack-central/sc-skins.php');
rs_test(!preg_match("/str_starts_with\(\\\$[a-z_]+, 'screenshot'\)/i", $skins),
    'the skin packager does NOT strip screenshots -- skins may carry their own images');

// What the core rule is worth, measured.
$bytes = 0; $count = 0;
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST,
    RecursiveIteratorIterator::CATCH_GET_CHILD);
foreach ($it as $file) {
    if (!$file->isFile()) continue;
    $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    if (str_starts_with($rel, '.git/') || str_contains($rel, '/node_modules/')) continue;
    $name = strtolower($file->getFilename());
    if (!str_contains($name, 'screenshot') && !str_contains($name, 'screencapture')) continue;
    if (preg_match('/\.(png|jpe?g|gif|webp|bmp|avif)$/', $name) !== 1) continue;
    $bytes += $file->getSize();
    $count++;
}
printf("     %d screenshot file(s), %d KB in the tree that the release now refuses to carry.\n",
    $count, (int)round($bytes / 1024));

if ($fail > 0) {
    fwrite(STDERR, "The release could carry screenshots. {$fail} check(s) failed.\n");
    exit(1);
}

echo "Release ships no screenshots: PASS.\n";

// ===== SNAPSMACK EOF =====
