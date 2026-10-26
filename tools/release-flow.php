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

/**
 * Paths that cannot reach a CMS release, and so cannot make one unsafe.
 *
 * The clean check used to read the whole repository. In a shared checkout that
 * means a half-finished desktop tool, an audit still being written, or Smack
 * Central -- which ships through its own updater, not this zip -- blocks a CMS
 * release that none of them can affect. It refused tag after tag for work that
 * was never going in the package, and the message named nothing, so the only
 * way to find out why was to run git status yourself.
 *
 * This is a narrowing of scope, not of strictness: everything that ships is
 * still checked, and the release machinery under tools/_build and this file
 * are checked too, because a release cut with an edited builder is exactly the
 * thing worth stopping.
 */
function rf_nonshipping_prefixes(): array {
    return [
        'smack-central/',   // its own product with its own self-updater
        'opaudits/',        // reports about the software, never in it
        'outputs/',         // generated audit and parity reports
        'projects/',        // per-site content, not CMS code
        '_spec/',           // private design notes, gitignored anyway
        'tools/',           // desktop apps and scripts; see rf_shipping_tools()
    ];
}

/** Paths under tools/ that ARE release machinery and must be clean. */
function rf_shipping_tools(): array {
    return ['tools/_build/', 'tools/release-flow.php'];
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

/**
 * Split the dirty paths into the ones that would ship and the ones that cannot.
 * Returns ['shipping' => [...], 'ignored' => [...]].
 */
function rf_dirty_split(): array {
    return rf_classify_dirt(explode("
", rf_worktree_status()));
}

/**
 * The classification on its own, so it can be tested with fixed input instead
 * of whatever happens to be lying around in somebody's checkout.
 * Takes raw `git status --porcelain` lines.
 */
function rf_classify_dirt(array $lines): array {
    $shipping = [];
    $ignored = [];
    foreach ($lines as $line) {
        $line = rtrim($line);
        if ($line === '') continue;
        $path = trim(substr($line, 2));
        if (str_contains($path, ' -> ')) $path = trim(substr($path, strpos($path, ' -> ') + 4));
        $path = trim($path, '"');
        $isShipping = true;
        foreach (rf_nonshipping_prefixes() as $prefix) {
            if (str_starts_with($path, $prefix)) { $isShipping = false; break; }
        }
        foreach (rf_shipping_tools() as $prefix) {
            if (str_starts_with($path, $prefix)) { $isShipping = true; break; }
        }
        if ($isShipping) $shipping[] = $path; else $ignored[] = $path;
    }
    return ['shipping' => $shipping, 'ignored' => $ignored];
}

function rf_require_clean(): void {
    $split = rf_dirty_split();
    if ($split['shipping'] === []) {
        if ($split['ignored'] !== []) {
            echo 'NOTE: ' . count($split['ignored']) . " uncommitted file(s) outside the release are being left alone.
";
        }
        return;
    }
    $shown = array_slice($split['shipping'], 0, 20);
    $more = count($split['shipping']) - count($shown);
    rf_fail("these release files are uncommitted:
      "
        . implode("
      ", $shown)
        . ($more > 0 ? "
      ... and {$more} more" : '')
        . "
  Commit them, or set them aside deliberately. Work under "
        . implode(', ', rf_nonshipping_prefixes())
        . ' is ignored because it cannot reach a CMS release.');
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

/**
 * Why the named commit may not be released, or '' if it may be.
 *
 * This used to be the body of rf_require_release_gate() and only ever asked
 * about HEAD. It takes a commit now because the pre-push hook has to ask the
 * same question about a tag's target before letting the tag leave the machine
 * (OPAUDIT 026, step 1): v0.7.946D, v0.7.947D and v0.7.948D were all tagged by
 * hand, outside this tool, over three red regressions. One wording, one set of
 * rules, two callers.
 */
function rf_release_gate_problem(string $head): string {
    $ref = 'refs/notes/release-gates';
    exec('git notes --ref=' . escapeshellarg($ref) . ' show ' . escapeshellarg($head) . ' 2>&1', $lines, $code);
    if ($code !== 0) {
        return "commit {$head} has no release-gate note; security, full skin parity, and authority review must pass before tagging";
    }
    $raw = trim(implode("\n", $lines));
    $gate = json_decode($raw, true);
    if (!is_array($gate)) return "release-gate note for {$head} is not valid JSON";
    $required = [
        'commit' => $head,
        'security' => 'pass',
        'parity' => 'pass',
        'skin_render_parity' => 'pass',
        'authority_review' => 'approved',
    ];
    foreach ($required as $field => $expected) {
        if (($gate[$field] ?? null) !== $expected) {
            return "release-gate note field {$field} must be " . json_encode($expected);
        }
    }
    $skinDirs = glob(dirname(__DIR__) . '/skins/*/manifest.json') ?: [];
    $skinNames = array_map(static fn (string $manifest): string => basename(dirname($manifest)), $skinDirs);
    sort($skinNames, SORT_STRING);
    $skinCount = count($skinNames);
    $inventoryHash = hash('sha256', implode("\n", $skinNames));
    if (($gate['skin_count'] ?? null) !== $skinCount) {
        return "release-gate note must record all {$skinCount} packaged skins";
    }
    if (($gate['skin_inventory_sha256'] ?? null) !== $inventoryHash) {
        return 'release-gate note skin inventory does not match the packaged skins';
    }
    if (!array_key_exists('skin_render_changes', $gate) || !is_array($gate['skin_render_changes'])) {
        return 'release-gate note must include skin_render_changes as an array (normally empty)';
    }
    foreach ($gate['skin_render_changes'] as $index => $change) {
        if (!is_array($change)) {
            return "skin_render_changes entry {$index} must be an object";
        }
        foreach (['skin', 'reason', 'pre_strip_reference'] as $field) {
            if (!is_string($change[$field] ?? null) || trim($change[$field]) === '') {
                return "skin_render_changes entry {$index} must declare {$field}";
            }
        }
        if (!in_array($change['skin'], $skinNames, true)) {
            return "skin_render_changes entry {$index} names an unbundled skin";
        }
    }
    return '';
}

function rf_require_release_gate(): void {
    $problem = rf_release_gate_problem(rf_git(['rev-parse', 'HEAD']));
    if ($problem !== '') rf_fail($problem);
}

function rf_tests(): void {
    foreach (glob(__DIR__ . '/../tests/*regression.php') ?: [] as $test) {
        rf_run([PHP_BINARY, $test]);
    }
    rf_run([PHP_BINARY, '-l', __DIR__ . '/../smack-central/sc-release.php']);
    rf_run([PHP_BINARY, '-l', __DIR__ . '/../core/updater.php']);
    rf_run([PHP_BINARY, '-l', __FILE__]);
}

// Requiring this file from a test must not run a command. When it is the
// script being run, argv[0] is this file; when it is included, it is not.
if (!isset($argv[0]) || realpath($argv[0]) !== realpath(__FILE__)) return;

$command = strtolower($argv[1] ?? 'status');

if ($command === 'status') {
    echo "Branch: " . rf_branch() . "\n";
    echo "Commit: " . rf_git(['rev-parse', '--short', 'HEAD']) . "\n";
    echo "Source version: " . rf_source_version() . "\n";
    $split = rf_dirty_split();
    echo "Working tree: " . ($split['shipping'] === [] ? 'clean for release' : 'dirty') . "
";
    if ($split['shipping'] !== []) {
        echo 'Uncommitted release files (' . count($split['shipping']) . "):
";
        foreach (array_slice($split['shipping'], 0, 20) as $path) echo "  {$path}
";
        if (count($split['shipping']) > 20) echo '  ... and ' . (count($split['shipping']) - 20) . " more
";
    }
    if ($split['ignored'] !== []) {
        echo 'Outside the release, left alone: ' . count($split['ignored']) . " file(s)
";
    }
    exit(0);
}

if ($command === 'gate-check') {
    // Asked by .githooks/pre-push about the commit a tag points at, before the
    // tag is allowed to leave the machine. Silent and exit 0 when the commit
    // may be released; one line on stderr and exit 1 when it may not.
    $target = $argv[2] ?? 'HEAD';
    $commit = rf_git(['rev-parse', $target . '^{commit}']);
    if ($commit === '') rf_fail("cannot resolve {$target} to a commit");
    $problem = rf_release_gate_problem($commit);
    if ($problem !== '') rf_fail($problem);
    echo "release gate passes for {$commit}\n";
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

if ($command === 'gate-note') {
    // The gate note used to be hand-written JSON: the commit sha copied by
    // hand, the skin count counted by hand, the inventory hash computed by
    // hand. Every one of those is derivable, and getting one wrong refuses the
    // tag with a message about a field rather than about the release. What is
    // NOT derivable is the sign-off, so this still refuses unless each of the
    // four judgements is stated outright. The check is unchanged; only the
    // typing is gone.
    rf_require_dev();
    rf_require_clean();

    $flags = [];
    foreach (array_slice($argv, 2) as $arg) {
        if (!preg_match('/^--([a-z-]+)=(.+)$/', $arg, $m)) {
            rf_fail("unrecognised argument '{$arg}'; expected --name=value");
        }
        $flags[$m[1]] = $m[2];
    }
    $required = [
        'security' => 'pass',
        'parity' => 'pass',
        'skin-render-parity' => 'pass',
        'authority' => 'approved',
    ];
    $missing = [];
    foreach ($required as $flag => $expected) {
        if (($flags[$flag] ?? '') !== $expected) $missing[] = "--{$flag}={$expected}";
    }
    if ($missing) {
        rf_fail("this release has not been signed off. State each judgement outright:
      "
            . implode("
      ", $missing)
            . "
  Nothing here is inferred, because a gate that signs itself is not a gate.");
    }

    $head = rf_git(['rev-parse', 'HEAD']);
    $skinDirs = glob(dirname(__DIR__) . '/skins/*/manifest.json') ?: [];
    $skinNames = array_map(static fn (string $manifest): string => basename(dirname($manifest)), $skinDirs);
    sort($skinNames, SORT_STRING);

    $note = [
        'commit' => $head,
        'security' => 'pass',
        'parity' => 'pass',
        'skin_render_parity' => 'pass',
        'authority_review' => 'approved',
        'skin_count' => count($skinNames),
        'skin_inventory_sha256' => hash('sha256', implode("
", $skinNames)),
        'skin_render_changes' => [],
    ];
    if (isset($flags['tests'])) $note['tests'] = $flags['tests'];
    if (isset($flags['authority-by'])) $note['authority'] = $flags['authority-by'];

    $tmp = tempnam(sys_get_temp_dir(), 'rfgate');
    file_put_contents($tmp, json_encode($note, JSON_UNESCAPED_SLASHES));
    rf_git(['notes', '--ref=release-gates', 'add', '-f', '-F', $tmp, $head]);
    @unlink($tmp);

    // Prove it by the same code the tag uses, rather than by assertion.
    rf_require_release_gate();
    echo "GATE NOTE WRITTEN for {$head} (" . count($skinNames) . " skins). "
        . "It is checked again when you tag.
";
    exit(0);
}

rf_fail('unknown command; use status, gate-check, gate-note, push-dev, tag-dev, or promote-stable');
// ===== SNAPSMACK EOF =====
