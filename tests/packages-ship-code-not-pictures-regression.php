<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * Gallery previews are served from the server. They never ship in a package.
 *
 * sc-release.php has stripped screenshot PNGs from the CORE zip since 0.7.313.
 * The SKIN packager never did. CHAPLIN 0.2.22 was a 2,424 KB package holding
 * 2,375 KB of screenshot PNGs to deliver about 70 KB of stylesheet -- 98% of
 * the package -- for three files the same handler had already copied to
 * releases/skins/screenshots/ and named in the registry entry a few lines
 * earlier. All three were live on the server, byte for byte, while every site
 * unzipped and hashed its own copy.
 *
 * Across skins/ that is 78 MB of pictures that no install needs, and TRUE GRIT
 * alone is 8.8 MB. The more a server unzips, the more chances it has to hit a
 * resource limit, and a site that trips one installs nothing at all.
 *
 * smack-skin.php prefers a local shot and falls back to the registry URL, so
 * stripping them changes nothing a visitor or an owner sees.
 */

$root  = dirname(__DIR__);
$screl = (string)file_get_contents($root . '/smack-central/sc-release.php');
$skins = (string)file_get_contents($root . '/smack-central/sc-skins.php');
$admin = (string)file_get_contents($root . '/smack-skin.php');

$fail = 0;
function pk_test(bool $ok, string $message): void {
    global $fail;
    echo ($ok ? "PASS " : "FAIL ") . $message . "\n";
    if (!$ok) $fail++;
}

/** Read one named function or loop body is overkill; both packagers guard the same way. */
$coreGuard = preg_match(
    "/str_starts_with\(\\\$[a-z_]+, 'screenshot'\)\s*&&\s*str_ends_with\(\\\$[a-z_]+, '\.png'\)/i",
    $screl
) === 1;
pk_test($coreGuard, 'sc-release strips screenshot PNGs from the core zip');

$skinGuard = preg_match(
    "/str_starts_with\(\\\$[a-z_]+, 'screenshot'\)\s*&&\s*str_ends_with\(\\\$[a-z_]+, '\.png'\)/i",
    $skins
) === 1;
pk_test($skinGuard, 'sc-skins strips screenshot PNGs from the skin package');

// Stripping is only safe because the same handler publishes them first and the
// registry entry points at those copies. If either half goes, the gallery for a
// non-installed skin loses its preview.
pk_test(str_contains($skins, "/skins/screenshots/'"),
    'sc-skins still copies the screenshots to the releases screenshots directory');
pk_test(str_contains($skins, "'screenshots'        => \$detected_screenshots"),
    'the registry entry still names those server copies');
pk_test(str_contains($admin, '$remote_screenshots'),
    'smack-skin falls back to the registry screenshots when none is installed');

// What it is worth, measured rather than asserted.
$bytes = 0; $count = 0; $worst = ['', 0];
foreach (glob($root . '/skins/*', GLOB_ONLYDIR) ?: [] as $dir) {
    $per = 0;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile()) continue;
        $name = strtolower($file->getFilename());
        if (!str_starts_with($name, 'screenshot') || !str_ends_with($name, '.png')) continue;
        $per += $file->getSize();
        $count++;
    }
    $bytes += $per;
    if ($per > $worst[1]) $worst = [basename($dir), $per];
}
printf("     %d screenshot file(s), %d KB across skins/ that no install needs. Heaviest: %s at %d KB.\n",
    $count, (int)round($bytes / 1024), $worst[0], (int)round($worst[1] / 1024));

if ($fail > 0) {
    fwrite(STDERR, "Packages would ship pictures the server already serves. {$fail} check(s) failed.\n");
    exit(1);
}

echo "Packages ship code, not pictures: PASS.\n";

// ===== SNAPSMACK EOF =====
