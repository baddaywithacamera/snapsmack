<?php
/**
 * SNAPSMACK - Guarded dev/stable release workflow.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
chdir($root);

function rf_fail(string $message): never {
    fwrite(STDERR, "REFUSED: {$message}\n");
    exit(1);
}

function rf_run(array $args, bool $quiet = false): string {
    $escaped = array_map('escapeshellarg', $args);
    exec(implode(' ', $escaped) . ' 2>&1', $lines, $code);
    $out = trim(implode("\n", $lines));
    if ($code !== 0) rf_fail($out !== '' ? $out : implode(' ', $args));
    if (!$quiet && $out !== '') echo $out . "\n";
    return $out;
}

function rf_git(array $args, bool $quiet = true): string {
    return rf_run(array_merge(['git'], $args), $quiet);
}

function rf_branch(): string {
    return rf_git(['branch', '--show-current']);
}

function rf_require_clean(): void {
    if (rf_git(['status', '--porcelain']) !== '') {
        rf_fail('working tree is not clean; commit or deliberately set aside every change first');
    }
}

function rf_require_dev(): void {
    if (rf_branch() !== 'dev') rf_fail('release work must run from the dev branch');
}

function rf_version(string $raw): string {
    $version = preg_replace('/D$/i', '', ltrim(trim($raw), 'vV'));
    if (!is_string($version) || !preg_match('/^\d+\.\d+\.\d+$/', $version)) {
        rf_fail('version must look like 0.7.456 (without v or D)');
    }
    return $version;
}

function rf_source_version(): string {
    $src = file_get_contents(__DIR__ . '/../core/constants.php');
    if (!is_string($src)
        || !preg_match("/SNAPSMACK_VERSION_SHORT',\\s*'([^']+)'/", $src, $m)) {
        rf_fail('could not read SNAPSMACK_VERSION_SHORT');
    }
    return $m[1];
}

function rf_tag_target(string $tag): string {
    exec('git show-ref --verify --hash ' . escapeshellarg('refs/tags/' . $tag) . ' 2>&1', $lines, $code);
    return $code === 0 ? trim(implode("\n", $lines)) : '';
}

function rf_tests(): void {
    foreach (glob(__DIR__ . '/../tests/*regression.php') ?: [] as $test) {
        rf_run([PHP_BINARY, $test]);
    }
    rf_run([PHP_BINARY, '-l', __DIR__ . '/../smack-central/sc-release.php']);
    rf_run([PHP_BINARY, '-l', __DIR__ . '/../core/updater.php']);
    rf_run([PHP_BINARY, '-l', __FILE__]);
}

$command = strtolower($argv[1] ?? 'status');

if ($command === 'status') {
    echo "Branch: " . rf_branch() . "\n";
    echo "Commit: " . rf_git(['rev-parse', '--short', 'HEAD']) . "\n";
    echo "Source version: " . rf_source_version() . "\n";
    echo "Working tree: " . (rf_git(['status', '--porcelain']) === '' ? 'clean' : 'dirty') . "\n";
    exit(0);
}

if ($command === 'push-dev') {
    rf_require_dev();
    rf_require_clean();
    rf_git(['push', 'Github', 'dev'], false);
    echo "DEV PUSH COMPLETE. No release tag or manifest was changed.\n";
    exit(0);
}

if ($command === 'tag-dev') {
    rf_require_dev();
    rf_require_clean();
    $version = rf_version($argv[2] ?? '');
    if (rf_source_version() !== $version) {
        rf_fail("source version is " . rf_source_version() . "; expected {$version}");
    }
    rf_git(['fetch', 'Github', '--tags', '--prune'], false);
    $tag = 'v' . $version . 'D';
    if (rf_tag_target($tag) !== '') rf_fail("tag {$tag} already exists; use the next version");
    rf_tests();
    rf_git(['tag', $tag]);
    rf_git(['push', 'Github', 'dev'], false);
    rf_git(['push', 'Github', $tag], false);
    echo "BETA TAGGED: {$tag}. Build it only in Smack Central's BITCHIN' panel.\n";
    exit(0);
}

if ($command === 'promote-stable') {
    rf_require_dev();
    rf_require_clean();
    $version = rf_version($argv[2] ?? '');
    if (($argv[3] ?? '') !== '--yes') rf_fail('promotion requires the explicit --yes argument');
    if (rf_source_version() !== $version) {
        rf_fail("source version is " . rf_source_version() . "; expected {$version}");
    }
    rf_git(['fetch', 'Github', '--tags', '--prune'], false);
    $head = rf_git(['rev-parse', 'HEAD']);
    if (rf_git(['rev-parse', 'refs/remotes/Github/dev']) !== $head) {
        rf_fail('current commit is not the commit published on Github/dev');
    }
    $dev_tag = 'v' . $version . 'D';
    if (rf_tag_target($dev_tag) !== $head) rf_fail("{$dev_tag} does not point to the current tested commit");
    $stable_tag = 'v' . $version;
    if (rf_tag_target($stable_tag) !== '') rf_fail("stable tag {$stable_tag} already exists");
    rf_git(['merge-base', '--is-ancestor', 'master', 'HEAD']);
    rf_tests();
    rf_git(['push', 'Github', 'HEAD:master'], false);
    rf_git(['tag', $stable_tag]);
    rf_git(['push', 'Github', $stable_tag], false);
    rf_git(['branch', '-f', 'master', 'HEAD']);
    echo "STABLE PROMOTED: {$stable_tag}. Build it in Smack Central's BORING panel.\n";
    exit(0);
}

rf_fail('unknown command; use status, push-dev, tag-dev, or promote-stable');
// ===== SNAPSMACK EOF =====
