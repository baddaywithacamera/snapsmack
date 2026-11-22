<?php
declare(strict_types=1);
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * sybu-data tells a desktop tool what the site actually looks like.
 *
 * WHY. COLD SNAP's BIGGIE preview had nothing to go on, so it drew white and a
 * serif no site uses and printed "site colours unavailable". The ask was for
 * site_bg, site_fg, active_skin and a body font.
 *
 * THREE OF THOSE FOUR DO NOT EXIST, which is the finding this test pins. There
 * is no site_bg or site_fg key in core/ and no global body-font key: colours
 * and fonts are per-skin DECLARED OPTIONS with per-skin names, stored scoped.
 * ALFRED -- the skin it was asked for -- declares none of the three. So the
 * endpoint returns what each skin genuinely declares and says nothing about
 * what it does not, and a client falls back deliberately instead of reading an
 * invented default as fact.
 *
 * STAGE 4 IS THE ONE THAT MATTERS: it drives the endpoint's own code against a
 * database, through the same resolver the public CSS uses, rather than
 * asserting on the source. A test that reads the file would pass with the
 * resolver swapped for a hardcoded array, which is the shape of fault this
 * project spent 2026-11-21 repairing.
 *
 * NOT COVERED: the HTTP request itself, which needs an authenticated session or
 * a scoped Bearer key. The assembly, the resolution and the absence rule are
 * all driven.
 */

$root = dirname(__DIR__);
$failures = [];

function sy_check(string $label, bool $ok, array &$failures): void {
    if ($ok) { echo "  PASS  {$label}\n"; }
    else { echo "  FAIL  {$label}\n"; $failures[] = $label; }
}

if (!defined('BASE_URL')) { define('BASE_URL', 'https://example.invalid/'); }
require_once $root . '/core/skin-settings.php';
require_once $root . '/core/skin-presentation.php';

echo "sybu-data carries the skin's declared values\n\n";

// ─────────────────────────────────────────────────────────────────────────────
echo "STAGE 1 — THE FINDING: THOSE GLOBAL KEYS DO NOT EXIST\n";
// ─────────────────────────────────────────────────────────────────────────────
// If anybody later adds a site_bg or site_fg setting, this stage fails and
// whoever added it has to decide deliberately whether the endpoint should carry
// it. That is the point: the absence is a design decision, not an accident.
$coreFiles = glob($root . '/core/*.php') ?: [];
$hits = [];
foreach ($coreFiles as $file) {
    $body = (string)file_get_contents($file);
    foreach (["'site_bg'", '"site_bg"', "'site_fg'", '"site_fg"'] as $needle) {
        if (str_contains($body, $needle)) { $hits[] = basename($file) . ' ' . $needle; }
    }
}
sy_check('no core file defines a site_bg or site_fg setting'
         . ($hits ? ' — FOUND: ' . implode(', ', array_slice($hits, 0, 4)) : ''),
         $hits === [], $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 2 — WHAT THE SMACKTALK SKINS REALLY DECLARE\n";
// ─────────────────────────────────────────────────────────────────────────────
// Measured from the manifests, not remembered. If a skin gains a background or
// a body font control, this stage notices.
$declared = [];
foreach (glob($root . '/skins/*/manifest.json') ?: [] as $path) {
    $m = json_decode((string)file_get_contents($path), true);
    if (!is_array($m) || ($m['cms_controller'] ?? '') !== 'smacktalk') { continue; }
    $slug = basename(dirname($path));
    $declared[$slug] = array_keys(is_array($m['options'] ?? null) ? $m['options'] : []);
}
sy_check('the SMACKTALK skins were found (' . count($declared) . ')',
         count($declared) >= 5, $failures);
sy_check('every one of them is schema_version 2, so the resolver applies',
         count(array_filter(glob($root . '/skins/*/manifest.json') ?: [],
             static function (string $p): bool {
                 $m = json_decode((string)file_get_contents($p), true);
                 return is_array($m) && ($m['cms_controller'] ?? '') === 'smacktalk'
                     && ($m['schema_version'] ?? 0) === 2;
             })) === count($declared), $failures);
sy_check('ALFRED declares no background, foreground or body-font control, '
         . 'which is why nothing was invented for it',
         isset($declared['alfred'])
         && !array_filter($declared['alfred'], static fn(string $k): bool
             => (bool)preg_match('/(background|^bg_|_bg$|body_font|site_fg|foreground)/i', $k)),
         $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 3 — THE RESOLVER IS THE ONE THE SITE USES\n";
// ─────────────────────────────────────────────────────────────────────────────
// A saved value beats the manifest default; a scoped key resolves; a colour is
// validated; a range is clamped to the manifest's own bounds. All of that is
// snapsmack_declared_skin_options()' behaviour, borrowed rather than rewritten.
$settings = ['active_skin' => 'alfred', 'alfred__accent_color' => '#AB12CD'];
snapsmack_apply_skin_settings($settings, 'alfred');
$opts = snapsmack_declared_skin_options($settings, 'alfred');
sy_check('a scoped saved colour resolves and is normalised',
         ($opts['accent_color'] ?? '') === '#ab12cd', $failures);
sy_check('a declared default arrives when nothing is saved',
         (string)($opts['post_content_width'] ?? '') !== '', $failures);

$settings = ['active_skin' => 'alfred', 'alfred__accent_color' => 'not-a-colour'];
snapsmack_apply_skin_settings($settings, 'alfred');
sy_check('a junk colour falls back to the declared default, never to junk',
         preg_match('/^#[0-9a-f]{6}$/', (string)(snapsmack_declared_skin_options($settings, 'alfred')['accent_color'] ?? '')) === 1,
         $failures);

$settings = ['active_skin' => 'alfred', 'alfred__landing_square_cols' => '99'];
snapsmack_apply_skin_settings($settings, 'alfred');
$cols = snapsmack_declared_skin_options($settings, 'alfred')['landing_square_cols'] ?? null;
sy_check('an out-of-range number is clamped by the manifest, not passed through',
         is_numeric($cols) && (float)$cols <= 5, $failures);

sy_check('an unknown skin yields no options rather than an error',
         snapsmack_declared_skin_options(['active_skin' => 'nope'], 'nope') === [], $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 4 — THE ENDPOINT'S OWN BLOCK, RUN AGAINST A DATABASE\n";
// ─────────────────────────────────────────────────────────────────────────────
// The endpoint's skin block is lifted out of sybu-data.php and executed with a
// real PDO, so this asserts on what the code produces rather than on what the
// file says. The lift fails loudly if the block is renamed or removed.
$source = (string)file_get_contents($root . '/sybu-data.php');
$from = strpos($source, "require_once 'core/skin-settings.php';");
$to = strpos($source, '// ── Response');
if ($from === false || $to === false || $to <= $from) {
    fwrite(STDERR, "could not find the skin block in sybu-data.php\n");
    exit(1);
}
// Through the END of the response, not just the skin block: a mutation that
// promoted post_content_width to a top-level response field survived a version
// of this stage that only inspected $skin. The payload is what a client reads,
// so the payload is what gets asserted.
$end = strpos($source, 'JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);', $to);
if ($end === false) { fwrite(STDERR, "could not find the response encode
"); exit(1); }
$block = substr($source, $from, ($end - $from) + strlen('JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);'));
// The endpoint resolves its requires against its own directory.
$block = str_replace(["require_once 'core/", "__DIR__ . '/skins/"],
                     ["require_once '" . $root . "/core/", "'" . $root . "/skins/"], $block);

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE snap_settings (setting_key TEXT PRIMARY KEY, setting_val TEXT)');
$ins = $pdo->prepare('INSERT INTO snap_settings (setting_key, setting_val) VALUES (?,?)');
$ins->execute(['active_skin', 'alfred']);
$ins->execute(['alfred__accent_color', '#102030']);
$ins->execute(['alfred__post_content_width', '760']);

// The other response fields are not this test's subject, so they arrive empty;
// the endpoint's own code builds the rest.
$run = static function (PDO $pdo, string $block): array {
    $categories = $albums = $tags = $titles = [];
    $site_mode = 'smacktalk';
    $publishing_policy = [];
    ob_start();
    eval($block);
    $json = (string)ob_get_clean();
    $payload = json_decode($json, true);
    return ['payload' => is_array($payload) ? $payload : [],
            'skin' => $skin ?? []];
};
$out = $run($pdo, $block);
$skin = $out['skin'];
$payload = $out['payload'];

sy_check('the block names the active skin', ($skin['slug'] ?? '') === 'alfred', $failures);
sy_check('  with the manifest name', ($skin['name'] ?? '') === 'ALFRED', $failures);
sy_check('  and its version', (string)($skin['version'] ?? '') !== '', $failures);
sy_check('  carrying the saved accent colour the site renders with',
         ($skin['options']['accent_color'] ?? '') === '#102030', $failures);
sy_check('  and post_content_width inside options, not as a field of its own',
         isset($skin['options']['post_content_width'])
         && !array_key_exists('post_content_width', $skin), $failures);
sy_check('  and NO invented background or foreground',
         !array_key_exists('site_bg', $skin) && !array_key_exists('site_fg', $skin)
         && !array_key_exists('site_bg', $skin['options'] ?? [])
         && !array_key_exists('site_fg', $skin['options'] ?? []), $failures);

// A site with no skin set must answer emptily rather than guessing one.
$bare = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$bare->exec('CREATE TABLE snap_settings (setting_key TEXT PRIMARY KEY, setting_val TEXT)');
$skinBare = $run($bare, $block)['skin'];
sy_check('a site with no active skin reports an empty slug and no options',
         ($skinBare['slug'] ?? 'x') === '' && ($skinBare['options'] ?? null) === [], $failures);

// THE PAYLOAD A CLIENT ACTUALLY READS. One source for the content width, so it
// cannot drift from the value the page uses, and nothing invented beside it.
sy_check('the response carries the skin block', isset($payload['skin']), $failures);
sy_check('  and does NOT carry a second copy of post_content_width',
         !array_key_exists('post_content_width', $payload), $failures);
sy_check('  nor an invented site_bg or site_fg anywhere in it',
         !str_contains(strtolower(json_encode($payload)), 'site_bg')
         && !str_contains(strtolower(json_encode($payload)), 'site_fg'), $failures);
sy_check('  while the fields it already had are untouched',
         array_key_exists('categories', $payload) && array_key_exists('site_mode', $payload)
         && array_key_exists('publishing_policy', $payload), $failures);

// A HOSTILE active_skin REACHES A FILESYSTEM PATH IF IT IS NOT SANITISED. The
// endpoint builds skins/<slug>/manifest.json from it, so the slug is filtered
// before use. A mutation removing that filter survived until this case existed.
foreach (['../../etc/passwd', 'alfred/../../../core', 'al fred', 'ALFRED'] as $hostile) {
    $evil = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $evil->exec('CREATE TABLE snap_settings (setting_key TEXT PRIMARY KEY, setting_val TEXT)');
    $evil->prepare('INSERT INTO snap_settings (setting_key, setting_val) VALUES (?,?)')
         ->execute(['active_skin', $hostile]);
    $got = $run($evil, $block)['skin'];
    $slug = (string)($got['slug'] ?? '');
    sy_check('a stored skin of ' . var_export($hostile, true)
             . ' cannot carry a path out of skins/ (slug=' . var_export($slug, true) . ')',
             $slug === strtolower(preg_replace('/[^a-z0-9-]/', '', strtolower($hostile))), $failures);
    sy_check('  and no traversal character survives into it',
             preg_match('/^[a-z0-9-]*$/', $slug) === 1, $failures);
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 5 — THE WARNING AGAINST A HELPFUL DEFAULT IS IN THE FILE\n";
// ─────────────────────────────────────────────────────────────────────────────
// The PM asked for the "absence is information" note in the endpoint comment.
// It is the only thing standing between the next reader and a default that
// quietly lies about every site, so it is asserted rather than trusted.
sy_check('the endpoint says absence is information',
         stripos($source, 'ABSENCE IS INFORMATION') !== false, $failures);
sy_check('and tells the next reader not to add a default here',
         stripos($source, 'DO NOT add a default here') !== false, $failures);

echo "\n";
if ($failures) {
    fwrite(STDERR, 'sybu-data FAILED — ' . count($failures) . " check(s):\n");
    foreach ($failures as $f) { fwrite(STDERR, "  - {$f}\n"); }
    exit(1);
}
echo "sybu-data PASS — the endpoint reports the active skin, its name and\n";
echo "version, and the values that skin genuinely declares, resolved through the\n";
echo "same code the public CSS uses. Nothing is invented: there is no site_bg or\n";
echo "site_fg in core, ALFRED declares no background, foreground or body font,\n";
echo "and the endpoint stays silent about all three so a client can fall back\n";
echo "deliberately.\n";
echo "NOT COVERED: the HTTP request itself, which needs an authenticated session\n";
echo "or a scoped Bearer key.\n";
// ===== SNAPSMACK EOF =====
