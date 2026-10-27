<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * Audit reports are ABOUT the software. They never ship IN it.
 *
 * opaudits/ was missing from both packagers' exclude lists. On 2026-10-26 a
 * 1,331 KB evidence screenshot of the owner's own site was committed there,
 * and the release zip went from 2,710 KB at 0.7.952D to 4,032 KB at 0.7.953D
 * overnight — a third larger, shipping that picture to every install on the
 * fleet. The owner found it by reading the size column in the packager.
 *
 * It is the same fault as SECAUDIT 055, where tests/ and wip/ were missing
 * from the same list and rode to 28 live installs for months. One directory
 * forgotten, no check that would say so.
 *
 * Only the extension allowlist limited the damage: .pdf and .html audit
 * reports were dropped, .png was not. So the blast radius of forgetting an
 * audit directory is every image in it, silently.
 *
 * This check holds both packagers and the tripwire to the same list, and
 * reports what WOULD ship if any of them were loosened again.
 */

$root = dirname(__DIR__);
$screl = (string)file_get_contents($root . '/smack-central/sc-release.php');
$bip   = (string)file_get_contents($root . '/tools/_build/build-install-package.php');

$fail = 0;
function ar_test(bool $ok, string $message): void {
    global $fail;
    echo ($ok ? "PASS " : "FAIL ") . $message . "\n";
    if (!$ok) $fail++;
}

/** Both audit directories, both packagers, and the tripwire that backstops them. */
$auditDirs = ['opaudits', 'secaudits'];

/**
 * Read one array literal's body by name.
 *
 * Searching the whole file is how the first draft of this check passed with
 * the exclude deleted: 'opaudits/' still appeared on the tripwire line below,
 * so a file-wide match proved nothing. Each assertion must look inside the
 * list it is actually about.
 */
function ar_array_body(string $source, string $var): string {
    return preg_match('/\$' . preg_quote($var, '/') . '\s*=\s*\[(.*?)\n\s*\];/s', $source, $m)
        ? $m[1] : '';
}

$excludeList = ar_array_body($screl, 'always_exclude');
$bipList     = ar_array_body($bip, 'exclude_dirs');
ar_test($excludeList !== '', 'sc-release still has an always_exclude list');
ar_test($bipList !== '', 'build-install-package still has an exclude_dirs list');

foreach ($auditDirs as $dir) {
    ar_test(preg_match("#^\\s*'" . preg_quote($dir, '#') . "/',#m", $excludeList) === 1,
        "sc-release always_exclude carries {$dir}/");
    ar_test(preg_match("#^\\s*'" . preg_quote($dir, '#') . "',#m", $bipList) === 1,
        "build-install-package excludes {$dir}");
}

// The tripwire is the backstop: it hard-fails the build rather than shipping
// quietly, because silence is how the first one lasted months.
$tripwire = ar_array_body($screl, 'dev_dir_tripwire');
if ($tripwire === '' && preg_match('/\$dev_dir_tripwire\s*=\s*\[(.*?)\];/s', $screl, $m)) {
    $tripwire = $m[1];
}
ar_test($tripwire !== '', 'sc-release still has a dev-dir tripwire');
foreach ($auditDirs as $dir) {
    ar_test(str_contains($tripwire, "'{$dir}/'"),
        "the tripwire would refuse a build that shipped {$dir}/");
}

// What is actually at stake, measured rather than assumed. The packager's own
// allowlist decides which audit files could ride; anything else is dropped on
// extension alone and is not the point here.
$shippable = ['php','css','js','txt','png','jpg','jpeg','gif','svg','ico','webp'];
foreach ($auditDirs as $dir) {
    $path = $root . '/' . $dir;
    if (!is_dir($path)) {
        printf("     %s/ is not in this checkout.\n", $dir);
        continue;
    }
    $bytes = 0;
    $count = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile()) continue;
        $ext = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
        if (!in_array($ext, $shippable, true)) continue;
        $bytes += $file->getSize();
        $count++;
    }
    printf("     %s/ holds %d file(s), %d KB, that the excludes above are the only thing keeping out.\n",
        $dir, $count, (int)round($bytes / 1024));
}

if ($fail > 0) {
    fwrite(STDERR, "Audit reports would ship. {$fail} check(s) failed.\n");
    exit(1);
}

echo "Audit reports never ship: PASS.\n";

// ===== SNAPSMACK EOF =====
