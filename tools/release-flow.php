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

function rf_worktree_status(): string {
    // Pytest can leave Windows cache directories with ACLs that Git cannot
    // traverse. They are disposable and never release inputs, so exclude them
    // rather than mistaking Git's access warning for a dirty working tree.
    return rf_git([
        'status', '--porcelain', '--untracked-files=all', '--', '.',
        ':(exclude).pytest_cache',
        ':(exclude)tools/hub/.pytest_cache',
    ]);
}

function rf_require_clean(): void {
    if (rf_worktree_status() !== '') {
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

function rf_require_changelog(string $version): void {
    $changelog = file_get_contents(__DIR__ . '/../CHANGELOG.md');
    if (!is_string($changelog)
        || !preg_match('/^## ' . preg_quote($version, '/') . '\s/m', $changelog)) {
        rf_fail("CHANGELOG.md has no versioned {$version} section");
    }
}

function rf_tag_target(string $tag): string {
    exec('git show-ref --verify --hash ' . escapeshellarg('refs/tags/' . $tag) . ' 2>&1', $lines, $code);
    return $code === 0 ? trim(implode("\n", $lines)) : '';
}

function rf_release_declarations(): array {
    $path = dirname(__DIR__) . '/smack-central/release-identifier-declarations.ndjson';
    if (!is_file($path)) rf_fail('declared release ledger is missing');
    $entries = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $entry = json_decode($line, true);
        $version = is_array($entry) ? preg_replace('/D$/i', '', (string)($entry['version'] ?? '')) : '';
        if ($version === '' || isset($entries[$version])) rf_fail('declared release ledger is invalid or contains a duplicate');
        $entries[$version] = $entry;
    }
    return $entries;
}

function rf_require_next_dev_version(string $version): void {
    // A Git tag is a candidate, not proof of a package or deployment. Keep
    // numbers sequential without claiming that any earlier tag was installed.
    $tags = rf_git(['tag', '--list', 'v*D']);
    $latest = -1;
    $prefix = '';
    foreach (explode("\n", $tags) as $tag) {
        if (!preg_match('/^v(\d+\.\d+)\.(\d+)D$/', trim($tag), $m)) continue;
        if ($m[1] !== implode('.', array_slice(explode('.', $version), 0, 2))) continue;
        if ((int)$m[2] > $latest) {
            $latest = (int)$m[2];
            $prefix = $m[1];
        }
    }
    $next = $latest + 1;
    $reservationsPath = __DIR__ . '/release-reservations.json';
    $reservations = is_file($reservationsPath) ? json_decode((string)file_get_contents($reservationsPath), true) : [];
    if (!is_array($reservations)) rf_fail('release reservation ledger is invalid');
    while (isset($reservations[$prefix . '.' . $next])) $next++;
    $declarations = rf_release_declarations();
    if (isset($declarations[$version])) rf_fail("development identifier {$version}D is already present in the append-only release ledger");
    if ($latest >= 0 && $version !== $prefix . '.' . $next) {
        rf_fail("next dev candidate must be {$prefix}.{$next}"
            . '; do not skip or reuse a tag number');
    }
}

function rf_fetch_public_manifest(string $url): string|false {
    // Minimal Windows PHP builds may omit the OpenSSL stream wrapper even
    // though the release workstation has the system curl client. Keep the
    // public sequence check fail-closed, but do not make its transport depend
    // on one optional PHP extension.
    if (in_array('https', stream_get_wrappers(), true)) {
        $context = stream_context_create(['http' => ['timeout' => 15, 'ignore_errors' => true]]);
        $raw = @file_get_contents($url, false, $context);
        if (is_string($raw) && $raw !== '') return $raw;
    }
    $command = 'curl --fail --silent --show-error --location --max-time 15 '
        . escapeshellarg($url) . ' 2>&1';
    exec($command, $lines, $code);
    $raw = trim(implode("\n", $lines));
    return $code === 0 && $raw !== '' ? $raw : false;
}

function rf_release_state_refusal(string $requested, string $latest_tagged, string $latest_packaged): string {
    $requested = rf_version($requested);
    $latest_tagged = rf_version($latest_tagged);
    $latest_packaged = rf_version($latest_packaged);
    $declarations = rf_release_declarations();
    if (isset($declarations[$requested])) {
        return "development identifier {$requested}D is already present in the append-only release ledger";
    }
    if ($latest_tagged !== $latest_packaged) {
        return "highest tagged dev version is {$latest_tagged}D but highest packaged dev version is {$latest_packaged}D; "
            . 'package and deploy every existing tag in order before creating another tag';
    }
    [$major, $minor, $patch] = array_map('intval', explode('.', $latest_packaged));
    $expected = "{$major}.{$minor}." . ($patch + 1);
    return $requested === $expected
        ? ''
        : "next dev tag must be {$expected}D because {$latest_packaged}D is the highest packaged version";
}

function rf_require_packaged_tag_alignment(string $requested): void {
    $tags = rf_git(['tag', '--list', 'v*D']);
    $latest_tagged = '';
    foreach (explode("\n", $tags) as $tag) {
        if (!preg_match('/^v(\d+\.\d+\.\d+)D$/i', trim($tag), $m)) continue;
        if ($latest_tagged === '' || version_compare($m[1], $latest_tagged, '>')) $latest_tagged = $m[1];
    }
    if ($latest_tagged === '') rf_fail('could not determine the highest tagged development version');

    $raw = rf_fetch_public_manifest('https://snapsmack.ca/releases/latest-dev.json');
    $manifest = is_string($raw) ? json_decode($raw, true) : null;
    $latest_packaged = is_array($manifest) ? (string)($manifest['version'] ?? '') : '';
    if (!preg_match('/^\d+\.\d+\.\d+D$/i', $latest_packaged)) {
        rf_fail('could not verify the highest packaged development version; tagging fails closed');
    }
    $latest_packaged = preg_replace('/D$/i', '', $latest_packaged);
    $refusal = rf_release_state_refusal($requested, $latest_tagged, $latest_packaged);
    if ($refusal !== '') rf_fail($refusal . '. No override exists.');
}

function rf_require_release_gate(): void {
    $head = rf_git(['rev-parse', 'HEAD']);
    $ref = 'refs/notes/release-gates';
    exec('git notes --ref=' . escapeshellarg($ref) . ' show ' . escapeshellarg($head) . ' 2>&1', $lines, $code);
    if ($code !== 0) {
        rf_fail("commit {$head} has no release-gate note; security, full skin parity, and authority review must pass before tagging");
    }
    $raw = trim(implode("\n", $lines));
    $gate = json_decode($raw, true);
    if (!is_array($gate)) rf_fail("release-gate note for {$head} is not valid JSON");
    $required = [
        'commit' => $head,
        'security' => 'pass',
        'parity' => 'pass',
        'skin_render_parity' => 'pass',
        'authority_review' => 'approved',
    ];
    foreach ($required as $field => $expected) {
        if (($gate[$field] ?? null) !== $expected) {
            rf_fail("release-gate note field {$field} must be " . json_encode($expected));
        }
    }
    $skinDirs = glob(dirname(__DIR__) . '/skins/*/manifest.json') ?: [];
    $skinNames = array_map(static fn (string $manifest): string => basename(dirname($manifest)), $skinDirs);
    sort($skinNames, SORT_STRING);
    $skinCount = count($skinNames);
    $inventoryHash = hash('sha256', implode("\n", $skinNames));
    if (($gate['skin_count'] ?? null) !== $skinCount) {
        rf_fail("release-gate note must record all {$skinCount} packaged skins");
    }
    if (($gate['skin_inventory_sha256'] ?? null) !== $inventoryHash) {
        rf_fail('release-gate note skin inventory does not match the packaged skins');
    }
    if (!array_key_exists('skin_render_changes', $gate) || !is_array($gate['skin_render_changes'])) {
        rf_fail('release-gate note must include skin_render_changes as an array (normally empty)');
    }
    foreach ($gate['skin_render_changes'] as $index => $change) {
        if (!is_array($change)) {
            rf_fail("skin_render_changes entry {$index} must be an object");
        }
        foreach (['skin', 'reason', 'pre_strip_reference'] as $field) {
            if (!is_string($change[$field] ?? null) || trim($change[$field]) === '') {
                rf_fail("skin_render_changes entry {$index} must declare {$field}");
            }
        }
        if (!in_array($change['skin'], $skinNames, true)) {
            rf_fail("skin_render_changes entry {$index} names an unbundled skin");
        }
    }
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
    echo "Working tree: " . (rf_worktree_status() === '' ? 'clean' : 'dirty') . "\n";
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
    $dev_version = $version . 'D';
    if (rf_source_version() !== $dev_version) {
        rf_fail("source version is " . rf_source_version() . "; expected {$dev_version}");
    }
    rf_require_changelog($dev_version);
    rf_git(['fetch', 'Github', '--tags', '--prune'], false);
    $tag = 'v' . $version . 'D';
    if (rf_tag_target($tag) !== '') rf_fail("tag {$tag} already exists; use the next version");
    rf_require_next_dev_version($version);
    rf_require_packaged_tag_alignment($version);
    rf_tests();
    rf_require_release_gate();
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
    rf_require_changelog($version);
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
    rf_require_release_gate();
    rf_git(['push', 'Github', 'HEAD:master'], false);
    rf_git(['tag', $stable_tag]);
    rf_git(['push', 'Github', $stable_tag], false);
    rf_git(['branch', '-f', 'master', 'HEAD']);
    echo "STABLE PROMOTED: {$stable_tag}. Build it in Smack Central's BORING panel.\n";
    exit(0);
}

rf_fail('unknown command; use status, push-dev, tag-dev, or promote-stable');
// ===== SNAPSMACK EOF =====
