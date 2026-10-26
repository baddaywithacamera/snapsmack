<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * The release gate must block on what ships, and only on what ships.
 *
 * The clean check used to read the whole repository. In a shared checkout that
 * means a half-finished desktop tool, an audit still being written, or SMACK
 * CENTRAL -- which reaches sites through its own self-updater, never through
 * the CMS zip -- refuses a CMS release that none of them can affect. On
 * 2026-10-26 that happened twice in one evening, and the message named nothing,
 * so the only way to find out what was blocking was to run git status yourself.
 *
 * This test holds the narrowing in place from both sides. A gate that stops
 * nothing is worse than no gate, so every shipping path must still block; a
 * gate that stops everything gets switched off, so the paths that cannot reach
 * a release must not.
 */

require_once dirname(__DIR__) . '/tools/release-flow.php';

$failures = 0;
function rgs_check(bool $ok, string $what): void
{
    global $failures;
    if ($ok) return;
    $failures++;
    fwrite(STDERR, "FAIL: {$what}\n");
}

// --- what blocks a release ---------------------------------------------------

$blocks = [
    ' M core/public-controller.php'          => 'CMS code',
    ' M index.php'                           => 'the router',
    '?? core/new-thing.php'                  => 'an untracked CMS file',
    ' M skins/chaplin/style.css'             => 'a skin stylesheet',
    ' M tests/skin-render-snapshot-regression.php' => 'a regression test',
    ' M tools/_build/build-release.php'      => 'the release builder',
    ' M tools/release-flow.php'              => 'the release flow itself',
    ' M CHANGELOG.md'                        => 'the changelog',
    ' M assets/css/public-base.css'          => 'shipped CSS',
];
foreach ($blocks as $line => $what) {
    $split = rf_classify_dirt([$line]);
    rgs_check($split['shipping'] !== [], "a release is no longer blocked by {$what}: {$line}");
}

// --- what does not ----------------------------------------------------------

$ignores = [
    ' M tools/gyss/gyss_qt.py'               => 'a desktop tool',
    ' M tools/coldsnap/coldsnap_qt_launcher.py' => 'another desktop tool',
    '?? tools/smackpress/wp-import/x.jpg'    => 'importer cache',
    ' M smack-central/sc-skins.php'          => 'SMACK CENTRAL, which self-updates',
    ' M opaudits/2026-10-25-027-x.html'      => 'an audit being written',
    ' M outputs/skin-parity-v0.7.772D.json'  => 'a generated report',
    ' M projects/photoblogs-fyi/cms-pages/custom.css' => 'per-site content',
    ' M _spec/page-components-by-function.md' => 'private design notes',
];
foreach ($ignores as $line => $what) {
    $split = rf_classify_dirt([$line]);
    rgs_check($split['shipping'] === [], "a release is still blocked by {$what}: {$line}");
    rgs_check($split['ignored'] !== [], "{$what} was not reported as left alone: {$line}");
}

// --- the shapes git actually prints -----------------------------------------

$renamed = rf_classify_dirt(['R  core/old.php -> core/new.php']);
rgs_check(in_array('core/new.php', $renamed['shipping'], true),
    'a renamed release file is not read as its new path');

$quoted = rf_classify_dirt(['?? "core/a file with spaces.php"']);
rgs_check(in_array('core/a file with spaces.php', $quoted['shipping'], true),
    'a quoted path keeps its quotation marks');

$mixed = rf_classify_dirt([' M core/x.php', ' M tools/gyss/y.py', '']);
rgs_check(count($mixed['shipping']) === 1 && count($mixed['ignored']) === 1,
    'a mixed list is not split one for one');
rgs_check(rf_classify_dirt([''])['shipping'] === [] && rf_classify_dirt([''])['ignored'] === [],
    'a blank line counted as a dirty file');

// --- the sign-off is still a human judgement --------------------------------

$flow = (string)file_get_contents(dirname(__DIR__) . '/tools/release-flow.php');
foreach (['security', 'parity', 'skin-render-parity', 'authority'] as $judgement) {
    rgs_check(str_contains($flow, "'{$judgement}' => '"),
        "gate-note no longer demands the {$judgement} judgement outright");
}
rgs_check((bool)preg_match('/notes[^;]*release-gates[^;]*add.*?rf_require_release_gate\(\)/s', $flow),
    'gate-note writes a note without then proving it with the same check the tag uses');
foreach (['tag-dev', 'promote-stable'] as $gated) {
    $start = strpos($flow, "if (\$command === '{$gated}')");
    $next = $start === false ? false : strpos($flow, "if (\$command === '", $start + 10);
    $block = $start === false ? '' : ($next === false ? substr($flow, $start) : substr($flow, $start, $next - $start));
    rgs_check(str_contains($block, 'rf_require_release_gate();'),
        "{$gated} no longer checks the release gate note");
    rgs_check(str_contains($block, 'rf_require_clean();'),
        "{$gated} no longer requires a clean release tree");
}
rgs_check(!str_contains($flow, '--force') && !str_contains($flow, 'skip-gate'),
    'the release flow has grown an override');

if ($failures > 0) {
    fwrite(STDERR, "\nRelease gate scope regression FAILED with {$failures} problem(s).\n");
    exit(1);
}
print("Release gate scope: blocks every shipping path, ignores what cannot ship, sign-off still required.\n");
exit(0);
// ===== SNAPSMACK EOF =====
