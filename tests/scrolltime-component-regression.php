<?php
declare(strict_types=1);

/**
 * THE SKIN ASKS, THE CMS PROVIDES IF IT IS SAFE.
 *
 * Sean's model for scroll-time. The thing that makes it non-obvious: a strict
 * skin CANNOT call snapsmack_scrolltime_tag() itself. core/skin-security-policy.php
 * allows exactly eight function names inside a layout and that is not one of
 * them, so a skin that tried would be refused by the ratchet and refused again
 * at packaging. The request therefore arrives through the component registry,
 * which is already allowlisted.
 *
 * The first check below is the one that matters before anyone edits a skin:
 * A LAYOUT THAT CALLS THIS COMPONENT MUST PASS THE RATCHET. I claimed it would
 * from reading the allowlist; this proves it by running the gate.
 */

$root = dirname(__DIR__);
if (!defined('SNAPSMACK_SKIN_RENDER')) define('SNAPSMACK_SKIN_RENDER', true);
if (!defined('BASE_URL')) define('BASE_URL', '/');
if (!defined('SNAPSMACK_VERSION_SHORT')) define('SNAPSMACK_VERSION_SHORT', '0.7.977D');

require_once $root . '/core/trusted-html.php';
require_once $root . '/core/skin-security-policy.php';
require_once $root . '/core/skin-manifest.php';
require_once $root . '/core/skin-render-helpers.php';
require_once $root . '/core/stats-logger.php';

$pass = 0; $fail = 0;
function cx(bool $ok, string $what): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    {$what}\n"; }
    else     { $fail++; echo "  FAIL  {$what}\n"; }
}

// ---------------------------------------------------------------------------
echo "A LAYOUT MAY CALL IT WITHOUT FAILING THE SECURITY RATCHET\n";
// Built outside skins/ on purpose: a stray directory in there would change the
// skin count and break the version/content gate for everybody.
$tmp = sys_get_temp_dir() . '/snapsmack-scrolltime-' . bin2hex(random_bytes(6));
mkdir($tmp);
try {
    // The full strict contract, copied from a real skin's shape. My first
    // fixture had only four keys and the gate returned six findings -- every
    // one of them about the MISSING CONTRACT (cms_controller, view_model,
    // templates, security_policy) and not one about the component call. A
    // fixture that fails for its own reasons proves nothing about the thing
    // under test, so it is complete now: if a finding appears, it is the call.
    file_put_contents($tmp . '/manifest.json', json_encode([
        'schema_version' => 2,
        'security_policy' => 2,
        'name' => 'RATCHET FIXTURE',
        'version' => '1.0.0',
        'cms_controller' => 'public',
        'view_model' => 'snapsmack.public.v1',
        'templates' => ['landing' => 'layout.php', 'default' => 'layout.php'],
        'scrolltime_pages' => ['landing'],
    ]));
    file_put_contents($tmp . '/style.css', ".x{color:#000}\n");
    file_put_contents($tmp . '/layout.php',
        "<?php defined('SNAPSMACK_SKIN_RENDER') || exit; ?>\n"
        . "<?php echo snap_render_html(snap_render_component('scroll-time', \$view)); ?>\n");
    $findings = snapsmack_skin_security_gate($tmp);
    cx($findings === [],
       'a layout whose only statement is this component is clean (' . count($findings) . ' findings)');
    if ($findings !== []) {
        echo '        ' . json_encode($findings) . "\n";
    }
} finally {
    foreach (glob($tmp . '/*') ?: [] as $f) unlink($f);
    rmdir($tmp);
    cx(!is_dir($tmp), 'the fixture skin directory was removed, so skins/ is untouched');
}

// ---------------------------------------------------------------------------
echo "\nA SKIN THAT ASKS FOR NOTHING GETS NOTHING\n";
// No installed skin declares scrolltime_pages yet, so any real slug proves the
// ungranted path without touching a single file.
$GLOBALS['settings'] = ['site_mode' => 'photoblog'];
$GLOBALS['snapsmack_hit'] = ['id' => 11, 'page_type' => 'landing'];
$out = (string)snap_render_component('scroll-time', ['site' => ['skin_slug' => 'true-grit']]);
cx($out === '', "TRUE GRIT declares no scrolltime_pages, so it is handed nothing");

$out = (string)snap_render_component('scroll-time', ['site' => ['skin_slug' => '']]);
cx($out === '', 'no skin slug at all is handed nothing rather than erroring');

$out = (string)snap_render_component('scroll-time', ['site' => ['skin_slug' => 'no-such-skin']]);
cx($out === '', 'an unknown skin is handed nothing');

// ---------------------------------------------------------------------------
echo "\nA SKIN THAT ASKS, AND IS ELIGIBLE, IS HANDED THE TAG\n";
// The grant is read from a real manifest, so one is temporarily given the
// declaration and restored byte-identically afterwards.
$target = $root . '/skins/glide/manifest.json';
$originalBytes = (string)file_get_contents($target);
$originalHash = hash('sha256', $originalBytes);

// REFUSE TO RUN IF THE FILE IS ALREADY DIRTY. This guard exists because I got
// it wrong: the restore below verifies against the bytes THIS run read, so a
// run that died before its finally would be "restored" to its own modified
// state and the check would still report success. It did exactly that once and
// left a live skin manifest modified in the shared checkout, where somebody
// else's `git add -A` could have committed it.
//
// Comparing against the COMMITTED blob is the only way to tell the difference.
$committed = (string)shell_exec('git -C ' . escapeshellarg($root)
    . ' show HEAD:skins/glide/manifest.json 2>&1');
$cleanToStart = $committed !== '' && hash('sha256', $committed) === $originalHash;
cx($cleanToStart, 'glide/manifest.json matches its committed state before this test touches it');
if (!$cleanToStart) {
    echo "        REFUSING to edit it. Restore it first:\n";
    echo "            git checkout -- skins/glide/manifest.json\n";
    echo "\n{$pass} checks, {$fail} failed\n";
    exit(1);
}

try {
    $mf = json_decode($originalBytes, true);
    $mf['scrolltime_pages'] = ['landing'];
    file_put_contents($target, json_encode($mf, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    // load_skin_manifest() caches per slug, so this must run in a child
    // process to see the edited file. Asserting against a cached read would
    // prove nothing at all.
    $probe = sys_get_temp_dir() . '/snapsmack-st-probe-' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($probe, <<<'PHP'
<?php
$root = $argv[1];
define('SNAPSMACK_SKIN_RENDER', true); define('BASE_URL', '/');
define('SNAPSMACK_VERSION_SHORT', '0.7.977D');
require_once $root . '/core/trusted-html.php';
require_once $root . '/core/skin-manifest.php';
require_once $root . '/core/skin-render-helpers.php';
require_once $root . '/core/stats-logger.php';
$GLOBALS['settings'] = ['site_mode' => $argv[3]];
$GLOBALS['snapsmack_hit'] = $argv[4] === 'nohit' ? null : ['id' => 11, 'page_type' => $argv[4]];
$one = (string)snap_render_component('scroll-time', ['site' => ['skin_slug' => $argv[2]]]);
$two = (string)snap_render_component('scroll-time', ['site' => ['skin_slug' => $argv[2]]]);
echo json_encode(['tags' => substr_count($one . $two, 'id="ss-scrolltime"')]);
PHP);
    $run = function (string $mode, string $type) use ($probe, $root): int {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probe) . ' '
             . escapeshellarg($root) . ' glide ' . escapeshellarg($mode) . ' ' . escapeshellarg($type);
        $r = json_decode((string)shell_exec($cmd), true);
        return (int)($r['tags'] ?? -1);
    };

    cx($run('photoblog', 'landing') === 1,
       'a granted skin on an eligible page gets exactly ONE tag, even though the component was called twice');
    cx($run('photoblog', 'image') === 0,
       'and nothing on a page type scroll-time does not cover');
    cx($run('carousel', 'archive') === 0,
       'the site-mode rules still apply: carousel archive is not eligible');
    cx($run('photoblog', 'nohit') === 0,
       'and nothing at all without a logged hit, so the beacon can never be unbound');
    unlink($probe);
} finally {
    file_put_contents($target, $originalBytes);
    cx(hash('sha256', (string)file_get_contents($target)) === $originalHash,
       'glide/manifest.json restored byte-identically');
}

// ---------------------------------------------------------------------------
echo "\nCORE STILL NAMES NO SKIN FOR THIS\n";
$helpers = (string)file_get_contents($root . '/core/skin-render-helpers.php');
$block = substr($helpers, (int)strpos($helpers, "elseif (\$name === 'scroll-time')"), 2200);
foreach (['glide', 'scroll', 'slickr', 'photogram', 'telegram', 'smacktalk'] as $skinName) {
    cx(!str_contains(strtolower($block), "'" . $skinName . "'"),
       "the component does not mention {$skinName}");
}

echo "\n{$pass} checks, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
// ===== SNAPSMACK EOF =====
